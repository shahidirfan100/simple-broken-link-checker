<?php
/**
 * WordPress admin screen and release export.
 *
 * @package SimpleBrokenLinkChecker
 */

namespace SimpleBrokenLinkChecker\Admin;

use SimpleBrokenLinkChecker\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and renders the plugin's administrator interface.
 */
final class Admin {
	/**
	 * Add the focused admin menu.
	 *
	 * @return void
	 */
	public static function menu() {
		add_menu_page( __( 'Simple Broken Link Checker', 'simple-broken-link-checker' ), __( 'Link Checker', 'simple-broken-link-checker' ), 'manage_options', 'sblc-dashboard', array( __CLASS__, 'render' ), 'dashicons-admin-links', 35 );
		add_submenu_page( 'sblc-dashboard', __( 'Link Checker', 'simple-broken-link-checker' ), __( 'Findings', 'simple-broken-link-checker' ), 'manage_options', 'sblc-dashboard', array( __CLASS__, 'render' ) );
		add_submenu_page( 'sblc-dashboard', __( 'Settings', 'simple-broken-link-checker' ), __( 'Settings', 'simple-broken-link-checker' ), 'manage_options', 'sblc-settings', array( __CLASS__, 'render' ) );
	}

	/**
	 * Enqueue assets only on plugin pages.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, array( 'toplevel_page_sblc-dashboard', 'link-checker_page_sblc-settings' ), true ) ) {
			return;
		}
		$page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS );
		$page = $page ? sanitize_key( $page ) : '';
		wp_enqueue_style( 'sblc-admin', SBLC_PLUGIN_URL . 'assets/css/admin.css', array(), SBLC_VERSION );
		wp_enqueue_script( 'sblc-admin', SBLC_PLUGIN_URL . 'assets/js/admin.js', array(), SBLC_VERSION, true );
		wp_localize_script(
			'sblc-admin',
			'SBLC_DATA',
			array(
				'restUrl'   => esc_url_raw( rest_url( 'sblc/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'exportUrl' => esc_url_raw( admin_url( 'admin-post.php?action=sblc_export&_wpnonce=' . wp_create_nonce( 'sblc_export' ) ) ),
				'page'      => 'sblc-settings' === $page ? 'settings' : 'findings',
				'homeUrl'   => esc_url_raw( home_url( '/' ) ),
				'logoUrl'   => esc_url_raw( SBLC_PLUGIN_URL . 'assets/images/sblc-mark.svg' ),
				'strings'   => array(
					'The request could not be completed.' => __( 'The request could not be completed.', 'simple-broken-link-checker' ),
					'Discovering sources'                 => __( 'Discovering sources', 'simple-broken-link-checker' ),
					'Verifying resources'                 => __( 'Verifying resources', 'simple-broken-link-checker' ),
					'Scan progress'                       => __( 'Scan progress', 'simple-broken-link-checker' ),
					'Stop scan'                           => __( 'Stop scan', 'simple-broken-link-checker' ),
					'All'                                 => __( 'All', 'simple-broken-link-checker' ),
					'Broken'                              => __( 'Broken', 'simple-broken-link-checker' ),
					'Redirects'                           => __( 'Redirects', 'simple-broken-link-checker' ),
					'Needs review'                        => __( 'Needs review', 'simple-broken-link-checker' ),
					'Blocked'                             => __( 'Blocked', 'simple-broken-link-checker' ),
					'Healthy'                             => __( 'Healthy', 'simple-broken-link-checker' ),
					'Ignored'                             => __( 'Ignored', 'simple-broken-link-checker' ),
					'Unverified'                          => __( 'Unverified', 'simple-broken-link-checker' ),
					'Queued'                              => __( 'Queued', 'simple-broken-link-checker' ),
					'Link scope'                          => __( 'Link scope', 'simple-broken-link-checker' ),
					'All links'                           => __( 'All links', 'simple-broken-link-checker' ),
					'Internal links'                      => __( 'Internal links', 'simple-broken-link-checker' ),
					'External links'                      => __( 'External links', 'simple-broken-link-checker' ),
					'Internal'                            => __( 'Internal', 'simple-broken-link-checker' ),
					'External'                            => __( 'External', 'simple-broken-link-checker' ),
					'Check internal links'                => __( 'Check internal links', 'simple-broken-link-checker' ),
					'Check external links'                => __( 'Check external links', 'simple-broken-link-checker' ),
					'Rate Limited'                        => __( 'Rate Limited', 'simple-broken-link-checker' ),
					'Temporary Error'                     => __( 'Temporary Error', 'simple-broken-link-checker' ),
					'SSL Error'                           => __( 'SSL Error', 'simple-broken-link-checker' ),
					'DNS Error'                           => __( 'DNS Error', 'simple-broken-link-checker' ),
					'Timeout'                             => __( 'Timeout', 'simple-broken-link-checker' ),
					'Findings'                            => __( 'Findings', 'simple-broken-link-checker' ),
					'One request per unique URL, with every source location retained.' => __( 'One request per unique URL, with every source location retained.', 'simple-broken-link-checker' ),
					'Run new scan'                        => __( 'Run new scan', 'simple-broken-link-checker' ),
					'Export CSV'                          => __( 'Export CSV', 'simple-broken-link-checker' ),
					'Finding summary'                     => __( 'Finding summary', 'simple-broken-link-checker' ),
					'Unique URLs'                         => __( 'Unique URLs', 'simple-broken-link-checker' ),
					'Occurrences'                         => __( 'Occurrences', 'simple-broken-link-checker' ),
					'Search URL'                          => __( 'Search URL', 'simple-broken-link-checker' ),
					'Resource type'                       => __( 'Resource type', 'simple-broken-link-checker' ),
					'All types'                           => __( 'All types', 'simple-broken-link-checker' ),
					'Links'                               => __( 'Links', 'simple-broken-link-checker' ),
					'Images'                              => __( 'Images', 'simple-broken-link-checker' ),
					'Bulk action'                         => __( 'Bulk action', 'simple-broken-link-checker' ),
					'Bulk actions'                        => __( 'Bulk actions', 'simple-broken-link-checker' ),
					'Queue recheck'                       => __( 'Queue recheck', 'simple-broken-link-checker' ),
					'Ignore'                              => __( 'Ignore', 'simple-broken-link-checker' ),
					'Restore'                             => __( 'Restore', 'simple-broken-link-checker' ),
					'Apply'                               => __( 'Apply', 'simple-broken-link-checker' ),
					'Bulk URL replacement is intentionally unavailable.' => __( 'Bulk URL replacement is intentionally unavailable.', 'simple-broken-link-checker' ),
					'Select all'                          => __( 'Select all', 'simple-broken-link-checker' ),
					'Status'                              => __( 'Status', 'simple-broken-link-checker' ),
					'URL'                                 => __( 'URL', 'simple-broken-link-checker' ),
					'HTTP'                                => __( 'HTTP', 'simple-broken-link-checker' ),
					'Type'                                => __( 'Type', 'simple-broken-link-checker' ),
					'Sources'                             => __( 'Sources', 'simple-broken-link-checker' ),
					'Action'                              => __( 'Action', 'simple-broken-link-checker' ),
					'Showing'                             => __( 'Showing', 'simple-broken-link-checker' ),
					'of'                                  => __( 'of', 'simple-broken-link-checker' ),
					'Previous'                            => __( 'Previous', 'simple-broken-link-checker' ),
					'Next'                                => __( 'Next', 'simple-broken-link-checker' ),
					'source'                              => __( 'source', 'simple-broken-link-checker' ),
					'sources'                             => __( 'sources', 'simple-broken-link-checker' ),
					'Evidence'                            => __( 'Evidence', 'simple-broken-link-checker' ),
					'Settings'                            => __( 'Settings', 'simple-broken-link-checker' ),
					'Requests stay on this WordPress server. The plugin does not use an account, cloud scanner, tracking, or link credits.' => __( 'Requests stay on this WordPress server. The plugin does not use an account, cloud scanner, tracking, or link credits.', 'simple-broken-link-checker' ),
					'Verification safety'                 => __( 'Verification safety', 'simple-broken-link-checker' ),
					'Resources per worker request'        => __( 'Resources per worker request', 'simple-broken-link-checker' ),
					'Small batches keep wp-admin responsive.' => __( 'Small batches keep wp-admin responsive.', 'simple-broken-link-checker' ),
					'Request timeout, seconds'            => __( 'Request timeout, seconds', 'simple-broken-link-checker' ),
					'Maximum redirect hops'               => __( 'Maximum redirect hops', 'simple-broken-link-checker' ),
					'Temporary failure retries'           => __( 'Temporary failure retries', 'simple-broken-link-checker' ),
					'Content sources'                     => __( 'Content sources', 'simple-broken-link-checker' ),
					'Public post types'                   => __( 'Public post types', 'simple-broken-link-checker' ),
					'Leave all unchecked to include every public post type except attachments and menu items.' => __( 'Leave all unchecked to include every public post type except attachments and menu items.', 'simple-broken-link-checker' ),
					'Scan approved comments'              => __( 'Scan approved comments', 'simple-broken-link-checker' ),
					'Scan navigation menu URLs'           => __( 'Scan navigation menu URLs', 'simple-broken-link-checker' ),
					'Check featured images'               => __( 'Check featured images', 'simple-broken-link-checker' ),
					'Read scalar custom-field and builder data' => __( 'Read scalar custom-field and builder data', 'simple-broken-link-checker' ),
					'Limit custom-field keys (one per line, optional)' => __( 'Limit custom-field keys (one per line, optional)', 'simple-broken-link-checker' ),
					'Builder metadata is read-only in this release; it is never rewritten blindly.' => __( 'Builder metadata is read-only in this release; it is never rewritten blindly.', 'simple-broken-link-checker' ),
					'Exclusions and lifecycle'            => __( 'Exclusions and lifecycle', 'simple-broken-link-checker' ),
					'Excluded domains (one per line)'     => __( 'Excluded domains (one per line)', 'simple-broken-link-checker' ),
					'Excluded URL fragments (one per line)' => __( 'Excluded URL fragments (one per line)', 'simple-broken-link-checker' ),
					'Scheduled scans'                     => __( 'Scheduled scans', 'simple-broken-link-checker' ),
					'Manual only'                         => __( 'Manual only', 'simple-broken-link-checker' ),
					'Daily'                               => __( 'Daily', 'simple-broken-link-checker' ),
					'Weekly'                              => __( 'Weekly', 'simple-broken-link-checker' ),
					'Scheduled work starts a bounded scan from WP-Cron. Manual scanning remains available if cron is delayed.' => __( 'Scheduled work starts a bounded scan from WP-Cron. Manual scanning remains available if cron is delayed.', 'simple-broken-link-checker' ),
					'Email a local summary when a scan completes' => __( 'Email a local summary when a scan completes', 'simple-broken-link-checker' ),
					'Notification email'                  => __( 'Notification email', 'simple-broken-link-checker' ),
					'Delete findings when the plugin is uninstalled' => __( 'Delete findings when the plugin is uninstalled', 'simple-broken-link-checker' ),
					'Save settings'                       => __( 'Save settings', 'simple-broken-link-checker' ),
					'Replacement URL'                     => __( 'Replacement URL', 'simple-broken-link-checker' ),
					'Replace URL'                         => __( 'Replace URL', 'simple-broken-link-checker' ),
					'Add nofollow'                        => __( 'Add nofollow', 'simple-broken-link-checker' ),
					'Unlink'                              => __( 'Unlink', 'simple-broken-link-checker' ),
					'Read-only source.'                   => __( 'Read-only source.', 'simple-broken-link-checker' ),
					'This source can be reviewed but is not automatically edited.' => __( 'This source can be reviewed but is not automatically edited.', 'simple-broken-link-checker' ),
					'Untitled source'                     => __( 'Untitled source', 'simple-broken-link-checker' ),
					'Open source'                         => __( 'Open source', 'simple-broken-link-checker' ),
					'Undone'                              => __( 'Undone', 'simple-broken-link-checker' ),
					'Undo'                                => __( 'Undo', 'simple-broken-link-checker' ),
					'Close evidence'                      => __( 'Close evidence', 'simple-broken-link-checker' ),
					'Recheck'                             => __( 'Recheck', 'simple-broken-link-checker' ),
					'Mark manually verified'              => __( 'Mark manually verified', 'simple-broken-link-checker' ),
					'Classification'                      => __( 'Classification', 'simple-broken-link-checker' ),
					'Confidence'                          => __( 'Confidence', 'simple-broken-link-checker' ),
					'HTTP result'                         => __( 'HTTP result', 'simple-broken-link-checker' ),
					'Final URL'                           => __( 'Final URL', 'simple-broken-link-checker' ),
					'Response time'                       => __( 'Response time', 'simple-broken-link-checker' ),
					'Last checked'                        => __( 'Last checked', 'simple-broken-link-checker' ),
					'Not checked'                         => __( 'Not checked', 'simple-broken-link-checker' ),
					'Explanation'                         => __( 'Explanation', 'simple-broken-link-checker' ),
					'Request history'                     => __( 'Request history', 'simple-broken-link-checker' ),
					'No request history.'                 => __( 'No request history.', 'simple-broken-link-checker' ),
					'Redirect chain'                      => __( 'Redirect chain', 'simple-broken-link-checker' ),
					'Source locations'                    => __( 'Source locations', 'simple-broken-link-checker' ),
					'No source locations remain in the current scan.' => __( 'No source locations remain in the current scan.', 'simple-broken-link-checker' ),
					'Repair history'                      => __( 'Repair history', 'simple-broken-link-checker' ),
					'Loading settings…'                   => __( 'Loading settings…', 'simple-broken-link-checker' ),
					'Loading findings…'                   => __( 'Loading findings…', 'simple-broken-link-checker' ),
					'No findings match these filters.'    => __( 'No findings match these filters.', 'simple-broken-link-checker' ),
					'Scan started. Discovery and verification will run in small bounded requests.' => __( 'Scan started. Discovery and verification will run in small bounded requests.', 'simple-broken-link-checker' ),
					'Select resources and a bulk action first.' => __( 'Select resources and a bulk action first.', 'simple-broken-link-checker' ),
					'Resources updated.'                  => __( 'Resources updated.', 'simple-broken-link-checker' ),
					'Resource updated.'                   => __( 'Resource updated.', 'simple-broken-link-checker' ),
					'Restore the source content saved before this repair?' => __( 'Restore the source content saved before this repair?', 'simple-broken-link-checker' ),
					'Repair undone.'                      => __( 'Repair undone.', 'simple-broken-link-checker' ),
					'Source updated.'                     => __( 'Source updated.', 'simple-broken-link-checker' ),
					'Settings saved.'                     => __( 'Settings saved.', 'simple-broken-link-checker' ),
					'This will change stored WordPress content and create an undo record. Continue?' => __( 'This will change stored WordPress content and create an undo record. Continue?', 'simple-broken-link-checker' ),
					'Unlink this occurrence while preserving its visible text?' => __( 'Unlink this occurrence while preserving its visible text?', 'simple-broken-link-checker' ),
					'Stop the current scan after its active bounded request?' => __( 'Stop the current scan after its active bounded request?', 'simple-broken-link-checker' ),
				),
			)
		);
	}

	/**
	 * Render the application shell.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$settings = 'sblc-settings' === filter_input( INPUT_GET, 'page', FILTER_SANITIZE_SPECIAL_CHARS );
		?>
		<div class="wrap sblc-wrap" id="sblc-app" data-screen="<?php echo esc_attr( $settings ? 'settings' : 'findings' ); ?>">
			<header class="sblc-header">
				<div class="sblc-brand">
					<img src="<?php echo esc_url( SBLC_PLUGIN_URL . 'assets/images/sblc-mark.svg' ); ?>" alt="" width="42" height="42" />
					<div>
						<p class="sblc-eyebrow"><?php esc_html_e( 'LOCAL SITE TOOL', 'simple-broken-link-checker' ); ?></p>
						<h1><?php esc_html_e( 'Simple Broken Link Checker', 'simple-broken-link-checker' ); ?></h1>
					</div>
				</div>
				<nav class="sblc-nav" aria-label="<?php esc_attr_e( 'Plugin navigation', 'simple-broken-link-checker' ); ?>">
					<a class="<?php echo $settings ? '' : 'is-active'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sblc-dashboard' ) ); ?>"><?php esc_html_e( 'Findings', 'simple-broken-link-checker' ); ?></a>
					<a class="<?php echo $settings ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=sblc-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'simple-broken-link-checker' ); ?></a>
				</nav>
			</header>
			<main class="sblc-main">
				<noscript><div class="notice notice-warning"><p><?php esc_html_e( 'JavaScript is required for the interactive scanner. WordPress content is not changed by simply opening this page.', 'simple-broken-link-checker' ); ?></p></div></noscript>
				<div class="sblc-screen" data-screen-content></div>
			</main>
		</div>
		<?php
	}

	/**
	 * Stream a CSV export of the current resources.
	 *
	 * @return void
	 */
	public static function export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export findings.', 'simple-broken-link-checker' ) );
		}
		check_admin_referer( 'sblc_export' );
		$list = Database::list_resources(
			array(
				'page'     => 1,
				'per_page' => 100,
				'status'   => 'all',
				'type'     => 'all',
				'scope'    => 'all',
				'search'   => '',
			)
		);
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=simple-broken-link-checker-findings.csv' );
		$output = fopen( 'php://output', 'w' );
		fputcsv( $output, array( 'URL', 'Status', 'Confidence', 'HTTP', 'Type', 'Occurrences', 'Final URL', 'Last Checked', 'Explanation' ) );
		$pages = max( 1, (int) ceil( $list['total'] / 100 ) );
		for ( $page = 1; $page <= $pages; $page++ ) {
			if ( 1 !== $page ) {
				$list = Database::list_resources(
					array(
						'page'     => $page,
						'per_page' => 100,
						'status'   => 'all',
						'type'     => 'all',
						'scope'    => 'all',
						'search'   => '',
					)
				);
			}
			foreach ( $list['items'] as $item ) {
				fputcsv( $output, array( $item->url, $item->status, $item->confidence, $item->http_code, $item->resource_type, $item->occurrence_count, $item->final_url, $item->last_checked, $item->explanation ) );
			}
		}
		exit;
	}
}
