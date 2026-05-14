<?php
/**
 * Sync job: paginate API, upsert, passive marking.
 *
 * @package Feedico_Sync
 */

class Feedico_Sync_Job {

	public const PAGE_SIZE = 100;

	/**
	 * Run full sync (merchants + coupons for selected network providers).
	 *
	 * @param string $trigger 'cron'|'manual'|'background'
	 * @return array{ok:bool,message:string,stats?:array<string,int>}
	 */
	public static function run( string $trigger = 'manual' ): array {
		$started_monotonic = microtime( true );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 );
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		Feedico_DB::ensure_tables();

		$email = (string) get_option( 'feedico_sync_email', '' );
		$pass  = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_password_enc', '' ) );
		$token = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_token_enc', '' ) );

		if ( $email === '' || $token === '' ) {
			$msg = __( 'Email and API token are required.', 'feedico-sync' );
			self::record_last_run( $trigger, false, $msg, array(), $started_monotonic );
			return array( 'ok' => false, 'message' => $msg );
		}

		$providers = self::provider_filters();
		if ( $providers === array() ) {
			$msg = __( 'No networks selected or no provider slugs in catalog.', 'feedico-sync' );
			self::record_last_run( $trigger, false, $msg, array(), $started_monotonic );
			return array( 'ok' => false, 'message' => $msg );
		}

		$log_id = Feedico_DB::insert_log_start( $trigger );
		$stats  = array(
			'merchants_upserted'  => 0,
			'coupons_upserted'    => 0,
			'merchants_passive'   => 0,
			'coupons_passive'     => 0,
			'merchant_pages'      => 0,
			'coupon_pages'        => 0,
		);

		try {
			foreach ( $providers as $prov ) {
				$seen_m = self::sync_merchants_provider( $email, $pass, $token, $prov, $stats );
				if ( $seen_m !== array() ) {
					$passive_m = Feedico_DB::mark_merchants_not_in_ids_passive( $prov, $seen_m );
					$stats['merchants_passive'] += count( $passive_m );
					foreach ( $passive_m as $mid ) {
						Feedico_Post_Types::sync_merchant_post( $mid );
					}
				}
			}

			$seen_c = self::sync_coupons_all_providers( $email, $pass, $token, $providers, $stats );
			if ( $seen_c !== array() ) {
				$passive_c = Feedico_DB::mark_coupons_not_in_ids_passive( $seen_c );
				$stats['coupons_passive'] += count( $passive_c );
				foreach ( $passive_c as $cid ) {
					Feedico_Post_Types::sync_coupon_post( $cid );
				}
			}

			Feedico_DB::update_log_finish( $log_id, 'success', $stats, '' );
			$out = array(
				'ok'     => true,
				'message'=> __( 'Sync completed.', 'feedico-sync' ),
				'stats'  => $stats,
			);
			self::record_last_run( $trigger, true, $out['message'], $stats, $started_monotonic );
			return $out;
		} catch ( \Throwable $e ) {
			Feedico_DB::update_log_finish(
				$log_id,
				'error',
				$stats,
				$e->getMessage()
			);
			$out = array(
				'ok'      => false,
				'message' => $e->getMessage(),
				'stats'   => $stats,
			);
			self::record_last_run( $trigger, false, $e->getMessage(), $stats, $started_monotonic );
			return $out;
		}
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
		$catalog = get_option( 'feedico_sync_network_catalog', array() );
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

	/**
	 * @param array<string,int> $stats
	 * @return array<int,string> seen merchant ids
	 */
	private static function sync_merchants_provider( string $email, string $pass, string $token, string $provider, array &$stats ): array {
		$seen = array();
		$page = 1;
		while ( true ) {
			$body    = Feedico_API::networks_list_body( $page, self::PAGE_SIZE, $provider, '' );
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
			foreach ( $rows as $r ) {
				$id = Feedico_DB::upsert_merchant( $r );
				if ( $id !== '' ) {
					$seen[] = $id;
					++$stats['merchants_upserted'];
					Feedico_Post_Types::sync_merchant_post( $id );
				}
			}
			if ( count( $rows ) < self::PAGE_SIZE ) {
				break;
			}
			++$page;
		}
		return $seen;
	}

	/**
	 * @param array<int,string>   $providers
	 * @param array<string,int> $stats
	 * @return array<int,string>
	 */
	private static function sync_coupons_all_providers( string $email, string $pass, string $token, array $providers, array &$stats ): array {
		$seen_all = array();
		foreach ( $providers as $prov ) {
			$page = 1;
			while ( true ) {
				$body    = Feedico_API::coupons_list_body( $page, self::PAGE_SIZE, $prov, null );
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
				foreach ( $rows as $r ) {
					$id = Feedico_DB::upsert_coupon( $r );
					if ( $id !== '' ) {
						$seen_all[] = $id;
						++$stats['coupons_upserted'];
						Feedico_Post_Types::sync_coupon_post( $id );
					}
				}
				if ( count( $rows ) < self::PAGE_SIZE ) {
					break;
				}
				++$page;
			}
		}
		return array_values( array_unique( $seen_all ) );
	}
}
