/* Numra for WooCommerce — build the distributable zip.
   ═══════════════════════════════════════════════════════════════════════════
       node build.mjs
       node build.mjs --publish-to ../../apps/portal/sdks

   Replaced build.ps1, which ran only on Windows and wrote its output into
   apps/portal/sdks — a path outside this directory, and the one thing that
   stopped this being its own repository. The zip now lands in dist/ and
   --publish-to is how the monorepo keeps the portal serving it. In the split
   repo, nobody passes that flag.

   No dependencies. The ZIP container is written by hand a few hundred lines
   down, which sounds like showing off until you read why.
   ═══════════════════════════════════════════════════════════════════════════ */

import fs from 'node:fs';
import path from 'node:path';
import zlib from 'node:zlib';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.dirname(fileURLToPath(import.meta.url));
const SLUG = 'numra-for-woocommerce';

const args = process.argv.slice(2);
const publishTo = args.includes('--publish-to') ? args[args.indexOf('--publish-to') + 1] : null;
const skipTests = args.includes('--skip-tests');

const die = (msg) => { console.error('\n  ' + msg + '\n'); process.exit(1); };
const say = (msg) => console.log(msg);

/* ── Version comes from one place: the plugin header ─────────────────────── */
const mainFile = path.join(root, `${SLUG}.php`);
const header = fs.readFileSync(mainFile, 'utf8');

const vHeader = header.match(/^\s*\*?\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/m);
if (!vHeader) die('Could not read Version from the plugin header.');
const version = vHeader[1];

/* The header and the constant must agree, or the WordPress updater and the
   plugin report different versions to the merchant. Checked here as well as
   in the test suite because this is the last gate before bytes are written. */
if (!new RegExp(`define\\(\\s*'NUMRA_VERSION',\\s*'${version.replace(/\./g, '\\.')}'\\s*\\)`).test(header)) {
  die(`Version mismatch: header says ${version} but NUMRA_VERSION does not match.`);
}
say(`\nBuilding version ${version}`);

/* ── Never ship a failing build ──────────────────────────────────────────── */
if (!skipTests) {
  say('\nRunning checks...');
  const r = spawnSync(process.execPath, [path.join('tests', 'run.mjs')], { cwd: root, stdio: 'inherit' });
  if (r.status !== 0) die('Checks failed — refusing to build.');
}

/* ── Stage ───────────────────────────────────────────────────────────────
   Only what a merchant's site needs. tests/ is excluded deliberately: those
   files define bare get_option()/update_option() shims with no ABSPATH guard,
   and a PHP file that executes when requested directly has no business inside
   a plugin folder on a live host. docs/ and ARCHITECTURE.md are internal.

   Built from the source tree on every run rather than copied into a reused
   directory. The 1.1.0 zip shipped class-numra-growth-center.php months after
   the file was deleted, because the staging directory still had it; reading
   the tree fresh makes that impossible rather than merely checked-for. */
const FILES = [`${SLUG}.php`, 'uninstall.php', 'readme.txt', 'README.md', 'CHANGELOG.md'];
const DIRS = ['includes', 'assets', 'languages'];

/** @type {{rel: string, buf: Buffer}[]} */
const staged = [];

for (const f of FILES) {
  const p = path.join(root, f);
  if (fs.existsSync(p)) staged.push({ rel: `${SLUG}/${f}`, buf: fs.readFileSync(p) });
}

const walk = (dir, prefix) => {
  for (const name of fs.readdirSync(dir).sort()) {
    const p = path.join(dir, name);
    const st = fs.statSync(p);
    if (st.isDirectory()) walk(p, `${prefix}/${name}`);
    else staged.push({ rel: `${prefix}/${name}`, buf: fs.readFileSync(p) });
  }
};
for (const d of DIRS) {
  const p = path.join(root, d);
  if (fs.existsSync(p)) walk(p, `${SLUG}/${d}`);
}

staged.sort((a, b) => (a.rel < b.rel ? -1 : a.rel > b.rel ? 1 : 0));
if (!staged.some((e) => e.rel === `${SLUG}/${SLUG}.php`)) {
  die('The plugin main file was not staged — nothing else is worth checking.');
}

