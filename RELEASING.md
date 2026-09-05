# Releasing Numra for WooCommerce

Distribution is **Numra's own platform, not wordpress.org**. Nothing here
publishes to the WordPress plugin directory, and `Stable tag` decides nothing.
What every installed store compares itself against is the current row in
`plugin_releases`, which the control panel writes.

## The one rule

**A version string is a promise about contents.** WordPress's updater compares
nothing else: `class-numra-updater.php` hands `latest_version` straight to WP,
so a store already running 1.8.0 is never offered a *different* 1.8.0.

This has bitten before — 1.8.0 was built three times in one afternoon and a
live store ended up running the first of them, permanently. `releases.json`
records a fingerprint of the staged content for every version, and the build
refuses to produce a second, different zip under a version already in it.

## Steps

```bash
node tests/run.mjs      # 4 gates: syntax, integrity, unit, version agreement
node build.mjs          # refuses on a failing check or a version reuse
```

Then publish it — **building a zip offers it to nobody.** Upload
`dist/numra-for-woocommerce.zip` in the control panel under Plugin releases and
mark it current. Until that row exists, `update-check` keeps telling every
store the old version is the latest.

Bump the version in **three** places or the build stops you:

| File | Field |
|---|---|
| `numra-for-woocommerce.php` | `Version:` header |
| `numra-for-woocommerce.php` | `NUMRA_VERSION` constant |
| `readme.txt` | `Stable tag:` |

## ⚠ Open right now: the source has moved past the published 1.17.0

The zip currently served at `/downloads/sdks/numra-for-woocommerce.zip` is
**not** what this tree builds, and both call themselves 1.17.0:

| | published zip | this source |
|---|---|---|
| entries | 27 | 28 |
| fingerprint | `67d8059b2e5a5cf8` | `63ff4fe5045aaf18` |

What differs:

- **`class-numra-updater.php` — 251 lines published, 348 here.** Includes a
  real fix: `'tested' => '6.6'` became `'6.7'`. While it said 6.6, a merchant
  on WordPress 6.7 saw an incompatibility warning on the Updates screen and no
  such warning in the details modal — the plugin contradicting itself.
- **`assets/numra-icon.svg` is missing from the published zip.** The updater
  here references it via `plugins_url( 'assets/numra-icon.svg', ... )`, so the
  shipped plugin points at a file that is not inside it.
- **`readme.txt`** lost its `Contributors:` line.

`releases.json` is seeded with the **published** fingerprint, so `node
build.mjs` refuses until this is resolved. That is the guard working on real
data, not a bug.

**The fix is a version bump**, not deleting the ledger entry — 1.17.0 was
published and stores are running it. Rebuilding 1.17.0 would strand every one
of them.

Before bumping, note that `CHANGELOG.md` jumps from `[Unreleased]` to
`[1.2.0]` while the plugin reports 1.17.0: **fifteen releases are
undocumented**, and the `[Unreleased]` section describes 1.1.0-era rename
hardening rather than anything above. Deciding what 1.18.0 says is a judgement
about work this file cannot reconstruct, which is why the bump was left to you
rather than guessed at.
