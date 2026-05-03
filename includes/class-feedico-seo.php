<?php
/**
 * Front-end SEO helpers (Feedico CPT archives and singles).
 *
 * @package Feedico_Sync
 */

class Feedico_Seo {

	public static function init(): void {
		add_filter( 'wp_robots', array( __CLASS__, 'wp_robots' ), 20 );
	}

	/**
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public static function wp_robots( array $robots ): array {
		if ( ! apply_filters( 'feedico_sync_cpt_noindex', true ) ) {
			return $robots;
		}

		$types = array( Feedico_Post_Types::STORE_POST_TYPE, Feedico_Post_Types::COUPON_POST_TYPE );
		if ( ! is_singular( $types ) && ! is_post_type_archive( $types ) ) {
			return $robots;
		}

		$robots['noindex'] = true;
		return $robots;
	}
}
