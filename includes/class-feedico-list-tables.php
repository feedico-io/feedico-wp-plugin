<?php
/**
 * WP admin list tables for synced merchants and coupons.
 *
 * @package Feedico_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Merchants grid.
 */
class Feedico_Merchants_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'merchant',
				'plural'   => 'merchants',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'display_name'         => __( 'Name', 'feedico-sync' ),
			'description'          => __( 'Description', 'feedico-sync' ),
			'id'                   => __( 'ID', 'feedico-sync' ),
			'provider'             => __( 'Provider', 'feedico-sync' ),
			'coupon_count'         => __( 'Coupons (active)', 'feedico-sync' ),
			'merchant_website_url' => __( 'Website', 'feedico-sync' ),
			'status'               => __( 'Status', 'feedico-sync' ),
			'wp_feedico_active'    => __( 'Active', 'feedico-sync' ),
			'last_synced_at'       => __( 'Last sync', 'feedico-sync' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'display_name'      => array( 'display_name', false ),
			'provider'          => array( 'provider', false ),
			'id'                => array( 'id', true ),
			'wp_feedico_active' => array( 'wp_feedico_active', false ),
			'last_synced_at'    => array( 'last_synced_at', false ),
			'status'            => array( 'status', false ),
		);
	}

	public function prepare_items() {
		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'display_name';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'ASC';

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$total       = Feedico_DB::count_merchants_admin( $search );
		$this->items = Feedico_DB::get_merchants_admin( $per_page, $offset, $search, $orderby, $order );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No merchants found. Run a sync from the main Feedico Sync screen.', 'feedico-sync' );
	}

	protected function column_display_name( $item ) {
		$n = isset( $item['display_name'] ) ? trim( (string) $item['display_name'] ) : '';
		if ( $n === '' ) {
			$n = isset( $item['id'] ) ? (string) $item['id'] : '—';
		}
		$pk      = isset( $item['id'] ) ? (string) $item['id'] : '';
		$actions = array();
		if ( $pk !== '' && current_user_can( 'manage_options' ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=feedico-sync-merchant-edit&merchant_id=' . rawurlencode( $pk ) ) ),
				esc_html__( 'Edit', 'feedico-sync' )
			);
		}
		if ( $pk !== '' && current_user_can( 'edit_pages' ) ) {
			$actions['feedico_landing'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					wp_nonce_url(
						admin_url( 'admin-post.php?action=feedico_create_merchant_landing&merchant_id=' . rawurlencode( $pk ) ),
						'feedico_create_merchant_landing'
					)
				),
				esc_html__( 'Create landing page', 'feedico-sync' )
			);
		}
		return '<strong>' . esc_html( $n ) . '</strong>' . $this->row_actions( $actions );
	}

	protected function column_description( $item ) {
		$raw = isset( $item['description'] ) ? (string) $item['description'] : '';
		$txt = trim( wp_strip_all_tags( $raw ) );
		if ( $txt === '' ) {
			return '—';
		}
		$full = $txt;
		if ( strlen( $txt ) > 200 ) {
			$txt = substr( $txt, 0, 197 ) . '…';
		}
		return '<span class="feedico-dt-excerpt" title="' . esc_attr( $full ) . '">' . esc_html( $txt ) . '</span>';
	}

	protected function column_coupon_count( $item ) {
		$n = isset( $item['coupon_count'] ) ? (int) $item['coupon_count'] : 0;
		return '<span class="feedico-dt-num" title="' . esc_attr__( 'Active coupons linked to this merchant in the database.', 'feedico-sync' ) . '">' . esc_html( number_format_i18n( $n ) ) . '</span>';
	}

	protected function column_merchant_website_url( $item ) {
		$u = isset( $item['merchant_website_url'] ) ? trim( (string) $item['merchant_website_url'] ) : '';
		if ( $u === '' ) {
			return '—';
		}
		$host = wp_parse_url( $u, PHP_URL_HOST );
		$lab  = $host !== null && $host !== false && $host !== '' ? $host : $u;
		if ( strlen( $lab ) > 48 ) {
			$lab = substr( $lab, 0, 45 ) . '…';
		}
		return '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $lab ) . '</a>';
	}

	protected function column_wp_feedico_active( $item ) {
		$on = isset( $item['wp_feedico_active'] ) && (int) $item['wp_feedico_active'] === 1;
		return $on
			? '<span class="feedico-dt-badge feedico-dt-badge--ok">' . esc_html__( 'Yes', 'feedico-sync' ) . '</span>'
			: '<span class="feedico-dt-badge feedico-dt-badge--off">' . esc_html__( 'No', 'feedico-sync' ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		if ( ! isset( $item[ $column_name ] ) ) {
			return '—';
		}
		$v = $item[ $column_name ];
		if ( (string) $v === '' ) {
			return '—';
		}
		return esc_html( (string) $v );
	}
}

/**
 * Coupons grid.
 */
