// Gold Trend Retest V2 portable update. Does not modify the preserved first fix.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const zlib = require('node:zlib');
const { execFileSync } = require('node:child_process');
const { zip, normalized, runtimePaths: freshnessPaths } = require('./build_signal_freshness_package.cjs');

const root = path.resolve(__dirname, '../..');
const release = 'gold-retest-v2-2026-11-02';
const priorRelease = 'signal-freshness-2026-11-02';
const priorPackagePath = `docs/releases/${priorRelease}.zip`;
const priorPackageSha256 = 'a89e8db7b54fa3778cb143265d04b78f930c09b0a43cccf0fcd2126e4df8eb2e';
const runtimePaths = [...freshnessPaths,
  'app/Services/GoldTrendRetestStrategy.php',
  'app/Services/GoldTrendRetestV2Strategy.php',
  'app/Console/Commands/CurrentSignal.php',
  'app/Console/Commands/RegisterRetestV2.php',
  'config/services.php',
];
const hash = bytes => crypto.createHash('sha256').update(bytes).digest('hex');
function source(relative) {
  const item = fs.lstatSync(path.join(root, relative));
  if (!item.isFile() || item.isSymbolicLink()) throw new Error(`Expected regular source: ${relative}`);
  return normalized(fs.readFileSync(path.join(root, relative)));
}
function git(args) { return execFileSync('git', args, { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] }); }

function readPreservedFreshnessPackage() {
  const bytes = fs.readFileSync(path.join(root, priorPackagePath));
  if (hash(bytes) !== priorPackageSha256) throw new Error('The preserved freshness ZIP changed; upgrade baselines must be reviewed.');
  const entries = new Map();
  let offset = 0;
  while (offset + 30 <= bytes.length && bytes.readUInt32LE(offset) === 0x04034b50) {
    const flags = bytes.readUInt16LE(offset + 6), method = bytes.readUInt16LE(offset + 8);
    const compressedSize = bytes.readUInt32LE(offset + 18), uncompressedSize = bytes.readUInt32LE(offset + 22);
    const nameLength = bytes.readUInt16LE(offset + 26), extraLength = bytes.readUInt16LE(offset + 28);
    if (flags & 0x8) throw new Error('Unsupported ZIP descriptor in preserved package.');
    const name = bytes.subarray(offset + 30, offset + 30 + nameLength).toString('utf8');
    const start = offset + 30 + nameLength + extraLength, end = start + compressedSize;
    if (end > bytes.length || entries.has(name) || name.startsWith('/') || name.split('/').includes('..')) throw new Error('Invalid preserved ZIP structure.');
    const compressed = bytes.subarray(start, end);
    const data = method === 8 ? zlib.inflateRawSync(compressed) : (method === 0 ? compressed : null);
    if (!data || data.length !== uncompressedSize) throw new Error('Unsupported or corrupt preserved ZIP entry.');
    entries.set(name, data);
    offset = end;
  }
  const manifest = JSON.parse(entries.get('manifest.json').toString('utf8'));
  if (manifest.release !== priorRelease || manifest.files.length !== freshnessPaths.length) throw new Error('Unexpected preserved update manifest.');
  for (const file of manifest.files) {
    const data = entries.get('payload/' + file.path);
    if (!data || hash(normalized(data)) !== file.sha256_normalized_lf) throw new Error(`Preserved payload checksum failed: ${file.path}`);
  }
  return { manifest, contents: entries };
}

function createManifest() {
  const prior = readPreservedFreshnessPackage().manifest;
  const previousFiles = new Map(prior.files.map(file => [file.path, file]));
  const baselineCommit = git(['rev-parse', '--verify', 'HEAD']).toString('utf8').trim();
  const contents = new Map();
  const files = runtimePaths.map(relative => {
    const data = source(relative);
    contents.set('payload/' + relative, data);
    let baseline = null;
    try { baseline = hash(normalized(git(['show', `${baselineCommit}:${relative}`]))); }
    catch (error) {
      if (git(['ls-tree', '--name-only', baselineCommit, '--', relative]).toString('utf8').trim()) throw error;
    }
    const previous = previousFiles.get(relative)?.sha256_normalized_lf;
    return { path: relative, sha256_normalized_lf: hash(data), baseline_sha256_normalized_lf: baseline,
      accepted_installed_sha256_normalized_lf: [...new Set([baseline, previous].filter(Boolean))], may_create: baseline === null };
  });
  return { manifest: { schema_version: 2, release, built_at_utc: new Date().toISOString(), baseline_commit: baselineCommit,
    prior_update: { release: priorRelease, package_sha256: priorPackageSha256 },
    hash_normalization: 'UTF-8 bytes with CRLF/CR normalized to LF; BOM preserved', files }, contents };
}

function main(args) {
  const preview = args.includes('--preview'), replace = args.includes('--replace');
  const outputAt = args.indexOf('--output');
  if (outputAt >= 0 && !args[outputAt + 1]) throw new Error('--output needs a workspace-relative ZIP path.');
  const output = path.resolve(root, outputAt >= 0 ? args[outputAt + 1] : `docs/releases/${release}.zip`);
  if (!output.toLowerCase().startsWith((root + path.sep).toLowerCase()) || path.extname(output).toLowerCase() !== '.zip') throw new Error('Output must be a ZIP inside the workspace.');
  if (output.toLowerCase() === path.resolve(root, priorPackagePath).toLowerCase()) throw new Error('The preserved freshness ZIP cannot be overwritten by the V2 builder.');
  const { manifest, contents } = createManifest();
  if (preview) { console.log(JSON.stringify(manifest, null, 2)); return; }
  const entries = new Map([
    ['Apply-GoldRetestV2Update.ps1', source('scripts/release/Apply-GoldRetestV2Update.ps1')],
    ['GOLD_RETEST_V2_UPDATE.md', source('docs/GOLD_RETEST_V2_UPDATE.md')],
    ['manifest.json', Buffer.from(JSON.stringify(manifest, null, 2) + '\n', 'utf8')], ...contents,
  ]);
  const archive = zip(entries);
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, archive, { flag: replace ? 'w' : 'wx' });
  console.log(JSON.stringify({ output, release, runtime_files: runtimePaths.length, archive_files: entries.size,
    bytes: archive.length, sha256: hash(archive), prior_package_preserved_sha256: priorPackageSha256,
    includes_secrets_or_settings: false }, null, 2));
}

module.exports = { createManifest, runtimePaths, normalized, readPreservedFreshnessPackage, priorPackagePath, priorPackageSha256 };
if (require.main === module) {
  try { main(process.argv.slice(2)); } catch (error) { console.error(error.message); process.exitCode = 1; }
}
