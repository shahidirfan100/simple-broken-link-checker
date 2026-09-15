<?php
/**
 * Evidence-based local HTTP verifier.
 *
 * @package SimpleBrokenLinkChecker
 */

namespace SimpleBrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Verifies one URL with bounded requests and evidence-based classification.
 */
final class Http_Checker {
	/**
	 * Check one normalized URL.
	 *
	 * @param string $url         URL.
	 * @param int    $retry_count Previous retry count.
	 * @return array
	 */
	public static function check( $url, $retry_count = 0 ) {
		$settings    = Settings::all();
		$max_retries = absint( $settings['max_retries'] );
		$safety      = Ssrf_Guard::validate( $url );
		if ( empty( $safety['safe'] ) ) {
			$category    = ! empty( $safety['code'] ) ? sanitize_key( $safety['code'] ) : 'unsafe_request';
			$status_text = 'dns_error' === $category ? __( 'DNS error', 'simple-broken-link-checker' ) : __( 'Request not sent', 'simple-broken-link-checker' );
			$explanation = 'dns_error' === $category ? __( 'The domain could not be resolved from this server, so no request was sent.', 'simple-broken-link-checker' ) : __( 'This URL was not requested because it failed the local safety checks.', 'simple-broken-link-checker' );
			return self::result( 'unverified', 'unverified', 0, $url, $category, $status_text, $explanation, array(), array(), 0, $retry_count );
		}

		$history        = array();
		$redirects      = array();
		$visited        = array( Url::hash( $url ) => true );
		$current        = $url;
		$total_time     = 0.0;
		$redirect_count = 0;
		$max_redirects  = absint( $settings['max_redirects'] );
		$final_response = null;

		while ( true ) {
			$head        = self::request( $current, 'HEAD', $settings );
			$total_time += (float) $head['duration'];
			$history[]   = self::history_item( 'HEAD', $current, $head );
			$response    = $head;

			/*
			 * HEAD is only an optimization. Many otherwise healthy sites block it,
			 * close it without a response, or return an error page only for HEAD.
			 * Confirm every non-successful HEAD result with a small bounded GET.
			 */
			if ( $head['error'] || $head['code'] < 200 || $head['code'] >= 400 ) {
				$get         = self::request( $current, 'GET', $settings );
				$total_time += (float) $get['duration'];
				$history[]   = self::history_item( 'GET', $current, $get );
				$response    = $get;
			}

			$final_response = $response;
			$code           = absint( $response['code'] );
			if ( $response['error'] || $code < 300 || $code >= 400 ) {
				break;
			}

			$location = wp_remote_retrieve_header( $response['response'], 'location' );
			$next     = $location ? Url::resolve_location( $location, $current ) : '';
			if ( ! $next ) {
				break;
			}
			if ( $redirect_count >= $max_redirects ) {
				/* translators: %d: Maximum number of allowed redirect hops. */
				return self::result( 'redirect', 'likely', $code, $url, 'too_many_redirects', self::status_name( $code ), sprintf( __( 'The link redirected more than the configured limit of %d hops.', 'simple-broken-link-checker' ), $max_redirects ), $redirects, $history, $total_time, 0, $current, $redirect_count );
			}
			$next_safety = Ssrf_Guard::validate( $next );
			if ( empty( $next_safety['safe'] ) ) {
				return self::result( 'unverified', 'unverified', $code, $url, 'unsafe_redirect', self::status_name( $code ), __( 'The redirect chain was stopped because its next destination failed the local safety checks.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $url, 0 );
			}
			$next_hash   = Url::hash( $next );
			$redirects[] = array(
				'from'   => $current,
				'status' => $code,
				'to'     => $next,
			);
			if ( isset( $visited[ $next_hash ] ) ) {
				return self::result( 'redirect', 'likely', $code, $url, 'redirect_loop', __( 'Redirect loop', 'simple-broken-link-checker' ), __( 'The redirect chain returned to a URL that was already visited.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $next, ++$redirect_count );
			}
			$visited[ $next_hash ] = true;
			$current               = $next;
			++$redirect_count;
		}

		$code = $final_response ? absint( $final_response['code'] ) : 0;
		if ( $final_response && $final_response['error'] ) {
			return self::network_result( $final_response['error_code'], $final_response['message'], $url, $redirects, $history, $total_time, $retry_count, $redirect_count, $max_retries );
		}
		if ( $code >= 200 && $code < 300 ) {
			if ( $redirect_count > 0 ) {
				return self::result( 'redirect', 'confirmed', $code, $url, 'redirect', self::status_name( $code ), __( 'The URL works, but it reaches a different destination through a redirect chain.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
			}
			return self::result( 'healthy', 'confirmed', $code, $url, '', self::status_name( $code ), __( 'The resource responded successfully.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, 0 );
		}
		if ( $code >= 300 && $code < 400 ) {
			return self::result( 'redirect', 'likely', $code, $url, 'redirect', self::status_name( $code ), __( 'The server returned a redirect that could not be fully followed.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, max( 1, $redirect_count ) );
		}
		if ( 404 === $code || 410 === $code ) {
			/* translators: %d: HTTP response status code, such as 404 or 410. */
			return self::result( 'broken', 'confirmed', $code, $url, 'http_' . $code, self::status_name( $code ), sprintf( __( 'The target returned definitive HTTP %d evidence. It is highly likely that the resource no longer exists.', 'simple-broken-link-checker' ), $code ), $redirects, $history, $total_time, 0, $current, $redirect_count );
		}
		if ( 429 === $code ) {
			$next_retry   = $retry_count + 1;
			$stored_retry = min( $max_retries, $next_retry );
			$delay        = $next_retry <= $max_retries ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS;
			return self::result( 'rate_limited', 'unverified', $code, $url, 'http_429', self::status_name( $code ), __( 'The remote server asked the scanner to slow down. This does not mean the link is broken.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, $stored_retry, $current, $redirect_count, time() + $delay );
		}
		if ( $code >= 500 && $code <= 599 ) {
			$next_retry   = $retry_count + 1;
			$stored_retry = min( $max_retries, $next_retry );
			$delay        = $next_retry <= $max_retries ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS;
			return self::result( 'temporary_error', 'unverified', $code, $url, 'http_' . $code, self::status_name( $code ), __( 'The remote server returned a temporary error. The scanner will leave this for a later recheck instead of calling it broken.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, $stored_retry, $current, $redirect_count, time() + $delay );
		}
		if ( in_array( $code, array( 401, 403, 405, 406, 407, 451 ), true ) ) {
			return self::result( 'blocked', 'unverified', $code, $url, 'http_' . $code, self::status_name( $code ), __( 'The remote website rejected automated verification. This does not necessarily mean the link is broken.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
		}
		return self::result( 'unverified', 'unverified', $code, $url, $code ? 'http_' . $code : 'no_response', self::status_name( $code ), __( 'The scanner could not obtain enough evidence to call this link broken.', 'simple-broken-link-checker' ), $redirects, $history, $total_time, 0, $current, $redirect_count );
	}

	/**
	 * Make a single bounded request without following redirects.
	 *
	 * @param string $url      URL.
	 * @param string $method   HEAD or GET.
	 * @param array  $settings Settings.
	 * @return array
	 */
	private static function request( $url, $method, $settings ) {
		$start = microtime( true );
		$args  = array(
			'method'              => $method,
			'timeout'             => absint( $settings['timeout'] ),
			'connect_timeout'     => absint( $settings['timeout'] ),
			'redirection'         => 0,
			'limit_response_size' => absint( $settings['max_body_bytes'] ),
			'sslverify'           => true,
			'user-agent'          => 'Mozilla/5.0 (compatible; Simple Broken Link Checker/' . SBLC_VERSION . '; +' . home_url( '/' ) . ')',
			'headers'             => array(
				'Accept'     => '*/*',
				'Connection' => 'close',
			),
		);
		if ( 'GET' === $method ) {
			$args['headers']['Range'] = 'bytes=0-' . max( 0, absint( $settings['max_body_bytes'] ) - 1 );
		}
		$home = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $home && $host && strtolower( $home ) === strtolower( $host ) ) {
			$response = wp_remote_request( $url, $args );
		} else {
			$response = wp_safe_remote_request( $url, $args );
		}
		$duration = microtime( true ) - $start;
		if ( is_wp_error( $response ) ) {
			return array(
				'response'   => array(),
				'code'       => 0,
				'message'    => self::safe_error_message( $response ),
				'error'      => true,
				'error_code' => self::error_code( $response ),
				'duration'   => $duration,
			);
		}
		return array(
			'response'   => $response,
			'code'       => (int) wp_remote_retrieve_response_code( $response ),
			'message'    => sanitize_text_field( wp_remote_retrieve_response_message( $response ) ),
			'error'      => false,
			'error_code' => '',
			'duration'   => $duration,
		);
	}

	/**
	 * Make a compact request-history entry.
	 *
	 * @param string $method Method.
	 * @param string $url    URL.
	 * @param array  $item   Request result.
	 * @return array
	 */
	private static function history_item( $method, $url, $item ) {
		return array(
			'method'   => $method,
			'url'      => $url,
			'status'   => absint( $item['code'] ),
			'message'  => $item['error'] ? $item['error_code'] : $item['message'],
			'duration' => round( (float) $item['duration'], 3 ),
		);
	}

	/**
	 * Convert transport errors into user-safe categories.
	 *
	 * @param string $error_code Error code.
	 * @param string $message     Error message.
	 * @param string $url         URL.
	 * @param array  $redirects   Redirects.
	 * @param array  $history     History.
	 * @param float  $duration    Duration.
	 * @param int    $retry_count Retry count.
	 * @param int    $redirect_count Redirect count.
	 * @param int    $max_retries   Retry limit.
	 * @return array
	 */
	private static function network_result( $error_code, $message, $url, $redirects, $history, $duration, $retry_count, $redirect_count, $max_retries ) {
		$haystack     = strtolower( $error_code . ' ' . $message );
		$status       = 'unverified';
		$category     = 'network_error';
		$title        = __( 'Network error', 'simple-broken-link-checker' );
		$explanation  = __( 'The scanner could not complete a safe request. This is not enough evidence to call the link broken.', 'simple-broken-link-checker' );
		$next_retry   = $retry_count + 1;
		$stored_retry = min( absint( $max_retries ), $next_retry );
		$next         = time() + ( $next_retry <= absint( $max_retries ) ? min( 3600, 120 * max( 1, $next_retry ) ) : DAY_IN_SECONDS );
		if ( false !== strpos( $haystack, 'ssl' ) || false !== strpos( $haystack, 'certificate' ) ) {
			$status      = 'ssl_error';
			$category    = 'ssl_error';
			$title       = __( 'SSL error', 'simple-broken-link-checker' );
			$explanation = __( 'The HTTPS certificate or TLS negotiation could not be verified.', 'simple-broken-link-checker' );
		} elseif ( false !== strpos( $haystack, 'resolve' ) || false !== strpos( $haystack, 'dns' ) || false !== strpos( $haystack, 'host' ) ) {
			$status      = 'dns_error';
			$category    = 'dns_error';
			$title       = __( 'DNS error', 'simple-broken-link-checker' );
			$explanation = __( 'The domain could not be resolved from this server.', 'simple-broken-link-checker' );
		} elseif ( false !== strpos( $haystack, 'timed out' ) || false !== strpos( $haystack, 'timeout' ) || false !== strpos( $haystack, 'curl error 28' ) ) {
			$status      = 'timeout';
			$category    = 'timeout';
			$title       = __( 'Timeout', 'simple-broken-link-checker' );
			$explanation = __( 'The server did not respond within the configured timeout.', 'simple-broken-link-checker' );
		}
		return self::result( $status, 'unverified', 0, $url, $category, $title, $explanation, $redirects, $history, $duration, $stored_retry, $url, $redirect_count, $next );
	}

	/**
	 * Return a normalized result structure.
	 *
	 * @param string $status Status.
	 * @param string $confidence Confidence.
	 * @param int    $code HTTP code.
	 * @param string $url Original URL.
	 * @param string $error_code Error code.
	 * @param string $status_text Status text.
	 * @param string $explanation Explanation.
	 * @param array  $redirects Redirects.
	 * @param array  $history Request history.
	 * @param float  $duration Duration.
	 * @param int    $retry_count Retry count.
	 * @param string $final_url Final URL.
	 * @param int    $redirect_count Redirect count.
	 * @param int    $next_check_at Unix timestamp.
	 * @return array
	 */
	private static function result( $status, $confidence, $code, $url, $error_code, $status_text, $explanation, $redirects, $history, $duration, $retry_count, $final_url = '', $redirect_count = 0, $next_check_at = 0 ) {
		return array(
			'status'          => $status,
			'confidence'      => $confidence,
			'http_code'       => absint( $code ),
			'error_code'      => $error_code,
			'status_text'     => $status_text,
			'explanation'     => $explanation,
			'final_url'       => $final_url ? $final_url : $url,
			'redirect_chain'  => $redirects,
			'request_history' => $history,
			'response_time'   => round( (float) $duration, 3 ),
			'retry_count'     => absint( $retry_count ),
			'next_check_at'   => $next_check_at ? gmdate( 'Y-m-d H:i:s', $next_check_at ) : null,
			'redirect_count'  => absint( $redirect_count ),
		);
	}

	/**
	 * Get the safe WordPress error code.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function error_code( $error ) {
		$codes = $error->get_error_codes();
		return ! empty( $codes ) ? sanitize_key( reset( $codes ) ) : 'http_request_failed';
	}

	/**
	 * Reduce transport details to a non-sensitive message.
	 *
	 * @param \WP_Error $error Error.
	 * @return string
	 */
	private static function safe_error_message( $error ) {
		$message = sanitize_text_field( $error->get_error_message() );
		return strlen( $message ) > 160 ? substr( $message, 0, 160 ) : $message;
	}

	/**
	 * Friendly HTTP status name.
	 *
	 * @param int $code HTTP code.
	 * @return string
	 */
	private static function status_name( $code ) {
		$names = array(
			200 => 'OK',
			201 => 'Created',
			204 => 'No Content',
			301 => 'Moved Permanently',
			302 => 'Found',
			303 => 'See Other',
			307 => 'Temporary Redirect',
			308 => 'Permanent Redirect',
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			410 => 'Gone',
			429 => 'Too Many Requests',
			500 => 'Internal Server Error',
			502 => 'Bad Gateway',
			503 => 'Service Unavailable',
			504 => 'Gateway Timeout',
		);
		return isset( $names[ $code ] ) ? $names[ $code ] : ( $code ? 'HTTP ' . absint( $code ) : __( 'No response', 'simple-broken-link-checker' ) );
	}
}
