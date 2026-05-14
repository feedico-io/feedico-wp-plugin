<?php
/**
 * Sync job: paginate API, upsert, passive marking (time-sliced for short HTTP requests).
 *
 * @package Feedico_Sync
 */

class Feedico_Sync_Job {

	public const PAGE_SIZE = 100;

	private const SLICE_STATE_OPTION = 'feedico_sync_slice_state';

	private const LOCK_TRANSIENT = 'feedico_sync_run_lock';

	/**
	 * Run full sync (merchants + coupons for selected network providers).
	 * May return early with partial=true; WP-Cron continues the same run until finished.
	 *
	 * @param string $trigger 'cron'|'manual'|'background'
	 * @return array{ok:bool,message:string,stats?:array<string,int>,partial?:bool}
	 */
	public static function run( string $trigger = 'manual' ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		Feedico_DB::ensure_tables();

		$state = get_option( self::SLICE_STATE_OPTION, null );
		if ( ! is_array( $state ) ) {
			$state = null;
		}
		$lock = get_transient( self::LOCK_TRANSIENT );
		if ( is_array( $state ) && isset( $state['run_id'], $state['v'] ) && (int) $state['v'] === 2 ) {
			$rid = (string) $state['run_id'];
			if ( false === $lock || $lock === '' ) {
				set_transient( self::LOCK_TRANSIENT, $rid, 3 * HOUR_IN_SECONDS );
				$lock = $rid;
			} elseif ( is_string( $lock ) && $lock !== '' && $lock !== $rid ) {
				delete_option( self::SLICE_STATE_OPTION );
				$state = null;
			}
		}
		if ( ! is_array( $state ) && is_string( $lock ) && $lock !== '' ) {
			delete_transient( self::LOCK_TRANSIENT );
		}

		$resume = is_array( $state )
			&& isset( $state['v'], $state['run_id'], $state['log_id'], $state['step'], $state['providers'] )
			&& is_array( $state['providers'] )
			&& (int) $state['v'] === 2;

		if ( ! $resume ) {
			if ( is_string( $lock ) && $lock !== '' ) {
				return array(
					'ok'      => false,
					'message' => __( 'A sync is already in progress. Try again in a few minutes.', 'feedico-sync' ),
				);
			}

			delete_option( self::SLICE_STATE_OPTION );

			$email = (string) get_option( 'feedico_sync_email', '' );
			$pass  = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_password_enc', '' ) );
			$token = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_token_enc', '' ) );

			if ( $email === '' || $token === '' ) {
				$msg = __( 'Email and API token are required.', 'feedico-sync' );
				self::record_last_run( $trigger, false, $msg, array(), microtime( true ) );
				return array( 'ok' => false, 'message' => $msg );
			}

			$providers = self::provider_filters();
			if ( $providers === array() ) {
				$msg = __( 'No networks selected or no provider slugs in catalog.', 'feedico-sync' );
				self::record_last_run( $trigger, false, $msg, array(), microtime( true ) );
				return array( 'ok' => false, 'message' => $msg );
			}

			$run_id = wp_generate_password( 16, false, false );
			set_transient( self::LOCK_TRANSIENT, $run_id, 3 * HOUR_IN_SECONDS );
			Feedico_DB::truncate_sync_seen();

			$log_id = Feedico_DB::insert_log_start( $trigger );
			$stats  = array(
				'merchants_upserted' => 0,
				'coupons_upserted'   => 0,
				'merchants_passive'  => 0,
				'coupons_passive'    => 0,
				'merchant_pages'     => 0,
				'coupon_pages'       => 0,
			);

			$ctx = array(
				'v'                 => 2,
				'run_id'            => $run_id,
				'log_id'            => $log_id,
				'trigger'           => $trigger,
				'stats'             => $stats,
				'providers'         => $providers,
				'started_monotonic' => microtime( true ),
				'step'              => 'mf',
				'prov_index'        => 0,
				'merchant_page'     => 1,
				'coupon_prov_index' => 0,
				'coupon_page'       => 1,
				'cpt_queue'         => array(),
				'cp_cpt_queue'      => array(),
			);
		} else {
			$ctx = $state;
			if ( get_transient( self::LOCK_TRANSIENT ) !== $ctx['run_id'] ) {
				delete_option( self::SLICE_STATE_OPTION );
				return array(
					'ok'      => false,
					'message' => __( 'Sync lock mismatch; state was reset. Start a new sync if needed.', 'feedico-sync' ),
				);
			}
			$trigger = (string) $ctx['trigger'];
		}

		$deadline = microtime( true ) + self::slice_max_seconds();
		$run_id   = (string) $ctx['run_id'];
		$log_id   = (int) $ctx['log_id'];
		$stats    = is_array( $ctx['stats'] ) ? $ctx['stats'] : array(
			'merchants_upserted' => 0,
			'coupons_upserted'   => 0,
			'merchants_passive'  => 0,
			'coupons_passive'    => 0,
			'merchant_pages'     => 0,
			'coupon_pages'       => 0,
		);
		$started_total = (float) $ctx['started_monotonic'];
		$providers = is_array( $ctx['providers'] ) ? $ctx['providers'] : array();
		$email     = (string) get_option( 'feedico_sync_email', '' );
		$pass      = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_password_enc', '' ) );
		$token     = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_token_enc', '' ) );
		if ( $email === '' || $token === '' ) {
			Feedico_DB::update_log_finish( $log_id, 'error', $stats, __( 'Credentials missing during sync resume.', 'feedico-sync' ) );
			delete_option( self::SLICE_STATE_OPTION );
			delete_transient( self::LOCK_TRANSIENT );
			self::record_last_run( $trigger, false, __( 'Credentials missing during sync resume.', 'feedico-sync' ), $stats, $started_total );
			return array(
				'ok'      => false,
				'message' => __( 'Credentials missing during sync resume; state was cleared.', 'feedico-sync' ),
			);
		}
		$step      = (string) $ctx['step'];
		$prov_index = (int) $ctx['prov_index'];
		$merchant_page = (int) $ctx['merchant_page'];
		$coupon_prov_index = (int) $ctx['coupon_prov_index'];
		$coupon_page = (int) $ctx['coupon_page'];
		$cpt_queue   = is_array( $ctx['cpt_queue'] ) ? $ctx['cpt_queue'] : array();
		$cp_cpt_queue = is_array( $ctx['cp_cpt_queue'] ) ? $ctx['cp_cpt_queue'] : array();

		try {
			while ( true ) {
				if ( ! self::has_time( $deadline ) ) {
					self::persist_slice_state(
						array(
							'v'                 => 2,
							'run_id'            => $run_id,
							'log_id'            => $log_id,
							'trigger'           => $trigger,
							'stats'             => $stats,
							'providers'         => $providers,
							'started_monotonic' => $started_total,
							'step'              => $step,
							'prov_index'        => $prov_index,
							'merchant_page'     => $merchant_page,
							'coupon_prov_index' => $coupon_prov_index,
							'coupon_page'       => $coupon_page,
							'cpt_queue'         => $cpt_queue,
							'cp_cpt_queue'      => $cp_cpt_queue,
						)
					);
					Feedico_Sync_Plugin::queue_background_full_sync();
					return array(
						'ok'      => true,
						'partial' => true,
						'message' => __( 'Sync paused to keep the site responsive; it will continue automatically in the background.', 'feedico-sync' ),
						'stats'   => $stats,
					);
				}

				if ( $step === 'mf' ) {
					if ( $prov_index >= count( $providers ) ) {
						$step               = 'cf';
						$coupon_prov_index = 0;
						$coupon_page       = 1;
						continue;
					}
					$prov = $providers[ $prov_index ];
					$body = Feedico_API::networks_list_body( $merchant_page, self::PAGE_SIZE, $prov, '' );
					$payload = Feedico_API::fetch_json_feed(
						Feedico_API::merchants_url(),
						$email,
						$token,
						$pass,
						$body
					);
					if ( is_wp_error( $payload ) ) {
						throw new \RuntimeException( $payload->get_error_message() );
					}
					$rows = Feedico_API::pull_page_rows( $payload );
					++$stats['merchant_pages'];
					$page_ids = array();
					foreach ( $rows as $r ) {
						if ( ! is_array( $r ) ) {
							continue;
						}
						$id = Feedico_DB::upsert_merchant( $r );
						if ( $id !== '' ) {
							$page_ids[] = $id;
							++$stats['merchants_upserted'];
							Feedico_Post_Types::sync_merchant_post( $id );
						}
					}
					if ( $page_ids !== array() ) {
						Feedico_DB::insert_sync_seen_batch( $run_id, 'merchant', $page_ids );
					}
					if ( count( $rows ) < self::PAGE_SIZE ) {
						$step          = 'mpsql';
						$merchant_page = 1;
					} else {
						++$merchant_page;
					}
					continue;
				}

				if ( $step === 'mpsql' ) {
					$prov = $providers[ $prov_index ];
					$passive_m = Feedico_DB::mark_merchants_passive_vs_seen( $prov, $run_id );
					$stats['merchants_passive'] += count( $passive_m );
					$cpt_queue = array_values( $passive_m );
					$step      = 'mpc';
					continue;
				}

				if ( $step === 'mpc' ) {
					$mid = array_shift( $cpt_queue );
					if ( $mid === null ) {
						++$prov_index;
						$merchant_page = 1;
						$step          = 'mf';
						continue;
					}
					Feedico_Post_Types::sync_merchant_post( (string) $mid );
					continue;
				}

				if ( $step === 'cf' ) {
					if ( $coupon_prov_index >= count( $providers ) ) {
						$step = 'cpsql';
						continue;
					}
					$prov = $providers[ $coupon_prov_index ];
					$body = Feedico_API::coupons_list_body( $coupon_page, self::PAGE_SIZE, $prov, null );
					$payload = Feedico_API::fetch_json_feed(
						Feedico_API::coupons_url(),
						$email,
						$token,
						$pass,
						$body
					);
					if ( is_wp_error( $payload ) ) {
						throw new \RuntimeException( $payload->get_error_message() );
					}
					$rows = Feedico_API::pull_page_rows( $payload );
					if ( $rows === array() && is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
						foreach ( $payload['items'] as $x ) {
							if ( is_array( $x ) ) {
								$rows[] = $x;
							}
						}
					}
					++$stats['coupon_pages'];
					$page_ids = array();
					foreach ( $rows as $r ) {
						if ( ! is_array( $r ) ) {
							continue;
						}
						$id = Feedico_DB::upsert_coupon( $r );
						if ( $id !== '' ) {
							$page_ids[] = $id;
							++$stats['coupons_upserted'];
							Feedico_Post_Types::sync_coupon_post( $id );
						}
					}
					if ( $page_ids !== array() ) {
						Feedico_DB::insert_sync_seen_batch( $run_id, 'coupon', $page_ids );
					}
					if ( count( $rows ) < self::PAGE_SIZE ) {
						++$coupon_prov_index;
						$coupon_page = 1;
					} else {
						++$coupon_page;
					}
					continue;
				}

				if ( $step === 'cpsql' ) {
					$passive_c = Feedico_DB::mark_coupons_passive_vs_seen( $run_id );
					$stats['coupons_passive'] += count( $passive_c );
					$cp_cpt_queue = array_values( $passive_c );
					$step         = 'cpc';
					continue;
				}

				if ( $step === 'cpc' ) {
					$cid = array_shift( $cp_cpt_queue );
					if ( $cid === null ) {
						break;
					}
					Feedico_Post_Types::sync_coupon_post( (string) $cid );
					continue;
				}

				break;
			}

			Feedico_DB::update_log_finish( $log_id, 'success', $stats, '' );
			Feedico_DB::truncate_sync_seen();
			delete_option( self::SLICE_STATE_OPTION );
			delete_transient( self::LOCK_TRANSIENT );

			$out = array(
				'ok'      => true,
				'message' => __( 'Sync completed.', 'feedico-sync' ),
				'stats'   => $stats,
			);
			self::record_last_run( $trigger, true, $out['message'], $stats, $started_total );
			return $out;
		} catch ( \Throwable $e ) {
			Feedico_DB::update_log_finish(
				$log_id,
				'error',
				$stats,
				$e->getMessage()
			);
			Feedico_DB::truncate_sync_seen();
			delete_option( self::SLICE_STATE_OPTION );
			delete_transient( self::LOCK_TRANSIENT );
			$out = array(
				'ok'      => false,
				'message' => $e->getMessage(),
				'stats'   => $stats,
			);
			self::record_last_run( $trigger, false, $e->getMessage(), $stats, $started_total );
			return $out;
		}
	}

