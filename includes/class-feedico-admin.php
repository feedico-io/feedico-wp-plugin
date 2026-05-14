<?php
/**
 * Admin UI.
 *
 * @package Feedico_Sync
 */

class Feedico_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_feedico_save_merchant', array( __CLASS__, 'handle_save_merchant_admin_post' ) );
		add_action( 'admin_post_feedico_save_coupon', array( __CLASS__, 'handle_save_coupon_admin_post' ) );
		add_action( 'admin_post_feedico_create_merchant_landing', array( __CLASS__, 'handle_create_merchant_landing' ) );
		add_action( 'wp_ajax_feedico_sync_test', array( __CLASS__, 'ajax_test' ) );
		add_action( 'wp_ajax_feedico_sync_refresh_dashboard', array( __CLASS__, 'ajax_refresh_dashboard' ) );
		add_action( 'wp_ajax_feedico_sync_run', array( __CLASS__, 'ajax_run' ) );
	}

	public static function assets( string $hook ): void {
		if ( strpos( $hook, 'feedico-sync' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'feedico-sync-admin',
			FEEDICO_SYNC_URL . 'assets/admin.css',
			array( 'dashicons' ),
			FEEDICO_SYNC_VERSION
		);
		wp_enqueue_script(
			'feedico-sync-admin',
			FEEDICO_SYNC_URL . 'assets/admin.js',
			array(),
			FEEDICO_SYNC_VERSION,
			true
		);
		wp_localize_script(
			'feedico-sync-admin',
			'feedicoSync',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'feedico_sync_ajax' ),
				'strings' => array(
					'testing'           => __( 'Testing…', 'feedico-sync' ),
					'testOk'            => __( 'Connection OK.', 'feedico-sync' ),
					'syncRunning'       => __( 'Queuing sync…', 'feedico-sync' ),
					'syncOk'            => __( 'Sync finished.', 'feedico-sync' ),
					'syncFail'          => __( 'Sync failed.', 'feedico-sync' ),
					'requestFailed'     => __( 'Request failed.', 'feedico-sync' ),
					'networksEmpty'     => __( 'Connect successfully first to load networks.', 'feedico-sync' ),
					'networksHint'      => __( 'Choose which affiliate networks to include in each sync.', 'feedico-sync' ),
				),
			)
		);
	}

	public static function menu(): void {
		add_menu_page(
			__( 'Feedico Sync', 'feedico-sync' ),
			__( 'Feedico Sync', 'feedico-sync' ),
			'manage_options',
			'feedico-sync',
			array( __CLASS__, 'render' ),
			'dashicons-update',
			58
		);
		add_submenu_page(
			'feedico-sync',
			__( 'Merchants', 'feedico-sync' ),
			__( 'Merchants', 'feedico-sync' ),
			'manage_options',
			'feedico-sync-merchants',
			array( __CLASS__, 'render_merchants_admin' )
		);
		add_submenu_page(
			'feedico-sync',
			__( 'Coupons', 'feedico-sync' ),
			__( 'Coupons', 'feedico-sync' ),
			'manage_options',
			'feedico-sync-coupons',
			array( __CLASS__, 'render_coupons_admin' )
		);
		add_submenu_page(
			null,
			__( 'Edit merchant', 'feedico-sync' ),
			'',
			'manage_options',
			'feedico-sync-merchant-edit',
			array( __CLASS__, 'render_merchant_edit' )
		);
		add_submenu_page(
			null,
			__( 'Edit coupon', 'feedico-sync' ),
			'',
			'manage_options',
			'feedico-sync-coupon-edit',
			array( __CLASS__, 'render_coupon_edit' )
		);
	}

	public static function handle_save_merchant_admin_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'feedico-sync' ) );
		}
		if ( ! isset( $_POST['feedico_merchant_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['feedico_merchant_edit_nonce'] ) ), 'feedico_merchant_edit' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-merchants&feedico_edit_err=nonce' ) );
			exit;
		}
		$id = isset( $_POST['merchant_id'] ) ? trim( wp_unslash( (string) $_POST['merchant_id'] ) ) : '';
		if ( $id === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-merchants&feedico_edit_err=id' ) );
			exit;
		}
		$url_in = isset( $_POST['merchant_website_url'] ) ? (string) wp_unslash( $_POST['merchant_website_url'] ) : '';
		$url_in = self::normalize_http_url_input( $url_in );
		$fields = array(
			'display_name'         => isset( $_POST['display_name'] ) ? sanitize_text_field( wp_unslash( $_POST['display_name'] ) ) : '',
			'description'          => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'merchant_website_url' => $url_in,
			'status'               => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'wp_feedico_active'    => ! empty( $_POST['wp_feedico_active'] ) ? 1 : 0,
		);
		$ok = Feedico_DB::update_merchant_admin( $id, $fields );
		if ( $ok && ! empty( $_POST['feedico_allow_api_overwrite'] ) ) {
			Feedico_DB::set_merchant_manual_override( $id, 0 );
		}
		if ( $ok ) {
			Feedico_Post_Types::sync_merchant_post( $id );
		}
		wp_safe_redirect(
			admin_url(
				'admin.php?page=feedico-sync-merchant-edit&merchant_id=' . rawurlencode( $id ) . ( $ok ? '&updated=1' : '&error=1' )
			)
		);
		exit;
	}

	public static function handle_save_coupon_admin_post(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'feedico-sync' ) );
		}
		if ( ! isset( $_POST['feedico_coupon_edit_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['feedico_coupon_edit_nonce'] ) ), 'feedico_coupon_edit' ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-coupons&feedico_coupon_edit_err=nonce' ) );
			exit;
		}
		$id = isset( $_POST['coupon_id'] ) ? trim( wp_unslash( (string) $_POST['coupon_id'] ) ) : '';
		if ( $id === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-coupons&feedico_coupon_edit_err=id' ) );
			exit;
		}
		$aff = isset( $_POST['affiliate_url'] ) ? (string) wp_unslash( $_POST['affiliate_url'] ) : '';
		$img = isset( $_POST['image_url'] ) ? (string) wp_unslash( $_POST['image_url'] ) : '';
		$fields = array(
			'title'            => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description'      => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'coupon_code'      => isset( $_POST['coupon_code'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) : '',
			'affiliate_url'    => self::normalize_http_url_input( $aff ),
			'image_url'        => self::normalize_http_url_input( $img ),
			'network_name'     => isset( $_POST['network_name'] ) ? sanitize_text_field( wp_unslash( $_POST['network_name'] ) ) : '',
			'starts_at'        => isset( $_POST['starts_at'] ) ? sanitize_text_field( wp_unslash( $_POST['starts_at'] ) ) : '',
			'ends_at'          => isset( $_POST['ends_at'] ) ? sanitize_text_field( wp_unslash( $_POST['ends_at'] ) ) : '',
			'discount_type'    => isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : '',
			'discount_value'   => isset( $_POST['discount_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_value'] ) ) : '',
			'currency_code'    => isset( $_POST['currency_code'] ) ? sanitize_text_field( wp_unslash( $_POST['currency_code'] ) ) : '',
			'status'           => isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '',
			'wp_feedico_active'=> ! empty( $_POST['wp_feedico_active'] ) ? 1 : 0,
		);
		$ok = Feedico_DB::update_coupon_admin( $id, $fields );
		if ( $ok && ! empty( $_POST['feedico_allow_api_overwrite'] ) ) {
			Feedico_DB::set_coupon_manual_override( $id, 0 );
		}
		if ( $ok ) {
			Feedico_Post_Types::sync_coupon_post( $id );
		}
		wp_safe_redirect(
			admin_url(
				'admin.php?page=feedico-sync-coupon-edit&coupon_id=' . rawurlencode( $id ) . ( $ok ? '&updated=1' : '&error=1' )
			)
		);
		exit;
	}

	/**
	 * Accept host-only or scheme-less URLs; keep valid empty.
	 */
	public static function normalize_http_url_input( string $url ): string {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}
		return esc_url_raw( $url );
	}

	/**
	 * @deprecated Use normalize_http_url_input().
	 */
	public static function normalize_merchant_website_input( string $url ): string {
		return self::normalize_http_url_input( $url );
	}

	public static function handle_post(): void {
		if ( ! isset( $_POST['feedico_sync_action'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['feedico_sync_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['feedico_sync_nonce'] ) ), 'feedico_sync_save' ) ) {
			return;
		}

		$action = sanitize_text_field( wp_unslash( $_POST['feedico_sync_action'] ) );
		if ( 'save_settings' === $action ) {
			$email = isset( $_POST['feedico_email'] ) ? sanitize_email( wp_unslash( $_POST['feedico_email'] ) ) : '';
			$pass  = isset( $_POST['feedico_password'] ) ? (string) wp_unslash( $_POST['feedico_password'] ) : '';
			$tok   = isset( $_POST['feedico_token'] ) ? sanitize_text_field( wp_unslash( $_POST['feedico_token'] ) ) : '';

			update_option( 'feedico_sync_email', $email );
			if ( $pass !== '' ) {
				update_option( 'feedico_sync_password_enc', Feedico_Crypto::encrypt( $pass ) );
			}
			if ( $tok !== '' ) {
				update_option( 'feedico_sync_token_enc', Feedico_Crypto::encrypt( $tok ) );
			}

			$custom_mins = isset( $_POST['feedico_cron_custom_minutes'] ) ? (int) wp_unslash( $_POST['feedico_cron_custom_minutes'] ) : 60;
			$custom_mins = Feedico_Sync_Plugin::clamp_custom_interval_minutes( $custom_mins );
			update_option( 'feedico_sync_cron_custom_minutes', $custom_mins );

			$interval = isset( $_POST['feedico_cron_interval'] ) ? sanitize_text_field( wp_unslash( $_POST['feedico_cron_interval'] ) ) : 'hourly';
			if ( ! in_array( $interval, Feedico_Sync_Plugin::allowed_cron_interval_slugs(), true ) ) {
				$interval = 'hourly';
			}
			update_option( 'feedico_sync_cron_interval', $interval );
			Feedico_Sync_Plugin::reschedule_cron( $interval );

			$sel = isset( $_POST['feedico_networks'] ) && is_array( $_POST['feedico_networks'] )
				? array_map( 'sanitize_text_field', wp_unslash( $_POST['feedico_networks'] ) )
				: array();
			$prev_sel = get_option( 'feedico_sync_selected_networks', array() );
			if ( ! is_array( $prev_sel ) ) {
				$prev_sel = array();
			}
			$new_sel = array_values( array_filter( $sel ) );
			update_option( 'feedico_sync_selected_networks', $new_sel );

			$pa = array_map( 'strval', $prev_sel );
			$na = array_map( 'strval', $new_sel );
			sort( $pa );
			sort( $na );
			if ( $new_sel !== array() && $pa !== $na ) {
				Feedico_Sync_Plugin::queue_background_full_sync();
				add_settings_error(
					'feedico_sync',
					'queued_sync',
					__( 'A full sync was queued to run in the background with your new network selection. Refresh this screen in a few minutes to see updated logs.', 'feedico-sync' ),
					'success'
				);
			}

			add_settings_error( 'feedico_sync', 'saved', __( 'Settings saved.', 'feedico-sync' ), 'success' );
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync&settings-updated=1' ) );
			exit;
		}
	}

	/**
	 * Keep only fields needed for admin cards and network checkboxes (API may embed very large lists).
	 *
	 * @param array<string,mixed> $r Raw dashboard JSON.
	 * @return array<string,mixed>
	 */
	private static function slim_dashboard_for_storage( array $r ): array {
		$slim = array();
		if ( array_key_exists( 'ok', $r ) ) {
			$slim['ok'] = $r['ok'];
		}
		foreach ( array( 'error', 'message' ) as $k ) {
			if ( isset( $r[ $k ] ) && is_string( $r[ $k ] ) ) {
				$slim[ $k ] = $r[ $k ];
			}
		}

		if ( isset( $r['profile'] ) && is_array( $r['profile'] ) ) {
			$prof             = $r['profile'];
			$slim['profile']  = array();
			foreach ( array( 'fullName', 'name', 'displayName', 'email', 'planName' ) as $k ) {
				if ( isset( $prof[ $k ] ) && is_scalar( $prof[ $k ] ) ) {
					$slim['profile'][ $k ] = $prof[ $k ];
				}
			}
			if ( isset( $prof['plan'] ) && is_array( $prof['plan'] ) && isset( $prof['plan']['name'] ) && is_scalar( $prof['plan']['name'] ) ) {
				$slim['profile']['plan'] = array( 'name' => $prof['plan']['name'] );
			}
		}

		if ( isset( $r['overview'] ) && is_array( $r['overview'] ) ) {
			$ov                = $r['overview'];
			$slim['overview']  = array();
			foreach ( array( 'activeFeeds', 'couponsSynced24h', 'lastSyncLabel', 'lastSyncStatus', 'daysUntilRenewal', 'planRenewalLabel', 'planName' ) as $k ) {
				if ( ! array_key_exists( $k, $ov ) ) {
					continue;
				}
				$v = $ov[ $k ];
				if ( is_scalar( $v ) || $v === null ) {
					$slim['overview'][ $k ] = $v;
				}
			}
			if ( isset( $ov['connectedNetworks'] ) && is_array( $ov['connectedNetworks'] ) ) {
				$net_keys = array(
					'id',
					'networkId',
					'network_id',
					'slug',
					'code',
					'label',
					'name',
					'provider',
					'subtitle',
					'description',
					'tagline',
					'summary',
					'caption',
					'detail',
					'secondaryLabel',
					'merchantCount',
					'couponCount',
					'lastSyncLabel',
					'lastSyncStatus',
				);
				$nets = array();
				foreach ( $ov['connectedNetworks'] as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$row = array();
					foreach ( $net_keys as $nk ) {
						if ( ! array_key_exists( $nk, $item ) ) {
							continue;
						}
						$vv = $item[ $nk ];
						if ( is_scalar( $vv ) ) {
							$row[ $nk ] = $vv;
						}
					}
					if ( $row !== array() ) {
						$nets[] = $row;
					}
				}
				$slim['overview']['connectedNetworks'] = $nets;
			}
		}

		return $slim;
	}

	/**
	 * Detect dashboard blobs that would bloat wp_options or slow admin (embedded catalog lists).
	 *
	 * @param array<string,mixed> $connected Decoded feedico_sync_last_dashboard.
	 */
	private static function dashboard_payload_looks_bloated( array $connected ): bool {
		if ( isset( $connected['overview'] ) && is_array( $connected['overview'] ) ) {
			$ov = $connected['overview'];
			foreach ( array( 'merchants', 'coupons', 'items', 'deals', 'offers', 'feeds' ) as $heavy ) {
				if ( isset( $ov[ $heavy ] ) && is_array( $ov[ $heavy ] ) && count( $ov[ $heavy ] ) > 50 ) {
					return true;
				}
			}
			if ( isset( $ov['connectedNetworks'] ) && is_array( $ov['connectedNetworks'] ) ) {
				foreach ( $ov['connectedNetworks'] as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					foreach ( array( 'merchants', 'coupons', 'items', 'deals', 'offers', 'feeds' ) as $heavy ) {
						if ( isset( $item[ $heavy ] ) && is_array( $item[ $heavy ] ) && $item[ $heavy ] !== array() ) {
							return true;
						}
					}
				}
			}
		}
		$json_check = wp_json_encode( $connected );
		return is_string( $json_check ) && strlen( $json_check ) > 300000;
	}

	/**
	 * One-time shrink of oversized dashboard option (older plugin versions stored the full API body).
	 *
	 * @param array<string,mixed> $connected Current decoded option.
	 * @return array<string,mixed> Possibly replaced payload for render.
	 */
	private static function maybe_prune_stored_dashboard( array $connected ): array {
		if ( ! self::dashboard_payload_looks_bloated( $connected ) ) {
			return $connected;
		}
		$slim = self::slim_dashboard_for_storage( $connected );
		update_option( 'feedico_sync_last_dashboard', $slim );
		update_option( 'feedico_sync_network_catalog', self::catalog_from_payload( $slim ) );
		return $slim;
	}

	/**
	 * Store dashboard payload and refresh network catalog / default selection.
	 *
	 * @param array<string,mixed> $r Dashboard API response (decoded).
	 */
	private static function persist_dashboard_success( array $r ): void {
		update_option( 'feedico_sync_connection_ok', '1' );
		$slim = self::slim_dashboard_for_storage( $r );
		update_option( 'feedico_sync_last_dashboard', $slim );
		$catalog = self::catalog_from_payload( $slim );
		update_option( 'feedico_sync_network_catalog', $catalog );

		$raw_sel = get_option( 'feedico_sync_selected_networks', null );
		if ( null === $raw_sel || ! is_array( $raw_sel ) || $raw_sel === array() ) {
			$ids = array();
			foreach ( $catalog as $row ) {
				if ( ! empty( $row['id'] ) ) {
					$ids[] = (string) $row['id'];
				}
			}
			update_option( 'feedico_sync_selected_networks', $ids );
		}
	}

	public static function ajax_test(): void {
		check_ajax_referer( 'feedico_sync_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$pass  = isset( $_POST['password'] ) ? (string) wp_unslash( $_POST['password'] ) : '';
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$r = Feedico_API::verify_dashboard( $email, $pass, $token );
		if ( is_wp_error( $r ) ) {
			update_option( 'feedico_sync_connection_ok', '0' );
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		update_option( 'feedico_sync_email', $email );
		if ( $pass !== '' ) {
			update_option( 'feedico_sync_password_enc', Feedico_Crypto::encrypt( $pass ) );
		}
		if ( $token !== '' ) {
			update_option( 'feedico_sync_token_enc', Feedico_Crypto::encrypt( $token ) );
		}
		self::persist_dashboard_success( $r );
		$slim    = get_option( 'feedico_sync_last_dashboard', array() );
		$slim    = is_array( $slim ) ? $slim : array();
		$catalog = get_option( 'feedico_sync_network_catalog', array() );
		$catalog = is_array( $catalog ) ? $catalog : array();

		wp_send_json_success(
			array(
				'dashboard'      => $slim,
				'catalog'        => $catalog,
				'dashboard_html' => self::dashboard_cards_html( $slim ),
			)
		);
	}

	/**
	 * Reload dashboard from Feedico using stored credentials (page load / background refresh).
	 */
	public static function ajax_refresh_dashboard(): void {
		check_ajax_referer( 'feedico_sync_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}

		$email = (string) get_option( 'feedico_sync_email', '' );
		$pass  = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_password_enc', '' ) );
		$token = Feedico_Crypto::decrypt( (string) get_option( 'feedico_sync_token_enc', '' ) );
		if ( $email === '' || ( $pass === '' && $token === '' ) ) {
			wp_send_json_error( array( 'message' => 'not_configured' ) );
		}

		$r = Feedico_API::verify_dashboard( $email, $pass, $token );
		if ( is_wp_error( $r ) ) {
			update_option( 'feedico_sync_connection_ok', '0' );
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}

		self::persist_dashboard_success( $r );
		$slim    = get_option( 'feedico_sync_last_dashboard', array() );
		$slim    = is_array( $slim ) ? $slim : array();
		$catalog = get_option( 'feedico_sync_network_catalog', array() );
		$catalog = is_array( $catalog ) ? $catalog : array();

		wp_send_json_success(
			array(
				'dashboard_html' => self::dashboard_cards_html( $slim ),
				'catalog'        => $catalog,
			)
		);
	}

	public static function ajax_run(): void {
		check_ajax_referer( 'feedico_sync_ajax', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
		}
		Feedico_Sync_Plugin::queue_background_full_sync();
		$last   = get_option( 'feedico_sync_last_run', array() );
		$banner = is_array( $last ) ? self::last_sync_banner_html( $last ) : '';
		$dur    = is_array( $last ) ? self::format_last_sync_duration_min_sec( $last ) : '';

		wp_send_json_success(
			array(
				'queued'               => true,
				'message'              => __( 'Full sync has been queued. It runs in the background via WP-Cron; refresh this page in a few minutes to see the latest log and banner.', 'feedico-sync' ),
				'stats'                => array(),
				'banner_html'          => $banner,
				'last_sync_duration'   => $dur,
			)
		);
	}

	/**
	 * Human-readable last sync length (minutes + seconds) for the schedule UI.
	 *
	 * @param array<string,mixed> $last_run Option payload from feedico_sync_last_run.
	 */
	private static function format_last_sync_duration_min_sec( array $last_run ): string {
		if ( ! isset( $last_run['duration_seconds'] ) || ! is_numeric( $last_run['duration_seconds'] ) ) {
			return '';
		}
		$sec = (float) $last_run['duration_seconds'];
		if ( $sec < 0 ) {
			return '';
		}
		$whole = (int) floor( $sec );
		$mins  = intdiv( $whole, 60 );
		$rem   = $whole % 60;
		if ( $mins > 0 ) {
			/* translators: 1: minutes, 2: seconds (remainder) */
			return sprintf( __( '%1$d min %2$d sec', 'feedico-sync' ), $mins, $rem );
		}
		if ( $whole > 0 ) {
			/* translators: %d: seconds */
			return sprintf( __( '%d sec', 'feedico-sync' ), $whole );
		}
		/* translators: %s: seconds with decimal, for runs under one second */
		return sprintf( __( '%s sec', 'feedico-sync' ), number_format_i18n( $sec, 1 ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function last_sync_banner_html( array $data ): string {
		$ok      = ! empty( $data['ok'] );
		$class   = $ok ? 'feedico-banner feedico-banner-success' : 'feedico-banner feedico-banner-error';
		$label   = $ok ? __( 'Last sync: success', 'feedico-sync' ) : __( 'Last sync: error', 'feedico-sync' );
		$when_d  = '—';
		if ( ! empty( $data['finished_at_local'] ) ) {
			$ts = strtotime( (string) $data['finished_at_local'] );
			if ( $ts ) {
				$when_d = wp_date( get_option( 'date_format' ) . ' H:i', $ts );
			}
		}
		$trigger = isset( $data['trigger'] ) ? (string) $data['trigger'] : '';
		$msg     = isset( $data['message'] ) ? (string) $data['message'] : '';
		$stats   = isset( $data['stats'] ) && is_array( $data['stats'] ) ? $data['stats'] : array();

		ob_start();
		?>
		<div id="feedico-last-sync-banner" class="<?php echo esc_attr( $class ); ?>" role="status">
			<div class="feedico-banner-title"><?php echo esc_html( $label ); ?></div>
			<div class="feedico-banner-meta">
				<?php
				echo esc_html(
					sprintf(
						__( 'Finished: %1$s · Trigger: %2$s', 'feedico-sync' ),
						$when_d,
						$trigger !== '' ? $trigger : '—'
					)
				);
				?>
			</div>
			<?php if ( $msg !== '' ) : ?>
				<p class="feedico-banner-msg"><?php echo esc_html( $msg ); ?></p>
			<?php endif; ?>
			<?php if ( $stats !== array() ) : ?>
				<ul class="feedico-banner-stats">
					<?php foreach ( $stats as $k => $v ) : ?>
						<li><strong><?php echo esc_html( (string) $k ); ?>:</strong> <?php echo esc_html( number_format_i18n( (int) $v ) ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function dashboard_cards_html( array $payload ): string {
		ob_start();
		self::render_dashboard_cards( $payload );
		return (string) ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public static function render_dashboard_cards( array $payload ): void {
		$prof = isset( $payload['profile'] ) && is_array( $payload['profile'] ) ? $payload['profile'] : array();
		$ov   = isset( $payload['overview'] ) && is_array( $payload['overview'] ) ? $payload['overview'] : array();

		$name = '';
		foreach ( array( 'fullName', 'name', 'displayName' ) as $k ) {
			if ( ! empty( $prof[ $k ] ) ) {
				$name = (string) $prof[ $k ];
				break;
			}
		}
		$email_p = isset( $prof['email'] ) ? (string) $prof['email'] : '';

		$active_feeds = isset( $ov['activeFeeds'] ) && is_numeric( $ov['activeFeeds'] ) ? (int) $ov['activeFeeds'] : null;
		$coupons_24   = $ov['couponsSynced24h'] ?? null;
		$last_sync_l  = isset( $ov['lastSyncLabel'] ) ? (string) $ov['lastSyncLabel'] : '';
		$last_sync_st = isset( $ov['lastSyncStatus'] ) ? (string) $ov['lastSyncStatus'] : '';
		$renew_days   = isset( $ov['daysUntilRenewal'] ) && is_numeric( $ov['daysUntilRenewal'] ) ? (int) $ov['daysUntilRenewal'] : null;
		$renew_lbl    = isset( $ov['planRenewalLabel'] ) ? (string) $ov['planRenewalLabel'] : '';
		$nets_raw     = isset( $ov['connectedNetworks'] ) && is_array( $ov['connectedNetworks'] ) ? $ov['connectedNetworks'] : array();
		$net_count    = count( $nets_raw );
		$plan_name    = $ov['planName'] ?? '';
		if ( $plan_name === '' && isset( $prof['plan'] ) && is_array( $prof['plan'] ) && isset( $prof['plan']['name'] ) ) {
			$plan_name = (string) $prof['plan']['name'];
		}

		?>
		<div class="feedico-dash-cards">
			<?php if ( $name !== '' || $email_p !== '' ) : ?>
				<div class="feedico-dash-profile">
					<h3><?php esc_html_e( 'Account', 'feedico-sync' ); ?></h3>
					<?php if ( $name !== '' ) : ?>
						<p class="feedico-profile-name"><?php echo esc_html( $name ); ?></p>
					<?php endif; ?>
					<?php if ( $email_p !== '' ) : ?>
						<p class="feedico-profile-email"><?php echo esc_html( $email_p ); ?></p>
					<?php endif; ?>
					<?php if ( $plan_name !== '' ) : ?>
						<p class="feedico-profile-plan"><?php echo esc_html( (string) $plan_name ); ?></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<div class="feedico-metric-grid">
				<?php
				if ( null !== $active_feeds ) {
					self::metric_card( __( 'Active feeds', 'feedico-sync' ), number_format_i18n( $active_feeds ) );
				}
				if ( null !== $coupons_24 && ( is_numeric( $coupons_24 ) ) ) {
					self::metric_card( __( 'Coupons (24h)', 'feedico-sync' ), number_format_i18n( (int) $coupons_24 ) );
				}
				if ( $last_sync_l !== '' || $last_sync_st !== '' ) {
					$v = trim( $last_sync_l . ( $last_sync_st !== '' ? ' · ' . strtoupper( $last_sync_st ) : '' ) );
					self::metric_card( __( 'Last sync', 'feedico-sync' ), $v );
				}
				if ( null !== $renew_days ) {
					self::metric_card( __( 'Renewal in', 'feedico-sync' ), sprintf( /* translators: %d days */ _n( '%d day', '%d days', $renew_days, 'feedico-sync' ), $renew_days ) );
				} elseif ( $renew_lbl !== '' ) {
					self::metric_card( __( 'Renewal', 'feedico-sync' ), $renew_lbl );
				}
				self::metric_card( __( 'Connected networks', 'feedico-sync' ), number_format_i18n( $net_count ) );
				?>
			</div>

			<?php if ( $nets_raw !== array() ) : ?>
				<div class="feedico-network-list">
					<h4><?php esc_html_e( 'Connected networks', 'feedico-sync' ); ?></h4>
					<?php
					foreach ( $nets_raw as $n ) {
						if ( ! is_array( $n ) ) {
							continue;
						}
						self::render_network_dashboard_card( $n );
					}
					?>
				</div>
			<?php endif; ?>

			<details class="feedico-raw-json-wrap">
				<summary><?php esc_html_e( 'Raw API response (JSON)', 'feedico-sync' ); ?></summary>
				<pre class="feedico-json"><?php echo esc_html( wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></pre>
			</details>
		</div>
		<?php
	}

	private static function metric_card( string $label, string $value ): void {
		echo '<div class="feedico-metric-card">';
		echo '<span class="feedico-metric-label">' . esc_html( $label ) . '</span>';
		echo '<span class="feedico-metric-value">' . esc_html( $value ) . '</span>';
		echo '</div>';
	}

	/**
	 * Status cell markup for sync log table.
	 *
	 * @param string $status Raw status from DB.
	 */
	private static function log_status_badge_html( string $status ): string {
		$status = trim( $status );
		if ( $status === '' ) {
			return '<span class="feedico-log-pill feedico-log-pill--muted">—</span>';
		}
		$s   = strtolower( $status );
		$cls = 'feedico-log-pill';
		if ( in_array( $s, array( 'ok', 'success', 'completed', 'complete', 'done' ), true ) ) {
			$cls .= ' feedico-log-pill--ok';
		} elseif ( in_array( $s, array( 'error', 'failed', 'fail' ), true ) || strpos( $s, 'error' ) !== false ) {
			$cls .= ' feedico-log-pill--err';
		} elseif ( $s === 'running' ) {
			$cls .= ' feedico-log-pill--run';
		} else {
			$cls .= ' feedico-log-pill--muted';
		}
		return '<span class="' . esc_attr( $cls ) . '">' . esc_html( $status ) . '</span>';
	}

	/**
	 * Network card on dashboard: head (title + provider pill), subtitle, stat tiles, footer (last sync + status).
	 *
	 * @param array<string,mixed> $n
	 */
	private static function render_network_dashboard_card( array $n ): void {
		$title = '';
		foreach ( array( 'label', 'name', 'provider' ) as $k ) {
			if ( ! empty( $n[ $k ] ) && is_string( $n[ $k ] ) ) {
				$title = trim( $n[ $k ] );
				break;
			}
		}
		if ( $title === '' ) {
			$title = self::stable_network_id( $n );
		}
		if ( $title === '' ) {
			return;
		}

		$subtitle_primary = '';
		foreach ( array( 'subtitle', 'description', 'tagline', 'summary', 'caption', 'detail', 'secondaryLabel' ) as $k ) {
			if ( ! empty( $n[ $k ] ) && is_string( $n[ $k ] ) ) {
				$subtitle_primary = trim( $n[ $k ] );
				break;
			}
		}

		$merchant_count = null;
		if ( isset( $n['merchantCount'] ) && ( is_numeric( $n['merchantCount'] ) || ( is_string( $n['merchantCount'] ) && is_numeric( $n['merchantCount'] ) ) ) ) {
			$merchant_count = (int) $n['merchantCount'];
		}
		$coupon_count = null;
		if ( isset( $n['couponCount'] ) && ( is_numeric( $n['couponCount'] ) || ( is_string( $n['couponCount'] ) && is_numeric( $n['couponCount'] ) ) ) ) {
			$coupon_count = (int) $n['couponCount'];
		}

		$last_sync_label = ( ! empty( $n['lastSyncLabel'] ) && is_string( $n['lastSyncLabel'] ) ) ? trim( $n['lastSyncLabel'] ) : '';
		$last_sync_status = ( ! empty( $n['lastSyncStatus'] ) && is_string( $n['lastSyncStatus'] ) ) ? trim( $n['lastSyncStatus'] ) : '';

		$provider_slug = '';
		if ( ! empty( $n['provider'] ) && is_string( $n['provider'] ) ) {
			$provider_slug = trim( $n['provider'] );
		}

		echo '<div class="feedico-network-card">';
		echo '<div class="feedico-network-card-head">';
		echo '<div class="feedico-network-title">' . esc_html( $title ) . '</div>';
		if ( $provider_slug !== '' && strcasecmp( $provider_slug, $title ) !== 0 ) {
			echo '<span class="feedico-network-provider" title="' . esc_attr__( 'Provider slug', 'feedico-sync' ) . '">' . esc_html( $provider_slug ) . '</span>';
		}
		echo '</div>';

		if ( $subtitle_primary !== '' ) {
			echo '<p class="feedico-network-subtitle">' . esc_html( $subtitle_primary ) . '</p>';
		}

		if ( $merchant_count !== null || $coupon_count !== null ) {
			echo '<div class="feedico-network-stats" role="presentation">';
			if ( $merchant_count !== null ) {
				echo '<div class="feedico-network-stat feedico-network-stat--merchants" role="group" aria-label="' . esc_attr__( 'Merchant count', 'feedico-sync' ) . '">';
				echo '<span class="feedico-network-stat-ico dashicons dashicons-store" aria-hidden="true"></span>';
				echo '<span class="feedico-network-stat-line">';
				echo '<span class="feedico-network-stat-label">' . esc_html__( 'Merchants', 'feedico-sync' ) . '</span>';
				echo '<span class="feedico-network-stat-value">' . esc_html( number_format_i18n( $merchant_count ) ) . '</span>';
				echo '</span></div>';
			}
			if ( $coupon_count !== null ) {
				echo '<div class="feedico-network-stat feedico-network-stat--coupons" role="group" aria-label="' . esc_attr__( 'Coupon count', 'feedico-sync' ) . '">';
				echo '<span class="feedico-network-stat-ico dashicons dashicons-tickets-alt" aria-hidden="true"></span>';
				echo '<span class="feedico-network-stat-line">';
				echo '<span class="feedico-network-stat-label">' . esc_html__( 'Coupons', 'feedico-sync' ) . '</span>';
				echo '<span class="feedico-network-stat-value">' . esc_html( number_format_i18n( $coupon_count ) ) . '</span>';
				echo '</span></div>';
			}
			echo '</div>';
		}

		if ( $last_sync_label !== '' || $last_sync_status !== '' ) {
			echo '<div class="feedico-network-footer">';
			echo '<span class="feedico-network-footer-inner">';
			if ( $last_sync_label !== '' ) {
				echo '<span class="feedico-network-sync-meta">';
				echo '<span class="feedico-network-sync-ic dashicons dashicons-clock" aria-hidden="true"></span>';
				echo '<span class="feedico-network-sync-time">' . esc_html( $last_sync_label ) . '</span>';
				echo '</span>';
			}
			if ( $last_sync_status !== '' ) {
				$status_class = 'feedico-network-status feedico-network-status--muted';
				$s            = strtolower( $last_sync_status );
				if ( in_array( $s, array( 'ok', 'success', 'completed', 'complete', 'done' ), true ) ) {
					$status_class = 'feedico-network-status feedico-network-status--ok';
				} elseif ( in_array( $s, array( 'error', 'failed', 'fail' ), true ) ) {
					$status_class = 'feedico-network-status feedico-network-status--err';
				}
				echo '<span class="' . esc_attr( $status_class ) . '">' . esc_html( strtoupper( $last_sync_status ) ) . '</span>';
			}
			echo '</span></div>';
		}

		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<int,array<string,string>>
	 */
	public static function catalog_from_payload( array $payload ): array {
		$ov = isset( $payload['overview'] ) && is_array( $payload['overview'] ) ? $payload['overview'] : array();
		$raw = isset( $ov['connectedNetworks'] ) && is_array( $ov['connectedNetworks'] ) ? $ov['connectedNetworks'] : array();
		$out = array();
		$seen = array();
		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$nid = self::stable_network_id( $item );
			if ( $nid === '' || isset( $seen[ $nid ] ) ) {
				continue;
			}
			$seen[ $nid ] = true;
			$prov = isset( $item['provider'] ) ? trim( (string) $item['provider'] ) : '';
			if ( $prov === '' ) {
				$fb = $item['label'] ?? $item['name'] ?? '';
				if ( is_string( $fb ) && trim( $fb ) !== '' ) {
					$prov = trim( $fb );
				}
			}
			$out[] = array(
				'id'       => $nid,
				'label'    => self::network_label_item( $item, $nid ),
				'provider' => $prov,
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function stable_network_id( array $item ): string {
		foreach ( array( 'id', 'networkId', 'network_id', 'slug', 'code' ) as $k ) {
			if ( ! empty( $item[ $k ] ) ) {
				return trim( (string) $item[ $k ] );
			}
		}
		$lab = $item['label'] ?? $item['provider'] ?? $item['name'] ?? '';
		if ( is_string( $lab ) && trim( $lab ) !== '' ) {
			return trim( $lab );
		}
		return '';
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private static function network_label_item( array $item, string $fallback ): string {
		foreach ( array( 'label', 'provider', 'name' ) as $k ) {
			if ( ! empty( $item[ $k ] ) && is_string( $item[ $k ] ) ) {
				return trim( $item[ $k ] );
			}
		}
		return $fallback;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$email    = (string) get_option( 'feedico_sync_email', '' );
		$interval = (string) get_option( 'feedico_sync_cron_interval', 'hourly' );
		if ( ! in_array( $interval, Feedico_Sync_Plugin::allowed_cron_interval_slugs(), true ) ) {
			$interval = 'hourly';
		}
		$custom_mins = Feedico_Sync_Plugin::clamp_custom_interval_minutes( (int) get_option( 'feedico_sync_cron_custom_minutes', 60 ) );
		$catalog   = get_option( 'feedico_sync_network_catalog', array() );
		if ( ! is_array( $catalog ) ) {
			$catalog = array();
		}
		$selected  = get_option( 'feedico_sync_selected_networks', array() );
		if ( ! is_array( $selected ) ) {
			$selected = array();
		}
		$sel_flip  = array_flip( array_map( 'strval', $selected ) );
		$dashboard = get_option( 'feedico_sync_last_dashboard', array() );
		$connected = is_array( $dashboard ) ? $dashboard : array();
		$connected = self::maybe_prune_stored_dashboard( $connected );
		$next_cron = wp_next_scheduled( 'feedico_sync_cron' );
		$logs      = Feedico_DB::get_recent_logs( 30 );
		$last_run  = get_option( 'feedico_sync_last_run', array() );
		if ( ! is_array( $last_run ) ) {
			$last_run = array();
		}
		$last_sync_duration_label = self::format_last_sync_duration_min_sec( $last_run );

		settings_errors( 'feedico_sync' );
		?>
		<div class="wrap feedico-sync-wrap">
			<header class="feedico-sync-hero" role="banner">
				<div class="feedico-sync-hero-inner">
					<p class="feedico-sync-eyebrow"><?php esc_html_e( 'Directory & offers', 'feedico-sync' ); ?></p>
					<h1 class="feedico-sync-page-title"><?php esc_html_e( 'Feedico Sync', 'feedico-sync' ); ?></h1>
					<p class="feedico-sync-tagline"><?php esc_html_e( 'Connect your Feedico account, keep merchants and coupons in WordPress, then show them with blocks or shortcodes.', 'feedico-sync' ); ?></p>
					<nav class="feedico-sync-quicklinks" aria-label="<?php esc_attr_e( 'Data shortcuts', 'feedico-sync' ); ?>">
						<a class="feedico-pill-link" href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync-merchants' ) ); ?>">
							<span class="dashicons dashicons-store" aria-hidden="true"></span>
							<?php esc_html_e( 'Merchants', 'feedico-sync' ); ?>
						</a>
						<a class="feedico-pill-link" href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync-coupons' ) ); ?>">
							<span class="dashicons dashicons-tickets-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Coupons', 'feedico-sync' ); ?>
						</a>
					</nav>
				</div>
			</header>

			<div class="feedico-sync-body">
			<?php
			if ( $last_run !== array() ) {
				echo self::last_sync_banner_html( $last_run ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self-contained HTML builder.
			} else {
				echo '<div id="feedico-last-sync-banner" class="feedico-banner feedico-banner-muted"><div class="feedico-banner-title">' . esc_html__( 'No sync has run yet on this site.', 'feedico-sync' ) . '</div></div>';
			}
			?>

			<div class="feedico-sync-toolbar">
				<button type="button" class="button button-primary button-large feedico-sync-toolbar-btn" id="feedico-run-sync">
					<span class="dashicons dashicons-update" aria-hidden="true"></span>
					<?php esc_html_e( 'Run sync now', 'feedico-sync' ); ?>
				</button>
				<span id="feedico-sync-run-status" class="feedico-run-status" aria-live="polite"></span>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync' ) ); ?>" id="feedico-sync-main-form" class="feedico-sync-main-form">
				<?php wp_nonce_field( 'feedico_sync_save', 'feedico_sync_nonce' ); ?>
				<input type="hidden" name="feedico_sync_action" value="save_settings" />

			<div class="feedico-sync-grid">
				<div class="feedico-sync-sidebar-stack">
				<div class="feedico-sync-card feedico-sync-card--connection">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-admin-network" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Connection', 'feedico-sync' ); ?></span>
					</h2>
					<p class="feedico-card-lead"><?php esc_html_e( 'Use the same credentials as the Feedico desktop client. Test connection verifies API and updates dashboard cards below.', 'feedico-sync' ); ?></p>
					<table class="form-table">
						<tr>
							<th><label for="feedico_email"><?php esc_html_e( 'Email', 'feedico-sync' ); ?></label></th>
							<td><input type="email" name="feedico_email" id="feedico_email" class="regular-text" value="<?php echo esc_attr( $email ); ?>" autocomplete="username" /></td>
						</tr>
						<tr>
							<th><label for="feedico_password"><?php esc_html_e( 'Password', 'feedico-sync' ); ?></label></th>
							<td><input type="password" name="feedico_password" id="feedico_password" class="regular-text" value="" autocomplete="current-password" placeholder="<?php esc_attr_e( 'Leave blank to keep saved password', 'feedico-sync' ); ?>" /></td>
						</tr>
						<tr>
							<th><label for="feedico_token"><?php esc_html_e( 'API token', 'feedico-sync' ); ?></label></th>
							<td><input type="password" name="feedico_token" id="feedico_token" class="regular-text" value="" autocomplete="off" placeholder="<?php esc_attr_e( 'Leave blank to keep saved token', 'feedico-sync' ); ?>" /></td>
						</tr>
					</table>
					<p class="feedico-card-actions">
						<button type="button" class="button button-primary feedico-btn-with-icon" id="feedico-test-connection">
							<span class="dashicons dashicons-search" aria-hidden="true"></span>
							<?php esc_html_e( 'Test connection', 'feedico-sync' ); ?>
						</button>
						<span id="feedico-test-status" class="feedico-inline-status" aria-live="polite"></span>
					</p>
				</div>

				<div class="feedico-sync-card feedico-sync-card--networks">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-networking" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Networks to sync', 'feedico-sync' ); ?></span>
					</h2>
					<div id="feedico-networks-body">
					<?php if ( $catalog === array() ) : ?>
						<p class="description feedico-networks-empty"><?php esc_html_e( 'Connect successfully first to load networks.', 'feedico-sync' ); ?></p>
					<?php else : ?>
						<p class="feedico-card-lead feedico-networks-hint"><?php esc_html_e( 'Choose which affiliate networks to include in each sync.', 'feedico-sync' ); ?></p>
						<fieldset class="feedico-network-fieldset">
							<?php foreach ( $catalog as $row ) : ?>
								<?php
								if ( ! is_array( $row ) || empty( $row['id'] ) ) {
									continue;
								}
								$id    = (string) $row['id'];
								$label = isset( $row['label'] ) ? (string) $row['label'] : $id;
								$prov  = isset( $row['provider'] ) ? (string) $row['provider'] : '';
								$chk   = isset( $sel_flip[ $id ] );
								?>
								<label class="feedico-network-label">
									<input type="checkbox" name="feedico_networks[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( $chk ); ?> />
									<span class="feedico-network-label-text"><?php echo esc_html( $label ); ?></span>
									<?php if ( $prov !== '' ) : ?>
										<span class="feedico-network-label-prov"><?php echo esc_html( $prov ); ?></span>
									<?php endif; ?>
								</label>
							<?php endforeach; ?>
						</fieldset>
					<?php endif; ?>
					</div>
				</div>
				</div>

				<div class="feedico-sync-card feedico-sync-card--dashboard">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-chart-area" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Dashboard (API)', 'feedico-sync' ); ?></span>
					</h2>
					<div id="feedico-dashboard-cards-wrap">
						<?php
						if ( $connected === array() ) {
							echo '<p class="description">' . esc_html__( 'Run a successful connection test to load cards.', 'feedico-sync' ) . '</p>';
						} else {
							self::render_dashboard_cards( $connected );
						}
						?>
					</div>
				</div>
			</div>

				<div class="feedico-sync-card feedico-sync-card--schedule">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-clock" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Sync schedule', 'feedico-sync' ); ?></span>
					</h2>
					<div class="feedico-schedule-layout">
					<div class="feedico-schedule-fields">
					<label class="feedico-schedule-label" for="feedico_cron_interval"><?php esc_html_e( 'Interval', 'feedico-sync' ); ?></label>
					<select name="feedico_cron_interval" id="feedico_cron_interval" class="feedico-select-wide">
						<optgroup label="<?php esc_attr_e( 'Every few minutes', 'feedico-sync' ); ?>">
							<option value="feedico_5min" <?php selected( $interval, 'feedico_5min' ); ?>><?php esc_html_e( 'Every 5 minutes', 'feedico-sync' ); ?></option>
							<option value="feedico_10min" <?php selected( $interval, 'feedico_10min' ); ?>><?php esc_html_e( 'Every 10 minutes', 'feedico-sync' ); ?></option>
							<option value="feedico_15min" <?php selected( $interval, 'feedico_15min' ); ?>><?php esc_html_e( 'Every 15 minutes', 'feedico-sync' ); ?></option>
							<option value="feedico_30min" <?php selected( $interval, 'feedico_30min' ); ?>><?php esc_html_e( 'Every 30 minutes', 'feedico-sync' ); ?></option>
							<option value="feedico_45min" <?php selected( $interval, 'feedico_45min' ); ?>><?php esc_html_e( 'Every 45 minutes', 'feedico-sync' ); ?></option>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Every few hours', 'feedico-sync' ); ?>">
							<option value="hourly" <?php selected( $interval, 'hourly' ); ?>><?php esc_html_e( 'Every hour', 'feedico-sync' ); ?></option>
							<option value="feedico_120min" <?php selected( $interval, 'feedico_120min' ); ?>><?php esc_html_e( 'Every 2 hours', 'feedico-sync' ); ?></option>
							<option value="feedico_240min" <?php selected( $interval, 'feedico_240min' ); ?>><?php esc_html_e( 'Every 4 hours', 'feedico-sync' ); ?></option>
							<option value="feedico_360min" <?php selected( $interval, 'feedico_360min' ); ?>><?php esc_html_e( 'Every 6 hours', 'feedico-sync' ); ?></option>
							<option value="feedico_720min" <?php selected( $interval, 'feedico_720min' ); ?>><?php esc_html_e( 'Every 12 hours', 'feedico-sync' ); ?></option>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'WordPress defaults', 'feedico-sync' ); ?>">
							<option value="twicedaily" <?php selected( $interval, 'twicedaily' ); ?>><?php esc_html_e( 'Twice daily', 'feedico-sync' ); ?></option>
							<option value="daily" <?php selected( $interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'feedico-sync' ); ?></option>
						</optgroup>
						<optgroup label="<?php esc_attr_e( 'Other', 'feedico-sync' ); ?>">
							<option value="feedico_weekly" <?php selected( $interval, 'feedico_weekly' ); ?>><?php esc_html_e( 'Once weekly', 'feedico-sync' ); ?></option>
							<option value="feedico_custom" <?php selected( $interval, 'feedico_custom' ); ?>><?php esc_html_e( 'Custom interval (minutes)', 'feedico-sync' ); ?></option>
						</optgroup>
					</select>
					<div id="feedico-custom-interval-wrap" class="feedico-custom-interval-wrap<?php echo $interval === 'feedico_custom' ? ' feedico-custom-interval-wrap--open' : ''; ?>">
						<label class="feedico-schedule-label feedico-schedule-sub" for="feedico_cron_custom_minutes"><?php esc_html_e( 'Minutes between runs', 'feedico-sync' ); ?></label>
						<div class="feedico-custom-interval-row">
							<input type="number" name="feedico_cron_custom_minutes" id="feedico_cron_custom_minutes" class="feedico-input-number" min="<?php echo esc_attr( (string) Feedico_Sync_Plugin::CUSTOM_INTERVAL_MIN ); ?>" max="<?php echo esc_attr( (string) Feedico_Sync_Plugin::CUSTOM_INTERVAL_MAX ); ?>" step="1" value="<?php echo esc_attr( (string) $custom_mins ); ?>" />
						</div>
						<p class="description feedico-custom-interval-hint">
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: minimum minutes, 2: maximum minutes (7 days) */
									__( 'Choose any interval from %1$d minutes up to %2$d minutes (one week). WP-Cron runs when your site gets traffic; for exact timing use a server cron hitting wp-cron.php.', 'feedico-sync' ),
									Feedico_Sync_Plugin::CUSTOM_INTERVAL_MIN,
									Feedico_Sync_Plugin::CUSTOM_INTERVAL_MAX
								)
							);
							?>
						</p>
					</div>
					<p class="description feedico-schedule-next">
						<?php
						echo esc_html(
							sprintf(
								__( 'Next WP-Cron run (if scheduled): %s', 'feedico-sync' ),
								$next_cron ? wp_date( get_option( 'date_format' ) . ' H:i', $next_cron ) : __( 'not scheduled', 'feedico-sync' )
							)
						);
						?>
					</p>
					</div>
					<aside class="feedico-schedule-duration" aria-labelledby="feedico-schedule-duration-heading">
						<p class="feedico-schedule-duration-heading" id="feedico-schedule-duration-heading"><?php esc_html_e( 'Last sync duration', 'feedico-sync' ); ?></p>
						<p class="feedico-schedule-duration-value" id="feedico-last-sync-duration"><?php echo esc_html( $last_sync_duration_label !== '' ? $last_sync_duration_label : '—' ); ?></p>
						<p class="description feedico-schedule-duration-note"><?php esc_html_e( 'Pick an interval comfortably above this duration so the next WP-Cron run is unlikely to start before the previous sync finishes. Traffic and hosting still affect real timing.', 'feedico-sync' ); ?></p>
					</aside>
					</div>
				</div>

				<div class="feedico-sync-card feedico-sync-card--uninstall-note">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-info" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Removing the plugin', 'feedico-sync' ); ?></span>
					</h2>
					<p class="feedico-card-lead">
						<?php esc_html_e( 'By default, uninstall leaves custom tables and mirrored posts in place so you do not lose data if you reinstall.', 'feedico-sync' ); ?>
					</p>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: PHP constant name */
								__( 'To delete custom tables, all %1$s / %2$s posts, and plugin options when the plugin is deleted, define %3$s as true in wp-config.php before uninstalling.', 'feedico-sync' ),
								'feedico_store',
								'feedico_coupon',
								'FEEDICO_SYNC_DELETE_DATA'
							)
						);
						?>
					</p>
				</div>

				<div class="feedico-sync-card feedico-sync-card--public-doc">
					<h2 class="feedico-card-heading">
						<span class="feedico-card-icon dashicons dashicons-welcome-view-site" aria-hidden="true"></span>
						<span class="feedico-card-heading-text"><?php esc_html_e( 'Show merchants & coupons on the site', 'feedico-sync' ); ?></span>
					</h2>
					<p class="feedico-card-lead"><?php esc_html_e( 'Add the Feedico blocks from the block inserter, or paste shortcodes into a Shortcode block. Visitors only see merchants and coupons that are still active after the last sync.', 'feedico-sync' ); ?></p>
					<ul class="feedico-shortcode-hints">
						<li><?php esc_html_e( 'Blocks:', 'feedico-sync' ); ?> <code>feedico/merchants</code>, <code>feedico/coupons</code>, <code>feedico/merchant-page</code> (<?php esc_html_e( 'same output as the shortcodes below', 'feedico-sync' ); ?>).</li>
						<li><code>[feedico_merchants]</code> — <?php esc_html_e( 'searchable grid of firms; optional', 'feedico-sync' ); ?> <code>per_page="24"</code>.</li>
						<li><code>[feedico_coupons]</code> — <?php esc_html_e( 'offers with code, image, and affiliate button;', 'feedico-sync' ); ?> <code>per_page="24"</code> <?php esc_html_e( 'or filter with', 'feedico-sync' ); ?> <code>merchant_id="…"</code>.</li>
						<li><code>[feedico_merchant_page merchant_id="…"]</code> — <?php esc_html_e( 'one firm header plus its active coupons; put other blocks above/below in the editor. Optional:', 'feedico-sync' ); ?> <code>show_hero="0"</code>, <code>search_form="0"</code>, <code>per_page="24"</code>.</li>
					</ul>
					<p class="description"><?php esc_html_e( 'Tip: merchant cards include a “Coupons” link that opens this page with the right filter. Style pack:', 'feedico-sync' ); ?> <code>wp-content/plugins/feedico-sync/assets/public.css</code></p>
				</div>

				<p class="feedico-sync-save-wrap">
					<button type="submit" class="button button-primary button-large feedico-save-button">
						<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
						<?php esc_html_e( 'Save settings', 'feedico-sync' ); ?>
					</button>
				</p>
			</form>

			<div class="feedico-sync-logs-section">
			<div class="feedico-sync-card feedico-sync-card--logs">
				<h2 class="feedico-card-heading">
					<span class="feedico-card-icon dashicons dashicons-editor-table" aria-hidden="true"></span>
					<span class="feedico-card-heading-text"><?php esc_html_e( 'Recent sync logs', 'feedico-sync' ); ?></span>
				</h2>
				<p class="feedico-card-lead feedico-logs-hint"><?php esc_html_e( 'Latest sync runs on this site (newest first).', 'feedico-sync' ); ?></p>
				<div class="feedico-logs-table-wrap">
				<table class="widefat striped feedico-sync-logs-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'feedico-sync' ); ?></th>
							<th><?php esc_html_e( 'Started', 'feedico-sync' ); ?></th>
							<th><?php esc_html_e( 'Finished', 'feedico-sync' ); ?></th>
							<th><?php esc_html_e( 'Status', 'feedico-sync' ); ?></th>
							<th><?php esc_html_e( 'Trigger', 'feedico-sync' ); ?></th>
							<th><?php esc_html_e( 'Stats / error', 'feedico-sync' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><?php echo esc_html( (string) ( $row['id'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['started_at'] ?? '' ) ); ?></td>
								<td><?php echo esc_html( (string) ( $row['finished_at'] ?? '' ) ); ?></td>
								<td><?php echo self::log_status_badge_html( (string) ( $row['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
								<td><?php echo esc_html( (string) ( $row['trigger_type'] ?? '' ) ); ?></td>
								<td>
									<?php
									$st = $row['stats_json'] ?? '';
									$er = $row['error_message'] ?? '';
									echo esc_html( $st !== '' ? $st : (string) $er );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				</div>
			</div>
			</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Hidden admin screen: edit one merchant row (full page).
	 */
	public static function render_merchant_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'feedico-sync' ) );
		}
		$mid = isset( $_GET['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_GET['merchant_id'] ) ) : '';
		?>
		<div class="wrap feedico-merchant-edit-wrap">
			<h1><?php esc_html_e( 'Edit merchant', 'feedico-sync' ); ?></h1>
			<p class="feedico-merchant-edit-back">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync-merchants' ) ); ?>"><?php esc_html_e( '← Back to merchants', 'feedico-sync' ); ?></a>
			</p>
		<?php
		if ( $mid === '' ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing merchant ID.', 'feedico-sync' ) . '</p></div></div>';
			return;
		}
		$row = Feedico_DB::get_merchant_admin_by_pk( $mid );
		if ( ! is_array( $row ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Merchant not found.', 'feedico-sync' ) . '</p></div></div>';
			return;
		}
		if ( isset( $_GET['updated'] ) && '1' === (string) wp_unslash( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Merchant saved.', 'feedico-sync' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) && '1' === (string) wp_unslash( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not save merchant. If the problem continues, check the database user can UPDATE the merchants table, or try again.', 'feedico-sync' ) . '</p></div>';
		}

		$dn   = isset( $row['display_name'] ) ? (string) $row['display_name'] : '';
		$desc = isset( $row['description'] ) ? (string) $row['description'] : '';
		$url  = isset( $row['merchant_website_url'] ) ? (string) $row['merchant_website_url'] : '';
		$st   = isset( $row['status'] ) ? (string) $row['status'] : '';
		$act  = isset( $row['wp_feedico_active'] ) && (int) $row['wp_feedico_active'] === 1;
		$pid  = isset( $row['property_id'] ) ? (string) $row['property_id'] : '';
		$prov = isset( $row['provider'] ) ? (string) $row['provider'] : '';
		$ext  = isset( $row['external_merchant_key'] ) ? (string) $row['external_merchant_key'] : '';
		$ls   = isset( $row['last_synced_at'] ) ? (string) $row['last_synced_at'] : '';
		?>
			<div class="feedico-sync-card feedico-merchant-edit-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="feedico-merchant-edit-form">
					<?php wp_nonce_field( 'feedico_merchant_edit', 'feedico_merchant_edit_nonce' ); ?>
					<input type="hidden" name="action" value="feedico_save_merchant" />
					<input type="hidden" name="merchant_id" value="<?php echo esc_attr( $mid ); ?>" />
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Record ID', 'feedico-sync' ); ?></th>
								<td><code><?php echo esc_html( $mid ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Provider', 'feedico-sync' ); ?></th>
								<td><code><?php echo esc_html( $prov !== '' ? $prov : '—' ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Property ID', 'feedico-sync' ); ?></th>
								<td><code><?php echo esc_html( $pid !== '' ? $pid : '—' ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'External merchant key', 'feedico-sync' ); ?></th>
								<td><code class="feedico-merchant-edit-mono"><?php echo esc_html( $ext !== '' ? $ext : '—' ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Last synced (API)', 'feedico-sync' ); ?></th>
								<td><?php echo esc_html( $ls !== '' ? $ls : '—' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_me_display_name"><?php esc_html_e( 'Display name', 'feedico-sync' ); ?></label></th>
								<td><input name="display_name" type="text" id="feedico_me_display_name" class="regular-text" value="<?php echo esc_attr( $dn ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_me_description"><?php esc_html_e( 'Description', 'feedico-sync' ); ?></label></th>
								<td><textarea name="description" id="feedico_me_description" class="large-text" rows="6"><?php echo esc_textarea( $desc ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_me_url"><?php esc_html_e( 'Website URL', 'feedico-sync' ); ?></label></th>
								<td><input name="merchant_website_url" type="text" id="feedico_me_url" class="large-text" value="<?php echo esc_attr( $url ); ?>" spellcheck="false" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_me_status"><?php esc_html_e( 'Status', 'feedico-sync' ); ?></label></th>
								<td><input name="status" type="text" id="feedico_me_status" class="regular-text" value="<?php echo esc_attr( $st ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Active in WordPress', 'feedico-sync' ); ?></th>
								<td>
									<label>
										<input name="wp_feedico_active" type="checkbox" value="1" <?php checked( $act ); ?> />
										<?php esc_html_e( 'Show this merchant and its coupons on the site (uncheck to hide without deleting).', 'feedico-sync' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Manual edit lock', 'feedico-sync' ); ?></th>
								<td>
									<?php
									$mov = ! empty( $row['wp_manual_override'] );
									if ( $mov ) {
										echo '<p class="feedico-manual-lock-msg">' . esc_html__( 'Locked: sync will keep your WordPress-edited fields until you allow the API to overwrite them again.', 'feedico-sync' ) . '</p>';
									} else {
										echo '<p class="feedico-manual-lock-msg">' . esc_html__( 'Not locked: the next sync can refresh these fields from the API.', 'feedico-sync' ) . '</p>';
									}
									?>
									<label>
										<input name="feedico_allow_api_overwrite" type="checkbox" value="1" />
										<?php esc_html_e( 'On save, clear the lock so the next sync can overwrite these fields from the Feedico API.', 'feedico-sync' ); ?>
									</label>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'Website: you may enter example.com or https://example.com — a scheme is added if missing.', 'feedico-sync' ); ?></p>
					<p class="description"><?php esc_html_e( 'Saving this form sets the manual lock so your edits are preserved across syncs. Use the checkbox above only when you want the next sync to replace your text with API data again.', 'feedico-sync' ); ?></p>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save merchant', 'feedico-sync' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Hidden admin screen: edit one coupon row.
	 */
	public static function render_coupon_edit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'feedico-sync' ) );
		}
		$cid = isset( $_GET['coupon_id'] ) ? sanitize_text_field( wp_unslash( $_GET['coupon_id'] ) ) : '';
		?>
		<div class="wrap feedico-merchant-edit-wrap feedico-coupon-edit-wrap">
			<h1><?php esc_html_e( 'Edit coupon', 'feedico-sync' ); ?></h1>
			<p class="feedico-merchant-edit-back">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync-coupons' ) ); ?>"><?php esc_html_e( '← Back to coupons', 'feedico-sync' ); ?></a>
			</p>
		<?php
		if ( $cid === '' ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Missing coupon ID.', 'feedico-sync' ) . '</p></div></div>';
			return;
		}
		$row = Feedico_DB::get_coupon_admin_by_pk( $cid );
		if ( ! is_array( $row ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Coupon not found.', 'feedico-sync' ) . '</p></div></div>';
			return;
		}
		if ( isset( $_GET['updated'] ) && '1' === (string) wp_unslash( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Coupon saved.', 'feedico-sync' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) && '1' === (string) wp_unslash( $_GET['error'] ) ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not save coupon. If the problem continues, check the database user can UPDATE the coupons table, or try again.', 'feedico-sync' ) . '</p></div>';
		}

		$fk = function ( $key ) use ( $row ) {
			return isset( $row[ $key ] ) ? (string) $row[ $key ] : '';
		};
		$title  = $fk( 'title' );
		$desc   = $fk( 'description' );
		$code   = $fk( 'coupon_code' );
		$aff    = $fk( 'affiliate_url' );
		$img    = $fk( 'image_url' );
		$nn     = $fk( 'network_name' );
		$ss     = $fk( 'starts_at' );
		$ee     = $fk( 'ends_at' );
		$dty    = $fk( 'discount_type' );
		$dvl    = $fk( 'discount_value' );
		$cur    = $fk( 'currency_code' );
		$st     = $fk( 'status' );
		$act    = isset( $row['wp_feedico_active'] ) && (int) $row['wp_feedico_active'] === 1;
		$pk     = $fk( 'id' );
		$mid    = $fk( 'merchant_id' );
		$nid    = $fk( 'network_id' );
		$cr     = $fk( 'created_at' );
		$up     = $fk( 'updated_at' );
		$cr_up  = array_values( array_filter( array( $cr, $up ), 'strlen' ) );
		?>
			<div class="feedico-sync-card feedico-merchant-edit-card">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="feedico-merchant-edit-form">
					<?php wp_nonce_field( 'feedico_coupon_edit', 'feedico_coupon_edit_nonce' ); ?>
					<input type="hidden" name="action" value="feedico_save_coupon" />
					<input type="hidden" name="coupon_id" value="<?php echo esc_attr( $pk ); ?>" />
					<table class="form-table" role="presentation">
						<tbody>
							<tr>
								<th scope="row"><?php esc_html_e( 'Coupon ID', 'feedico-sync' ); ?></th>
								<td><code><?php echo esc_html( $pk ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Merchant ID', 'feedico-sync' ); ?></th>
								<td><code class="feedico-merchant-edit-mono"><?php echo esc_html( $mid !== '' ? $mid : '—' ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Network ID', 'feedico-sync' ); ?></th>
								<td><code class="feedico-merchant-edit-mono"><?php echo esc_html( $nid !== '' ? $nid : '—' ); ?></code></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Created / updated (API)', 'feedico-sync' ); ?></th>
								<td><?php echo esc_html( $cr_up !== array() ? implode( ' · ', $cr_up ) : '—' ); ?></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_title"><?php esc_html_e( 'Title', 'feedico-sync' ); ?></label></th>
								<td><input name="title" type="text" id="feedico_ce_title" class="large-text" value="<?php echo esc_attr( $title ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_desc"><?php esc_html_e( 'Description', 'feedico-sync' ); ?></label></th>
								<td><textarea name="description" id="feedico_ce_desc" class="large-text" rows="5"><?php echo esc_textarea( $desc ); ?></textarea></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_code"><?php esc_html_e( 'Coupon code', 'feedico-sync' ); ?></label></th>
								<td><input name="coupon_code" type="text" id="feedico_ce_code" class="regular-text" value="<?php echo esc_attr( $code ); ?>" spellcheck="false" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_aff"><?php esc_html_e( 'Affiliate / offer URL', 'feedico-sync' ); ?></label></th>
								<td><input name="affiliate_url" type="text" id="feedico_ce_aff" class="large-text" value="<?php echo esc_attr( $aff ); ?>" spellcheck="false" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_img"><?php esc_html_e( 'Image URL', 'feedico-sync' ); ?></label></th>
								<td><input name="image_url" type="text" id="feedico_ce_img" class="large-text" value="<?php echo esc_attr( $img ); ?>" spellcheck="false" autocomplete="off" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_net"><?php esc_html_e( 'Network name', 'feedico-sync' ); ?></label></th>
								<td><input name="network_name" type="text" id="feedico_ce_net" class="regular-text" value="<?php echo esc_attr( $nn ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_start"><?php esc_html_e( 'Starts at', 'feedico-sync' ); ?></label></th>
								<td><input name="starts_at" type="text" id="feedico_ce_start" class="regular-text" value="<?php echo esc_attr( $ss ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_end"><?php esc_html_e( 'Ends at', 'feedico-sync' ); ?></label></th>
								<td><input name="ends_at" type="text" id="feedico_ce_end" class="regular-text" value="<?php echo esc_attr( $ee ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_dtype"><?php esc_html_e( 'Discount type', 'feedico-sync' ); ?></label></th>
								<td><input name="discount_type" type="text" id="feedico_ce_dtype" class="regular-text" value="<?php echo esc_attr( $dty ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_dval"><?php esc_html_e( 'Discount value', 'feedico-sync' ); ?></label></th>
								<td><input name="discount_value" type="text" id="feedico_ce_dval" class="regular-text" value="<?php echo esc_attr( $dvl ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_cur"><?php esc_html_e( 'Currency code', 'feedico-sync' ); ?></label></th>
								<td><input name="currency_code" type="text" id="feedico_ce_cur" class="regular-text" value="<?php echo esc_attr( $cur ); ?>" maxlength="16" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="feedico_ce_status"><?php esc_html_e( 'Status', 'feedico-sync' ); ?></label></th>
								<td><input name="status" type="text" id="feedico_ce_status" class="regular-text" value="<?php echo esc_attr( $st ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Active in WordPress', 'feedico-sync' ); ?></th>
								<td>
									<label>
										<input name="wp_feedico_active" type="checkbox" value="1" <?php checked( $act ); ?> />
										<?php esc_html_e( 'Show this coupon on the site (uncheck to hide without deleting).', 'feedico-sync' ); ?>
									</label>
								</td>
							</tr>
							<tr>
								<th scope="row"><?php esc_html_e( 'Manual edit lock', 'feedico-sync' ); ?></th>
								<td>
									<?php
									$cov = ! empty( $row['wp_manual_override'] );
									if ( $cov ) {
										echo '<p class="feedico-manual-lock-msg">' . esc_html__( 'Locked: sync will keep your WordPress-edited fields until you allow the API to overwrite them again.', 'feedico-sync' ) . '</p>';
									} else {
										echo '<p class="feedico-manual-lock-msg">' . esc_html__( 'Not locked: the next sync can refresh these fields from the API.', 'feedico-sync' ) . '</p>';
									}
									?>
									<label>
										<input name="feedico_allow_api_overwrite" type="checkbox" value="1" />
										<?php esc_html_e( 'On save, clear the lock so the next sync can overwrite these fields from the Feedico API.', 'feedico-sync' ); ?>
									</label>
								</td>
							</tr>
						</tbody>
					</table>
					<p class="description"><?php esc_html_e( 'URLs: you may enter example.com/path or full https://… — a scheme is added if missing. Dates usually match API format (ISO 8601).', 'feedico-sync' ); ?></p>
					<p class="description"><?php esc_html_e( 'Saving this form sets the manual lock so your edits are preserved across syncs. Use the checkbox above only when you want the next sync to replace your text with API data again.', 'feedico-sync' ); ?></p>
					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save coupon', 'feedico-sync' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Admin: merchants datagrid (all rows; active + passive).
	 */
	public static function render_merchants_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-list-tables.php';
		$table = new Feedico_Merchants_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap feedico-datagrid-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Merchants', 'feedico-sync' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Settings', 'feedico-sync' ); ?></a>
			<hr class="wp-header-end" />
			<?php
			if ( isset( $_GET['feedico_edit_err'] ) ) {
				$err = sanitize_key( wp_unslash( $_GET['feedico_edit_err'] ) );
				if ( 'nonce' === $err ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Try again.', 'feedico-sync' ) . '</p></div>';
				} elseif ( 'id' === $err ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid merchant.', 'feedico-sync' ) . '</p></div>';
				}
			}
			if ( isset( $_GET['feedico_landing'] ) && (string) wp_unslash( $_GET['feedico_landing'] ) === '0' ) {
				echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not create the landing page. The merchant may be missing or saving the page failed.', 'feedico-sync' ) . '</p></div>';
			}
			?>
			<form method="get" class="feedico-datagrid-toolbar">
				<input type="hidden" name="page" value="feedico-sync-merchants" />
				<?php $table->search_box( __( 'Search merchants', 'feedico-sync' ), 'feedico-merchant' ); ?>
			</form>
			<div class="feedico-datagrid-table">
				<?php $table->display(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Admin: coupons datagrid.
	 */
	public static function render_coupons_admin(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		require_once FEEDICO_SYNC_PATH . 'includes/class-feedico-list-tables.php';
		$table = new Feedico_Coupons_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap feedico-datagrid-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Coupons', 'feedico-sync' ); ?></h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=feedico-sync' ) ); ?>" class="page-title-action"><?php esc_html_e( '← Settings', 'feedico-sync' ); ?></a>
			<hr class="wp-header-end" />
			<?php
			if ( isset( $_GET['feedico_coupon_edit_err'] ) ) {
				$err = sanitize_key( wp_unslash( $_GET['feedico_coupon_edit_err'] ) );
				if ( 'nonce' === $err ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Security check failed. Try again.', 'feedico-sync' ) . '</p></div>';
				} elseif ( 'id' === $err ) {
					echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Invalid coupon.', 'feedico-sync' ) . '</p></div>';
				}
			}
			?>
			<form method="get" class="feedico-datagrid-toolbar">
				<input type="hidden" name="page" value="feedico-sync-coupons" />
				<?php $table->search_box( __( 'Search coupons', 'feedico-sync' ), 'feedico-coupon' ); ?>
			</form>
			<div class="feedico-datagrid-table">
				<?php $table->display(); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Create draft Page with intro + [feedico_merchant_page] for the given synced merchant row.
	 *
	 * @param array<string,mixed> $merchant_row Row from Feedico_DB::get_merchants_* / get_merchant_row_by_ref.
	 * @return int|\WP_Error Post ID or error.
	 */
	public static function create_merchant_landing_page( array $merchant_row ) {
		$pk = isset( $merchant_row['id'] ) ? trim( (string) $merchant_row['id'] ) : '';
		if ( $pk === '' ) {
			return new \WP_Error( 'feedico_mid', __( 'Invalid merchant.', 'feedico-sync' ) );
		}
		$title_text = isset( $merchant_row['display_name'] ) ? trim( (string) $merchant_row['display_name'] ) : '';
		if ( $title_text === '' ) {
			$title_text = $pk;
		}
		$page_title = sprintf(
			/* translators: 1: merchant name, 2: label (e.g. Coupons) */
			__( '%1$s — %2$s', 'feedico-sync' ),
			$title_text,
			__( 'Coupons', 'feedico-sync' )
		);
		$intro_p = '<p>' . esc_html(
			sprintf(
				/* translators: %s: merchant display name */
				__( 'Deals and coupon codes from %s.', 'feedico-sync' ),
				$title_text
			)
		) . '</p>';
		$content  = "<!-- wp:paragraph -->\n{$intro_p}\n<!-- /wp:paragraph -->\n\n";
		$content .= "<!-- wp:shortcode -->\n[feedico_merchant_page merchant_id=\"" . esc_attr( $pk ) . "\"]\n<!-- /wp:shortcode -->\n";

		$post_id = wp_insert_post(
			array(
				'post_title'   => wp_slash( $page_title ),
				'post_content' => wp_slash( $content ),
				'post_status'  => 'draft',
				'post_type'    => 'page',
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		return (int) $post_id;
	}

	public static function handle_create_merchant_landing(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_pages' ) ) {
			wp_die( esc_html__( 'You do not have permission to create pages.', 'feedico-sync' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'feedico_create_merchant_landing' );
		$mid = isset( $_GET['merchant_id'] ) ? sanitize_text_field( wp_unslash( $_GET['merchant_id'] ) ) : '';
		if ( $mid === '' ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-merchants&feedico_landing=0' ) );
			exit;
		}
		$row = Feedico_DB::get_merchant_row_by_ref( $mid );
		if ( ! is_array( $row ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-merchants&feedico_landing=0' ) );
			exit;
		}
		$result = self::create_merchant_landing_page( $row );
		if ( is_wp_error( $result ) || $result < 1 ) {
			wp_safe_redirect( admin_url( 'admin.php?page=feedico-sync-merchants&feedico_landing=0' ) );
			exit;
		}
		wp_safe_redirect( admin_url( 'post.php?post=' . (int) $result . '&action=edit' ) );
		exit;
	}
}
