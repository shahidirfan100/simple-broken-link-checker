<?php
/**
 * URL normalization and relative URL handling.
 *
 * @package SimpleBrokenLinkChecker
 */

namespace SimpleBrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes and resolves HTTP and HTTPS URLs without destructive rewriting.
 */
final class Url {
	/**
	 * Normalize a discovered URL to an HTTP(S) resource URL.
	 *
	 * @param string $raw  Raw URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function normalize( $raw, $base = '' ) {
		$raw = html_entity_decode( (string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$raw = str_replace( array( "\xE2\x80\x8E", "\xE2\x80\x8F", "\xEF\xBB\xBF" ), '', trim( $raw ) );
		if ( '' === $raw || '#' === substr( $raw, 0, 1 ) ) {
			return '';
		}

		$parts = wp_parse_url( $raw );
		if ( false === $parts ) {
			return '';
		}
		if ( ! empty( $parts['scheme'] ) && ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return '';
		}

		if ( empty( $parts['scheme'] ) ) {
			$raw = self::make_absolute( $raw, $base ? $base : home_url( '/' ) );
		}
		$parts = wp_parse_url( $raw );
		if ( false === $parts || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			return '';
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$host = strtolower( trim( $parts['host'], '.' ) );
		if ( function_exists( 'idn_to_ascii' ) && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid IDNs remain unchanged and are rejected later by URL validation.
			$ascii = @idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			if ( $ascii ) {
				$host = strtolower( $ascii );
			}
		}

		$port = isset( $parts['port'] ) ? absint( $parts['port'] ) : 0;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = isset( $parts['query'] ) && '' !== $parts['query'] ? '?' . $parts['query'] : '';
		return $scheme . '://' . $host . ( $port ? ':' . $port : '' ) . $path . $query;
	}

	/**
	 * Resolve a relative or protocol-relative URL.
	 *
	 * @param string $url  Relative URL.
	 * @param string $base Base URL.
	 * @return string
	 */
	public static function make_absolute( $url, $base ) {
		$base_parts = wp_parse_url( $base );
		if ( false === $base_parts || empty( $base_parts['scheme'] ) || empty( $base_parts['host'] ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return $base_parts['scheme'] . ':' . $url;
		}
		$authority = $base_parts['scheme'] . '://' . $base_parts['host'];
		if ( ! empty( $base_parts['port'] ) ) {
			$authority .= ':' . absint( $base_parts['port'] );
		}
		if ( 0 === strpos( $url, '?' ) ) {
			$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
			return $authority . $base_path . $url;
		}
		if ( 0 === strpos( $url, '/' ) ) {
			$path = $url;
		} else {
			$base_path = isset( $base_parts['path'] ) ? $base_parts['path'] : '/';
			$directory = preg_replace( '#/[^/]*$#', '/', $base_path );
			$path      = $directory . $url;
		}
		$parts     = wp_parse_url( $path );
		$path_only = isset( $parts['path'] ) ? $parts['path'] : '/';
		$segments  = array();
		foreach ( explode( '/', $path_only ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $segments );
				continue;
			}
			$segments[] = $segment;
		}
		$resolved_path = '/' . implode( '/', $segments );
		if ( '/' === substr( $path_only, -1 ) && '/' !== substr( $resolved_path, -1 ) ) {
			$resolved_path .= '/';
		}
		$resolved = $authority . $resolved_path;
		if ( isset( $parts['query'] ) ) {
			$resolved .= '?' . $parts['query'];
		}
		return $resolved;
	}

	/**
	 * Hash a normalized URL.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function hash( $url ) {
		return hash( 'sha256', $url );
	}

	/**
	 * Classify a URL relative to this WordPress installation.
	 *
	 * @param string $url URL.
	 * @return string internal or external.
	 */
	public static function scope( $url ) {
		$url_host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$home_host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		return $url_host && $home_host && $url_host === $home_host ? 'internal' : 'external';
	}

	/**
	 * Resolve a Location header against the current URL.
	 *
	 * @param string $location Location value.
	 * @param string $current  Current URL.
	 * @return string
	 */
	public static function resolve_location( $location, $current ) {
		return self::normalize( $location, $current );
	}
}
