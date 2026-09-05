# Changelog: Numra for WooCommerce

All notable changes to this plugin will be documented in this file.

## [1.18.0] - 2026-09-03

Cuts a release for work that was already sitting in the tree. The zip published
as 1.17.0 does not contain any of the below: the source was edited after that
build, and WordPress compares the version string and nothing else — so a store
running the published 1.17.0 would never have been offered these fixes.

### Fixed
* **The plugin contradicted itself about WordPress compatibility.** The updater
  reported `'tested' => '6.6'` while `readme.txt` said 6.7, so a merchant on
  6.7 saw an incompatibility warning on the Updates screen and no such warning
  in the details modal. Both now say 6.7, and the value carries a comment
  saying which file it has to match.
* **The plugin icon was referenced but not shipped.** `class-numra-updater.php`
  points WordPress at `assets/numra-icon.svg`; that file was not inside the
  1.17.0 package, so the Plugins and Updates screens fell back to a grey
  puzzle piece. It is now in the archive.

### Changed
* `Contributors:` removed from `readme.txt`. Distribution is Numra's own
  platform, not wordpress.org, so the field named an account that decides
  nothing.

### Build
* The build no longer writes outside this directory, so the plugin can live in
  its own repository. `build.mjs` replaces `build.ps1` (which stays as a shim)
  and `tests/run.mjs` replaces `tests/run.bat`, both cross-platform.
* `releases.json` records a fingerprint of the packaged content per version,
  and the build refuses to produce a second, different zip under a version
  already in it. That is what caught the divergence above.

## [Unreleased]

> **Stale — predates 1.17.0.** This section describes hardening of the 1.1.0
> rename migration, which shipped somewhere between 1.3.0 and 1.17.0; none of
> those releases were written up, so it was never moved out. Retained rather
> than deleted because the notes are accurate about the code. Reconciling
> 1.3.0–1.17.0 is outstanding.

Hardening of the DPD → Numra rename migration introduced in 1.1.0. No merchant-
facing behaviour changes; nothing needs to be re-entered or reconnected.

### Fixed
* **The rename migration ran on every request, forever.** It issued seven
  `get_option()` calls on every page load of every install, including the
  overwhelming majority that never had a `dpd_*` row. It is now gated on a
  stored `numra_schema_version` option and runs once per site.
* **Uninstall left thirteen options behind.** The delete list had not been
  updated since the order-protection release, so deleting the plugin orphaned
  the sync consent, backfill state, heartbeat state, customer styles, risk band
  and flagged-styles rows. All are now removed, along with the migration
  bookkeeping — without which a reinstall would claim to be already migrated.
* **Uninstall left scheduled events behind.** `numra_heartbeat` and
  `numra_backfill_batch` stayed in the cron array after the plugin was deleted,
  firing indefinitely against callbacks that no longer exist. Deactivation
  cleared them, but uninstall is reachable without deactivating.

### Added
* The migration now also unschedules the pre-rename cron hooks (`dpd_heartbeat`,
  `dpd_backfill_batch`), clears stale `dpd_*` transients, and renames any
  `_dpd_*` order meta to `_numra_*` — in **both** the legacy `postmeta` table
  and the HPOS `wc_orders_meta` table, so a store on custom order tables keeps
  its risk history.
* Order meta is renamed with bounded, prepared `UPDATE`/`DELETE` statements
  keyed on the indexed `meta_key` column, never by loading orders into PHP.

### Notes
* The option migration remains an explicit allow-list rather than a wildcard
  sweep of `dpd_*` rows. DPD is also a real parcel carrier with its own
  WooCommerce plugins, and renaming their options into the Numra namespace
  would corrupt an unrelated plugin's settings.

## [1.2.0] - 2026-08-14

The release where the plugin starts doing what it says on the tin. Up to 1.1.0
it connected, stored settings and updated itself, but contained no WooCommerce
integration at all — no order was ever scored and no outcome was ever reported.

