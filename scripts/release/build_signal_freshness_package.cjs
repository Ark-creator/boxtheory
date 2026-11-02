// Portable patch builder: fixed runtime allowlist, no dependencies or credentials.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '../..');
const release = 'signal-freshness-2026-11-02';
const runtimePaths = [
  'app/Services/MarketDataGuard.php',
  'app/Services/LiveMarketDataService.php',
  'app/Services/TradingService.php',
  'app/Services/SignalAlertService.php',
  'app/Services/Mt5BridgeClient.php',
  'app/Services/SignalSnapshotCache.php',
  'app/Console/Commands/CheckSignalData.php',
  'app/Http/Controllers/Admin/AdminController.php',
  'app/Http/Controllers/WatchlistController.php',
  'resources/views/emails/signals/alert.blade.php',
  'mt5_bridge/terminal.py',
];
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
function normalized(bytes) {
  const text = bytes.toString('utf8');
  if (!Buffer.from(text, 'utf8').equals(bytes)) throw new Error('Expected valid UTF-8 runtime source.');
  return Buffer.from(text.replace(/\r\n?/g, '\n'), 'utf8');
}
function source(relative) {
  const file = path.join(root, relative);
  const item = fs.lstatSync(file);
  if (!item.isFile() || item.isSymbolicLink()) throw new Error(`Expected a regular source file: ${relative}`);
  return normalized(fs.readFileSync(file));
}
function git(args) { return execFileSync('git', args, { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] }); }
function createManifest() {
  const baselineCommit = git(['rev-parse', '--verify', 'HEAD']).toString('utf8').trim();
  const contents = new Map();
  const files = runtimePaths.map(relative => {
    const data = source(relative);
    contents.set('payload/' + relative, data);
    let baseline = null;
    try { baseline = hash(normalized(git(['show', `${baselineCommit}:${relative}`]))); }
    catch (error) {
      // A new file legitimately has no baseline. Unexpected git errors still abort.
      const listing = git(['ls-tree', '--name-only', baselineCommit, '--', relative]).toString('utf8').trim();
      if (listing) throw error;
    }
    return { path: relative, sha256_normalized_lf: hash(data), baseline_sha256_normalized_lf: baseline };
  });
  return { manifest: { schema_version: 1, release, built_at_utc: new Date().toISOString(), baseline_commit: baselineCommit,
    hash_normalization: 'UTF-8 bytes with CRLF/CR normalized to LF; BOM preserved', files }, contents };
}

// A small standards-based ZIP writer avoids downloading a release dependency.
const crcTable = Array.from({ length: 256 }, (_, n) => {
  for (let i = 0; i < 8; i++) n = n & 1 ? 0xedb88320 ^ (n >>> 1) : n >>> 1;
  return n >>> 0;
});
function crc32(data) {
  let crc = 0xffffffff;
  for (const byte of data) crc = crcTable[(crc ^ byte) & 255] ^ (crc >>> 8);
  return (crc ^ 0xffffffff) >>> 0;
}
function zip(entries) {
  const local = [], central = [];
  let offset = 0;
  const dosDate = ((2026 - 1980) << 9) | (11 << 5) | 2;
  for (const [name, data] of entries) {
    if (name.startsWith('/') || name.split('/').includes('..') || name.includes('\\')) throw new Error('Unsafe ZIP path.');
    const filename = Buffer.from(name, 'utf8');
    const compressed = zlib.deflateRawSync(data, { level: 9 });
    const crc = crc32(data);
    const lh = Buffer.alloc(30);
    lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(0x800, 6);
    lh.writeUInt16LE(8, 8); lh.writeUInt16LE(dosDate, 12); lh.writeUInt32LE(crc, 14);
    lh.writeUInt32LE(compressed.length, 18); lh.writeUInt32LE(data.length, 22); lh.writeUInt16LE(filename.length, 26);
    local.push(lh, filename, compressed);
    const ch = Buffer.alloc(46);
    ch.writeUInt32LE(0x02014b50, 0); ch.writeUInt16LE(20, 4); ch.writeUInt16LE(20, 6); ch.writeUInt16LE(0x800, 8);
    ch.writeUInt16LE(8, 10); ch.writeUInt16LE(dosDate, 14); ch.writeUInt32LE(crc, 16);
    ch.writeUInt32LE(compressed.length, 20); ch.writeUInt32LE(data.length, 24); ch.writeUInt16LE(filename.length, 28);
    ch.writeUInt32LE(offset, 42);
    central.push(ch, filename);
    offset += lh.length + filename.length + compressed.length;
  }
  const directory = Buffer.concat(central);
  const end = Buffer.alloc(22);
  end.writeUInt32LE(0x06054b50, 0); end.writeUInt16LE(entries.size, 8); end.writeUInt16LE(entries.size, 10);
  end.writeUInt32LE(directory.length, 12); end.writeUInt32LE(offset, 16);
  return Buffer.concat([...local, directory, end]);
}

function main(args) {
  const preview = args.includes('--preview');
  const replace = args.includes('--replace');
  const outputAt = args.indexOf('--output');
  if (outputAt >= 0 && !args[outputAt + 1]) throw new Error('--output needs a workspace-relative ZIP path.');
  const output = path.resolve(root, outputAt >= 0 ? args[outputAt + 1] : `docs/releases/${release}.zip`);
  if (!output.toLowerCase().startsWith((root + path.sep).toLowerCase()) || path.extname(output).toLowerCase() !== '.zip') {
    throw new Error('Release output must be a ZIP inside the workspace.');
  }
  const { manifest, contents } = createManifest();
  if (preview) { console.log(JSON.stringify(manifest, null, 2)); return; }
  const entries = new Map([
    ['Apply-SignalFreshnessFix.ps1', source('scripts/release/Apply-SignalFreshnessFix.ps1')],
    ['SIGNAL_FRESHNESS_UPDATE.md', source('docs/SIGNAL_FRESHNESS_UPDATE.md')],
    ['manifest.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n', 'utf8')],
    ...contents,
  ]);
  const archive = zip(entries);
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, archive, { flag: replace ? 'w' : 'wx' });
  console.log(JSON.stringify({ output, release, baseline_commit: manifest.baseline_commit, runtime_files: runtimePaths.length,
    archive_files: entries.size, bytes: archive.length, sha256: hash(archive), includes_secrets_or_settings: false }, null, 2));
}

module.exports = { createManifest, zip, normalized, runtimePaths };
if (require.main === module) {
  try { main(process.argv.slice(2)); }
  catch (error) { console.error(error.message); process.exitCode = 1; }
}
