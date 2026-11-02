// Four prespecified development candidates. No parameter or profitable-clock search.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const { readM1 } = require('./mt5_m1.cjs');

const file = process.argv[2] || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
const output = path.resolve(process.argv[3] || 'docs/research/scalper-upgrade-2026-11-02');
const probe = process.argv.includes('--primary-only');
const temporary = path.resolve('storage/app/private/signal-research/scalper-upgrade-2026-11-02');
const protocol = 'docs/research/scalper-upgrade-2026-11-02/PRE_REGISTERED.md';
const sourceFiles = [protocol, 'app/Services/TradingService.php', 'app/Services/MarketDataGuard.php',
  'app/Services/RsiScalperMinuteBacktester.php', 'scripts/research/mt5_m1.cjs',
  'scripts/research/research_scalper_candidates.php', 'scripts/research/research_scalper_candidates.cjs'];
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const hashes = () => Object.fromEntries(sourceFiles.map(file => [file, hash(file)]));
const before = hashes();
const input = readM1(file);
fs.mkdirSync(output, { recursive: true });
fs.mkdirSync(temporary, { recursive: true });
const iso = t => new Date(t * 1000).toISOString().replace('.000Z', '+00:00');
const near = (a, b) => assert.ok(Math.abs(a - b) < 1e-8, `${a} differs from ${b}`);
const candidates = ['baseline', 'trend', 'session', 'trend-session'];
const costs = [
  { name: 'gross', spread_multiplier: 0, slippage: 0, fee: 0 },
  { name: 'minimum-spread-only', spread_multiplier: 1, slippage: 0, fee: 0 },
  { name: 'assumed-costs', spread_multiplier: 1, slippage: 0.10, fee: 0.07 },
  { name: 'double-assumed-costs', spread_multiplier: 2, slippage: 0.20, fee: 0.14 },
];
const comparisons = [], clocks = [];
for (const offset of probe ? [3] : [3, 0]) {
  const minutes = input.bars.map(b => ({ ...b, time: b.time - offset * 3600 }));
  const buckets = new Map();
  for (const b of minutes) {
    const at = Math.floor(b.time / 300) * 300;
    if (!buckets.has(at)) buckets.set(at, []);
    buckets.get(at).push(b);
  }
  const m5 = [];
  let omitted = 0;
  for (const [at, rows] of buckets) {
    if (rows.length !== 5 || rows.some((b, i) => b.time !== at + i * 60)) { omitted++; continue; }
    m5.push({ timestamp: iso(at), open: rows[0].open, high: Math.max(...rows.map(b => b.high)),
      low: Math.min(...rows.map(b => b.low)), close: rows.at(-1).close });
  }
  const first = minutes[0].time, end = minutes.at(-1).time + 60;
  const split = Math.ceil((first + 0.7 * (end - first)) / 900) * 900;
  const data = { source: 'mt5', symbol: 'XAUUSD-STD',
    timestamp_assumption: `Assume CSV clock is UTC+${offset}; not independently verified.`,
    m1: minutes.map(b => ({ timestamp: iso(b.time), open: b.open, high: b.high, low: b.low, close: b.close, spread_points: b.spreadPoints })), m5 };
  const dataFile = path.join(temporary, `input-utcplus${offset}.json`);
  fs.writeFileSync(dataFile, JSON.stringify(data));
  const experiment = { filter_file: `filter-decisions-utcplus${offset}.json`, cases: [] };
  const segments = [{ name: 'full', from: null, to: null }, { name: 'first70', from: null, to: iso(split) }, { name: 'last30', from: iso(split), to: null }];
  for (const candidate of candidates) for (const segment of probe ? segments.slice(0, 1) : segments) {
    for (const target of ['tp1', 'tp2']) for (const cost of probe ? costs.filter(c => c.name === 'assumed-costs') : costs) {
      experiment.cases.push({ candidate, offset_hours: offset, segment: segment.name, target, cost: cost.name,
        file: `${candidate}-utcplus${offset}-${segment.name}-${target}-${cost.name}.json`,
        options: { point_size: 0.01, slippage: cost.slippage, fee: cost.fee, spread_multiplier: cost.spread_multiplier,
          target, position_aware: false, from: segment.from, to: segment.to } });
    }
  }
  const experimentFile = path.join(temporary, `experiment-utcplus${offset}-${probe ? 'probe' : 'all'}.json`);
  fs.writeFileSync(experimentFile, JSON.stringify(experiment));
  const result = JSON.parse(cp.execFileSync('php', ['scripts/research/research_scalper_candidates.php', dataFile, experimentFile, temporary],
    { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024 }));
  clocks.push({ offset_hours: offset, split_utc: iso(split), complete_m5: m5.length, incomplete_m5_omitted: omitted,
    diagnostics: result.diagnostics, filtered_signal_counts: result.filtered_signal_counts });
  fs.copyFileSync(path.join(temporary, experiment.filter_file), path.join(output, experiment.filter_file));
  for (const item of result.rows) {
    const raw = path.join(temporary, item.file);
    assert.equal(hash(raw), item.result_sha256);
    const r = JSON.parse(fs.readFileSync(raw, 'utf8'));
    const wins = r.trades.filter(t => t.net_r > 0), losses = r.trades.filter(t => t.net_r < 0);
    assert.equal(r.metrics.closed_trades, r.trades.length);
    assert.equal(r.metrics.wins, wins.length);
    assert.equal(r.metrics.losses, losses.length);
    near(r.metrics.net_r, r.trades.reduce((a, t) => a + t.net_r, 0));
    let total = 0, peak = 0, dd = 0;
    for (const t of r.trades) {
      near(t.risk, (t.entry - t.sl) * t.side);
      near(t.net_r, ((t.exit - t.entry) * t.side - item.options.fee) / t.risk);
      total += t.net_r; peak = Math.max(peak, total); dd = Math.max(dd, peak - total);
    }
    near(r.metrics.max_closed_trade_drawdown_r, dd);
    if (losses.length) near(r.metrics.profit_factor_r, wins.reduce((a, t) => a + t.net_r, 0) / -losses.reduce((a, t) => a + t.net_r, 0));
    assert.equal(r.signals, r.trades.length + Number(r.open_position_at_end !== null) + Object.values(r.entry_rejections).reduce((a, b) => a + b, 0));
    const { result: ignored, ...identity } = item;
    const publish = offset === 3 && item.segment === 'full' && ['assumed-costs', 'double-assumed-costs'].includes(item.cost);
    if (publish) fs.copyFileSync(raw, path.join(output, item.file));
    comparisons.push({ ...identity, raw_file: path.relative(process.cwd(), raw).replace(/\\/g, '/'), published_trade_details: publish,
      signals: r.signals, entry_rejections: r.entry_rejections, metrics: r.metrics, contiguous_path_metrics: r.contiguous_path_metrics,
      gap_trades: r.trades_crossing_nonconsecutive_minutes, ambiguous_minutes: r.ambiguous_minutes_stop_first,
      open_position_at_end: r.open_position_at_end, monthly_exit_metrics: r.monthly_exit_metrics,
      iso_week_exit_metrics: r.iso_week_exit_metrics, side_metrics: r.side_metrics, holding_minutes: r.holding_minutes });
  }
}
assert.deepEqual(hashes(), before, 'Research sources/protocol changed during execution.');
assert.equal(hash(file), input.metadata.sha256, 'Price input changed during execution.');
assert.equal(comparisons.length, probe ? 8 : 192);
const summary = { study: 'Four prespecified RSI entry-filter development diagnostics; unchanged signal and execution rules',
  analysis_date: '2026-11-02', preferred_engineering_hypothesis_declared_before_returns: 'trend with TP1 unchanged',
  genuine_unseen_data_preregistration: false, phase: probe ? 'primary-probe' : 'complete',
  protocol, source: input.metadata, source_hashes_before_after_verified_equal: before, clocks, comparisons,
  cautions: ['All provided September–October history and baseline outcomes were already inspected; no genuine untouched holdout.',
    'No clock, target or highest-return filter is selected from these results; trend-only was the advance engineering hypothesis.',
    'Clock UTC+3/UTC, point0.01, minimum-spread proxy, slippage and fee are assumptions. No swaps, funding, margin, lot sizing, latency or account returns.',
    'First70/final30 retain past warmup but reset positions/execution gates; segments are chronological diagnostics.',
    'Monthly/weekly results are grouped by exit time; gap-free results are a subset of the same simulated trades, not a separate portfolio.',
    'Different filters/targets change which later signals a one-position account can accept; performance differences are not matched independent trade effects.',
    'No maximum holding duration was added, so this study does not yet test a strictly time-limited scalp.'] };
fs.writeFileSync(path.join(output, probe ? 'primary-probe-summary.json' : 'comparison-summary.json'), JSON.stringify(summary, null, 2) + '\n');
const flat = comparisons.map(c => ({ candidate: c.candidate, offset_hours: c.offset_hours, segment: c.segment, target: c.target, cost: c.cost,
  signals: c.signals, ...c.metrics, contiguous_closed: c.contiguous_path_metrics.closed_trades, contiguous_net_r: c.contiguous_path_metrics.net_r,
  gap_trades: c.gap_trades, ambiguous_minutes: c.ambiguous_minutes, open_at_end: Number(c.open_position_at_end !== null),
  median_hold_minutes: c.holding_minutes.median, maximum_hold_minutes: c.holding_minutes.maximum }));
const fields = Object.keys(flat[0]), cell = v => '"' + String(v ?? '').replace(/"/g, '""') + '"';
fs.writeFileSync(path.join(output, probe ? 'primary-probe.csv' : 'comparison.csv'), fields.map(cell).join(',') + '\n'
  + flat.map(r => fields.map(f => cell(r[f])).join(',')).join('\n') + '\n');
console.log(JSON.stringify(flat.filter(r => r.offset_hours === 3 && r.cost === 'assumed-costs'), null, 2));
