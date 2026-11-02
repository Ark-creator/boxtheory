// Prespecified RSI scalper experiment. No strategy parameters are optimized here.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const cp = require('node:child_process');
const assert = require('node:assert/strict');
const { readM1 } = require('./mt5_m1.cjs');

const file = process.argv[2] || 'C:/Users/Romark/Downloads/XAUUSD-STD_M1_202609010100_202610302356.csv';
const output = path.resolve(process.argv[3] || 'docs/research/rsi-scalper-backtest-2026-11-02');
const temporary = path.resolve('storage/app/private/signal-research/rsi-scalper-backtest-2026-11-02');
fs.mkdirSync(output, { recursive: true });
fs.mkdirSync(temporary, { recursive: true });
const sourcePaths = ['app/Services/TradingService.php', 'app/Services/MarketDataGuard.php',
  'app/Services/RsiScalperMinuteBacktester.php', 'scripts/research/mt5_m1.cjs',
  'scripts/research/run_rsi_scalper.php', 'scripts/research/backtest_rsi_scalper.cjs'];
const hash = file => crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex');
const sourceHashes = () => Object.fromEntries(sourcePaths.map(file => [file, hash(file)]));
const before = sourceHashes();
const input = readM1(file);
const iso = seconds => new Date(seconds * 1000).toISOString().replace('.000Z', '+00:00');
const caseRows = [];
const clocks = [];
const costs = [
  { name: 'gross', spread_multiplier: 0, slippage: 0, fee: 0 },
  { name: 'minimum-spread-only', spread_multiplier: 1, slippage: 0, fee: 0 },
  { name: 'assumed-costs', spread_multiplier: 1, slippage: 0.10, fee: 0.07 },
  { name: 'double-assumed-costs', spread_multiplier: 2, slippage: 0.20, fee: 0.14 },
];
const approx = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-8, `${actual} differs from ${expected}`);