/* ── Refuse to publish two different zips under one version ───────────────
   A version string is a promise about contents. WordPress's updater compares
   nothing else: class-numra-updater.php hands `latest_version` straight to
   WP, so a store already running 1.8.0 is never offered a different 1.8.0.
   Rebuilding the same number after a change therefore strands every merchant
   who downloaded the earlier one, silently and permanently — they have to
   notice by eye and re-upload by hand.

   This happened: 1.8.0 was built three times in one afternoon while the panel
   was being reworked, and a live store ended up running the first of them.

   build.ps1 caught this by reading the portal's manifest.json, which is a
   path outside this repo and gone after the split. The ledger below is local,
   committed, and stricter: it records a fingerprint of the staged CONTENT, so
   rebuilding the same version from the same source is fine and rebuilding it
   from different source is refused. Presence alone was never the question. */
const LEDGER = path.join(root, 'releases.json');

const fingerprint = (() => {
  const h = crypto.createHash('sha256');
  for (const e of staged) {
    h.update(e.rel, 'utf8');
    h.update('\0');
    h.update(e.buf);
    h.update('\0');
  }
  return h.digest('hex').slice(0, 16);
})();

let ledger = {};
if (fs.existsSync(LEDGER)) {
  try { ledger = JSON.parse(fs.readFileSync(LEDGER, 'utf8').replace(/^﻿/, '')); }
  catch (e) { die(`releases.json will not parse: ${e.message}\n  Fix or delete it by hand — refusing to guess.`); }
}

const prior = ledger[version];
if (prior && prior.fingerprint !== fingerprint) {
  console.error(`\n  REFUSING TO BUILD: v${version} was already built from different source\n`);
  console.error(`    built at     ${prior.built_at}`);
  console.error(`    fingerprint  ${prior.fingerprint}   (recorded)`);
  console.error(`    fingerprint  ${fingerprint}   (this build)\n`);
  console.error('  Any store that installed the earlier v' + version + ' will never be offered');
  console.error('  this one — WordPress compares the version string and nothing else.\n');
  console.error(`  Bump Version and NUMRA_VERSION in ${SLUG}.php (and Stable tag in`);
  console.error('  readme.txt), or delete the entry from releases.json if that build');
  console.error('  was never published to anyone.\n');
  process.exit(1);
}
if (prior) say(`  (identical to the recorded v${version} — rebuilding is safe)`);

/* ── The archive ─────────────────────────────────────────────────────────
   Written by hand rather than shelled out to a zip tool, for two reasons.

   1. SEPARATORS. Windows PowerShell 5.1's Compress-Archive writes entry names
      with BACKSLASHES, which the ZIP spec forbids (APPNOTE 4.4.17.1 requires
      forward slashes). A Linux host treats the backslash as an ordinary
      filename character, so WordPress extracts one flat file literally called
      "numra-for-woocommerce\includes\class-numra-order-guard.php" instead of
      a directory tree, and the plugin installs broken or is rejected. Here
      the names are built with '/' and there is no code path that could
      produce anything else.

   2. REPRODUCIBILITY. Every entry gets the same fixed timestamp, so building
      the same source twice produces byte-identical output. That is what makes
      the ledger's fingerprint meaningful rather than decorative — and it
      means a merchant's zip can be compared against a rebuild.

   Store-or-deflate, no zip64: the package is ~120 KB with 27 entries, five
   orders of magnitude below any 32-bit limit. */

const CRC = (() => {
  const t = new Int32Array(256);
  for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    t[n] = c;
  }
  return t;
})();

