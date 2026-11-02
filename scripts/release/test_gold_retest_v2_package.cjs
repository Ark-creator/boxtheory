// Exercises only disposable workspace fixtures with a stub artisan script.
// Never invokes the real app, a mail command, a scheduler, or a trading command.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { spawnSync, execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const { runtimePaths, normalized, createManifest, readPreservedFreshnessPackage } = require('./build_gold_retest_v2_package.cjs');

const root = path.resolve(__dirname, '../..');
// Keep fixture paths short enough for Windows PowerShell 5's legacy path limit.
const testRoot = path.join(root, 'storage/app/private/v2-fixtures', crypto.randomUUID().slice(0, 8));
const phpAt = process.argv.indexOf('--php');
const php = phpAt >= 0 ? process.argv[phpAt + 1] : 'C:/php/php.exe';
const digest = bytes => crypto.createHash('sha256').update(normalized(bytes)).digest('hex');
const makeParent = file => fs.mkdirSync(path.dirname(file), { recursive: true });
const write = (file, bytes) => { makeParent(file); fs.writeFileSync(file, bytes); };
let passed = 0;
let fixtureNumber = 0;

function fixture(name, actualPayload = false, installedPrior = false) {
  const folder = path.join(testRoot, String(++fixtureNumber));
  const app = path.join(folder, 'app with spaces');
  const bundle = path.join(folder, 'unpacked update');
  const states = [];
  fs.mkdirSync(app, { recursive: true });
  fs.mkdirSync(bundle, { recursive: true });
  write(path.join(app, 'composer.json'), '{}\n');
  write(path.join(app, '.env'), 'LOCAL_ONLY_PLACEHOLDER=unchanged\n');
  write(path.join(app, 'fixture-health-exit'), '0');
  write(path.join(app, 'artisan'), `<?php
$command = $argv[1] ?? '';
if (!in_array($command, ['config:clear', 'view:clear', 'signals:check-data'], true)) { exit(99); }
file_put_contents(__DIR__.'/invocations.log', json_encode(array_slice($argv, 1)).PHP_EOL, FILE_APPEND);
if ($command === 'signals:check-data') {
    $code = (int) trim(file_get_contents(__DIR__.'/fixture-health-exit'));
    echo json_encode(['healthy' => $code === 0, 'emails_sent' => 0, 'orders_sent' => 0]).PHP_EOL;
    exit($code);
}
echo "fixture cache cleared".PHP_EOL;
`);
  fs.copyFileSync(path.join(__dirname, 'Apply-GoldRetestV2Update.ps1'), path.join(bundle, 'Apply-GoldRetestV2Update.ps1'));
  const actual = actualPayload ? createManifest() : null;
  const preserved = actualPayload ? readPreservedFreshnessPackage() : null;
  const files = runtimePaths.map(relative => {
    const actualEntry = actual?.manifest.files.find(file => file.path === relative);
    const mayCreate = actualEntry ? actualEntry.may_create : /(?:MarketDataGuard|LiveMarketDataService|SignalSnapshotCache|CheckSignalData|GoldTrendRetestStrategy|GoldTrendRetestV2Strategy|CurrentSignal|RegisterRetestV2)\.php$/.test(relative);
    const prefix = relative.endsWith('.php') ? '<?php\n// ' : '# ';
    const baseline = actualEntry && !mayCreate
      ? normalized(execFileSync('git', ['show', `${actual.manifest.baseline_commit}:${relative}`], { cwd: root, stdio: ['ignore', 'pipe', 'ignore'] }))
      : Buffer.from(prefix + 'baseline fixture: ' + relative + '\n', 'utf8');
    const updated = actualEntry ? actual.contents.get('payload/' + relative) : Buffer.from(prefix + 'updated fixture: ' + relative + '\n', 'utf8');
    const prior = actualEntry ? preserved.contents.get('payload/' + relative)
      : (runtimePaths.indexOf(relative) < 11 ? Buffer.from(prefix + 'preserved freshness fixture: ' + relative + '\n', 'utf8') : null);
    const original = installedPrior && prior ? prior : baseline;
    const isNew = mayCreate && !(installedPrior && prior);
    const target = path.join(app, relative);
    if (!isNew) write(target, Buffer.from(original.toString('utf8').replace(/\n/g, '\r\n'), 'utf8'));
    write(path.join(bundle, 'payload', relative), updated);
    states.push({ relative, target, original, updated, isNew });
    return actualEntry || { path: relative, sha256_normalized_lf: digest(updated), baseline_sha256_normalized_lf: mayCreate ? null : digest(baseline),
      accepted_installed_sha256_normalized_lf: [...new Set([mayCreate ? null : digest(baseline), prior ? digest(prior) : null].filter(Boolean))], may_create: mayCreate };
  });
  write(path.join(bundle, 'manifest.json'), JSON.stringify({ schema_version: 2, release: 'gold-retest-v2-2026-11-02',
    hash_normalization: 'UTF-8 bytes with CRLF/CR normalized to LF; BOM preserved', files }, null, 2));
  return { folder, app, bundle, states, files };
}

