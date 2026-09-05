/* ═══════════════════════════════════════════════════════════════════════════
   The plugin's version is written in three places. They must agree.
   ─────────────────────────────────────────────────────────────────────────
     numra-for-woocommerce.php   `Version:` header      — what WordPress shows
     numra-for-woocommerce.php   NUMRA_VERSION constant — what the code reports
     readme.txt                  `Stable tag:`          — the packaged record

   THE HAZARD THIS EXISTS FOR. A version that must be typed into a second
   place, by hand, on every release, will eventually be typed into only one.
   That is not hypothetical here: `Stable tag` sat at 1.7.0 while the plugin
   shipped 1.16.0, and AGENT_VERSIONS sat at 1.1.0 for fifteen releases. Both
   looked fine from the outside the entire time.

   Distribution is Numra's own platform, not wordpress.org, so `Stable tag`
   no longer decides what any store is served — plugin_releases does. It is
   still checked, because readme.txt ships inside the zip and a package that
   contradicts itself about its own version cannot be diagnosed from a bug
   report.

   The dangerous one is what every installed store compares itself against:
   the current row in plugin_releases. Build 1.18.0 and publish a row saying
   1.17.0 and the fleet never updates; publish 1.19.0 against a zip containing
   1.18.0 and every store installs a package that immediately reports itself
   out of date, forever.

   ── What changed when this repo was split out ─────────────────────────────
   This used to live in the monorepo at scripts/check-plugin-version.js and
   read plugin_releases straight from Postgres. A plugin repo has no database
   and should not have credentials for one, so it now asks the public
   update-check endpoint the same question. That is strictly better: it reads
   what stores are ACTUALLY being offered rather than what the table says, and
   it needs nothing but a URL.

   Run it before building. Exits non-zero on a mismatch between the three
   files; the published-release comparison only ever reports.
   ═══════════════════════════════════════════════════════════════════════════ */

'use strict';

const fs = require('fs');
const path = require('path');

const DIR = path.join(__dirname, '..');
const PHP = path.join(DIR, 'numra-for-woocommerce.php');
const README = path.join(DIR, 'readme.txt');

function fail(msg) {
  console.error('\n  ✗ ' + msg + '\n');
  process.exit(1);
}

const php = fs.readFileSync(PHP, 'utf8');
const readme = fs.readFileSync(README, 'utf8');

const header = php.match(/^\s*\*?\s*Version:\s*([0-9][0-9.]*)/m);
const constant = php.match(/define\(\s*'NUMRA_VERSION'\s*,\s*'([0-9][0-9.]*)'\s*\)/);
const stable = readme.match(/^Stable tag:\s*([0-9][0-9.]*)/m);

if (!header)   fail('No `Version:` header found in numra-for-woocommerce.php');
if (!constant) fail('No NUMRA_VERSION constant found in numra-for-woocommerce.php');
if (!stable)   fail('No `Stable tag:` found in readme.txt');

const v = {
  'plugin header': header[1],
  'NUMRA_VERSION': constant[1],
  'readme Stable tag': stable[1],
};

const distinct = [...new Set(Object.values(v))];
if (distinct.length !== 1) {
  console.error('\n  Plugin version disagreement:\n');
  for (const [where, val] of Object.entries(v)) {
    console.error(`    ${where.padEnd(20)} ${val}`);
  }
  console.error(
    '\n  All three ship inside the same zip. A package that disagrees with\n' +
    '  itself about its own version cannot be diagnosed from a merchant\n' +
    '  bug report.\n'
  );
  process.exit(1);
}

const built = distinct[0];
console.log(`\n  ✓ plugin version ${built} — header, constant and Stable tag agree.`);

/* Exported so build.mjs can reuse the answer rather than re-parsing. */
module.exports = { version: built };

/* ── What stores are actually being offered ────────────────────────────────
   Advisory, and deliberately so. A mismatch is usually not an error: you
   build the zip BEFORE you publish the release, so during a normal release
   the published version is legitimately one behind for a few minutes. What
   this catches is the other order — publishing a release and forgetting to
   build, or building a version nobody ever published — which is silent and
   permanent.

   So it reports and never sets the exit code. Set NUMRA_API_BASE to point at
   staging; unset it and this is skipped rather than failed, because a build
   must not depend on a service being up. */
(async () => {
  const base = (process.env.NUMRA_API_BASE || 'https://api.numra.ma').replace(/\/+$/, '');

  if (process.env.NUMRA_SKIP_RELEASE_CHECK === '1') {
    console.log('    (NUMRA_SKIP_RELEASE_CHECK=1 — skipped the published-release check)\n');
    return;
  }

  /* `version` is sent as 0.0.0 on purpose: the endpoint answers with the
     current release relative to what the caller claims to run, and a version
     nothing can be newer than makes it always tell us the truth. */
  const url = `${base}/v1/plugin/connect/update-check?platform=woocommerce&version=0.0.0`;

  try {
    const ctl = new AbortController();
    const timer = setTimeout(() => ctl.abort(), 5000);
    const res = await fetch(url, { signal: ctl.signal });
    clearTimeout(timer);

    if (!res.ok) {
      console.log(`    (update-check answered ${res.status} — skipped the published-release check)\n`);
      return;
    }
    const body = await res.json();
    const live = body.latest_version || null;

    if (!live) {
      console.log(`    note: nothing is published for woocommerce — no store will be offered ${built}.\n`);
    } else if (live === built) {
      console.log(`    published release agrees: ${live}.\n`);
    } else {
      console.log(
        `    note: stores are being offered ${live}, this build is ${built}.\n` +
        `    Publish ${built} from the control panel, or no store will ever see it.\n`
      );
    }
  } catch (e) {
    console.log(`    (could not reach ${base} — skipped the published-release check: ${e.message})\n`);
  }
})();
