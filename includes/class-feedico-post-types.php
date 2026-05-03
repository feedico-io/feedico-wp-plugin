<?php
/**
 * WordPress post types mirroring synced Feedico merchants and coupons.
 *
 * @package Feedico_Sync
 */

class Feedico_Post_Types {

	public const STORE_POST_TYPE  = 'feedico_store';
	public const COUPON_POST_TYPE = 'feedico_coupon';

	/** Links the post to the primary key in wp_feedico_merchants / wp_feedico_coupons. */
	public const META_ENTITY_ID = '_feedico_entity_id';

	public const META_COUPON_CODE     = 'feedico_coupon_code';
	public const META_AFFILIATE_URL   = 'feedico_affiliate_url';
	public const META_EXPIRES_AT      = 'feedico_expires_at';

	/**
	 * When true, skip pushing CPT changes back into custom tables (avoids loops during API→post sync).
	 *
	 * @var bool
	 */
	private static $syncing_from_table = false;

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register_types' ), 5 );
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::STORE_POST_TYPE, array( __CLASS__, 'on_save_store' ), 99, 3 );
		add_action( 'save_post_' . self::COUPON_POST_TYPE, array( __CLASS__, 'on_save_coupon' ), 99, 3 );
	}

	/**
	 * Register CPTs and meta (safe to call from activate or init).
	 */
	public static function register_types(): void {
		register_post_type(
			self::STORE_POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Feedico Stores', 'feedico-sync' ),
					'singular_name' => __( 'Feedico Store', 'feedico-sync' ),
					'add_new_item'  => __( 'Add New Store', 'feedico-sync' ),
					'edit_item'     => __( 'Edit Store', 'feedico-sync' ),
					'search_items'  => __( 'Search Stores', 'feedico-sync' ),
					'not_found'     => __( 'No stores found.', 'feedico-sync' ),
				),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-store',
				'supports'            => array( 'title', 'editor' ),
				'has_archive'         => true,
				'rewrite'             => array( 'slug' => 'feedico-store' ),
				'show_in_rest'        => true,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_post_type(
			self::COUPON_POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Feedico Coupons', 'feedico-sync' ),
					'singular_name' => __( 'Feedico Coupon', 'feedico-sync' ),
					'add_new_item'  => __( 'Add New Coupon', 'feedico-sync' ),
					'edit_item'     => __( 'Edit Coupon', 'feedico-sync' ),
					'search_items'  => __( 'Search Coupons', 'feedico-sync' ),
					'not_found'     => __( 'No coupons found.', 'feedico-sync' ),
				),
				'public'              => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-tickets-alt',
				'supports'            => array( 'title', 'editor' ),
				'has_archive'         => true,
				'rewrite'             => array( 'slug' => 'feedico-coupon' ),
				'show_in_rest'        => true,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);

		register_post_meta(
			self::COUPON_POST_TYPE,
			self::META_COUPON_CODE,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'auth_callback'     => array( __CLASS__, 'meta_auth' ),
			)
		);
		register_post_meta(
			self::COUPON_POST_TYPE,
			self::META_AFFILIATE_URL,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'esc_url_raw',
				'show_in_rest'      => true,
				'auth_callback'     => array( __CLASS__, 'meta_auth' ),
			)
		);
		register_post_meta(
			self::COUPON_POST_TYPE,
			self::META_EXPIRES_AT,
			array(
				'type'              => 'string',
				'single'            => true,
				'sanitize_callback' => 'sanitize_text_field',
				'show_in_rest'      => true,
				'auth_callback'     => array( __CLASS__, 'meta_auth' ),
			)
		);
	}

	public static function meta_auth(): bool {
		return current_user_can( 'edit_posts' );
	}

	public static function add_meta_boxes(): void {
		add_meta_box(
			'feedico_coupon_fields',
			__( 'Coupon details', 'feedico-sync' ),
			array( __CLASS__, 'render_coupon_meta_box' ),
			self::COUPON_POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * @param WP_Post $post
	 */
	public static function render_coupon_meta_box( $post ): void {
		wp_nonce_field( 'feedico_coupon_meta_save', 'feedico_coupon_meta_nonce' );
		$code = (string) get_post_meta( $post->ID, self::META_COUPON_CODE, true );
		$aff  = (string) get_post_meta( $post->ID, self::META_AFFILIATE_URL, true );
		$end  = (string) get_post_meta( $post->ID, self::META_EXPIRES_AT, true );
		echo '<p><label for="feedico_coupon_code_input"><strong>' . esc_html__( 'Coupon code', 'feedico-sync' ) . '</strong></label><br />';
		echo '<input type="text" class="widefat" id="feedico_coupon_code_input" name="feedico_coupon_code_input" value="' . esc_attr( $code ) . '" /></p>';
		echo '<p><label for="feedico_affiliate_url_input"><strong>' . esc_html__( 'Affiliate URL', 'feedico-sync' ) . '</strong></label><br />';
		echo '<input type="url" class="widefat" id="feedico_affiliate_url_input" name="feedico_affiliate_url_input" value="' . esc_attr( $aff ) . '" /></p>';
		echo '<p><label for="feedico_expires_at_input"><strong>' . esc_html__( 'Expiry (end date / ISO)', 'feedico-sync' ) . '</strong></label><br />';
		echo '<input type="text" class="widefat" id="feedico_expires_at_input" name="feedico_expires_at_input" value="' . esc_attr( $end ) . '" placeholder="2025-12-31" /></p>';
		echo '<p class="description">' . esc_html__( 'Synced coupons are tied to the hidden Feedico ID. Create coupons from the Feedico Sync admin or API sync; manual posts here are not added to the Feedico table.', 'feedico-sync' ) . '</p>';
	}

	/**
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_save_store( $post_id, $post = null ): void {
		if ( self::$syncing_from_table ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || $post->post_type !== self::STORE_POST_TYPE ) {
			return;
		}
		$entity = get_post_meta( $post_id, self::META_ENTITY_ID, true );
		if ( ! is_string( $entity ) || $entity === '' ) {
			return;
		}
		$plain = wp_strip_all_tags( (string) $post->post_content );
		Feedico_DB::update_merchant_from_cpt( $entity, (string) $post->post_title, $plain );
	}

	/**
	 * @param int          $post_id Post ID.
	 * @param WP_Post|null $post    Post object.
	 */
	public static function on_save_coupon( $post_id, $post = null ): void {
		if ( self::$syncing_from_table ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post instanceof WP_Post ) {
			$post = get_post( $post_id );
		}
		if ( ! $post instanceof WP_Post || $post->post_type !== self::COUPON_POST_TYPE ) {
			return;
		}

		if ( isset( $_POST['feedico_coupon_meta_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['feedico_coupon_meta_nonce'] ) ), 'feedico_coupon_meta_save' ) ) {
			$code = isset( $_POST['feedico_coupon_code_input'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['feedico_coupon_code_input'] ) ) : '';
			$aff  = isset( $_POST['feedico_affiliate_url_input'] ) ? esc_url_raw( wp_unslash( (string) $_POST['feedico_affiliate_url_input'] ) ) : '';
			$end  = isset( $_POST['feedico_expires_at_input'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['feedico_expires_at_input'] ) ) : '';
			update_post_meta( $post_id, self::META_COUPON_CODE, $code );
			update_post_meta( $post_id, self::META_AFFILIATE_URL, $aff );
			update_post_meta( $post_id, self::META_EXPIRES_AT, $end );
		}

		$entity = get_post_meta( $post_id, self::META_ENTITY_ID, true );
		if ( ! is_string( $entity ) || $entity === '' ) {
			return;
		}
		$code = (string) get_post_meta( $post_id, self::META_COUPON_CODE, true );
		$aff  = (string) get_post_meta( $post_id, self::META_AFFILIATE_URL, true );
		$end  = (string) get_post_meta( $post_id, self::META_EXPIRES_AT, true );
		$plain = wp_strip_all_tags( (string) $post->post_content );
		Feedico_DB::update_coupon_from_cpt( $entity, (string) $post->post_title, $plain, $code, $aff, $end );
	}

	/**
	 * @return int Post ID or 0.
	 */
	private static function find_post_id_for_entity( string $post_type, string $entity_id ): int {
		$entity_id = trim( $entity_id );
		if ( $entity_id === '' ) {
			return 0;
		}
		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'any',
				'meta_key'               => self::META_ENTITY_ID,
				'meta_value'             => $entity_id,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return isset( $posts[0] ) ? (int) $posts[0] : 0;
	}

	public static function sync_merchant_post( string $merchant_id ): void {
		$row = Feedico_DB::get_merchant_admin_by_pk( $merchant_id );
		if ( ! is_array( $row ) ) {
			return;
		}
		$title   = isset( $row['display_name'] ) ? (string) $row['display_name'] : '';
		if ( trim( $title ) === '' ) {
			$title = isset( $row['id'] ) ? (string) $row['id'] : __( 'Store', 'feedico-sync' );
		}
		$desc_raw = isset( $row['description'] ) ? (string) $row['description'] : '';
		$content  = $desc_raw !== '' ? wpautop( esc_html( $desc_raw ) ) : '';
		$active   = ! empty( $row['wp_feedico_active'] );

		$post_id = self::find_post_id_for_entity( self::STORE_POST_TYPE, $merchant_id );
		$slug    = sanitize_title( $title . '-' . $merchant_id );
		if ( strlen( $slug ) > 180 ) {
			$slug = substr( $slug, 0, 180 );
		}

		self::$syncing_from_table = true;
		try {
			$args = array(
				'post_type'    => self::STORE_POST_TYPE,
				'post_status'  => $active ? 'publish' : 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'post_name'    => $slug,
			);
			if ( $post_id > 0 ) {
				$args['ID'] = $post_id;
				$r          = wp_update_post( wp_slash( $args ), true );
				if ( is_wp_error( $r ) ) {
					return;
				}
				update_post_meta( (int) $r, self::META_ENTITY_ID, $merchant_id );
			} else {
				$r = wp_insert_post( wp_slash( $args ), true );
				if ( is_wp_error( $r ) || ! $r ) {
					return;
				}
				update_post_meta( (int) $r, self::META_ENTITY_ID, $merchant_id );
			}
		} finally {
			self::$syncing_from_table = false;
		}
	}

	public static function sync_coupon_post( string $coupon_id ): void {
		$row = Feedico_DB::get_coupon_admin_by_pk( $coupon_id );
		if ( ! is_array( $row ) ) {
			return;
		}
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		if ( trim( $title ) === '' ) {
			$title = isset( $row['id'] ) ? (string) $row['id'] : __( 'Coupon', 'feedico-sync' );
		}
		$desc_raw = isset( $row['description'] ) ? (string) $row['description'] : '';
		$content  = $desc_raw !== '' ? wpautop( esc_html( $desc_raw ) ) : '';
		$code     = isset( $row['coupon_code'] ) ? (string) $row['coupon_code'] : '';
		$aff      = isset( $row['affiliate_url'] ) ? (string) $row['affiliate_url'] : '';
		$ends     = isset( $row['ends_at'] ) ? (string) $row['ends_at'] : '';
		$active   = ! empty( $row['wp_feedico_active'] );

		$post_id = self::find_post_id_for_entity( self::COUPON_POST_TYPE, $coupon_id );
		$slug    = sanitize_title( $title . '-' . $coupon_id );
		if ( strlen( $slug ) > 180 ) {
			$slug = substr( $slug, 0, 180 );
		}

		self::$syncing_from_table = true;
		try {
			$args = array(
				'post_type'    => self::COUPON_POST_TYPE,
				'post_status'  => $active ? 'publish' : 'draft',
				'post_title'   => $title,
				'post_content' => $content,
				'post_name'    => $slug,
			);
			if ( $post_id > 0 ) {
				$args['ID'] = $post_id;
				$r          = wp_update_post( wp_slash( $args ), true );
				if ( is_wp_error( $r ) ) {
					return;
				}
				$pid = (int) $r;
			} else {
				$r = wp_insert_post( wp_slash( $args ), true );
				if ( is_wp_error( $r ) || ! $r ) {
					return;
				}
				$pid = (int) $r;
			}
			update_post_meta( $pid, self::META_ENTITY_ID, $coupon_id );
			update_post_meta( $pid, self::META_COUPON_CODE, $code );
			update_post_meta( $pid, self::META_AFFILIATE_URL, $aff );
			update_post_meta( $pid, self::META_EXPIRES_AT, $ends );
		} finally {
			self::$syncing_from_table = false;
		}
	}
}