function crc32(buf) {
  let c = -1;
  for (let i = 0; i < buf.length; i++) c = CRC[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
  return (c ^ -1) >>> 0;
}

/* 1980-01-01 00:00:00 in DOS date/time — the earliest the format can express,
   and the conventional choice for a reproducible build. */
const DOS_TIME = 0;
const DOS_DATE = 0x0021;

function buildZip(entries) {
  const locals = [];
  const central = [];
  let offset = 0;

  for (const e of entries) {
    const name = Buffer.from(e.rel, 'utf8');
    if (name.includes(0x5c)) die(`Refusing to write a backslash in an entry name: ${e.rel}`);

    const raw = e.buf;
    const deflated = zlib.deflateRawSync(raw, { level: 9 });
    /* Stored when deflate does not help — a already-compressed asset would
       otherwise grow. */
    const useDeflate = deflated.length < raw.length;
    const body = useDeflate ? deflated : raw;
    const method = useDeflate ? 8 : 0;
    const sum = crc32(raw);

    const local = Buffer.alloc(30);
    local.writeUInt32LE(0x04034b50, 0);
    local.writeUInt16LE(20, 4);      // version needed
    local.writeUInt16LE(0, 6);       // flags
    local.writeUInt16LE(method, 8);
    local.writeUInt16LE(DOS_TIME, 10);
    local.writeUInt16LE(DOS_DATE, 12);
    local.writeUInt32LE(sum, 14);
    local.writeUInt32LE(body.length, 18);
    local.writeUInt32LE(raw.length, 22);
    local.writeUInt16LE(name.length, 26);
    local.writeUInt16LE(0, 28);      // extra field length

    locals.push(local, name, body);

    const cd = Buffer.alloc(46);
    cd.writeUInt32LE(0x02014b50, 0);
    cd.writeUInt16LE(20, 4);         // version made by
    cd.writeUInt16LE(20, 6);         // version needed
    cd.writeUInt16LE(0, 8);
    cd.writeUInt16LE(method, 10);
    cd.writeUInt16LE(DOS_TIME, 12);
    cd.writeUInt16LE(DOS_DATE, 14);
    cd.writeUInt32LE(sum, 16);
    cd.writeUInt32LE(body.length, 20);
    cd.writeUInt32LE(raw.length, 24);
    cd.writeUInt16LE(name.length, 28);
    cd.writeUInt16LE(0, 30);         // extra
    cd.writeUInt16LE(0, 32);         // comment
    cd.writeUInt16LE(0, 34);         // disk number
    cd.writeUInt16LE(0, 36);         // internal attrs
    /* `>>> 0`, not a bare `<<`. JavaScript's shift operators are signed
       32-bit, and 0o100644 << 16 overflows to a negative number that
       writeUInt32LE rejects outright. */
    cd.writeUInt32LE((0o100644 << 16) >>> 0, 38); // external attrs: -rw-r--r--
    cd.writeUInt32LE(offset, 42);

    central.push(cd, name);
    offset += local.length + name.length + body.length;
  }

  const cdBuf = Buffer.concat(central);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0);
  end.writeUInt16LE(0, 4);
  end.writeUInt16LE(0, 6);
  end.writeUInt16LE(entries.length, 8);
  end.writeUInt16LE(entries.length, 10);
  end.writeUInt32LE(cdBuf.length, 12);
  end.writeUInt32LE(offset, 16);
  end.writeUInt16LE(0, 20);

  return Buffer.concat([...locals, cdBuf, end]);
}

/* ── Write it ────────────────────────────────────────────────────────────── */
const distDir = path.join(root, 'dist');
fs.mkdirSync(distDir, { recursive: true });
const out = path.join(distDir, `${SLUG}.zip`);

if (fs.existsSync(out)) {
  fs.copyFileSync(out, path.join(distDir, `${SLUG}-previous.zip`));
  say(`  Previous zip kept as ${SLUG}-previous.zip`);
}

const zip = buildZip(staged);
fs.writeFileSync(out, zip);

/* ── Prove it before anyone downloads it ──────────────────────────────────
   Not "the writer looks right" — a real reader, opening the real file. PHP's
   ZipArchive is the closest thing to hand to what WordPress uses, and it
   fails loudly on a malformed central directory. */
