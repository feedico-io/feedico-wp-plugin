<?php
/**
 * Encrypt / decrypt sensitive options (AES-256-CBC, key from WP salts).
 *
 * @package Feedico_Sync
 */

class Feedico_Crypto {

	/**
	 * Derive 32-byte key from WordPress salts.
	 */
	private static function key(): string {
		$raw = hash( 'sha256', wp_salt( 'auth' ) . 'feedico_sync|' . wp_salt( 'secure_auth' ), true );
		return substr( $raw, 0, 32 );
	}

	public static function encrypt( string $plain ): string {
		if ( $plain === '' ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( 'plain:' . $plain );
		}
		$iv = random_bytes( 16 );
		$enc = openssl_encrypt( $plain, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $enc ) {
			return base64_encode( 'plain:' . $plain );
		}
		return base64_encode( $iv . $enc );
	}

	public static function decrypt( string $stored ): string {
		if ( $stored === '' ) {
			return '';
		}
		$bin = base64_decode( $stored, true );
		if ( false === $bin || strlen( $bin ) < 17 ) {
			$maybe = base64_decode( $stored, true );
			if ( is_string( $maybe ) && strpos( $maybe, 'plain:' ) === 0 ) {
				return substr( $maybe, 6 );
			}
			return '';
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$iv  = substr( $bin, 0, 16 );
		$enc = substr( $bin, 16 );
		$dec = openssl_decrypt( $enc, 'aes-256-cbc', self::key(), OPENSSL_RAW_DATA, $iv );
		return false === $dec ? '' : $dec;
	}
}
