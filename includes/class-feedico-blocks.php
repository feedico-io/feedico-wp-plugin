<?php
/**
 * Block editor: dynamic blocks wrapping public shortcodes.
 *
 * @package Feedico_Sync
 */

class Feedico_Blocks {

	private const EDITOR_SCRIPT = 'feedico-block-editor';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		wp_register_script(
			self::EDITOR_SCRIPT,
			FEEDICO_SYNC_URL . 'assets/blocks-editor.js',
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components' ),
			FEEDICO_SYNC_VERSION,
			true
		);

		register_block_type(
			'feedico/merchants',
			array(
				'api_version'     => 2,
				'title'           => __( 'Feedico merchants', 'feedico-sync' ),
				'category'        => 'widgets',
				'icon'            => 'store',
				'attributes'      => array(
					'perPage' => array(
						'type'    => 'integer',
						'default' => 24,
					),
				),
				'editor_script'   => self::EDITOR_SCRIPT,
				'render_callback' => array( __CLASS__, 'render_merchants' ),
			)
		);

		register_block_type(
			'feedico/coupons',
			array(
				'api_version'     => 2,
				'title'           => __( 'Feedico coupons', 'feedico-sync' ),
				'category'        => 'widgets',
				'icon'            => 'tickets-alt',
				'attributes'      => array(
					'merchantId' => array(
						'type'    => 'string',
						'default' => '',
					),
					'perPage'    => array(
						'type'    => 'integer',
						'default' => 24,
					),
					'showSearch' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'wrapOuter'  => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'editor_script'   => self::EDITOR_SCRIPT,
				'render_callback' => array( __CLASS__, 'render_coupons' ),
			)
		);

		register_block_type(
			'feedico/merchant-page',
			array(
				'api_version'     => 2,
				'title'           => __( 'Feedico merchant page', 'feedico-sync' ),
				'category'        => 'widgets',
				'icon'            => 'id-alt',
				'attributes'      => array(
					'merchantId' => array(
						'type'    => 'string',
						'default' => '',
					),
					'perPage'    => array(
						'type'    => 'integer',
						'default' => 24,
					),
					'showSearch' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'showHero'   => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
				'editor_script'   => self::EDITOR_SCRIPT,
				'render_callback' => array( __CLASS__, 'render_merchant_page' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public static function render_merchants( array $attributes ): string {
		$p = isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 24;
		$p = max( 1, min( 100, $p ) );
		return Feedico_Public::shortcode_merchants( array( 'per_page' => (string) $p ) );
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public static function render_coupons( array $attributes ): string {
		$p   = isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 24;
		$p   = max( 1, min( 100, $p ) );
		$mid = isset( $attributes['merchantId'] ) ? trim( (string) $attributes['merchantId'] ) : '';
		$wrap = ! empty( $attributes['wrapOuter'] );
		$search = ! empty( $attributes['showSearch'] );
		return Feedico_Public::shortcode_coupons(
			array(
				'per_page'    => (string) $p,
				'merchant_id' => $mid,
				'wrapper'     => $wrap ? '1' : '0',
				'search_form' => $search ? '1' : '0',
			)
		);
	}

	/**
	 * @param array<string,mixed> $attributes
	 */
	public static function render_merchant_page( array $attributes ): string {
		$mid = isset( $attributes['merchantId'] ) ? trim( (string) $attributes['merchantId'] ) : '';
		if ( $mid === '' ) {
			return '<p class="feedico-pub feedico-pub-empty">' . esc_html__( 'Set a merchant ID in the block sidebar.', 'feedico-sync' ) . '</p>';
		}
		$p = isset( $attributes['perPage'] ) ? (int) $attributes['perPage'] : 24;
		$p = max( 1, min( 100, $p ) );
		$search = ! empty( $attributes['showSearch'] );
		$hero   = array_key_exists( 'showHero', $attributes ) ? (bool) $attributes['showHero'] : true;
		return Feedico_Public::shortcode_merchant_page(
			array(
				'merchant_id' => $mid,
				'per_page'    => (string) $p,
				'search_form' => $search ? '1' : '0',
				'show_hero'   => $hero ? '1' : '0',
			)
		);
	}
}