const verifier = `
$z = new ZipArchive();
if ($z->open(${JSON.stringify(out)}) !== true) { fwrite(STDERR, "cannot open\\n"); exit(1); }
$names = [];
for ($i = 0; $i < $z->numFiles; $i++) { $names[] = $z->getNameIndex($i); }
$main = ${JSON.stringify(`${SLUG}/${SLUG}.php`)};
$idx = array_search($main, $names, true);
if ($idx === false) { fwrite(STDERR, "missing $main\\n"); exit(1); }
foreach ($names as $n) { if (strpos($n, "\\\\") !== false) { fwrite(STDERR, "backslash in $n\\n"); exit(1); } }
$body = $z->getFromIndex($idx);
if ($body === false || strpos($body, "NUMRA_VERSION") === false) { fwrite(STDERR, "main file unreadable\\n"); exit(1); }
echo count($names), "\\n";
$z->close();
`;

const php = process.env.PHP_BIN || 'php';

/* No -d flag: the extension name differs by platform (php_zip.dll vs zip.so)
   and hard-coding either makes this script work on one of them. If ext-zip is
   not loaded, say so and stop — shipping an unverified archive is the exact
   class of failure this step exists to prevent. */
const hasZip = spawnSync(php, ['-r', 'echo class_exists("ZipArchive") ? 1 : 0;'], { encoding: 'utf8' });
if (String(hasZip.stdout).trim() !== '1') {
  die(
    'PHP has no ZipArchive, so the archive cannot be verified.\n' +
    '  Enable ext-zip (php.ini: extension=zip) and build again.\n' +
    '  Refusing to publish a zip nothing has opened.',
  );
}

const check = spawnSync(php, ['-r', verifier], { encoding: 'utf8' });
if (check.status !== 0) {
  die(`Archive failed verification: ${(check.stderr || check.error?.message || '').trim()}`);
}
const entryCount = Number(String(check.stdout).trim());
say(`  Verified by PHP ZipArchive: ${entryCount} entries, forward slashes, header at the right depth.`);

/* ── Record what was built ───────────────────────────────────────────────── */
const sizeKb = Math.round((fs.statSync(out).size / 1024) * 10) / 10;

ledger[version] = {
  fingerprint,
  entries: entryCount,
  size_kb: sizeKb,
  built_at: prior?.built_at ?? new Date().toISOString().replace(/\.\d+Z$/, 'Z'),
};

/* Sorted by version so the file reads as a history and diffs cleanly. */
const sorted = Object.fromEntries(
  Object.entries(ledger).sort(([a], [b]) =>
    a.localeCompare(b, undefined, { numeric: true, sensitivity: 'base' })),
);
fs.writeFileSync(LEDGER, JSON.stringify(sorted, null, 2) + '\n', 'utf8');

/* ── Optional: hand it to the portal ─────────────────────────────────────
   Only used while this lives inside the monorepo. The portal serves
   apps/portal/sdks at /downloads/sdks, and its manifest.json carries the
   version the Integration page shows — written here because this script is
   the only thing that knows what was really built.

   In the split repo nobody passes --publish-to, and the release pipeline
   publishes through the control panel instead. */
if (publishTo) {
  const dir = path.resolve(root, publishTo);
  if (!fs.existsSync(dir)) die(`--publish-to ${dir} does not exist.`);

  const dest = path.join(dir, `${SLUG}.zip`);
  if (fs.existsSync(dest)) fs.copyFileSync(dest, path.join(dir, `${SLUG}-previous.zip`));
  fs.copyFileSync(out, dest);

  const manifestPath = path.join(dir, 'manifest.json');
  let manifest = {};
  if (fs.existsSync(manifestPath)) {
    /* Strip a UTF-8 BOM before parsing. build.ps1 used to write one, and
       JSON.parse treats the leading U+FEFF as an unexpected token — the
       portal endpoint fell into its catch and served an empty manifest,
       which looks exactly like "the version feature silently does not work". */
    try { manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8').replace(/^﻿/, '')); }
    catch { say('  Existing manifest.json unreadable — rewriting.'); }
  }
  manifest[SLUG] = {
    version: `v${version}`,
    size_kb: sizeKb,
    entries: entryCount,
    built_at: ledger[version].built_at,
  };
  fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n', 'utf8');
  say(`  Published to ${path.relative(root, dir)} and stamped its manifest.`);
}

say(`\nBuilt: ${path.relative(root, out)}  (${sizeKb} KB, v${version}, fingerprint ${fingerprint})`);
say('Publish it from the control panel — building a zip offers it to nobody.\n');