for (const offset of [3, 0]) {
  const minutes = input.bars.map(bar => ({ ...bar, time: bar.time - offset * 3600 }));
  const grouped = new Map();
  for (const minute of minutes) {
    const bucket = Math.floor(minute.time / 300) * 300;
    if (!grouped.has(bucket)) grouped.set(bucket, []);
    grouped.get(bucket).push(minute);
  }
  const m5 = [];
  let partial = 0;
  for (const [time, rows] of grouped) {
    if (rows.length !== 5 || rows.some((bar, i) => bar.time !== time + i * 60)) { partial++; continue; }
    m5.push({ timestamp: iso(time), open: rows[0].open, high: Math.max(...rows.map(bar => bar.high)),
      low: Math.min(...rows.map(bar => bar.low)), close: rows.at(-1).close });
  }
  const first = minutes[0].time, end = minutes.at(-1).time + 60;
  // Keep the exact same calendar split used by the V2 comparison.
  const split = Math.ceil((first + 0.7 * (end - first)) / 900) * 900;
  const assumption = `Assume CSV printed broker wall time is UTC+${offset} throughout; offset is not independently verified.`;
  const dataset = { source: 'mt5', symbol: 'XAUUSD-STD', timestamp_assumption: assumption,
    m1: minutes.map(bar => ({ timestamp: iso(bar.time), open: bar.open, high: bar.high, low: bar.low,
      close: bar.close, spread_points: bar.spreadPoints })), m5 };
  const dataFile = path.join(temporary, `input-utcplus${offset}.json`);
  fs.writeFileSync(dataFile, JSON.stringify(dataset));
  const experiment = { signal_file: `signals-utcplus${offset}.json`, cases: [] };
  for (const segment of [{ name: 'full', from: null, to: null }, { name: 'first70', from: null, to: iso(split) },
    { name: 'last30', from: iso(split), to: null }]) {
    for (const target of ['tp1', 'tp2']) for (const managed of [false, true]) for (const cost of costs) {
      experiment.cases.push({ offset_hours: offset, segment: segment.name, target,
        exit_policy: managed ? 'managed' : 'fixed', cost: cost.name,
        file: `utcplus${offset}-${segment.name}-${target}-${managed ? 'managed' : 'fixed'}-${cost.name}.json`,
        options: { point_size: 0.01, spread_multiplier: cost.spread_multiplier, slippage: cost.slippage, fee: cost.fee,
          target, position_aware: managed, from: segment.from, to: segment.to } });
    }
  }
  const experimentFile = path.join(temporary, `experiment-utcplus${offset}.json`);
  fs.writeFileSync(experimentFile, JSON.stringify(experiment));
  const execution = JSON.parse(cp.execFileSync('php', ['scripts/research/run_rsi_scalper.php', dataFile, experimentFile, temporary],
    { encoding: 'utf8', maxBuffer: 16 * 1024 * 1024 }));
  clocks.push({ offset_hours: offset, timestamp_assumption: assumption, split_utc: iso(split),
    complete_m5_before_session_filter: m5.length, partial_m5_omitted: partial, diagnostics: execution.diagnostics });
  for (const item of execution.rows) {
    const resultFile = path.join(temporary, item.file);
    assert.equal(hash(resultFile), item.result_sha256);
    const result = JSON.parse(fs.readFileSync(resultFile, 'utf8'));
    const trades = result.trades;
    const wins = trades.filter(trade => trade.net_r > 0), losses = trades.filter(trade => trade.net_r < 0);
    assert.equal(trades.length, result.metrics.closed_trades);
    assert.equal(wins.length, result.metrics.wins);
    assert.equal(losses.length, result.metrics.losses);
    approx(trades.reduce((sum, trade) => sum + trade.net_r, 0), result.metrics.net_r);
    let equity = 0, peak = 0, drawdown = 0;
    for (const trade of trades) {
      approx(trade.risk, (trade.entry - trade.sl) * trade.side);
      approx(trade.net_r, ((trade.exit - trade.entry) * trade.side - item.options.fee) / trade.risk);
      assert.ok(Date.parse(trade.exit_minute_open) >= Date.parse(trade.opened_at));
      equity += trade.net_r; peak = Math.max(peak, equity); drawdown = Math.max(drawdown, peak - equity);
    }
    approx(drawdown, result.metrics.max_closed_trade_drawdown_r);
    if (losses.length) approx(wins.reduce((sum, trade) => sum + trade.net_r, 0) / -losses.reduce((sum, trade) => sum + trade.net_r, 0), result.metrics.profit_factor_r);
    assert.equal(trades.length + Number(result.open_position_at_end !== null)
      + Object.values(result.entry_rejections).reduce((sum, value) => sum + value, 0), result.signals);
    assert.equal(trades.filter(trade => !trade.crossed_nonconsecutive_minutes).length, result.contiguous_path_metrics.closed_trades);
    if (item.segment === 'full') fs.copyFileSync(resultFile, path.join(output, item.file));
    const { result: ignoredResult, ...identity } = item;
    caseRows.push({ ...identity, raw_file: path.relative(process.cwd(), resultFile).replace(/\\/g, '/'),
      published_trade_details: item.segment === 'full', signals: result.signals, entry_rejections: result.entry_rejections,
      metrics: result.metrics, contiguous_path_metrics: result.contiguous_path_metrics,
      trades_crossing_nonconsecutive_minutes: result.trades_crossing_nonconsecutive_minutes,
      ambiguous_minutes_stop_first: result.ambiguous_minutes_stop_first, open_position_at_end: result.open_position_at_end });
  }
  fs.copyFileSync(path.join(temporary, experiment.signal_file), path.join(output, experiment.signal_file));
}
assert.equal(caseRows.length, 96);
assert.deepEqual(sourceHashes(), before, 'Sources changed during the experiment; rerun against stable code.');
assert.equal(hash(file), input.metadata.sha256, 'Input prices changed during the experiment.');
const summary = { study: 'Existing RSI Gold Scalper, unchanged default rules; offline causal M1 execution research',
  analysis_date: '2026-11-02', completed_at_utc: new Date().toISOString(), source: input.metadata,
  source_hashes_before_and_after_verified_equal: before, scenarios: 96, clocks, comparisons: caseRows,
  cautions: ['UTC+3 primary and UTC sensitivity are assumptions, never chosen by returns.',
    'Broker point0.01, minute minimum spread, slippage0.10 per fill and commission0.07 per ounce are assumptions.',
    'Primary fixed TP1 matches entry-only email use; TP2 is an alternative full-position exit, with no invented partial split.',
    'Managed exits evaluate existing position-aware CLOSE logic; the current email scheduler does not provide this position context.',
    'One-position execution is a declared model; recipient positions and API availability are not reconstructed.',
    'The final30% has been inspected before and is not untouched out-of-sample data.',
    'No swaps, account sizing, volume rounding, margin, historical news, latency or exact historical ask ticks.'],
};
fs.writeFileSync(path.join(output, 'comparison-summary.json'), JSON.stringify(summary, null, 2) + '\n');
const flat = caseRows.map(row => ({ offset_hours: row.offset_hours, segment: row.segment, target: row.target,
  exit_policy: row.exit_policy, cost: row.cost, signals: row.signals, ...row.metrics,
  gap_trades: row.trades_crossing_nonconsecutive_minutes, contiguous_closed: row.contiguous_path_metrics.closed_trades,
  contiguous_net_r: row.contiguous_path_metrics.net_r, open_at_end: Number(row.open_position_at_end !== null) }));
const fields = Object.keys(flat[0]);
const cell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
fs.writeFileSync(path.join(output, 'comparison.csv'), fields.map(cell).join(',') + '\n'
  + flat.map(row => fields.map(field => cell(row[field])).join(',')).join('\n') + '\n');
console.log(JSON.stringify(flat.filter(row => row.offset_hours === 3 && row.cost === 'assumed-costs'), null, 2));