### Added
* **Order scoring.** Every new order's phone number is checked against Numra the
  moment the order is created (classic *and* Blocks checkout). The score, risk
  level, carrier and verdict are stored on the order and written to the order notes.
* **Automatic hold.** Orders at or above your risk threshold — and any number
  blacklisted on the network — are placed On hold for review before dispatch.
  Applied once per order; if you release it, Numra will not hold it again.
* **Delivery outcome reporting.** Order status changes are reported back to Numra
  through Action Scheduler, with retries, so the network keeps learning from your
  store's deliveries. Sensible defaults, and a mapping screen for stores using
  custom statuses.
* **Order Protection settings tab** — enable/disable scoring, COD-only mode, the
  risk threshold, auto-hold, outcome reporting and the status mapping.
* **Risk column on the orders list** and a risk panel on the order screen, both
  working on HPOS and legacy order storage.
* **HPOS and Cart/Checkout Blocks compatibility** formally declared.
* `tests/unit.php` and `tests/integrity.php` — dependency-free checks runnable with
  plain `php`, covering status mapping, threshold clamping, and that every class
  and method reference in the plugin actually resolves.

### Fixed
* **Every API call fataled.** An unclosed docblock in `class-numra-api-client.php`
  swallowed `parse_response()` into a comment, so the method did not exist while
  all five public API methods called it. `php -l` passes on a commented-out
  method, which is why this survived: connection tests, connect, disconnect and
  announcements all raised *Call to undefined method* at runtime.
* **Disconnecting the store, or changing the API key, fataled.** Three call sites
  invoked `Numra_Growth_Center::bust_cache()`; the autoloader mapped that class to
  `includes/class-numra-growth-center.php`, a file that has never existed. The
  class had been renamed to `Numra_Announcements` and the rename was never finished.
* **The settings page fataled.** `Numra_Announcements` was used statically in four
  places but was absent from the autoloader map, so it was never loaded.

### Notes
* Orders are **never blocked at checkout** and Numra being unreachable never stops
  a sale: if the check cannot run, the order goes through and the reason is recorded.
* Scoring uses one check from your plan per order, and only for cash-on-delivery
  orders unless you turn that off.

## [1.1.0] - 2026-08-08
### Changed
* **Renamed the plugin from "DPD for WooCommerce" to "Numra for WooCommerce."** The folder, main file, text domain, PHP classes, constants, CSS class names, script handle and page slug all move from the `DPD`/`dpd-trust` namespace to `Numra`/`numra`. See ADR-002 in `ARCHITECTURE.md` for why the Sprint 1.3 freeze was lifted.
* The REST namespace is now `numra/v1` (was `dpd-trust/v1`) and the connect handshake arguments are `numra_connect_token`, `numra_state` and `numra_connect_cancelled`. The merchant portal was changed in the same release — an older portal cannot verify a store running this version, and vice versa.

### Migration
* Settings carry over automatically. On upgrade, every `dpd_*` option is copied to its `numra_*` name and the old row is deleted. Nothing needs to be re-entered and no reconnection is required.
* **The plugin folder name changed.** WordPress treats `numra-for-woocommerce` as a different plugin from `dpd-for-woocommerce`, so an upgrade installs alongside the old copy rather than replacing it. Delete the old `dpd-for-woocommerce` folder after activating this one, or the two will both try to register the same menu.

## [1.0.2] - 2026-07-19
### Changed
* Refactored connection state validation to dynamically update connection health to `connection_lost` when 401/403 errors are returned during API calls.
* Restructured option saving so `numra_connection_status` defaults to `disconnected` instead of `unknown`.
* Decoupled the settings screen to show specific key status badges (`Verified`, `Invalid / Revoked`, `Saved (Unavailable)`, `Saved (Unverified)`).

## [1.0.1] - 2026-07-18
### Added
* Custom slate-based Aurora admin interface design.
* Dynamic banner placements and impression tracking via the Growth Center integration.
* Remote settings disconnect webhook forwarding.

## [1.0.0] - 2026-07-15
### Added
* Initial release containing basic token exchange connection, manual API key settings page, and logger.