function run(item) {
  const result = spawnSync('powershell.exe', ['-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass',
    '-File', path.join(item.bundle, 'Apply-GoldRetestV2Update.ps1'), '-AppRoot', item.app, '-PhpPath', php],
  { encoding: 'utf8', windowsHide: true, maxBuffer: 1024 * 1024 });
  if (result.error) throw result.error;
  return { status: result.status, output: (result.stdout || '') + (result.stderr || '') };
}
function backupFolders(item) {
  const directory = path.join(item.app, 'storage/app/gold-retest-v2-backups');
  return fs.existsSync(directory) ? fs.readdirSync(directory).map(name => path.join(directory, name)) : [];
}
function unchanged(item, custom = new Map()) {
  for (const state of item.states) {
    if (custom.has(state.relative)) assert.deepEqual(fs.readFileSync(state.target), custom.get(state.relative));
    else if (state.isNew) assert.equal(fs.existsSync(state.target), false, state.relative);
    else assert.equal(digest(fs.readFileSync(state.target)), digest(state.original), state.relative);
  }
  assert.equal(backupFolders(item).length, 0);
  assert.equal(fs.existsSync(path.join(item.app, 'invocations.log')), false);
  assert.equal(fs.readFileSync(path.join(item.app, '.env'), 'utf8'), 'LOCAL_ONLY_PLACEHOLDER=unchanged\n');
}
function pass(message) { passed++; console.log(`PASS ${message}`); }
function invocations(item) { return fs.readFileSync(path.join(item.app, 'invocations.log'), 'utf8').trim().split(/\r?\n/).map(line => JSON.parse(line)); }
const expectedInvocations = [['config:clear', '--no-interaction'], ['view:clear', '--no-interaction'], ['signals:check-data', '--no-interaction']];

