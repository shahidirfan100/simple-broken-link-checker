=== Simple Broken Link Checker ===
Contributors: shahidirfan100
Tags: broken links, link checker, broken images, redirects, local scanner
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find broken links, redirects, and missing images locally with evidence, safe retries, and auditable repairs.

== Description ==

Simple Broken Link Checker is a local WordPress tool for administrators who need to know what is genuinely broken before changing content.

It discovers URLs in public posts, pages, public custom post types, approved comments, custom navigation menus, featured images, and optionally scalar custom-field or page-builder data. It stores each unique URL separately from the places where it appears, so the same destination is verified once while all source locations remain available.

The plugin classifies results instead of treating every failed request as a dead link:

* **Healthy** — a successful response was received.
* **Redirect** — the destination works but reaches another URL.
* **Broken** — HTTP 404 or 410 provides strong evidence that the destination no longer exists.
* **Blocked** — the destination rejected automated verification, such as HTTP 401 or 403.
* **Rate Limited** — the destination returned HTTP 429 and should be checked later.
* **Temporary Error** — the destination returned a 5xx response.
* **SSL Error, DNS Error, Timeout, or Unverified** — the scanner could not establish enough evidence to call the URL broken.

Every result includes request history, redirect chain, response time, confidence, explanation, and source locations.

### Local by design

* No cloud account.
* No link credits or usage limits imposed by a third party.
* No telemetry, advertising, or developer tracking.
* No URLs or site content are sent to a developer service.
* HTTP requests are made from your WordPress server to the destinations found in your content.

### Safe repairs

Supported post content, approved comment content, and custom menu URLs can be replaced, unlinked, or marked nofollow. Builder metadata is read-only in this release. Each repair stores the complete before and after source, the user, time, operation, and checksums. Undo is blocked when the source changed after the repair.

### Resource controls

Scanning runs in small resumable worker requests. Each discovered source batch is verified before the next source batch is read, and repeated occurrences do not re-queue a settled URL. It uses a recoverable lock, bounded execution time, per-resource deduplication, request timeouts, redirect limits, response-size limits, and safe retry scheduling. Manual scanning remains available when WordPress cron is delayed.

### Privacy

This plugin does not create a remote account or transmit data to the developer. The server running WordPress may make requests to external URLs found in your content. The request destination, response status, timing, and limited diagnostic categories are stored in your WordPress database.

== Installation ==

1. Upload the `simple-broken-link-checker` folder to `/wp-content/plugins/`, or install the ZIP from **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Open **Link Checker → Findings**.
4. Review **Link Checker → Settings** before starting a large scan.
5. Run a scan and open **Evidence** on any result that needs review.

== Frequently Asked Questions ==

= Does it use a cloud service? =

No. All discovery, storage, classification, and repair operations are local to your WordPress installation. The server still requests the URLs it is checking, as any link checker must.

= Is a 403 link reported as broken? =

No. A 401 or 403 response is classified as **Blocked** because automated access was rejected. Other inconclusive failures remain in a review state instead of being called broken.

= Is a 429 link reported as broken? =

No. HTTP 429 is classified as **Rate Limited** and scheduled for a later check.

= Can it edit page-builder data? =

The plugin can read scalar metadata when enabled, but it does not rewrite serialized or builder data automatically. This avoids corrupting Elementor, ACF, or other structured content.

= Can I remove all data on uninstall? =

Data is retained by default. Enable **Delete findings when the plugin is uninstalled** in Settings if you explicitly want plugin-owned tables and options removed.

= Does it add scripts to the public website? =

No. Styles and scripts are loaded only on the plugin's WordPress admin screens.

== Limitations ==

* Some websites intentionally reject automated verification. A successful browser visit cannot always be reproduced by a server-side request.
* Redirects to private, loopback, link-local, metadata, or otherwise reserved addresses are stopped for SSRF protection.
* Structured page-builder data is review-only until a source-specific safe editor is available.
* WordPress cron may be delayed or disabled by hosting configuration. The findings screen provides manual worker progress.

== Screenshots ==

1. Dashboard overview with realistic healthy, redirected, and broken results from a completed scan.
2. Broken-link filter focused on confirmed HTTP evidence.
3. Evidence panel with request history, source context, and safe repair controls.
4. Redirect evidence showing the recorded hop and final destination.
5. Settings for request safety, source discovery, exclusions, scheduling, and data retention.
6. Active bounded scan with visible progress and a safe stop control.

== Changelog ==

= 1.0.5 =
* Serialized discovery and verification so each discovered batch is classified before more source URLs are fetched.
* Prevented repeated occurrences from resetting an already verified URL to Queued during the same scan.
* Kept ignored resources out of the verification queue while preserving their current-scan visibility.
* Added progress messages and resilient REST polling so active scans continue updating after transient browser requests fail.

= 1.0.4 =
* Prevented a scan from completing while current-scan resources remain queued.
* Normalized legacy nullable queue markers during upgrade.
* Removed the stale empty-table row when live results arrive.

= 1.0.3 =
* Added Internal links, External links, and All links filtering on the Findings screen.
* Added separate settings to include or exclude internal and external links from scans.
* Reset rediscovered URLs to Queued until their current verification request finishes.
* Updated individual result rows without replacing the complete table body during scan progress.

= 1.0.2 =
* Updated scan progress, counters, filters, and result rows in place to prevent dashboard blinking during active scans.
* Preserved the dashboard shell and filter controls while new findings are added.
* Added bounded GET verification when a server rejects or fails a HEAD request.
* Distinguished URLs waiting in the scan queue from completed unverified results.
* Made every new manual or scheduled scan retry previously inconclusive URLs instead of silently skipping them.

= 1.0.1 =
* Fixed admin REST requests on sites using plain permalinks.
* Improved DNS failure classification and nonstandard-port URL resolution.
* Preserved every repeated source occurrence while checking each unique URL once.
* Hardened repair auditing, undo behavior, recheck scheduling, translations, and admin asset loading.
* Added a one-minute bounded worker continuation and refreshed WordPress.org release assets.

= 1.0.0 =
* New local-only broken link and image checker.
* Added evidence-based HTTP classifications, URL deduplication, bounded scanning, safe repairs, and conflict-aware undo.