	private static function slice_max_seconds(): float {
		$v = (float) apply_filters( 'feedico_sync_slice_max_seconds', 22.0 );
		return max( 5.0, min( 120.0, $v ) );
	}

	private static function has_time( float $deadline ): bool {
		return microtime( true ) < $deadline;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private static function persist_slice_state( array $payload ): void {
		update_option( self::SLICE_STATE_OPTION, $payload, false );
		set_transient( self::LOCK_TRANSIENT, (string) $payload['run_id'], 3 * HOUR_IN_SECONDS );
	}

	/**
	 * Persist last sync outcome for the admin UI.
	 *
	 * @param array<string,int> $stats
	 * @param float             $started_monotonic Value from microtime( true ) at job start.
	 */
	public static function record_last_run( string $trigger, bool $ok, string $message, array $stats, float $started_monotonic ): void {
		$duration_seconds = round( max( 0, microtime( true ) - $started_monotonic ), 2 );
		update_option(
			'feedico_sync_last_run',
			array(
				'finished_at'       => current_time( 'mysql', true ),
				'finished_at_local' => current_time( 'mysql' ),
				'ok'                => $ok,
				'message'           => $message,
				'stats'             => $stats,
				'trigger'           => $trigger,
				'duration_seconds'  => $duration_seconds,
			),
			false
		);
	}

	/**
	 * @return array<int,string>
	 */
	private static function provider_filters(): array {
		$catalog  = get_option( 'feedico_sync_network_catalog', array() );
		$selected = get_option( 'feedico_sync_selected_networks', array() );
		if ( ! is_array( $catalog ) || ! is_array( $selected ) ) {
			return array();
		}
		$sel = array_flip( array_map( 'strval', $selected ) );
		$out = array();
		foreach ( $catalog as $row ) {
			if ( ! is_array( $row ) || empty( $row['id'] ) ) {
				continue;
			}
			$id = (string) $row['id'];
			if ( ! isset( $sel[ $id ] ) ) {
				continue;
			}
			$p = isset( $row['provider'] ) ? trim( (string) $row['provider'] ) : '';
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return array_values( array_unique( $out ) );
	}
}
