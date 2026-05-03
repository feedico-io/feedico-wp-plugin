<?php
/**
 * Main plugin bootstrap.
 *
 * @package Feedico_Sync
 */

class Feedico_Sync_Plugin {

	/** Minimum minutes for custom sync interval. */
	public const CUSTOM_INTERVAL_MIN = 5;

	/** Maximum minutes for custom sync interval (7 days). */
	public const CUSTOM_INTERVAL_MAX = 10080;

	public static function init(): void {
		load_plugin_textdomain( 'feedico-sync', false, dirname( plugin_basename( FEEDICO_SYNC_FILE ) ) . '/languages' );
		Feedico_DB::ensure_tables();
		Feedico_Post_Types::init();
		Feedico_Public::init();
		Feedico_Privacy::init();
		Feedico_Blocks::init();
		Feedico_Seo::init();
		Feedico_Admin::init();
		add_action(
			'feedico_sync_cron',
			static function () {
				Feedico_Sync_Job::run( 'cron' );
			}
		);
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
	}

	public static function activate(): void {
		Feedico_DB::ensure_tables();
		Feedico_Post_Types::register_types();
		flush_rewrite_rules();
		$interval = get_option( 'feedico_sync_cron_interval', 'hourly' );
		if ( ! is_string( $interval ) || $interval === '' ) {
			$interval = 'hourly';
		}
		self::reschedule_cron( $interval );
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'feedico_sync_cron' );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['feedico_5min'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 minutes', 'feedico-sync' ),
		);
		$schedules['feedico_10min'] = array(
			'interval' => 10 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 10 minutes', 'feedico-sync' ),
		);
		$schedules['feedico_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'feedico-sync' ),
		);
		$schedules['feedico_30min'] = array(
			'interval' => 30 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 30 minutes', 'feedico-sync' ),
		);
		$schedules['feedico_45min'] = array(
			'interval' => 45 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 45 minutes', 'feedico-sync' ),
		);
		$schedules['feedico_120min'] = array(
			'interval' => 120 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 2 hours', 'feedico-sync' ),
		);
		$schedules['feedico_240min'] = array(
			'interval' => 240 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 4 hours', 'feedico-sync' ),
		);
		$schedules['feedico_360min'] = array(
			'interval' => 360 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'feedico-sync' ),
		);
		$schedules['feedico_720min'] = array(
			'interval' => 720 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 12 hours', 'feedico-sync' ),
		);
		$schedules['feedico_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly', 'feedico-sync' ),
		);

		$custom = self::clamp_custom_interval_minutes( (int) get_option( 'feedico_sync_cron_custom_minutes', 60 ) );
		$schedules['feedico_custom'] = array(
			'interval' => $custom * MINUTE_IN_SECONDS,
			/* translators: %d: number of minutes between runs */
			'display'  => sprintf( __( 'Custom: every %d minutes', 'feedico-sync' ), $custom ),
		);

		return $schedules;
	}

	/**
	 * @return array<int,string>
	 */
	public static function allowed_cron_interval_slugs(): array {
		return array(
			'feedico_5min',
			'feedico_10min',
			'feedico_15min',
			'feedico_30min',
			'feedico_45min',
			'feedico_120min',
			'feedico_240min',
			'feedico_360min',
			'feedico_720min',
			'hourly',
			'twicedaily',
			'daily',
			'feedico_weekly',
			'feedico_custom',
		);
	}

	public static function clamp_custom_interval_minutes( int $minutes ): int {
		return max( self::CUSTOM_INTERVAL_MIN, min( self::CUSTOM_INTERVAL_MAX, $minutes ) );
	}

	public static function reschedule_cron( string $interval ): void {
		wp_clear_scheduled_hook( 'feedico_sync_cron' );
		$allowed = self::allowed_cron_interval_slugs();
		if ( ! in_array( $interval, $allowed, true ) ) {
			$interval = 'hourly';
		}
		if ( $interval === 'feedico_custom' ) {
			$m = self::clamp_custom_interval_minutes( (int) get_option( 'feedico_sync_cron_custom_minutes', 60 ) );
			update_option( 'feedico_sync_cron_custom_minutes', $m );
		}
		wp_schedule_event( time() + 120, $interval, 'feedico_sync_cron' );
	}
}
