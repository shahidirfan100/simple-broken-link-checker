<?php
/**
 * Settings storage and sanitization.
 *
 * @package SimpleBrokenLinkChecker
 */

namespace SimpleBrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Stores defaults and sanitizes administrator-controlled settings.
 */
final class Settings {
	const OPTION = 'sblc_settings';

	/**
	 * Return defaults for a new installation.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'batch_size'               => 5,
			'execution_seconds'        => 8,
			'timeout'                  => 12,
			'max_redirects'            => 8,
			'max_retries'              => 2,
			'max_body_bytes'           => 4096,
			'include_comments'         => false,
			'include_menus'            => true,
			'include_custom_fields'    => false,
			'include_featured_images'  => true,
			'check_internal_links'     => true,
			'check_external_links'     => true,
			'post_types'               => array(),
			'custom_meta_keys'         => array(),
			'schedule'                 => 'manual',
			'email_notifications'      => false,
			'notification_email'       => '',
			'delete_data_on_uninstall' => false,
			'excluded_domains'         => array(),
			'excluded_url_fragments'   => array(),
		);
	}

	/**
	 * Get all settings.
	 *
	 * @return array
	 */
	public static function all() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$settings = wp_parse_args( $stored, self::defaults() );
		if ( ! array_key_exists( 'schedule', $stored ) && ! empty( $stored['schedule_enabled'] ) ) {
			$settings['schedule'] = 'daily';
		}
		return $settings;
	}

	/**
	 * Get one setting.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( $key, $fallback = null ) {
		$settings = self::all();
		return array_key_exists( $key, $settings ) ? $settings[ $key ] : $fallback;
	}

	/**
	 * Save sanitized settings.
	 *
	 * @param array $input Settings input.
	 * @return array
	 */
	public static function save( $input ) {
		$previous  = self::all();
		$sanitized = self::sanitize( $input );
		update_option( self::OPTION, $sanitized, false );
		if ( 'manual' === $sanitized['schedule'] ) {
			delete_option( 'sblc_next_scan_at' );
		} elseif ( $previous['schedule'] !== $sanitized['schedule'] || ! get_option( 'sblc_next_scan_at' ) ) {
			update_option( 'sblc_next_scan_at', time() + self::schedule_interval( $sanitized['schedule'] ), false );
		}
		return $sanitized;
	}

	/**
	 * Sanitize settings exposed through REST.
	 *
	 * @param mixed $input Raw settings.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$output   = self::all();

		$integer_keys = array( 'batch_size', 'execution_seconds', 'timeout', 'max_redirects', 'max_retries', 'max_body_bytes' );
		foreach ( $integer_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = absint( $input[ $key ] );
			}
		}

		$output['batch_size']        = min( 25, max( 1, (int) $output['batch_size'] ) );
		$output['execution_seconds'] = min( 20, max( 3, (int) $output['execution_seconds'] ) );
		$output['timeout']           = min( 60, max( 3, (int) $output['timeout'] ) );
		$output['max_redirects']     = min( 15, max( 0, (int) $output['max_redirects'] ) );
		$output['max_retries']       = min( 4, max( 0, (int) $output['max_retries'] ) );
		$output['max_body_bytes']    = min( 65536, max( 1024, (int) $output['max_body_bytes'] ) );

		$boolean_keys = array( 'include_comments', 'include_menus', 'include_custom_fields', 'include_featured_images', 'check_internal_links', 'check_external_links', 'email_notifications', 'delete_data_on_uninstall' );
		foreach ( $boolean_keys as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				$output[ $key ] = ! empty( $input[ $key ] );
			}
		}

		$array_keys = array( 'post_types', 'custom_meta_keys', 'excluded_domains', 'excluded_url_fragments' );
		foreach ( $array_keys as $key ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$values         = is_array( $input[ $key ] ) ? $input[ $key ] : array();
			$values         = array_map( 'sanitize_text_field', wp_unslash( $values ) );
			$values         = array_filter( array_map( 'trim', $values ) );
			$output[ $key ] = array_values( array_unique( $values ) );
		}

		if ( array_key_exists( 'notification_email', $input ) ) {
			$output['notification_email'] = sanitize_email( $input['notification_email'] );
		}

		if ( array_key_exists( 'schedule', $input ) ) {
			$schedule           = sanitize_key( $input['schedule'] );
			$output['schedule'] = in_array( $schedule, array( 'manual', 'daily', 'weekly' ), true ) ? $schedule : 'manual';
		}

		foreach ( $defaults as $key => $default ) {
			if ( ! array_key_exists( $key, $output ) ) {
				$output[ $key ] = $default;
			}
		}
		unset( $output['schedule_enabled'] );

		return $output;
	}

	/**
	 * Return the safe delay before the next scheduled scan.
	 *
	 * @param string $schedule Schedule key.
	 * @return int
	 */
	public static function schedule_interval( $schedule ) {
		return 'weekly' === $schedule ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
	}

	/**
	 * Get post types selected for scanning.
	 *
	 * @return array
	 */
	public static function post_types() {
		$selected = self::get( 'post_types', array() );
		$public   = get_post_types( array( 'public' => true ), 'names' );
		$public   = array_values( array_diff( $public, array( 'attachment', 'nav_menu_item' ) ) );

		if ( empty( $selected ) ) {
			return $public;
		}

		return array_values( array_intersect( $selected, $public ) );
	}
}
