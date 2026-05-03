<?php
/**
 * Front-end shortcodes: merchants & coupons from synced tables.
 *
 * Shortcodes:
 * [feedico_merchants] — list firms (active only).
 * [feedico_coupons] — list coupons; optional merchant_id="...".
 * [feedico_merchant_page] — one merchant hero + filtered coupons (editable page shell around the shortcode).
 *
 * @package Feedico_Sync
 */

class Feedico_Public {

	public static function init(): void {
		add_shortcode( 'feedico_merchants', array( __CLASS__, 'shortcode_merchants' ) );
		add_shortcode( 'feedico_coupons', array( __CLASS__, 'shortcode_coupons' ) );
		add_shortcode( 'feedico_merchant_page', array( __CLASS__, 'shortcode_merchant_page' ) );
	}

	/**
	 * Permalink of the post/page rendering the shortcode (fallback home).
	 */
	private static function shortcode_context_url(): string {
		$id = get_the_ID();
		if ( $id ) {
			$url = get_permalink( $id );
			if ( is_string( $url ) && $url !== '' ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	private static function enqueue_public_assets(): void {
		if ( wp_style_is( 'feedico-sync-public', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style(
			'feedico-sync-public',
			FEEDICO_SYNC_URL . 'assets/public.css',
			array(),
			FEEDICO_SYNC_VERSION
		);
		wp_enqueue_script(
			'feedico-sync-public',
			FEEDICO_SYNC_URL . 'assets/public.js',
			array(),
			FEEDICO_SYNC_VERSION,
			true
		);
		wp_localize_script(
			'feedico-sync-public',
			'feedicoPub',
			array(
				'copyLabel'   => __( 'Copy code', 'feedico-sync' ),
				'copiedLabel' => __( 'Copied!', 'feedico-sync' ),
				'copyFailed'  => __( 'Could not copy', 'feedico-sync' ),
			)
		);
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode_merchants( $atts ): string {
		self::enqueue_public_assets();
		$a = shortcode_atts(
			array(
				'per_page' => '24',
				'page'     => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'feedico_merchants'
		);

		$per_page = max( 1, min( 100, (int) $a['per_page'] ) );
		$page     = isset( $_GET['fcm_p'] ) ? absint( wp_unslash( $_GET['fcm_p'] ) ) : (int) $a['page'];
		$page     = max( 1, $page );
		$search   = isset( $_GET['fcm_q'] ) ? sanitize_text_field( wp_unslash( $_GET['fcm_q'] ) ) : '';

		$total      = Feedico_DB::count_active_merchants( $search );
		$total_pages = (int) max( 1, ceil( $total / $per_page ) );
		if ( $page > $total_pages ) {
			$page = $total_pages;
		}
		$offset = ( $page - 1 ) * $per_page;
		$rows   = Feedico_DB::get_active_merchants( $per_page, $offset, $search );

		$ctx = self::shortcode_context_url();

		ob_start();
		echo '<div class="feedico-pub feedico-pub-merchants">';
		echo '<form class="feedico-pub-search" method="get" action="' . esc_url( $ctx ) . '">';
		echo '<label class="screen-reader-text" for="fcm_q">' . esc_html__( 'Search merchants', 'feedico-sync' ) . '</label>';
		echo '<input type="search" name="fcm_q" id="fcm_q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search merchants…', 'feedico-sync' ) . '" />';
		echo '<button type="submit" class="feedico-pub-search-btn">' . esc_html__( 'Search', 'feedico-sync' ) . '</button>';
		echo '</form>';
		if ( $rows === array() ) {
			echo '<p class="feedico-pub-empty">' . esc_html__( 'No merchants to show yet. Run a sync from the Feedico admin screen.', 'feedico-sync' ) . '</p>';
			echo '</div>';
			return ob_get_clean();
		}
		echo '<div class="feedico-pub-grid feedico-pub-grid--merchants">';
		foreach ( $rows as $row ) {
			$id       = isset( $row['id'] ) ? (string) $row['id'] : '';
			$title    = self::merchant_label( $row );
			$url      = isset( $row['merchant_website_url'] ) ? esc_url( $row['merchant_website_url'] ) : '';
			$prov     = isset( $row['provider'] ) ? trim( (string) $row['provider'] ) : '';
			$coupon_l = $id !== '' ? add_query_arg( array( 'fcc_mid' => $id, 'fcc_p' => 1 ), $ctx ) : '';
			echo '<article id="feedico-merchant-' . esc_attr( $id ) . '" class="feedico-pub-card feedico-pub-card--merchant">';
			echo '<h3 class="feedico-pub-card-title">' . esc_html( $title ) . '</h3>';
			if ( $prov !== '' ) {
				echo '<span class="feedico-pub-badge">' . esc_html( $prov ) . '</span>';
			}
			$coupon_n = isset( $row['coupon_count'] ) ? (int) $row['coupon_count'] : 0;
			echo '<p class="feedico-pub-merchant-couponline">';
			echo esc_html(
				sprintf(
					/* translators: %s: formatted number of coupons */
					_n( '%s active coupon', '%s active coupons', $coupon_n, 'feedico-sync' ),
					number_format_i18n( $coupon_n )
				)
			);
			echo '</p>';
			echo '<div class="feedico-pub-card-actions">';
			if ( $url !== '' ) {
				echo '<a class="feedico-pub-link feedico-pub-link--external" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Website', 'feedico-sync' ) . '</a>';
			}
			if ( $coupon_l !== '' ) {
				echo '<a class="feedico-pub-link feedico-pub-link--coupons" href="' . esc_url( $coupon_l ) . '">' . esc_html__( 'Coupons', 'feedico-sync' ) . '</a>';
			}
			echo '</div></article>';
		}
		echo '</div>';
		echo self::pagination_markup( 'fcm_p', $page, $total_pages, $search !== '' ? array( 'fcm_q' => $search ) : array() );
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode_coupons( $atts ): string {
		self::enqueue_public_assets();
		$a = shortcode_atts(
			array(
				'per_page'    => '24',
				'page'        => '1',
				'merchant_id' => '',
				'wrapper'     => '1',
				'search_form' => '0',
			),
			is_array( $atts ) ? $atts : array(),
			'feedico_coupons'
		);

		$wrap_outer    = ! in_array( strtolower( (string) $a['wrapper'] ), array( '0', 'no', 'false', 'off' ), true );
		$show_search_f = ! in_array( strtolower( (string) $a['search_form'] ), array( '0', 'no', 'false', 'off' ), true );

		$per_page = max( 1, min( 100, (int) $a['per_page'] ) );
		$page     = isset( $_GET['fcc_p'] ) ? absint( wp_unslash( $_GET['fcc_p'] ) ) : (int) $a['page'];
		$page     = max( 1, $page );
		$search   = isset( $_GET['fcc_q'] ) ? sanitize_text_field( wp_unslash( $_GET['fcc_q'] ) ) : '';

		$mid_attr = isset( $a['merchant_id'] ) ? trim( (string) $a['merchant_id'] ) : '';
		$mid_get  = isset( $_GET['fcc_mid'] ) ? sanitize_text_field( wp_unslash( $_GET['fcc_mid'] ) ) : '';
		$merchant_filter = $mid_get !== '' ? $mid_get : ( $mid_attr !== '' ? $mid_attr : null );

		$total       = Feedico_DB::count_active_coupons( $merchant_filter, $search );
		$total_pages = (int) max( 1, ceil( $total / $per_page ) );
		if ( $page > $total_pages ) {
			$page = $total_pages;
		}
		$offset = ( $page - 1 ) * $per_page;
		$rows   = Feedico_DB::get_active_coupons( $per_page, $offset, $merchant_filter, $search );

		$ctx = self::shortcode_context_url();

		$preserve = array();
		if ( $search !== '' ) {
			$preserve['fcc_q'] = $search;
		}
		if ( $merchant_filter !== null && $merchant_filter !== '' ) {
			$preserve['fcc_mid'] = $merchant_filter;
		}

		ob_start();
		if ( $wrap_outer ) {
			echo '<div class="feedico-pub feedico-pub-coupons">';
		}
		if ( $show_search_f ) {
			echo '<form class="feedico-pub-search" method="get" action="' . esc_url( $ctx ) . '">';
			if ( $merchant_filter !== null && $merchant_filter !== '' ) {
				echo '<input type="hidden" name="fcc_mid" value="' . esc_attr( $merchant_filter ) . '" />';
			}
			echo '<label class="screen-reader-text" for="fcc_q">' . esc_html__( 'Search coupons', 'feedico-sync' ) . '</label>';
			echo '<input type="search" name="fcc_q" id="fcc_q" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search coupons…', 'feedico-sync' ) . '" />';
			echo '<button type="submit" class="feedico-pub-search-btn">' . esc_html__( 'Search', 'feedico-sync' ) . '</button>';
			echo '</form>';
		}

		if ( $rows === array() ) {
			echo '<p class="feedico-pub-empty">' . esc_html__( 'No coupons match your filters.', 'feedico-sync' ) . '</p>';
			if ( $wrap_outer ) {
				echo '</div>';
			}
			return ob_get_clean();
		}
		echo '<div class="feedico-pub-grid feedico-pub-grid--coupons">';
		foreach ( $rows as $row ) {
			echo self::coupon_card_markup( $row );
		}
		echo '</div>';
		echo self::pagination_markup( 'fcc_p', $page, $total_pages, $preserve );
		if ( $wrap_outer ) {
			echo '</div>';
		}
		return ob_get_clean();
	}

	/**
	 * Single-merchant landing: hero + same coupon list as [feedico_coupons].
	 *
	 * @param array<string,string>|string $atts
	 */
	public static function shortcode_merchant_page( $atts ): string {
		self::enqueue_public_assets();
		$a = shortcode_atts(
			array(
				'merchant_id' => '',
				'per_page'    => '24',
				'search_form' => '0',
				'show_hero'   => '1',
			),
			is_array( $atts ) ? $atts : array(),
			'feedico_merchant_page'
		);

		$ref = trim( (string) $a['merchant_id'] );
		if ( $ref === '' ) {
			return '<div class="feedico-pub feedico-pub-merchant-page"><p class="feedico-pub-empty">' . esc_html__( 'Missing merchant_id on this shortcode.', 'feedico-sync' ) . '</p></div>';
		}

		$row = Feedico_DB::get_merchant_row_by_ref( $ref );
		if ( ! is_array( $row ) ) {
			return '<div class="feedico-pub feedico-pub-merchant-page"><p class="feedico-pub-empty">' . esc_html__( 'Merchant not found.', 'feedico-sync' ) . '</p></div>';
		}

		$pk = isset( $row['id'] ) ? (string) $row['id'] : '';
		if ( $pk === '' ) {
			return '<div class="feedico-pub feedico-pub-merchant-page"><p class="feedico-pub-empty">' . esc_html__( 'Merchant not found.', 'feedico-sync' ) . '</p></div>';
		}

		$show_hero = ! in_array( strtolower( (string) $a['show_hero'] ), array( '0', 'no', 'false', 'off' ), true );

		ob_start();
		echo '<div class="feedico-pub feedico-pub-merchant-page">';
		if ( $show_hero ) {
			$title = self::merchant_label( $row );
			$url   = isset( $row['merchant_website_url'] ) ? esc_url( $row['merchant_website_url'] ) : '';
			$coupon_n = isset( $row['coupon_count'] ) ? (int) $row['coupon_count'] : Feedico_DB::count_active_coupons( $pk );

			echo '<header class="feedico-pub-merchant-hero">';
			echo '<h2 class="feedico-pub-merchant-hero-title">' . esc_html( $title ) . '</h2>';
			$desc_m = isset( $row['description'] ) ? trim( wp_strip_all_tags( (string) $row['description'] ) ) : '';
			if ( $desc_m !== '' ) {
				echo '<p class="feedico-pub-merchant-desc">' . esc_html( $desc_m ) . '</p>';
			}
			echo '<p class="feedico-pub-merchant-couponline">';
			echo esc_html(
				sprintf(
					/* translators: %s: formatted number of coupons */
					_n( '%s active coupon', '%s active coupons', $coupon_n, 'feedico-sync' ),
					number_format_i18n( $coupon_n )
				)
			);
			echo '</p>';
			if ( $url !== '' ) {
				echo '<p class="feedico-pub-merchant-hero-actions"><a class="feedico-pub-link feedico-pub-link--external" href="' . $url . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Website', 'feedico-sync' ) . '</a></p>';
			}
			echo '</header>';
		}

		echo self::shortcode_coupons(
			array(
				'merchant_id' => $pk,
				'per_page'    => (string) max( 1, min( 100, (int) $a['per_page'] ) ),
				'wrapper'     => '0',
				'search_form' => (string) $a['search_form'],
			)
		);
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $row Coupon row.
	 */
	private static function coupon_card_markup( array $row ): string {
		$title      = isset( $row['title'] ) && trim( (string) $row['title'] ) !== '' ? trim( (string) $row['title'] ) : __( 'Offer', 'feedico-sync' );
		$desc       = isset( $row['description'] ) ? wp_strip_all_tags( (string) $row['description'] ) : '';
		$desc_short = $desc !== '' ? wp_trim_words( $desc, 36, '…' ) : '';
		$code       = isset( $row['coupon_code'] ) ? trim( (string) $row['coupon_code'] ) : '';
		$aff        = isset( $row['affiliate_url'] ) ? esc_url( $row['affiliate_url'] ) : '';
		$img        = isset( $row['image_url'] ) ? esc_url( $row['image_url'] ) : '';
		$disc       = self::discount_label( $row );
		$ends_raw         = isset( $row['ends_at'] ) ? trim( (string) $row['ends_at'] ) : '';
		$show_ends_pill   = $ends_raw !== '' && self::coupon_end_is_in_current_month( $ends_raw );
		$ends_disp        = $show_ends_pill ? self::format_coupon_end_for_display( $ends_raw ) : '';
		$net_name   = isset( $row['network_name'] ) ? trim( (string) $row['network_name'] ) : '';
		$pct        = self::coupon_percent_figure( $row );

		$share_page = self::shortcode_context_url();
		$tw_href    = 'https://twitter.com/intent/tweet?text=' . rawurlencode( $title ) . '&url=' . rawurlencode( $share_page );
		$fb_href    = 'https://www.facebook.com/sharer.php?u=' . rawurlencode( $share_page );

		ob_start();
		echo '<article class="feedico-pub-card feedico-pub-card--coupon">';
		if ( $img !== '' ) {
			echo '<div class="feedico-pub-coupon-img-wrap">';
			echo '<img class="feedico-pub-coupon-img" src="' . esc_url( $img ) . '" alt="' . esc_attr( $title ) . '" loading="lazy" width="400" height="225" />';
			echo '</div>';
		}
		echo '<div class="feedico-pub-coupon-body">';
		echo '<div class="feedico-pub-coupon-main">';
		echo '<div class="feedico-pub-coupon-head">';
		echo '<h3 class="feedico-pub-coupon-title">' . esc_html( $title ) . '</h3>';
		if ( $pct !== '' ) {
			echo '<div class="feedico-pub-coupon-pct-ring" aria-hidden="true"><span class="feedico-pub-coupon-pct-inner">';
			echo '<span class="feedico-pub-coupon-pct-num">' . esc_html( $pct ) . '</span>';
			echo '<span class="feedico-pub-coupon-pct-suffix">%</span>';
			echo '</span></div>';
		}
		echo '</div>';
		if ( $net_name !== '' ) {
			echo '<p class="feedico-pub-coupon-network">' . esc_html( $net_name ) . '</p>';
		}
		if ( $disc !== '' && $pct === '' ) {
			echo '<p class="feedico-pub-coupon-tagline">' . esc_html( $disc ) . '</p>';
		}
		if ( $desc_short !== '' ) {
			echo '<p class="feedico-pub-desc">' . esc_html( $desc_short ) . '</p>';
		}
		$validity_html = '';
		if ( $show_ends_pill && $ends_disp !== '' ) {
			$validity_html  = '<span class="feedico-pub-coupon-validity-pill">';
			$validity_html .= '<span class="feedico-pub-coupon-validity-label">' . esc_html__( 'Ends', 'feedico-sync' ) . '</span> ';
			$validity_html .= '<span class="feedico-pub-coupon-validity-date">' . esc_html( $ends_disp ) . '</span>';
			$validity_html .= '</span>';
		} elseif ( $ends_raw === '' ) {
			$validity_html = '<span class="feedico-pub-coupon-validity-pill feedico-pub-coupon-validity-pill--soft">' . esc_html__( 'Limited time offer', 'feedico-sync' ) . '</span>';
		}
		if ( $validity_html !== '' ) {
			echo '<p class="feedico-pub-coupon-validity">' . $validity_html . '</p>';
		}
		echo '</div>';

		echo '<div class="feedico-pub-coupon-share" role="group" aria-label="' . esc_attr__( 'Share this deal', 'feedico-sync' ) . '">';
		echo '<span class="feedico-pub-coupon-share-label">' . esc_html__( 'Share', 'feedico-sync' ) . '</span>';
		echo '<span class="feedico-pub-coupon-share-actions">';
		echo '<a class="feedico-pub-share-btn feedico-pub-share-btn--x" href="' . esc_url( $tw_href ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Share on X', 'feedico-sync' ) . '"><span class="feedico-pub-share-btn-inner">' . self::share_icon_x_svg() . '<span class="feedico-pub-share-btn-text">' . esc_html__( 'X', 'feedico-sync' ) . '</span></span></a>';
		echo '<a class="feedico-pub-share-btn feedico-pub-share-btn--fb" href="' . esc_url( $fb_href ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr__( 'Share on Facebook', 'feedico-sync' ) . '"><span class="feedico-pub-share-btn-inner">' . self::share_icon_facebook_svg() . '<span class="feedico-pub-share-btn-text">' . esc_html__( 'Facebook', 'feedico-sync' ) . '</span></span></a>';
		echo '</span>';
		echo '</div>';

		if ( $code !== '' ) {
			echo '<div class="feedico-pub-code-elite">';
			echo '<details class="feedico-pub-code-details feedico-pub-code-details--deal">';
			echo '<summary class="feedico-pub-code-summary feedico-pub-code-summary--elite">';
			echo '<span class="feedico-pub-code-elite-label">' . esc_html__( 'Promo code', 'feedico-sync' ) . '</span>';
			echo '<span class="feedico-pub-code-elite-action">';
			echo '<span class="feedico-pub-code-sum-txt feedico-pub-code-sum-txt--closed">' . esc_html__( 'Reveal code', 'feedico-sync' ) . '</span>';
			echo '<span class="feedico-pub-code-sum-txt feedico-pub-code-sum-txt--open">' . esc_html__( 'Hide', 'feedico-sync' ) . '</span>';
			echo '</span>';
			echo '<span class="feedico-pub-code-elite-chevron" aria-hidden="true"></span>';
			echo '</summary>';
			echo '<div class="feedico-pub-code-panel feedico-pub-code-panel--elite">';
			echo '<code class="feedico-pub-code-value" tabindex="0">' . esc_html( $code ) . '</code>';
			echo '<div class="feedico-pub-code-actions">';
			echo '<button type="button" class="feedico-pub-copy-code" data-code="' . esc_attr( $code ) . '"';
			if ( $aff !== '' ) {
				echo ' data-affiliate="' . esc_attr( $aff ) . '"';
			}
			echo '>' . esc_html__( 'Copy code', 'feedico-sync' ) . '</button>';
			echo '</div>';
			if ( $aff !== '' ) {
				echo '<a class="feedico-pub-coupon-apply-link" href="' . $aff . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . esc_html__( 'Open store to use code', 'feedico-sync' ) . '</a>';
			}
			echo '</div>';
			echo '</details>';
			echo '</div>';
		} elseif ( $aff !== '' ) {
			echo '<a class="feedico-pub-cta feedico-pub-cta--deal-solo" href="' . $aff . '" target="_blank" rel="nofollow sponsored noopener noreferrer">' . esc_html__( 'View deal', 'feedico-sync' ) . '</a>';
		}
		echo '</div></article>';
		return (string) ob_get_clean();
	}

	/**
	 * Whether the coupon end timestamp falls in the current calendar month (site timezone).
	 */
	private static function coupon_end_is_in_current_month( string $raw ): bool {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return false;
		}
		$ts = strtotime( $raw );
		if ( $ts === false ) {
			return false;
		}
		$tz  = wp_timezone();
		$end = wp_date( 'Y-m', $ts, $tz );
		$now = wp_date( 'Y-m', time(), $tz );
		return $end === $now;
	}

	/**
	 * Human-readable end date from API (ISO 8601, etc.).
	 */
	private static function format_coupon_end_for_display( string $raw ): string {
		$raw = trim( $raw );
		if ( $raw === '' ) {
			return '';
		}
		$ts = strtotime( $raw );
		if ( $ts === false ) {
			return $raw;
		}
		$pattern = (string) get_option( 'date_format' );
		if ( $pattern === '' ) {
			$pattern = 'M j, Y';
		}
		return wp_date( $pattern, $ts, wp_timezone() );
	}

	/**
	 * Numeric part for percent-type deals (large ring), or empty.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function coupon_percent_figure( array $row ): string {
		$type = strtolower( trim( (string) ( $row['discount_type'] ?? '' ) ) );
		$val = trim( (string) ( $row['discount_value'] ?? '' ) );
		if ( $val === '' ) {
			return self::coupon_percent_from_title( $row );
		}
		$is_pct = false;
		if ( $type !== '' ) {
			if ( strpos( $type, 'percent' ) !== false || $type === 'pct' || $type === '%' ) {
				$is_pct = true;
			}
		} elseif ( preg_match( '/^[\d.]+%$/', $val ) ) {
			$is_pct = true;
		}
		if ( ! $is_pct ) {
			return self::coupon_percent_from_title( $row );
		}
		$num = preg_replace( '/[^\d.]/', '', $val );
		if ( $num === '' || ! is_numeric( $num ) ) {
			return self::coupon_percent_from_title( $row );
		}
		return $num;
	}

	/**
	 * Parse e.g. "20%" from offer title when structured fields are missing.
	 *
	 * @param array<string,mixed> $row
	 */
	private static function coupon_percent_from_title( array $row ): string {
		$title = isset( $row['title'] ) ? (string) $row['title'] : '';
		if ( preg_match( '/(\d{1,2}(?:\.\d+)?)\s*%/', $title, $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * @param array<string,string> $extra_query_args Preserve on pagination links.
	 */
	private static function pagination_markup( string $page_key, int $page, int $total_pages, array $extra_query_args = array() ): string {
		if ( $total_pages <= 1 ) {
			return '';
		}
		$base = self::shortcode_context_url();
		ob_start();
		echo '<nav class="feedico-pub-nav" aria-label="' . esc_attr__( 'Pages', 'feedico-sync' ) . '">';
		$merge = array_merge( $extra_query_args, array() );
		if ( $page > 1 ) {
			$url = esc_url( add_query_arg( array_merge( $merge, array( $page_key => $page - 1 ) ), $base ) );
			echo '<a class="feedico-pub-nav-link feedico-pub-nav-prev" href="' . $url . '">' . esc_html__( 'Previous', 'feedico-sync' ) . '</a>';
		}
		echo '<span class="feedico-pub-nav-status">';
		echo esc_html( sprintf( /* translators: 1: current page, 2: total pages */ __( 'Page %1$d of %2$d', 'feedico-sync' ), $page, $total_pages ) );
		echo '</span>';
		if ( $page < $total_pages ) {
			$url = esc_url( add_query_arg( array_merge( $merge, array( $page_key => $page + 1 ) ), $base ) );
			echo '<a class="feedico-pub-nav-link feedico-pub-nav-next" href="' . $url . '">' . esc_html__( 'Next', 'feedico-sync' ) . '</a>';
		}
		echo '</nav>';
		return ob_get_clean();
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function merchant_label( array $row ): string {
		$n = isset( $row['display_name'] ) ? trim( (string) $row['display_name'] ) : '';
		if ( $n !== '' ) {
			return $n;
		}
		$id = isset( $row['id'] ) ? (string) $row['id'] : '';
		return $id !== '' ? $id : __( 'Merchant', 'feedico-sync' );
	}

	/**
	 * Inline SVG (X logo) for share control; uses currentColor.
	 */
	private static function share_icon_x_svg(): string {
		return '<svg class="feedico-pub-share-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>';
	}

	/**
	 * Inline SVG (Facebook) for share control; uses currentColor.
	 */
	private static function share_icon_facebook_svg(): string {
		return '<svg class="feedico-pub-share-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private static function discount_label( array $row ): string {
		$type = isset( $row['discount_type'] ) ? trim( (string) $row['discount_type'] ) : '';
		$val  = isset( $row['discount_value'] ) ? trim( (string) $row['discount_value'] ) : '';
		$cur  = isset( $row['currency_code'] ) ? trim( (string) $row['currency_code'] ) : '';
		if ( $val === '' && $type === '' ) {
			return '';
		}
		if ( $type !== '' && $val !== '' ) {
			return $cur !== '' ? strtoupper( $type ) . ' ' . $val . ' ' . $cur : strtoupper( $type ) . ' ' . $val;
		}
		return $val !== '' ? $val : $type;
	}
}
