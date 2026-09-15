<?php
/**
 * Safe source editing with audit records and conflict-aware undo.
 *
 * @package SimpleBrokenLinkChecker
 */

namespace SimpleBrokenLinkChecker;

defined( 'ABSPATH' ) || exit;

/**
 * Applies audited source repairs and conflict-aware undo operations.
 */
final class Repair {
	/**
	 * Apply one supported repair.
	 *
	 * @param int    $occurrence_id Occurrence ID.
	 * @param string $operation     replace, unlink, or nofollow.
	 * @param string $new_url       Replacement URL.
	 * @return array|\WP_Error
	 */
	public static function apply( $occurrence_id, $operation, $new_url = '' ) {
		$occurrence = Database::get_occurrence( $occurrence_id );
		if ( ! $occurrence ) {
			return new \WP_Error( 'sblc_occurrence_missing', __( 'The selected source occurrence was not found.', 'simple-broken-link-checker' ), array( 'status' => 404 ) );
		}
		if ( ! $occurrence->editable ) {
			return new \WP_Error( 'sblc_source_read_only', __( 'This source is read-only. The plugin will not modify builder or metadata data automatically.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
		}
		$operation = sanitize_key( $operation );
		if ( ! in_array( $operation, array( 'replace', 'unlink', 'nofollow' ), true ) ) {
			return new \WP_Error( 'sblc_operation_invalid', __( 'This repair action is not supported.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
		}
		if ( ! self::can_edit( $occurrence ) ) {
			return new \WP_Error( 'sblc_edit_forbidden', __( 'You do not have permission to edit this source.', 'simple-broken-link-checker' ), array( 'status' => 403 ) );
		}

		$resource = Database::get_resource( $occurrence->resource_id );
		$before   = self::source_value( $occurrence );
		if ( null === $before ) {
			return new \WP_Error( 'sblc_source_missing', __( 'The source content could not be loaded.', 'simple-broken-link-checker' ), array( 'status' => 404 ) );
		}
		$target      = $resource ? $resource->url : $occurrence->raw_url;
		$replacement = '';
		if ( 'replace' === $operation ) {
			$replacement = Url::normalize( $new_url, $occurrence->source_url );
			if ( ! $replacement ) {
				return new \WP_Error( 'sblc_replacement_invalid', __( 'Enter a valid HTTP or HTTPS replacement URL.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
			}
		} elseif ( 'nofollow' === $operation ) {
			$replacement = $target;
		}

		if ( 'menu_item' === $occurrence->source_type ) {
			if ( 'replace' !== $operation ) {
				return new \WP_Error( 'sblc_menu_action_invalid', __( 'Only URL replacement is supported for menu items.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
			}
			$menu_post = get_post( $occurrence->source_id );
			$item      = $menu_post ? wp_setup_nav_menu_item( $menu_post ) : false;
			$current   = $item && isset( $item->url ) ? $item->url : '';
			if ( ! $current || ! self::urls_match( $current, $occurrence->raw_url, $occurrence->source_url ) ) {
				return new \WP_Error( 'sblc_source_changed', __( 'The menu item changed since this finding was recorded. Recheck it before editing.', 'simple-broken-link-checker' ), array( 'status' => 409 ) );
			}
			$after = $replacement;
		} else {
			$after = Extractor::edit( $before, $occurrence->raw_url, $target, $replacement, $operation, $occurrence->source_url );
			if ( is_wp_error( $after ) ) {
				return $after;
			}
			if ( $after === $before ) {
				return new \WP_Error( 'sblc_no_change', __( 'No source content was changed.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $occurrence->source_type, array( 'post', 'comment' ), true ) ) {
				return new \WP_Error( 'sblc_source_unsupported', __( 'This source type does not have a safe editor.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
			}
		}

		// Store the undo record before changing content so a successful edit can never be unaudited.
		$repair_id = Database::add_repair(
			array(
				'resource_id'     => absint( $occurrence->resource_id ),
				'occurrence_id'   => absint( $occurrence_id ),
				'operation'       => $operation,
				'source_type'     => sanitize_key( $occurrence->source_type ),
				'source_id'       => absint( $occurrence->source_id ),
				'source_field'    => sanitize_text_field( $occurrence->source_field ),
				'before_value'    => $before,
				'after_value'     => $after,
				'before_checksum' => hash( 'sha256', $before ),
				'after_checksum'  => hash( 'sha256', $after ),
				'user_id'         => get_current_user_id(),
				'created_at'      => Database::now(),
				'undone'          => 0,
				'undo_error'      => '',
			)
		);
		if ( ! $repair_id ) {
			return new \WP_Error( 'sblc_audit_failed', __( 'The source was not changed because the undo record could not be created.', 'simple-broken-link-checker' ), array( 'status' => 500 ) );
		}

		$result = self::save_source( $occurrence, $after );
		if ( is_wp_error( $result ) || false === $result || 0 === $result ) {
			Database::update_repair(
				$repair_id,
				array(
					'undone'     => 1,
					'undo_error' => __( 'The repair was not applied because WordPress could not save the source.', 'simple-broken-link-checker' ),
				)
			);
			return new \WP_Error( 'sblc_update_failed', __( 'WordPress could not save the repaired source.', 'simple-broken-link-checker' ), array( 'status' => 500 ) );
		}
		Database::update_resource(
			$occurrence->resource_id,
			array(
				'status'          => 'unverified',
				'confidence'      => 'unverified',
				'status_text'     => __( 'Recheck needed', 'simple-broken-link-checker' ),
				'explanation'     => __( 'The source was changed. Recheck this resource to collect fresh evidence.', 'simple-broken-link-checker' ),
				'checked_scan_id' => 0,
				'last_checked'    => null,
				'next_check_at'   => null,
				'retry_count'     => 0,
			)
		);
		return array(
			'repair_id' => $repair_id,
			'message'   => __( 'The source was updated and the change was recorded for undo.', 'simple-broken-link-checker' ),
		);
	}

	/**
	 * Undo one repair if its source has not changed since the repair.
	 *
	 * @param int $repair_id Repair ID.
	 * @return array|\WP_Error
	 */
	public static function undo( $repair_id ) {
		$repair = Database::get_repair( $repair_id );
		if ( ! $repair ) {
			return new \WP_Error( 'sblc_repair_missing', __( 'The repair record was not found.', 'simple-broken-link-checker' ), array( 'status' => 404 ) );
		}
		if ( $repair->undone ) {
			return new \WP_Error( 'sblc_already_undone', __( 'This repair has already been undone.', 'simple-broken-link-checker' ), array( 'status' => 400 ) );
		}
		$occurrence = Database::get_occurrence( $repair->occurrence_id );
		if ( ! $occurrence ) {
			$occurrence = (object) array(
				'source_type'  => $repair->source_type,
				'source_id'    => absint( $repair->source_id ),
				'source_field' => $repair->source_field,
				'editable'     => 1,
			);
		}
		if ( ! $occurrence || ! self::can_edit( $occurrence ) ) {
			return new \WP_Error( 'sblc_edit_forbidden', __( 'You do not have permission to undo this repair.', 'simple-broken-link-checker' ), array( 'status' => 403 ) );
		}
		$current = self::source_value( $occurrence );
		if ( null === $current || hash( 'sha256', $current ) !== $repair->after_checksum ) {
			Database::update_repair( $repair_id, array( 'undo_error' => __( 'Undo was blocked because the source changed after this repair.', 'simple-broken-link-checker' ) ) );
			return new \WP_Error( 'sblc_undo_conflict', __( 'Undo was blocked because the source changed after this repair. Review the source manually.', 'simple-broken-link-checker' ), array( 'status' => 409 ) );
		}
		$result = self::save_source( $occurrence, $repair->before_value );
		if ( is_wp_error( $result ) || false === $result || 0 === $result ) {
			return new \WP_Error( 'sblc_undo_failed', __( 'WordPress could not restore the original source.', 'simple-broken-link-checker' ), array( 'status' => 500 ) );
		}
		Database::update_repair(
			$repair_id,
			array(
				'undone'     => 1,
				'undo_error' => '',
			)
		);
		Database::update_resource(
			$repair->resource_id,
			array(
				'status'          => 'unverified',
				'confidence'      => 'unverified',
				'status_text'     => __( 'Recheck needed', 'simple-broken-link-checker' ),
				'explanation'     => __( 'The source was restored. Recheck this resource to collect fresh evidence.', 'simple-broken-link-checker' ),
				'checked_scan_id' => 0,
				'last_checked'    => null,
				'next_check_at'   => null,
				'retry_count'     => 0,
			)
		);
		return array( 'message' => __( 'The original source was restored.', 'simple-broken-link-checker' ) );
	}

	/**
	 * Load a source value.
	 *
	 * @param object $occurrence Occurrence.
	 * @return string|null
	 */
	private static function source_value( $occurrence ) {
		if ( 'post' === $occurrence->source_type ) {
			$post = get_post( $occurrence->source_id );
			return $post ? (string) $post->post_content : null;
		}
		if ( 'comment' === $occurrence->source_type ) {
			$comment = get_comment( $occurrence->source_id );
			return $comment ? (string) $comment->comment_content : null;
		}
		if ( 'menu_item' === $occurrence->source_type ) {
			$item = get_post( $occurrence->source_id );
			return $item ? (string) get_post_meta( $item->ID, '_menu_item_url', true ) : null;
		}
		return null;
	}

	/**
	 * Save a source value.
	 *
	 * @param object $occurrence Occurrence.
	 * @param string $value      Value.
	 * @return mixed
	 */
	private static function save_source( $occurrence, $value ) {
		if ( 'post' === $occurrence->source_type ) {
			return wp_update_post(
				array(
					'ID'           => absint( $occurrence->source_id ),
					'post_content' => $value,
				),
				true
			);
		}
		if ( 'comment' === $occurrence->source_type ) {
			return wp_update_comment(
				array(
					'comment_ID'      => absint( $occurrence->source_id ),
					'comment_content' => $value,
				)
			);
		}
		if ( 'menu_item' === $occurrence->source_type ) {
			$menu_id = self::menu_id_for_item( $occurrence->source_id );
			return $menu_id ? wp_update_nav_menu_item(
				$menu_id,
				$occurrence->source_id,
				array(
					'menu-item-url'    => esc_url_raw( $value ),
					'menu-item-status' => 'publish',
				)
			) : false;
		}
		return false;
	}

	/**
	 * Get the menu term containing an item.
	 *
	 * @param int $item_id Item ID.
	 * @return int
	 */
	private static function menu_id_for_item( $item_id ) {
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = wp_get_nav_menu_items( $menu->term_id );
			foreach ( (array) $items as $item ) {
				if ( absint( $item->ID ) === absint( $item_id ) ) {
					return absint( $menu->term_id );
				}
			}
		}
		return 0;
	}

	/**
	 * Permission check for the source type.
	 *
	 * @param object $occurrence Occurrence.
	 * @return bool
	 */
	private static function can_edit( $occurrence ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		if ( 'post' === $occurrence->source_type ) {
			return current_user_can( 'edit_post', absint( $occurrence->source_id ) );
		}
		if ( 'comment' === $occurrence->source_type ) {
			return current_user_can( 'edit_comment', absint( $occurrence->source_id ) );
		}
		return current_user_can( 'edit_theme_options' );
	}

	/**
	 * Compare a raw source URL with a normalized resource URL.
	 *
	 * @param string $candidate Candidate.
	 * @param string $raw       Raw recorded value.
	 * @param string $base      Base URL.
	 * @return bool
	 */
	private static function urls_match( $candidate, $raw, $base ) {
		return trim( html_entity_decode( $candidate, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) === trim( $raw ) || Url::normalize( $candidate, $base ) === Url::normalize( $raw, $base );
	}
}