try {
  const basic = fixture('success-and-idempotency');
  let result = run(basic);
  assert.equal(result.status, 0, result.output);
  for (const state of basic.states) assert.deepEqual(fs.readFileSync(state.target), state.updated);
  assert.equal(fs.readFileSync(path.join(basic.app, '.env'), 'utf8'), 'LOCAL_ONLY_PLACEHOLDER=unchanged\n');
  assert.deepEqual(invocations(basic), expectedInvocations);
  const backups = backupFolders(basic);
  assert.equal(backups.length, 1);
  const record = JSON.parse(fs.readFileSync(path.join(backups[0], 'install-state.json'), 'utf8'));
  assert.equal(record.Files.length, runtimePaths.length);
  for (const state of basic.states.filter(row => !row.isNew)) {
    assert.deepEqual(fs.readFileSync(path.join(backups[0], 'original', state.relative)), Buffer.from(state.original.toString('utf8').replace(/\n/g, '\r\n')));
  }
  pass('success, full backups, paths with spaces, LF/CRLF compatibility, settings preservation, safe command allowlist');
  result = run(basic);
  assert.equal(result.status, 0, result.output);
  assert.match(result.output, /already applied/);
  assert.equal(backupFolders(basic).length, 1);
  pass('idempotent second run does not create another backup or copy runtime files');

  const priorFixture = fixture('accepted-prior-update', false, true);
  result = run(priorFixture);
  assert.equal(result.status, 0, result.output);
  for (const state of priorFixture.states) assert.deepEqual(fs.readFileSync(state.target), state.updated);
  assert.deepEqual(invocations(priorFixture), expectedInvocations);
  assert.equal(backupFolders(priorFixture).length, 1);
  pass('alternate accepted freshness hashes upgrade successfully, including files absent from HEAD');

  const localEdit = fixture('unknown-local-edit');
  const last = localEdit.states.at(-1);
  const edited = Buffer.from(last.original.toString('utf8') + '# unknown local change\n');
  write(last.target, edited);
  result = run(localEdit);
  assert.equal(result.status, 1, result.output);
  assert.match(result.output, /differs from the expected original/);
  unchanged(localEdit, new Map([[last.relative, edited]]));
  pass('unknown change in the final target aborts before any runtime write or artisan command');

  const newCollision = fixture('new-file-collision');
  const first = newCollision.states[0];
  const collision = Buffer.from('<?php\n// local unrelated implementation\n');
  write(first.target, collision);
  result = run(newCollision);
  assert.equal(result.status, 1, result.output);
  unchanged(newCollision, new Map([[first.relative, collision]]));
  pass('unrecognized existing implementation of a newly introduced file is preserved');

  const corrupt = fixture('corrupt-package');
  write(path.join(corrupt.bundle, 'payload', runtimePaths.at(-1)), '# checksum does not match\n');
  result = run(corrupt);
  assert.equal(result.status, 1, result.output);
  assert.match(result.output, /checksum failed/);
  unchanged(corrupt);
  pass('corrupt payload aborts before any runtime write');

  const traversal = fixture('path-traversal');
  const manifestPath = path.join(traversal.bundle, 'manifest.json');
  const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
  manifest.files.at(-1).path = '../.env';
  write(manifestPath, JSON.stringify(manifest));
  result = run(traversal);
  assert.equal(result.status, 1, result.output);
  assert.match(result.output, /Unexpected or duplicate/);
  unchanged(traversal);
  pass('manifest path traversal is rejected without touching application settings');

  const syntaxError = fixture('invalid-php-payload');
  const brokenPhp = Buffer.from('<?php\nif (\n');
  write(path.join(syntaxError.bundle, 'payload', runtimePaths[0]), brokenPhp);
  const syntaxManifestPath = path.join(syntaxError.bundle, 'manifest.json');
  const syntaxManifest = JSON.parse(fs.readFileSync(syntaxManifestPath, 'utf8'));
  syntaxManifest.files[0].sha256_normalized_lf = digest(brokenPhp);
  write(syntaxManifestPath, JSON.stringify(syntaxManifest));
  result = run(syntaxError);
  assert.equal(result.status, 1, result.output);
  assert.match(result.output, /PHP interpreter rejected package syntax/);
  unchanged(syntaxError);
  pass('PHP lint receives the actual payload path and rejects bad syntax before runtime writes');

  const unhealthy = fixture('unhealthy-data-keeps-fix');
  write(path.join(unhealthy.app, 'fixture-health-exit'), '1');
  result = run(unhealthy);
  assert.equal(result.status, 3, result.output);
  assert.match(result.output, /Update applied/);
  assert.match(result.output, /not healthy/);
  for (const state of unhealthy.states) assert.deepEqual(fs.readFileSync(state.target), state.updated);
  assert.equal(backupFolders(unhealthy).length, 1);
  pass('unhealthy data produces exit 3 while preserving the installed guard fix');

  if (!process.argv.includes('--fixtures-only')) {
    const actual = fixture('actual-frozen-runtime-payload', true);
    result = run(actual);
    assert.equal(result.status, 0, result.output);
    for (const state of actual.states) assert.deepEqual(fs.readFileSync(state.target), state.updated);
    assert.deepEqual(invocations(actual), expectedInvocations);
    pass('actual frozen runtime payload installs over HEAD baseline using only the stub artisan');
    const upgraded = fixture('upgrade-preserved-freshness-payload', true, true);
    result = run(upgraded);
    assert.equal(result.status, 0, result.output);
    // Already-applied files retain their original line endings; hashes normalize them.
    for (const state of upgraded.states) assert.equal(digest(fs.readFileSync(state.target)), digest(state.updated), state.relative);
    assert.deepEqual(invocations(upgraded), expectedInvocations);
    const upgradeBackups = backupFolders(upgraded);
    assert.equal(upgradeBackups.length, 1);
    for (const state of upgraded.states.filter(state => !state.isNew && digest(state.original) !== digest(state.updated))) {
      assert.deepEqual(fs.readFileSync(path.join(upgradeBackups[0], 'original', state.relative)), Buffer.from(state.original.toString('utf8').replace(/\n/g, '\r\n')));
    }
    pass('actual V2 payload safely upgrades the preserved freshness ZIP without enabling the strategy');
  }
  console.log(JSON.stringify({ passed, fixture_root: testRoot, actual_application_executed: false }));
} catch (error) {
  console.error(error.stack || error.message);
  console.error(`Inspectable fixture root: ${testRoot}`);
  process.exitCode = 1;
}
