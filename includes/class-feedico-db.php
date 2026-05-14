<?php
/**
 * DB schema and upsert helpers.
 *
 * @package Feedico_Sync
 */

class Feedico_DB {

	/**
	 * Cached column names per table (invalidated after ALTER).
	 *
	 * @var array<string,array<int,string>>
	 */
	private static $table_columns_cache = array();

	public static function merchants_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'feedico_merchants';
	}

	public static function coupons_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'feedico_coupons';
	}

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'feedico_sync_log';
	}

	public static function seen_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'feedico_sync_seen';
	}

	public static function create_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$m       = self::merchants_table();
		$c       = self::coupons_table();
		$l       = self::log_table();

		$sql_m = "CREATE TABLE IF NOT EXISTS {$m} (
			id varchar(191) NOT NULL,
			property_id varchar(64) NULL,
			provider varchar(128) NULL,
			external_merchant_key varchar(191) NULL,
			display_name varchar(512) NULL,
			description text NULL,
			merchant_website_url varchar(2048) NULL,
			status varchar(64) NULL,
			last_synced_at varchar(64) NULL,
			last_sync_error varchar(1024) NULL,
			payload_json longtext NOT NULL,
			wp_feedico_active tinyint(1) NOT NULL DEFAULT 1,
			wp_manual_override tinyint(1) NOT NULL DEFAULT 0,
			wp_deactivated_at datetime NULL,
			wp_row_updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_provider (provider),
			KEY idx_ext_key (external_merchant_key),
			KEY idx_wp_active (wp_feedico_active)
		) {$charset};";

		$sql_c = "CREATE TABLE IF NOT EXISTS {$c} (
			id varchar(191) NOT NULL,
			network_id varchar(191) NULL,
			network_name varchar(512) NULL,
			merchant_id varchar(191) NULL,
			title varchar(1024) NULL,
			description text NULL,
			coupon_code varchar(512) NULL,
			affiliate_url varchar(2048) NULL,
			image_url varchar(2048) NULL,
			starts_at varchar(64) NULL,
			ends_at varchar(64) NULL,
			discount_type varchar(128) NULL,
			discount_value varchar(128) NULL,
			currency_code varchar(16) NULL,
			status varchar(128) NULL,
			created_at varchar(64) NULL,
			updated_at varchar(64) NULL,
			payload_json longtext NOT NULL,
			wp_feedico_active tinyint(1) NOT NULL DEFAULT 1,
			wp_manual_override tinyint(1) NOT NULL DEFAULT 0,
			wp_deactivated_at datetime NULL,
			wp_row_updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY idx_network (network_id),
			KEY idx_merchant (merchant_id),
			KEY idx_wp_active (wp_feedico_active)
		) {$charset};";

		$sql_l = "CREATE TABLE IF NOT EXISTS {$l} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			started_at datetime NOT NULL,
			finished_at datetime NULL,
			status varchar(20) NOT NULL,
			trigger_type varchar(20) NOT NULL,
			stats_json longtext NULL,
			error_message text NULL,
			PRIMARY KEY (id),
			KEY idx_started (started_at)
		) {$charset};";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table names are escaped via prefix.
		$wpdb->query( $sql_m );
		$wpdb->query( $sql_c );
		$wpdb->query( $sql_l );
		self::create_seen_table_if_missing();
		self::invalidate_table_columns_cache( $m );
		self::invalidate_table_columns_cache( $c );
	}

	/**
	 * Session table for one sync run (IDs seen in API) so passive marking works across time-sliced requests.
	 */
	public static function create_seen_table_if_missing(): void {
		global $wpdb;
		$s = self::seen_table();
		if ( self::table_exists( $s ) ) {
			return;
		}
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE IF NOT EXISTS {$s} (
			run_id varchar(32) NOT NULL,
			kind varchar(16) NOT NULL,
			entity_id varchar(191) NOT NULL,
			PRIMARY KEY (run_id, kind, entity_id),
			KEY idx_run_kind (run_id, kind)
		) {$charset};";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- internal table name.
		$wpdb->query( $sql );
	}

	/**
	 * Add new columns on existing installs; safe to call repeatedly.
	 */
	public static function migrate_schema(): void {
		global $wpdb;
		$m = self::merchants_table();
		if ( self::table_exists( $m ) && ! self::raw_column_exists( $m, 'description' ) ) {
			$wpdb->query( "ALTER TABLE `{$m}` ADD COLUMN description text NULL AFTER display_name" );
			self::invalidate_table_columns_cache( $m );
		}
		$c = self::coupons_table();
		if ( self::table_exists( $c ) && ! self::raw_column_exists( $c, 'network_name' ) ) {
			$wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN network_name varchar(512) NULL AFTER network_id" );
			self::invalidate_table_columns_cache( $c );
		}
		if ( self::table_exists( $m ) && ! self::raw_column_exists( $m, 'wp_manual_override' ) ) {
			$wpdb->query( "ALTER TABLE `{$m}` ADD COLUMN wp_manual_override tinyint(1) NOT NULL DEFAULT 0 AFTER wp_feedico_active" );
			self::invalidate_table_columns_cache( $m );
		}
		if ( self::table_exists( $c ) && ! self::raw_column_exists( $c, 'wp_manual_override' ) ) {
			$wpdb->query( "ALTER TABLE `{$c}` ADD COLUMN wp_manual_override tinyint(1) NOT NULL DEFAULT 0 AFTER wp_feedico_active" );
			self::invalidate_table_columns_cache( $c );
		}
		self::create_seen_table_if_missing();
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		$like = $wpdb->esc_like( $table );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
		return $found === $table;
	}

	/**
	 * @param string $table Sanitized internal table name only.
	 */
	private static function raw_column_exists( string $table, string $column ): bool {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ),
			ARRAY_A
		);
		return is_array( $row ) && $row !== array();
	}

	/**
	 * @return array<int,string>
	 */
	private static function get_table_columns( string $table ): array {
		global $wpdb;
		if ( isset( self::$table_columns_cache[ $table ] ) ) {
			return self::$table_columns_cache[ $table ];
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table is internal prefix name.
		$rows = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A );
		$out  = array();
		if ( is_array( $rows ) ) {
			foreach ( $rows as $r ) {
				if ( isset( $r['Field'] ) ) {
					$out[] = (string) $r['Field'];
				}
			}
		}
		self::$table_columns_cache[ $table ] = $out;
		return $out;
	}

	private static function invalidate_table_columns_cache( string $table ): void {
		unset( self::$table_columns_cache[ $table ] );
	}

	private static function camel_to_snake( string $str ): string {
		$str = preg_replace( '/(?<!^)[A-Z]/', '_$0', $str );
		return strtolower( is_string( $str ) ? $str : '' );
	}

	/**
	 * Add TEXT columns for scalar API keys not yet present (excludes wp_* and nested values).
	 *
	 * @param array<string,mixed> $r
	 */
	private static function discover_columns_from_api_row( string $table, array $r ): void {
		global $wpdb;
		foreach ( $r as $key => $val ) {
			if ( ! is_string( $key ) || $key === '' ) {
				continue;
			}
			if ( is_array( $val ) || is_object( $val ) ) {
				continue;
			}
			$col = self::camel_to_snake( $key );
			if ( ! preg_match( '/^[a-z][a-z0-9_]{0,62}$/', $col ) ) {
				continue;
			}
			if ( strpos( $col, 'wp_' ) === 0 ) {
				continue;
			}
			if ( self::raw_column_exists( $table, $col ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- identifiers validated.
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$col}` text NULL" );
			self::invalidate_table_columns_cache( $table );
		}
	}

	/**
	 * @param array<string,mixed> $r
	 */
	private static function api_value_for_snake_column( array $r, string $col_snake ): string {
		foreach ( $r as $k => $v ) {
			if ( ! is_string( $k ) ) {
				continue;
			}
			if ( is_array( $v ) || is_object( $v ) ) {
				continue;
			}
			if ( self::camel_to_snake( $k ) !== $col_snake ) {
				continue;
			}
			if ( is_bool( $v ) ) {
				return $v ? '1' : '0';
			}
			if ( $v === null ) {
				return '';
			}
			return (string) $v;
		}
		return '';
	}

	/**
	 * Fill DB columns from API row keys (snake columns) not already in $t.
	 *
	 * @param array<string,mixed> $r
	 * @param array<string,string> $t
	 * @return array<string,string>
	 */
	private static function augment_tuple_from_api( string $table, array $r, array $t ): array {
		$allowed = array_flip( self::get_table_columns( $table ) );
		foreach ( array_keys( $allowed ) as $col ) {
			if ( isset( $t[ $col ] ) ) {
				continue;
			}
			if ( $col === 'id' ) {
				continue;
			}
			if ( strpos( $col, 'wp_' ) === 0 ) {
				continue;
			}
			$t[ $col ] = self::api_value_for_snake_column( $r, $col );
		}
		return $t;
	}

	/**
	 * When admin has edited a merchant, keep display name and description (and payload mirror) on API sync.
	 *
	 * @param array<string,string> $t
	 * @param array<string,mixed>  $r
	 * @return array<string,string>
	 */
	private static function maybe_preserve_merchant_manual_fields( string $table, array $t, array $r ): array {
		global $wpdb;
		if ( $t['id'] === '' ) {
			return $t;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT display_name, description, payload_json, wp_manual_override FROM `{$table}` WHERE id = %s LIMIT 1",
				$t['id']
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row['wp_manual_override'] ) ) {
			return $t;
		}
		$t['display_name'] = isset( $row['display_name'] ) ? (string) $row['display_name'] : '';
		$t['description']  = isset( $row['description'] ) ? (string) $row['description'] : '';

		$decoded = array();
		if ( isset( $row['payload_json'] ) && is_string( $row['payload_json'] ) && $row['payload_json'] !== '' ) {
			$d = json_decode( $row['payload_json'], true );
			if ( is_array( $d ) ) {
				$decoded = $d;
			}
		}
		$merged                   = array_merge( $decoded, $r );
		$merged['displayName']    = $t['display_name'];
		$merged['description']    = $t['description'];
		$encoded                  = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE );
		$t['payload_json']        = false !== $encoded ? $encoded : wp_json_encode( $r );

		return $t;
	}

	/**
	 * When admin has edited a coupon, keep form fields (and payload mirror) on API sync.
	 *
	 * @param array<string,string> $t
	 * @param array<string,mixed>  $r
	 * @return array<string,string>
	 */
	private static function maybe_preserve_coupon_manual_fields( string $table, array $t, array $r ): array {
		global $wpdb;
		if ( $t['id'] === '' ) {
			return $t;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT title, description, coupon_code, affiliate_url, image_url, network_name, starts_at, ends_at, discount_type, discount_value, currency_code, status, payload_json, wp_manual_override FROM `{$table}` WHERE id = %s LIMIT 1",
				$t['id']
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) || empty( $row['wp_manual_override'] ) ) {
			return $t;
		}
		$preserve = array(
			'title',
			'description',
			'coupon_code',
			'affiliate_url',
			'image_url',
			'network_name',
			'starts_at',
			'ends_at',
			'discount_type',
			'discount_value',
			'currency_code',
			'status',
		);
		foreach ( $preserve as $col ) {
			$t[ $col ] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
		}

		$decoded = array();
		if ( isset( $row['payload_json'] ) && is_string( $row['payload_json'] ) && $row['payload_json'] !== '' ) {
			$d = json_decode( $row['payload_json'], true );
			if ( is_array( $d ) ) {
				$decoded = $d;
			}
		}
		$merged                     = array_merge( $decoded, $r );
		$merged['title']            = $t['title'];
		$merged['description']      = $t['description'];
		$merged['couponCode']       = $t['coupon_code'];
		$merged['code']             = $t['coupon_code'];
		$merged['affiliateUrl']     = $t['affiliate_url'];
		$merged['url']              = $t['affiliate_url'];
		$merged['imageUrl']         = $t['image_url'];
		$merged['networkName']      = $t['network_name'];
		$merged['startsAt']         = $t['starts_at'];
		$merged['endsAt']           = $t['ends_at'];
		$merged['discountType']     = $t['discount_type'];
		$merged['discountValue']    = $t['discount_value'];
		$merged['currencyCode']     = $t['currency_code'];
		$merged['currency']         = $t['currency_code'];
		$merged['status']           = $t['status'];
		$encoded                    = wp_json_encode( $merged, JSON_UNESCAPED_UNICODE );
		$t['payload_json']          = false !== $encoded ? $encoded : wp_json_encode( $r );

		return $t;
	}

	/**
	 * @param array<string,string> $t
	 */
	private static function execute_upsert_row( string $table, array $t ): void {
		global $wpdb;
		$allowed = array_flip( self::get_table_columns( $table ) );
		$t       = array_intersect_key( $t, $allowed );
		if ( ! isset( $t['id'] ) || $t['id'] === '' ) {
			return;
		}
		$cols = array_keys( $t );
		$quoted = array_map(
			static function ( $c ) {
				return '`' . str_replace( '`', '', (string) $c ) . '`';
			},
			$cols
		);
		$fields   = implode( ',', $quoted );
		$holders  = implode( ',', array_fill( 0, count( $cols ), '%s' ) );
		$updates  = array();
		foreach ( $cols as $c ) {
			if ( $c === 'id' ) {
				continue;
			}
			$safe     = '`' . str_replace( '`', '', (string) $c ) . '`';
			$updates[] = "{$safe} = VALUES({$safe})";
		}
		$updates[] = '`wp_feedico_active` = 1';
		$updates[] = '`wp_deactivated_at` = NULL';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- columns whitelisted from SHOW COLUMNS.
		$sql = "INSERT INTO `{$table}` ({$fields}) VALUES ({$holders}) ON DUPLICATE KEY UPDATE " . implode( ', ', $updates );
		$wpdb->query( $wpdb->prepare( $sql, array_values( $t ) ) );
	}

	/**
	 * Create tables when missing (dbDelta can silently skip bad DDL).
	 */
	public static function ensure_tables(): void {
		global $wpdb;
		$c = self::coupons_table();
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $c ) ) );
		if ( $found !== $c ) {
			self::create_tables();
		}
		self::migrate_schema();
	}

	/**
	 * @param array<string,mixed> $r
	 */
	public static function upsert_merchant( array $r ): string {
		$table = self::merchants_table();
		self::discover_columns_from_api_row( $table, $r );
		$t = self::merchant_tuple( $r );
		if ( $t['id'] === '' ) {
			return '';
		}
		$t = self::augment_tuple_from_api( $table, $r, $t );
		$t = self::maybe_preserve_merchant_manual_fields( $table, $t, $r );
		self::execute_upsert_row( $table, $t );
		return $t['id'];
	}

	/**
	 * @param array<string,mixed> $r
	 */
	public static function upsert_coupon( array $r ): string {
		$table = self::coupons_table();
		self::discover_columns_from_api_row( $table, $r );
		$t = self::coupon_tuple( $r );
		if ( $t['id'] === '' ) {
			return '';
		}
		$t = self::augment_tuple_from_api( $table, $r, $t );
		$t = self::maybe_preserve_coupon_manual_fields( $table, $t, $r );
		self::execute_upsert_row( $table, $t );
		return $t['id'];
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,string>
	 */
	private static function merchant_tuple( array $r ): array {
		$err = $r['lastSyncError'] ?? null;
		return array(
			'id'                    => (string) ( $r['id'] ?? '' ),
			'property_id'           => (string) ( $r['propertyId'] ?? '' ),
			'provider'              => (string) ( $r['provider'] ?? '' ),
			'external_merchant_key' => (string) ( $r['externalMerchantKey'] ?? '' ),
			'display_name'          => (string) ( $r['displayName'] ?? '' ),
			'description'           => (string) ( $r['description'] ?? '' ),
			'merchant_website_url'  => (string) ( $r['merchantWebsiteUrl'] ?? '' ),
			'status'                => (string) ( $r['status'] ?? '' ),
			'last_synced_at'        => isset( $r['lastSyncedAt'] ) && $r['lastSyncedAt'] !== null ? (string) $r['lastSyncedAt'] : '',
			'last_sync_error'       => null === $err ? '' : (string) $err,
			'payload_json'          => wp_json_encode( $r ),
		);
	}

	/**
	 * @param array<string,mixed> $r
	 * @return array<string,string>
	 */
	private static function coupon_tuple( array $r ): array {
		return array(
			'id'              => (string) ( $r['id'] ?? $r['couponId'] ?? '' ),
			'network_id'      => (string) ( $r['networkId'] ?? $r['network_id'] ?? $r['propertyId'] ?? '' ),
			'network_name'    => (string) ( $r['networkName'] ?? $r['network_name'] ?? '' ),
			'merchant_id'     => (string) ( $r['merchantId'] ?? $r['merchant_id'] ?? $r['externalMerchantKey'] ?? $r['propertyId'] ?? '' ),
			'title'           => (string) ( $r['title'] ?? $r['name'] ?? '' ),
			'description'     => (string) ( $r['description'] ?? '' ),
			'coupon_code'     => (string) ( $r['code'] ?? $r['couponCode'] ?? '' ),
			'affiliate_url'   => (string) ( $r['url'] ?? $r['affiliateUrl'] ?? $r['affiliate_url'] ?? $r['offerUrl'] ?? '' ),
			'image_url'       => (string) ( $r['imageUrl'] ?? $r['image_url'] ?? '' ),
			'starts_at'       => (string) ( $r['startsAt'] ?? $r['starts_at'] ?? '' ),
			'ends_at'         => (string) ( $r['endsAt'] ?? $r['ends_at'] ?? '' ),
			'discount_type'   => (string) ( $r['discountType'] ?? $r['discount_type'] ?? '' ),
			'discount_value'  => (string) ( $r['discountValue'] ?? $r['discount'] ?? $r['discount_value'] ?? '' ),
			'currency_code'   => (string) ( $r['currency'] ?? $r['currencyCode'] ?? '' ),
			'status'          => (string) ( $r['status'] ?? '' ),
			'created_at'      => (string) ( $r['createdAt'] ?? $r['created_at'] ?? '' ),
			'updated_at'      => (string) ( $r['updatedAt'] ?? $r['updated_at'] ?? '' ),
			'payload_json'    => wp_json_encode( $r ),
		);
	}

	/**
	 * Mark merchants for this provider not in $keep_ids as inactive. Returns ids that were set passive.
	 *
	 * @param array<int,string> $keep_ids
	 * @return array<int,string>
	 */
	public static function mark_merchants_not_in_ids_passive( string $provider, array $keep_ids ): array {
		global $wpdb;
		if ( $provider === '' ) {
			return array();
		}
		$table    = self::merchants_table();
		$active   = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE provider = %s AND wp_feedico_active = 1",
				$provider
			)
		);
		if ( ! is_array( $active ) || $active === array() ) {
			return array();
		}
		$seen    = array_flip( $keep_ids );
		$to_pass = array();
		foreach ( $active as $aid ) {
			if ( ! isset( $seen[ $aid ] ) ) {
				$to_pass[] = $aid;
			}
		}
		self::batch_set_passive_by_ids( $table, $to_pass );
		return $to_pass;
	}

	/**
	 * Clear seen rows (between full sync runs).
	 */
	public static function truncate_sync_seen(): void {
		global $wpdb;
		$t = self::seen_table();
		if ( ! self::table_exists( $t ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- internal table name.
		$wpdb->query( "TRUNCATE TABLE `{$t}`" );
		if ( ! empty( $wpdb->last_error ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM `{$t}`" );
		}
	}

	/**
	 * @param array<int,string> $entity_ids
	 */
	public static function insert_sync_seen_batch( string $run_id, string $kind, array $entity_ids ): void {
		global $wpdb;
		if ( $run_id === '' || $entity_ids === array() ) {
			return;
		}
		$t = self::seen_table();
		if ( ! self::table_exists( $t ) ) {
			self::create_seen_table_if_missing();
		}
		$kind = $kind === 'coupon' ? 'coupon' : 'merchant';
		foreach ( array_chunk( array_values( array_unique( array_map( 'strval', $entity_ids ) ) ), 150 ) as $chunk ) {
			if ( $chunk === array() ) {
				continue;
			}
			$parts = array();
			foreach ( $chunk as $eid ) {
				if ( $eid === '' ) {
					continue;
				}
				$parts[] = $wpdb->prepare( '(%s,%s,%s)', $run_id, $kind, $eid );
			}
			if ( $parts === array() ) {
				continue;
			}
			$sql = "INSERT IGNORE INTO `{$t}` (run_id, kind, entity_id) VALUES " . implode( ',', $parts );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- VALUES built from prepare fragments.
			$wpdb->query( $sql );
		}
	}

	/**
	 * Active merchants for this provider not listed in seen for this run → passive.
	 *
	 * @return array<int,string> ids set passive
	 */
	public static function mark_merchants_passive_vs_seen( string $provider, string $run_id ): array {
		global $wpdb;
		if ( $provider === '' || $run_id === '' ) {
			return array();
		}
		$m = self::merchants_table();
		$s = self::seen_table();
		if ( ! self::table_exists( $m ) || ! self::table_exists( $s ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix helpers.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.id FROM `{$m}` m
				WHERE m.provider = %s AND m.wp_feedico_active = 1
				AND NOT EXISTS (
					SELECT 1 FROM `{$s}` x WHERE x.run_id = %s AND x.kind = 'merchant' AND x.entity_id = m.id
				)",
				$provider,
				$run_id
			)
		);
		if ( ! is_array( $ids ) || $ids === array() ) {
			return array();
		}
		self::batch_set_passive_by_ids( $m, $ids );
		return $ids;
	}

	/**
	 * Active coupons not listed in seen for this run → passive.
	 *
	 * @return array<int,string> ids set passive
	 */
	public static function mark_coupons_passive_vs_seen( string $run_id ): array {
		global $wpdb;
		if ( $run_id === '' ) {
			return array();
		}
		$c = self::coupons_table();
		$s = self::seen_table();
		if ( ! self::table_exists( $c ) || ! self::table_exists( $s ) ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from prefix helpers.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT c.id FROM `{$c}` c
				WHERE c.wp_feedico_active = 1
				AND NOT EXISTS (
					SELECT 1 FROM `{$s}` x WHERE x.run_id = %s AND x.kind = 'coupon' AND x.entity_id = c.id
				)",
				$run_id
			)
		);
		if ( ! is_array( $ids ) || $ids === array() ) {
			return array();
		}
		self::batch_set_passive_by_ids( $c, $ids );
		return $ids;
	}

	/**
	 * @param array<int,string> $keep_ids
	 * @return array<int,string>
	 */
	public static function mark_coupons_not_in_ids_passive( array $keep_ids ): array {
		global $wpdb;
		$table  = self::coupons_table();
		$active = $wpdb->get_col( "SELECT id FROM {$table} WHERE wp_feedico_active = 1" );
		if ( ! is_array( $active ) || $active === array() ) {
			return array();
		}
		$seen    = array_flip( $keep_ids );
		$to_pass = array();
		foreach ( $active as $aid ) {
			if ( ! isset( $seen[ $aid ] ) ) {
				$to_pass[] = $aid;
			}
		}
		self::batch_set_passive_by_ids( $table, $to_pass );
		return $to_pass;
	}

	/**
	 * @param array<int,string> $ids
	 */
	private static function batch_set_passive_by_ids( string $table, array $ids ): int {
		global $wpdb;
		$total = 0;
		foreach ( array_chunk( $ids, 400 ) as $chunk ) {
			if ( $chunk === array() ) {
				continue;
			}
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = "UPDATE {$table} SET wp_feedico_active = 0, wp_deactivated_at = UTC_TIMESTAMP() WHERE id IN ({$holders})";
			$wpdb->query( $wpdb->prepare( $sql, $chunk ) );
			$total += (int) $wpdb->rows_affected;
		}
		return $total;
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	public static function insert_log_start( string $trigger_type ): int {
		global $wpdb;
		$table = self::log_table();
		$wpdb->insert(
			$table,
			array(
				'started_at'    => current_time( 'mysql', true ),
				'status'        => 'running',
				'trigger_type'  => $trigger_type,
			),
			array( '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $stats
	 */
	public static function update_log_finish( int $log_id, string $status, array $stats, string $error_message = '' ): void {
		global $wpdb;
		$table = self::log_table();
		$wpdb->update(
			$table,
			array(
				'finished_at'    => current_time( 'mysql', true ),
				'status'         => $status,
				'stats_json'     => wp_json_encode( $stats ),
				'error_message'  => $error_message !== '' ? $error_message : null,
			),
			array( 'id' => $log_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_recent_logs( int $limit = 50 ): array {
		global $wpdb;
		$table = self::log_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param string $search Optional LIKE search on name / provider.
	 */
	public static function count_active_merchants( string $search = '' ): int {
		global $wpdb;
		$table = self::merchants_table();
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE wp_feedico_active = 1 AND (display_name LIKE %s OR provider LIKE %s OR IFNULL(description,'') LIKE %s)",
				$like,
				$like,
				$like
			);
		} else {
			$sql = "SELECT COUNT(*) FROM {$table} WHERE wp_feedico_active = 1";
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_active_merchants( int $limit, int $offset, string $search = '' ): array {
		global $wpdb;
		$table  = self::merchants_table();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT id, property_id, external_merchant_key, display_name, description, merchant_website_url, provider, status FROM {$table} WHERE wp_feedico_active = 1 AND (display_name LIKE %s OR provider LIKE %s OR IFNULL(description,'') LIKE %s) ORDER BY display_name ASC, id ASC LIMIT %d OFFSET %d",
				$like,
				$like,
				$like,
				$limit,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, property_id, external_merchant_key, display_name, description, merchant_website_url, provider, status FROM {$table} WHERE wp_feedico_active = 1 ORDER BY display_name ASC, id ASC LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || $rows === array() ) {
			return array();
		}
		return self::attach_active_coupon_counts( $rows );
	}

	/**
	 * Coupon rows store merchant_ref as internal id, externalMerchantKey, or propertyId — resolve to all keys for SQL IN (...).
	 *
	 * @param string $merchant_ref merchants.id from UI, or alternate key.
	 * @return array<int,string>
	 */
	public static function merchant_coupon_id_keys( string $merchant_ref ): array {
		global $wpdb;
		$merchant_ref = trim( $merchant_ref );
		if ( $merchant_ref === '' ) {
			return array();
		}
		$t = self::merchants_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, external_merchant_key, property_id FROM {$t} WHERE id = %s OR external_merchant_key = %s OR property_id = %s LIMIT 1",
				$merchant_ref,
				$merchant_ref,
				$merchant_ref
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return array( $merchant_ref );
		}
		$keys = array();
		$id   = trim( (string) ( $row['id'] ?? '' ) );
		if ( $id !== '' ) {
			$keys[] = $id;
		}
		$ext = trim( (string) ( $row['external_merchant_key'] ?? '' ) );
		if ( $ext !== '' && ! in_array( $ext, $keys, true ) ) {
			$keys[] = $ext;
		}
		$prop = trim( (string) ( $row['property_id'] ?? '' ) );
		if ( $prop !== '' && ! in_array( $prop, $keys, true ) ) {
			$keys[] = $prop;
		}
		return $keys !== array() ? array_values( $keys ) : array( $merchant_ref );
	}

	/**
	 * Single merchant row by primary id, external_merchant_key, or property_id.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_merchant_row_by_ref( string $ref ): ?array {
		global $wpdb;
		$ref = trim( $ref );
		if ( $ref === '' ) {
			return null;
		}
		$t = self::merchants_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, property_id, provider, external_merchant_key, display_name, description, merchant_website_url, status, wp_feedico_active, last_synced_at FROM {$t} WHERE id = %s OR external_merchant_key = %s OR property_id = %s LIMIT 1",
				$ref,
				$ref,
				$ref
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}
		$with = self::attach_active_coupon_counts( array( $row ) );
		return $with[0] ?? $row;
	}

	/**
	 * Merchant row by primary key only (admin edit).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_merchant_admin_by_pk( string $id ): ?array {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return null;
		}
		$t   = self::merchants_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, property_id, provider, external_merchant_key, display_name, description, merchant_website_url, status, wp_feedico_active, wp_manual_override, last_synced_at, last_sync_error, payload_json FROM {$t} WHERE id = %s LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update merchant fields from admin (also mirrors into payload_json for API-shaped keys).
	 *
	 * @param array{display_name:string,description:string,merchant_website_url:string,status:string,wp_feedico_active:int} $fields
	 */
	public static function update_merchant_admin( string $id, array $fields ): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$existing = self::get_merchant_admin_by_pk( $id );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		$table   = self::merchants_table();
		$payload = array();
		if ( isset( $existing['payload_json'] ) && is_string( $existing['payload_json'] ) && $existing['payload_json'] !== '' ) {
			$decoded = json_decode( $existing['payload_json'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$payload['displayName']        = $fields['display_name'];
		$payload['description']        = $fields['description'];
		$payload['merchantWebsiteUrl'] = $fields['merchant_website_url'];
		$payload['status']             = $fields['status'];

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$active = ! empty( $fields['wp_feedico_active'] ) ? 1 : 0;
		$rows   = $wpdb->update(
			$table,
			array(
				'display_name'         => $fields['display_name'],
				'description'          => $fields['description'],
				'merchant_website_url' => $fields['merchant_website_url'],
				'status'               => $fields['status'],
				'wp_feedico_active'    => $active,
				'wp_manual_override'   => 1,
				'payload_json'         => $json,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $rows ) {
			return false;
		}
		if ( $wpdb->last_error !== '' ) {
			return false;
		}
		if ( 0 === (int) $wpdb->rows_affected ) {
			$now = self::get_merchant_admin_by_pk( $id );
			if ( ! is_array( $now ) ) {
				return false;
			}
			if (
				(string) $now['display_name'] === (string) $fields['display_name']
				&& (string) $now['description'] === (string) $fields['description']
				&& (string) $now['merchant_website_url'] === (string) $fields['merchant_website_url']
				&& (string) $now['status'] === (string) $fields['status']
				&& (int) $now['wp_feedico_active'] === (int) $active
				&& (int) ( $now['wp_manual_override'] ?? 0 ) === 1
			) {
				return true;
			}
			return false;
		}
		return true;
	}

	/**
	 * Set manual-lock flag (1 = preserve WordPress-edited fields on API sync).
	 */
	public static function set_merchant_manual_override( string $id, int $enabled ): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$enabled = $enabled ? 1 : 0;
		$table   = self::merchants_table();
		$r       = $wpdb->update(
			$table,
			array( 'wp_manual_override' => $enabled ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%s' )
		);
		if ( false === $r || $wpdb->last_error !== '' ) {
			return false;
		}
		return true;
	}

	/**
	 * Push CPT edits (title + content) into the merchants table and mark manual override.
	 */
	public static function update_merchant_from_cpt( string $id, string $title, string $content_plain ): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$existing = self::get_merchant_admin_by_pk( $id );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		$title           = sanitize_text_field( $title );
		$content_plain   = sanitize_textarea_field( $content_plain );
		$table           = self::merchants_table();
		$payload         = array();
		if ( isset( $existing['payload_json'] ) && is_string( $existing['payload_json'] ) && $existing['payload_json'] !== '' ) {
			$decoded = json_decode( $existing['payload_json'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$payload['displayName'] = $title;
		$payload['description'] = $content_plain;

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$rows = $wpdb->update(
			$table,
			array(
				'display_name'       => $title,
				'description'        => $content_plain,
				'wp_manual_override' => 1,
				'payload_json'       => $json,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $rows || $wpdb->last_error !== '' ) {
			return false;
		}
		if ( 0 === (int) $wpdb->rows_affected ) {
			$now = self::get_merchant_admin_by_pk( $id );
			if ( ! is_array( $now ) ) {
				return false;
			}
			return (string) $now['display_name'] === (string) $title
				&& (string) $now['description'] === (string) $content_plain
				&& (int) ( $now['wp_manual_override'] ?? 0 ) === 1;
		}
		return true;
	}

	/**
	 * Coupon row by primary key only (admin edit).
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_coupon_admin_by_pk( string $id ): ?array {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return null;
		}
		$t = self::coupons_table();
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, network_id, network_name, merchant_id, title, description, coupon_code, affiliate_url, image_url, starts_at, ends_at, discount_type, discount_value, currency_code, status, created_at, updated_at, wp_feedico_active, wp_manual_override, payload_json FROM {$t} WHERE id = %s LIMIT 1",
				$id
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update coupon fields from admin (mirrors into payload_json).
	 *
	 * @param array<string,mixed> $fields
	 */
	public static function update_coupon_admin( string $id, array $fields ): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$existing = self::get_coupon_admin_by_pk( $id );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		$table   = self::coupons_table();
		$payload = array();
		if ( isset( $existing['payload_json'] ) && is_string( $existing['payload_json'] ) && $existing['payload_json'] !== '' ) {
			$decoded = json_decode( $existing['payload_json'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}

		$payload['title']           = $fields['title'];
		$payload['description']     = $fields['description'];
		$payload['couponCode']    = $fields['coupon_code'];
		$payload['code']          = $fields['coupon_code'];
		$payload['affiliateUrl']  = $fields['affiliate_url'];
		$payload['url']           = $fields['affiliate_url'];
		$payload['imageUrl']      = $fields['image_url'];
		$payload['networkName']   = $fields['network_name'];
		$payload['startsAt']      = $fields['starts_at'];
		$payload['endsAt']        = $fields['ends_at'];
		$payload['discountType']  = $fields['discount_type'];
		$payload['discountValue'] = $fields['discount_value'];
		$payload['currencyCode']  = $fields['currency_code'];
		$payload['currency']      = $fields['currency_code'];
		$payload['status']         = $fields['status'];

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$active = ! empty( $fields['wp_feedico_active'] ) ? 1 : 0;
		$rows   = $wpdb->update(
			$table,
			array(
				'title'            => $fields['title'],
				'description'      => $fields['description'],
				'coupon_code'      => $fields['coupon_code'],
				'affiliate_url'    => $fields['affiliate_url'],
				'image_url'        => $fields['image_url'],
				'network_name'     => $fields['network_name'],
				'starts_at'        => $fields['starts_at'],
				'ends_at'          => $fields['ends_at'],
				'discount_type'    => $fields['discount_type'],
				'discount_value'   => $fields['discount_value'],
				'currency_code'    => $fields['currency_code'],
				'status'             => $fields['status'],
				'wp_feedico_active'  => $active,
				'wp_manual_override' => 1,
				'payload_json'       => $json,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $rows ) {
			return false;
		}
		if ( $wpdb->last_error !== '' ) {
			return false;
		}
		if ( 0 === (int) $wpdb->rows_affected ) {
			$now = self::get_coupon_admin_by_pk( $id );
			if ( ! is_array( $now ) ) {
				return false;
			}
			$same =
				(string) $now['title'] === (string) $fields['title']
				&& (string) $now['description'] === (string) $fields['description']
				&& (string) $now['coupon_code'] === (string) $fields['coupon_code']
				&& (string) $now['affiliate_url'] === (string) $fields['affiliate_url']
				&& (string) $now['image_url'] === (string) $fields['image_url']
				&& (string) $now['network_name'] === (string) $fields['network_name']
				&& (string) $now['starts_at'] === (string) $fields['starts_at']
				&& (string) $now['ends_at'] === (string) $fields['ends_at']
				&& (string) $now['discount_type'] === (string) $fields['discount_type']
				&& (string) $now['discount_value'] === (string) $fields['discount_value']
				&& (string) $now['currency_code'] === (string) $fields['currency_code']
				&& (string) $now['status'] === (string) $fields['status']
				&& (int) $now['wp_feedico_active'] === (int) $active
				&& (int) ( $now['wp_manual_override'] ?? 0 ) === 1;
			return $same;
		}
		return true;
	}

	/**
	 * Set manual-lock flag for coupon row.
	 */
	public static function set_coupon_manual_override( string $id, int $enabled ): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$enabled = $enabled ? 1 : 0;
		$table   = self::coupons_table();
		$r       = $wpdb->update(
			$table,
			array( 'wp_manual_override' => $enabled ),
			array( 'id' => $id ),
			array( '%d' ),
			array( '%s' )
		);
		if ( false === $r || $wpdb->last_error !== '' ) {
			return false;
		}
		return true;
	}

	/**
	 * Push CPT edits into the coupons table for title, description, code, affiliate URL, end date.
	 */
	public static function update_coupon_from_cpt(
		string $id,
		string $title,
		string $content_plain,
		string $coupon_code,
		string $affiliate_url,
		string $expires_at
	): bool {
		global $wpdb;
		$id = trim( $id );
		if ( $id === '' ) {
			return false;
		}
		$existing = self::get_coupon_admin_by_pk( $id );
		if ( ! is_array( $existing ) ) {
			return false;
		}
		$title         = sanitize_text_field( $title );
		$content_plain = sanitize_textarea_field( $content_plain );
		$coupon_code   = sanitize_text_field( $coupon_code );
		$affiliate_url = $affiliate_url !== '' ? esc_url_raw( $affiliate_url ) : '';
		$expires_at    = sanitize_text_field( $expires_at );

		$table   = self::coupons_table();
		$payload = array();
		if ( isset( $existing['payload_json'] ) && is_string( $existing['payload_json'] ) && $existing['payload_json'] !== '' ) {
			$decoded = json_decode( $existing['payload_json'], true );
			if ( is_array( $decoded ) ) {
				$payload = $decoded;
			}
		}
		$payload['title']        = $title;
		$payload['description']  = $content_plain;
		$payload['couponCode']   = $coupon_code;
		$payload['code']         = $coupon_code;
		$payload['affiliateUrl'] = $affiliate_url;
		$payload['url']          = $affiliate_url;
		$payload['endsAt']       = $expires_at;

		$json = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			return false;
		}

		$rows = $wpdb->update(
			$table,
			array(
				'title'              => $title,
				'description'        => $content_plain,
				'coupon_code'        => $coupon_code,
				'affiliate_url'      => $affiliate_url,
				'ends_at'            => $expires_at,
				'wp_manual_override' => 1,
				'payload_json'       => $json,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);
		if ( false === $rows || $wpdb->last_error !== '' ) {
			return false;
		}
		if ( 0 === (int) $wpdb->rows_affected ) {
			$now = self::get_coupon_admin_by_pk( $id );
			if ( ! is_array( $now ) ) {
				return false;
			}
			return (string) $now['title'] === (string) $title
				&& (string) $now['description'] === (string) $content_plain
				&& (string) $now['coupon_code'] === (string) $coupon_code
				&& (string) $now['affiliate_url'] === (string) $affiliate_url
				&& (string) $now['ends_at'] === (string) $expires_at
				&& (int) ( $now['wp_manual_override'] ?? 0 ) === 1;
		}
		return true;
	}

	/**
	 * @param string|null $merchant_id Filter by merchant_id, or null for all.
	 * @param string      $search      Optional LIKE on title, description, code.
	 */
	public static function count_active_coupons( ?string $merchant_id, string $search = '' ): int {
		global $wpdb;
		$table = self::coupons_table();
		$parts = array( 'wp_feedico_active = 1' );
		$params = array();
		if ( $merchant_id !== null && $merchant_id !== '' ) {
			$keys = self::merchant_coupon_id_keys( $merchant_id );
			if ( $keys !== array() ) {
				$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$parts[] = "merchant_id IN ({$holders})";
				foreach ( $keys as $k ) {
					$params[] = $k;
				}
			}
		}
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$parts[]  = "(title LIKE %s OR description LIKE %s OR coupon_code LIKE %s OR IFNULL(network_name, '') LIKE %s)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where = implode( ' AND ', $parts );
		$sql   = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		if ( $params !== array() ) {
			$sql = $wpdb->prepare( $sql, ...$params );
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @param string|null $merchant_id
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_active_coupons( int $limit, int $offset, ?string $merchant_id, string $search = '' ): array {
		global $wpdb;
		$table  = self::coupons_table();
		$limit  = max( 1, min( 100, $limit ) );
		$offset = max( 0, $offset );
		$parts  = array( 'wp_feedico_active = 1' );
		$params = array();
		if ( $merchant_id !== null && $merchant_id !== '' ) {
			$keys = self::merchant_coupon_id_keys( $merchant_id );
			if ( $keys !== array() ) {
				$holders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
				$parts[] = "merchant_id IN ({$holders})";
				foreach ( $keys as $k ) {
					$params[] = $k;
				}
			}
		}
		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$parts[]  = "(title LIKE %s OR description LIKE %s OR coupon_code LIKE %s OR IFNULL(network_name, '') LIKE %s)";
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where = implode( ' AND ', $parts );
		$sql   = "SELECT id, merchant_id, network_id, network_name, title, description, coupon_code, affiliate_url, image_url, discount_type, discount_value, currency_code, ends_at, starts_at FROM {$table} WHERE {$where} ORDER BY wp_row_updated_at DESC, id DESC LIMIT %d OFFSET %d";
		$all_p = array_merge( $params, array( $limit, $offset ) );
		$sql   = $wpdb->prepare( $sql, ...$all_p );
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Admin list: all merchants (active and passive).
	 *
	 * @param string $search Optional LIKE on name, provider, id, external key.
	 */
	public static function count_merchants_admin( string $search = '' ): int {
		global $wpdb;
		$table = self::merchants_table();
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE display_name LIKE %s OR provider LIKE %s OR id LIKE %s OR IFNULL(external_merchant_key,'') LIKE %s OR IFNULL(description,'') LIKE %s",
				$like,
				$like,
				$like,
				$like,
				$like
			);
		} else {
			$sql = "SELECT COUNT(*) FROM {$table}";
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_merchants_admin( int $limit, int $offset, string $search = '', string $orderby = 'display_name', string $order = 'ASC' ): array {
		global $wpdb;
		$table   = self::merchants_table();
		$limit   = max( 1, min( 200, $limit ) );
		$offset  = max( 0, $offset );
		$allowed = array(
			'display_name'      => 'display_name',
			'provider'          => 'provider',
			'id'                => 'id',
			'wp_feedico_active' => 'wp_feedico_active',
			'last_synced_at'    => 'last_synced_at',
			'status'            => 'status',
		);
		$ob_col = isset( $allowed[ $orderby ] ) ? $allowed[ $orderby ] : 'display_name';
		$order  = strtoupper( $order ) === 'DESC' ? 'DESC' : 'ASC';

		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT id, property_id, provider, external_merchant_key, display_name, description, merchant_website_url, status, last_synced_at, wp_feedico_active, wp_deactivated_at FROM {$table} WHERE display_name LIKE %s OR provider LIKE %s OR id LIKE %s OR IFNULL(external_merchant_key,'') LIKE %s OR IFNULL(description,'') LIKE %s ORDER BY {$ob_col} {$order} LIMIT %d OFFSET %d",
				$like,
				$like,
				$like,
				$like,
				$like,
				$limit,
				$offset
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- order columns whitelisted above.
			$sql = $wpdb->prepare(
				"SELECT id, property_id, provider, external_merchant_key, display_name, description, merchant_website_url, status, last_synced_at, wp_feedico_active, wp_deactivated_at FROM {$table} ORDER BY {$ob_col} {$order} LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || $rows === array() ) {
			return array();
		}
		return self::attach_active_coupon_counts( $rows );
	}

	/**
	 * Active coupon counts per merchant primary key, matching coupons where merchant_id equals
	 * merchants.id, external_merchant_key, or property_id (API inconsistency).
	 *
	 * @param array<int,array<string,mixed>> $rows Merchant rows with id, optional external_merchant_key, property_id.
	 * @return array<string,int> merchants.id => count
	 */
	private static function map_active_coupon_counts_by_merchant_pk( array $rows ): array {
		global $wpdb;
		$table         = self::coupons_table();
		$merchant_keys = array();
		$all_keys      = array();

		foreach ( $rows as $r ) {
			$pk = isset( $r['id'] ) ? trim( (string) $r['id'] ) : '';
			if ( $pk === '' ) {
				continue;
			}
			$keys = array( $pk );
			$ext  = isset( $r['external_merchant_key'] ) ? trim( (string) $r['external_merchant_key'] ) : '';
			$prop = isset( $r['property_id'] ) ? trim( (string) $r['property_id'] ) : '';
			if ( $ext !== '' && ! in_array( $ext, $keys, true ) ) {
				$keys[] = $ext;
			}
			if ( $prop !== '' && ! in_array( $prop, $keys, true ) ) {
				$keys[] = $prop;
			}
			$merchant_keys[ $pk ] = $keys;
			foreach ( $keys as $k ) {
				$all_keys[] = $k;
			}
		}

		$all_keys = array_values( array_unique( $all_keys ) );
		if ( $all_keys === array() ) {
			return array();
		}

		$raw = array();
		foreach ( array_chunk( $all_keys, 500 ) as $chunk ) {
			$holders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$sql     = "SELECT merchant_id, COUNT(*) AS c FROM {$table} WHERE wp_feedico_active = 1 AND merchant_id IN ({$holders}) GROUP BY merchant_id";
			$sql     = $wpdb->prepare( $sql, $chunk );
			$qrows   = $wpdb->get_results( $sql, ARRAY_A );
			if ( is_array( $qrows ) ) {
				foreach ( $qrows as $qr ) {
					$mid = isset( $qr['merchant_id'] ) ? trim( (string) $qr['merchant_id'] ) : '';
					if ( $mid !== '' ) {
						$raw[ $mid ] = (int) ( $qr['c'] ?? 0 );
					}
				}
			}
		}

		$out = array();
		foreach ( $merchant_keys as $pk => $keys ) {
			$sum = 0;
			foreach ( $keys as $k ) {
				if ( isset( $raw[ $k ] ) ) {
					$sum += $raw[ $k ];
				}
			}
			$out[ $pk ] = $sum;
		}
		return $out;
	}

	/**
	 * Add coupon_count to each merchant row (uses id + external_merchant_key + property_id).
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	public static function attach_active_coupon_counts( array $rows ): array {
		if ( $rows === array() ) {
			return $rows;
		}
		$counts = self::map_active_coupon_counts_by_merchant_pk( $rows );
		foreach ( $rows as $k => $r ) {
			$pk                         = isset( $r['id'] ) ? (string) $r['id'] : '';
			$rows[ $k ]['coupon_count'] = ( $pk !== '' && isset( $counts[ $pk ] ) ) ? $counts[ $pk ] : 0;
		}
		return $rows;
	}

	public static function count_coupons_admin( string $search = '' ): int {
		global $wpdb;
		$table = self::coupons_table();
		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE title LIKE %s OR description LIKE %s OR coupon_code LIKE %s OR id LIKE %s OR IFNULL(merchant_id,'') LIKE %s OR IFNULL(network_name,'') LIKE %s",
				$like,
				$like,
				$like,
				$like,
				$like,
				$like
			);
		} else {
			$sql = "SELECT COUNT(*) FROM {$table}";
		}
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_coupons_admin( int $limit, int $offset, string $search = '', string $orderby = 'wp_row_updated_at', string $order = 'DESC' ): array {
		global $wpdb;
		$table   = self::coupons_table();
		$limit   = max( 1, min( 200, $limit ) );
		$offset  = max( 0, $offset );
		$allowed = array(
			'title'             => 'title',
			'merchant_id'       => 'merchant_id',
			'id'                => 'id',
			'wp_feedico_active' => 'wp_feedico_active',
			'ends_at'           => 'ends_at',
			'wp_row_updated_at' => 'wp_row_updated_at',
			'coupon_code'       => 'coupon_code',
		);
		$ob_col = isset( $allowed[ $orderby ] ) ? $allowed[ $orderby ] : 'wp_row_updated_at';
		$order  = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		if ( $search !== '' ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';
			$sql  = $wpdb->prepare(
				"SELECT id, merchant_id, network_id, network_name, title, coupon_code, affiliate_url, discount_type, discount_value, currency_code, ends_at, wp_feedico_active, wp_row_updated_at FROM {$table} WHERE title LIKE %s OR description LIKE %s OR coupon_code LIKE %s OR id LIKE %s OR IFNULL(merchant_id,'') LIKE %s OR IFNULL(network_name,'') LIKE %s ORDER BY {$ob_col} {$order} LIMIT %d OFFSET %d",
				$like,
				$like,
				$like,
				$like,
				$like,
				$like,
				$limit,
				$offset
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT id, merchant_id, network_id, network_name, title, coupon_code, affiliate_url, discount_type, discount_value, currency_code, ends_at, wp_feedico_active, wp_row_updated_at FROM {$table} ORDER BY {$ob_col} {$order} LIMIT %d OFFSET %d",
				$limit,
				$offset
			);
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}
}
