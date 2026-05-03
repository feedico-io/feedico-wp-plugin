<?php
/**
 * Feedico HTTP API (dashboard + list feeds).
 *
 * @package Feedico_Sync
 */

class Feedico_API {

	public const DASHBOARD_URL = 'https://api.feedico.io/api/v1/me/dashboard';
	public const API_ROOT      = 'https://api.feedico.io/api/v1';

	/**
	 * POST /me/dashboard — no Bearer; JSON email, password, token.
	 *
	 * @return array|WP_Error Decoded JSON array or error.
	 */
	public static function verify_dashboard( string $email, string $password, string $token ) {
		$body = wp_json_encode(
			array(
				'email'    => trim( $email ),
				'password' => $password,
				'token'    => trim( $token ),
			)
		);

		$response = wp_remote_post(
			self::DASHBOARD_URL,
			array(
				'timeout' => 45,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'feedico_bad_json', __( 'Invalid response from Feedico.', 'feedico-sync' ) );
		}

		if ( isset( $data['ok'] ) && true === $data['ok'] ) {
			return $data;
		}

		$err = $data['error'] ?? $data['message'] ?? __( 'Sign-in failed.', 'feedico-sync' );
		$err = is_string( $err ) ? $err : (string) $err;
		if ( $code >= 400 && $err === 'Sign-in failed.' ) {
			$err = 'HTTP ' . $code . ': ' . $err;
		}
		return new WP_Error( 'feedico_auth', $err, array( 'status' => $code ) );
	}

	/**
	 * Fetch JSON from merchants or coupons URL (Bearer + POST JSON body).
	 *
	 * @param string      $url Full URL.
	 * @param string      $email
	 * @param string      $token
	 * @param string      $password
	 * @param array       $json_body POST body when using Bearer.
	 * @return array|WP_Error
	 */
	public static function fetch_json_feed( string $url, string $email, string $token, string $password, array $json_body ) {
		$email = trim( $email );
		$token = trim( $token );

		$attempts = array();

		// 1) POST with Bearer + JSON body (preferred for /me/*).
		$r = self::request_json( $url, 'POST', $json_body, $token, null );
		if ( ! is_wp_error( $r ) ) {
			return $r;
		}
		$attempts[] = $r->get_error_message();

		// 2) GET Bearer.
		$r = self::request_json( $url, 'GET', null, $token, null );
		if ( ! is_wp_error( $r ) ) {
			return $r;
		}
		$attempts[] = $r->get_error_message();

		// 3) POST email + token.
		$r = self::request_json( $url, 'POST', array( 'email' => $email, 'token' => $token ), $token, null );
		if ( ! is_wp_error( $r ) ) {
			return $r;
		}
		$attempts[] = $r->get_error_message();

		// 4) POST email + password + token.
		if ( $password !== '' ) {
			$r = self::request_json(
				$url,
				'POST',
				array(
					'email'    => $email,
					'password' => $password,
					'token'    => $token,
				),
				$token,
				null
			);
			if ( ! is_wp_error( $r ) ) {
				return $r;
			}
			$attempts[] = $r->get_error_message();
		}

		return new WP_Error( 'feedico_fetch', implode( ' | ', $attempts ) );
	}

	/**
	 * @param array|null $body Encoded as JSON for POST.
	 */
	private static function request_json( string $url, string $method, ?array $body, string $bearer, ?callable $_unused ) {
		$headers = array(
			'Accept' => 'application/json',
		);
		if ( $bearer !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $bearer;
		}
		if ( $method === 'POST' && is_array( $body ) ) {
			$headers['Content-Type'] = 'application/json';
		}

		$args = array(
			'timeout' => 90,
			'method'  => $method,
			'headers' => $headers,
		);

		if ( $method === 'POST' && is_array( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$raw = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'feedico_bad_json', __( 'Invalid JSON from API.', 'feedico-sync' ) );
		}

		return $data;
	}

	/**
	 * Normalize list payload to array of dicts.
	 *
	 * @param mixed $payload
	 * @return array<int,array<string,mixed>>
	 */
	public static function pull_page_rows( $payload ): array {
		if ( is_array( $payload ) && array_keys( $payload ) === range( 0, count( $payload ) - 1 ) ) {
			return array_values(
				array_filter(
					$payload,
					function ( $x ) {
						return is_array( $x );
					}
				)
			);
		}
		if ( is_array( $payload ) && isset( $payload['networks'] ) && is_array( $payload['networks'] ) ) {
			return array_values(
				array_filter(
					$payload['networks'],
					function ( $x ) {
						return is_array( $x );
					}
				)
			);
		}
		if ( is_array( $payload ) && isset( $payload['coupons'] ) && is_array( $payload['coupons'] ) ) {
			return array_values(
				array_filter(
					$payload['coupons'],
					function ( $x ) {
						return is_array( $x );
					}
				)
			);
		}
		if ( is_array( $payload ) && isset( $payload['items'] ) && is_array( $payload['items'] ) ) {
			return array_values(
				array_filter(
					$payload['items'],
					function ( $x ) {
						return is_array( $x );
					}
				)
			);
		}
		return array();
	}

	public static function merchants_url(): string {
		return self::API_ROOT . '/me/networks';
	}

	public static function coupons_url(): string {
		return self::API_ROOT . '/me/coupons';
	}

	public static function networks_list_body( int $page, int $page_size, string $provider, string $firm_name = '' ): array {
		return array(
			'page'     => $page,
			'pageSize' => $page_size,
			'provider' => $provider,
			'firmName' => $firm_name,
		);
	}

	public static function coupons_list_body( int $page, int $page_size, ?string $provider, ?string $firm_name ): array {
		return array(
			'page'     => $page,
			'pageSize' => $page_size,
			'provider' => $provider,
			'firmName' => $firm_name,
		);
	}
}
