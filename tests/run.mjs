/* The full pre-release check, cross-platform.
   ─────────────────────────────────────────────────────────────────────────
       node tests/run.mjs

   Was tests\run.bat, which only ran on Windows. A PHP plugin should not need
   a Windows runner to prove it parses, and the release pipeline runs on
   Linux — so the batch file is now a two-line shim over this.

   Four gates, in the order that fails cheapest first. Exits non-zero if any
   of them does, so it works as a CI step or a pre-commit hook. */

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.join(here, '..');

const PHP = process.env.PHP_BIN || 'php';
let failed = false;

const step = (n, title) => console.log(`\n=== ${n}. ${title} ===`);

function run(cmd, args, opts = {}) {
  const r = spawnSync(cmd, args, { cwd: root, encoding: 'utf8', ...opts });
  return { code: r.status ?? 1, out: (r.stdout || '') + (r.stderr || ''), err: r.error };
}

/* ── 1. Every PHP file parses ───────────────────────────────────────────── */
step(1, 'PHP syntax');

const phpFiles = [
  'numra-for-woocommerce.php',
  'uninstall.php',
  ...fs.readdirSync(path.join(root, 'includes')).filter((f) => f.endsWith('.php')).map((f) => `includes/${f}`),
  ...fs.readdirSync(here).filter((f) => f.endsWith('.php')).map((f) => `tests/${f}`),
];

const probe = run(PHP, ['-v']);
if (probe.err || probe.code !== 0) {
  /* Not skipped quietly. A release built without the syntax gate is exactly
     the release that ships a parse error to every store at once. */
  console.error(`  ✗ ${PHP} is not runnable — cannot check syntax. Set PHP_BIN or install PHP.`);
  process.exit(1);
}

for (const f of phpFiles) {
  const r = run(PHP, ['-l', f]);
  if (r.code !== 0) {
    console.error(`  FAIL ${f}`);
    console.error(r.out.trim().split('\n').map((l) => '       ' + l).join('\n'));
    failed = true;
  }
}
if (!failed) console.log(`  All ${phpFiles.length} files parse.`);

/* ── 2. Class and method integrity ──────────────────────────────────────── */
step(2, 'Class and method integrity');
{
  const r = run(PHP, ['tests/integrity.php'], { stdio: 'inherit' });
  if (r.code !== 0) failed = true;
}

/* ── 3. Unit tests ──────────────────────────────────────────────────────── */
step(3, 'Unit tests');
{
  const r = run(PHP, ['tests/unit.php'], { stdio: 'inherit' });
  if (r.code !== 0) failed = true;
}

/* ── 4. The three version strings agree ─────────────────────────────────── */
step(4, 'Version agreement');
{
  /* Was a separate script in the monorepo that a person had to remember to
     run. Folding it into the suite is the point: build.mjs runs the suite and
     refuses on failure, so a version disagreement can no longer reach a zip. */
  const r = run(process.execPath, ['tests/check-version.js'], { stdio: 'inherit' });
  if (r.code !== 0) failed = true;
}

console.log('');
console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
process.exit(failed ? 1 : 0);
