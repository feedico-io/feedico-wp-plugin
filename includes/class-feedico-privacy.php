<?php
/**
 * Privacy (export / erase) hooks for stored credentials and settings.
 *
 * @package Feedico_Sync
 */

class Feedico_Privacy {

	/**
	 * Option keys removed on full erase (no custom table drops).
	 *
	 * @return array<int,string>
	 */
	public static function option_keys_to_erase(): array {
		return array(
			'feedico_sync_email',
			'feedico_sync_password_enc',
			'feedico_sync_token_enc',
			'feedico_sync_last_dashboard',
			'feedico_sync_network_catalog',
			'feedico_sync_selected_networks',
			'feedico_sync_cron_interval',
			'feedico_sync_cron_custom_minutes',
			'feedico_sync_connection_ok',
			'feedico_sync_last_run',
		);
	}

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_erasers' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $exporters
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_exporters( array $exporters ): array {
		$exporters[] = array(
			'exporter_friendly_name' => __( 'Feedico Sync', 'feedico-sync' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * @param array<int,array<string,mixed>> $erasers
	 * @return array<int,array<string,mixed>>
	 */
	public static function register_erasers( array $erasers ): array {
		$erasers[] = array(
			'eraser_friendly_name' => __( 'Feedico Sync credentials & settings', 'feedico-sync' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array<string,mixed>
	 */
	public static function export_personal_data( string $email, int $page = 1 ): array {
		$out = array(
			'data' => array(),
			'done' => true,
		);

		$stored = (string) get_option( 'feedico_sync_email', '' );
		$email  = trim( strtolower( $email ) );
		if ( $email === '' || strtolower( $stored ) !== $email ) {
			return $out;
		}

		$out['data'][] = array(
			'group_id'    => 'feedico_sync',
			'group_label' => __( 'Feedico Sync', 'feedico-sync' ),
			'item_id'     => 'feedico-email',
			'data'        => array(
				array(
					'name'  => __( 'Feedico account email', 'feedico-sync' ),
					'value' => $stored,
				),
			),
		);

		return $out;
	}

	/**
	 * @param string $email
	 * @param int    $page
	 * @return array<string,mixed>
	 */
	public static function erase_personal_data( string $email, int $page = 1 ): array {
		$stored = (string) get_option( 'feedico_sync_email', '' );
		$email  = trim( strtolower( $email ) );
		$items  = array();
		if ( $email === '' || strtolower( $stored ) !== $email ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		foreach ( self::option_keys_to_erase() as $key ) {
			delete_option( $key );
			$items[] = $key;
		}

		wp_clear_scheduled_hook( 'feedico_sync_cron' );

		return array(
			'items_removed'  => $items !== array(),
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