class Feedico_Coupons_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'coupon',
				'plural'   => 'coupons',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'title'             => __( 'Title', 'feedico-sync' ),
			'network_name'      => __( 'Network', 'feedico-sync' ),
			'merchant_id'       => __( 'Merchant ID', 'feedico-sync' ),
			'coupon_code'       => __( 'Code', 'feedico-sync' ),
			'discount'          => __( 'Discount', 'feedico-sync' ),
			'ends_at'           => __( 'Ends', 'feedico-sync' ),
			'wp_feedico_active' => __( 'Active', 'feedico-sync' ),
			'affiliate_url'     => __( 'Offer link', 'feedico-sync' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'title'             => array( 'title', false ),
			'merchant_id'       => array( 'merchant_id', false ),
			'id'                => array( 'id', true ),
			'coupon_code'       => array( 'coupon_code', false ),
			'ends_at'           => array( 'ends_at', false ),
			'wp_feedico_active' => array( 'wp_feedico_active', false ),
			'wp_row_updated_at' => array( 'wp_row_updated_at', true ),
		);
	}

	public function prepare_items() {
		$per_page     = 30;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'wp_row_updated_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'DESC';

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$total       = Feedico_DB::count_coupons_admin( $search );
		$this->items = Feedico_DB::get_coupons_admin( $per_page, $offset, $search, $orderby, $order );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) max( 1, ceil( $total / $per_page ) ),
			)
		);
	}

	public function no_items() {
		esc_html_e( 'No coupons found. Run a sync from the main Feedico Sync screen.', 'feedico-sync' );
	}

	protected function column_title( $item ) {
		$t = isset( $item['title'] ) ? trim( (string) $item['title'] ) : '';
		if ( $t === '' ) {
			$t = isset( $item['id'] ) ? (string) $item['id'] : '—';
		}
		if ( strlen( $t ) > 80 ) {
			$t = substr( $t, 0, 77 ) . '…';
		}
		$id = isset( $item['id'] ) ? (string) $item['id'] : '';
		$sub = $id !== '' ? '<br><span class="feedico-dt-subid">' . esc_html( $id ) . '</span>' : '';
		$actions = array();
		if ( $id !== '' && current_user_can( 'manage_options' ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=feedico-sync-coupon-edit&coupon_id=' . rawurlencode( $id ) ) ),
				esc_html__( 'Edit', 'feedico-sync' )
			);
		}
		return '<strong>' . esc_html( $t ) . '</strong>' . $sub . $this->row_actions( $actions );
	}

	protected function column_network_name( $item ) {
		$n = isset( $item['network_name'] ) ? trim( (string) $item['network_name'] ) : '';
		if ( $n === '' ) {
			$fallback = isset( $item['network_id'] ) ? trim( (string) $item['network_id'] ) : '';
			if ( $fallback !== '' ) {
				return '<span class="feedico-dt-muted">' . esc_html( substr( $fallback, 0, 48 ) . ( strlen( $fallback ) > 48 ? '…' : '' ) ) . '</span>';
			}
			return '—';
		}
		if ( strlen( $n ) > 64 ) {
			$n = substr( $n, 0, 61 ) . '…';
		}
		return esc_html( $n );
	}

	protected function column_coupon_code( $item ) {
		$c = isset( $item['coupon_code'] ) ? trim( (string) $item['coupon_code'] ) : '';
		return $c !== '' ? '<code>' . esc_html( $c ) . '</code>' : '—';
	}

	protected function column_discount( $item ) {
		$type = isset( $item['discount_type'] ) ? trim( (string) $item['discount_type'] ) : '';
		$val  = isset( $item['discount_value'] ) ? trim( (string) $item['discount_value'] ) : '';
		$cur  = isset( $item['currency_code'] ) ? trim( (string) $item['currency_code'] ) : '';
		if ( $val === '' && $type === '' ) {
			return '—';
		}
		if ( $type !== '' && $val !== '' ) {
			$s = strtoupper( $type ) . ' ' . $val;
			return esc_html( $cur !== '' ? $s . ' ' . $cur : $s );
		}
		return esc_html( $val !== '' ? $val : $type );
	}

	protected function column_affiliate_url( $item ) {
		$u = isset( $item['affiliate_url'] ) ? trim( (string) $item['affiliate_url'] ) : '';
		if ( $u === '' ) {
			return '—';
		}
		return '<a href="' . esc_url( $u ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open', 'feedico-sync' ) . '</a>';
	}

	protected function column_wp_feedico_active( $item ) {
		$on = isset( $item['wp_feedico_active'] ) && (int) $item['wp_feedico_active'] === 1;
		return $on
			? '<span class="feedico-dt-badge feedico-dt-badge--ok">' . esc_html__( 'Yes', 'feedico-sync' ) . '</span>'
			: '<span class="feedico-dt-badge feedico-dt-badge--off">' . esc_html__( 'No', 'feedico-sync' ) . '</span>';
	}

	protected function column_default( $item, $column_name ) {
		if ( ! isset( $item[ $column_name ] ) ) {
			return '—';
		}
		$v = $item[ $column_name ];
		if ( (string) $v === '' ) {
			return '—';
		}
		if ( strlen( (string) $v ) > 120 ) {
			return esc_html( substr( (string) $v, 0, 117 ) . '…' );
		}
		return esc_html( (string) $v );
	}
}
