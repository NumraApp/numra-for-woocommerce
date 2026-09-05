# Contributing to Numra for WooCommerce

Patches are welcome. This plugin runs in `wp-admin` on live shops and holds a
credential that spends the merchant's paid quota, so the bar for a change is a
test that would have caught the bug, not a convincing description of it.

## Running the checks

```bash
node tests/run.mjs
```

That is the whole suite, and it needs nothing installed — no Composer, no npm,
no WordPress. It runs four gates: PHP syntax across every file, class and
method integrity, the unit tests, and the three version strings agreeing with
each other.

The release pipeline runs the same command on PHP 7.4, 8.0, 8.1, 8.2 and 8.3,
because `Requires PHP: 7.4` in the plugin header is meant to be a fact rather
than an aspiration. A change that quietly needs 8.0 syntax will fail there.

## The three version strings

`numra-for-woocommerce.php` (the plugin header), `readme.txt` (`Stable tag`)
and `CHANGELOG.md` must all agree, and the suite fails if they do not.
WordPress compares the header version and nothing else, so a zip published
under the wrong number can never be corrected — the stores that already took
it will not be offered it again.

## Building

```bash
node build.mjs
```

This writes `dist/numra-for-woocommerce.zip` and refuses if the version in the
header was already built from different source. `releases.json` is the ledger
that makes that check possible and it is committed on purpose; the zip is not,
because it is reproducible from any tagged commit.

The build deliberately excludes the development files — a plugin folder on a
live host should not carry the test runner or the build script.

## What a change needs

- A bug fix comes with a test that fails before it and passes after.
- A change to the connect flow comes with the error path exercised, not only
  the happy path. `state` mismatch, a used token and an expired token are the
  three that actually happen.
- A change to what is stored comes with a note in the pull request about what
  an existing install sees on upgrade. Options are never renamed here, which
  is what makes rolling back safe; keep it that way.

Secrets never reach a page, a log or a URL. `class-numra-logger.php` redacts
anything matching `key|token|secret|password|credential` and any
`Authorization` header before writing. If a change makes that redaction fail,
the fix is not to relax the pattern.

## Where a fix belongs

This repository is one client of the Numra platform. If the behaviour you are
fixing is in the API rather than in the plugin — a verdict, a rate limit, an
error code — the plugin is the wrong place for the patch; open an issue here
describing it instead.

The Node and PHP SDKs live in their own repositories under the same
organisation and share no code with this plugin.

## Reporting a bug

Open an issue with the plugin version, the WordPress version, the PHP version,
and the smallest reproduction you can manage. Include the relevant lines from
`wp-content/debug.log` with any key redacted.

**A security vulnerability is not a bug report** — see [SECURITY.md](SECURITY.md)
and mail it privately instead.

## House style

British spelling, no emoji in headings, and prose that says what a thing does
rather than how good it is. Comments explain the decision, not the syntax.
WordPress coding standards for PHP: tabs, Yoda conditions, `esc_*` on
everything that reaches a page.
