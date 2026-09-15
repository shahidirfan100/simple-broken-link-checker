# Simple Broken Link Checker

Local WordPress link verification with evidence-based classifications, URL deduplication, bounded scans, and conflict-aware repairs.

The plugin does not require a cloud account, developer service, telemetry, advertising, or link credits. It checks destinations from the WordPress server and stores findings in plugin-owned tables.

## Scope

The current release covers public posts, pages, public custom post types, approved comments, custom navigation menu URLs, featured images, HTML links and images, plain-text HTTP(S) URLs, and optional read-only scalar metadata discovery.

It deliberately does not rewrite serialized page-builder structures. Direct edits are available only for standard post content, approved comments, and menu URLs, with an audit record and checksum-protected undo.

## Status model

The scanner distinguishes successful responses, redirects, definitive 404/410 failures, automated-access blocks, rate limits, temporary server errors, TLS failures, DNS failures, timeouts, and uncertainty. A non-2xx response is not automatically a broken-link finding.

## Development notes

The plugin uses WordPress HTTP APIs with redirect following disabled. Each redirect target is normalized and validated before the next request. Requests are limited by timeout, redirect count, response size, and a recoverable worker lock. The admin application uses plain JavaScript and scoped CSS loaded only on plugin screens.

## License

GPLv2 or later. See the plugin header and [GNU GPL](https://www.gnu.org/licenses/gpl-2.0.html).
