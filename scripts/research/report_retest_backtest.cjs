// Summarize an existing experiment without changing strategy rules or selecting parameters.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');

const directory = path.resolve(process.argv[2] || 'docs/research/retest-v2-backtest-2026-11-02');
const read = filename => JSON.parse(fs.readFileSync(path.join(directory, filename), 'utf8'));
const summary = read('comparison-summary.json');
assert.equal(summary.comparisons.length, 48);
const close = (actual, expected) => assert.ok(Math.abs(actual - expected) < 1e-9, `${actual} differs from ${expected}`);
const fixed = (value, digits = 5) => value == null ? 'Undefined' : (Math.abs(value) < 0.5 * 10 ** -digits ? 0 : Number(value)).toFixed(digits);
const percent = value => value == null ? 'Undefined' : `${(100 * value).toFixed(2)}%`;
const tick = String.fromCharCode(96);
const fence = tick.repeat(3);
const sources = { v1: 'app/Services/GoldTrendRetestStrategy.php', v2: 'app/Services/GoldTrendRetestV2Strategy.php',
  minute_backtester: 'app/Services/GoldRetestMinuteBacktester.php', input_parser: 'scripts/research/mt5_m1.cjs',
  preparation_script: 'scripts/research/compare_retest_v2.cjs' };
for (const [name, filename] of Object.entries(sources)) {
  const digest = crypto.createHash('sha256').update(fs.readFileSync(filename)).digest('hex');
  assert.equal(digest, summary.source_files_sha256_before_and_after_verified_equal[name], `${filename} changed after the experiment.`);
}
for (const scenario of summary.comparisons) {
  const result = read(scenario.file);
  const trades = result.trades;
  assert.equal(result.strategy, scenario.engine_slug);
  assert.equal(result.metrics.closed_trades, trades.length);
  for (const trade of trades) {
    close(trade.risk, (trade.entry - trade.sl) * trade.side);
    close(trade.net_r, ((trade.exit - trade.entry) * trade.side - result.cost_assumptions.round_trip_fee_usd_per_ounce) / trade.risk);
    assert.equal(trade.outcome, trade.net_r > 0 ? 'win' : trade.net_r < 0 ? 'loss' : 'breakeven');
    assert.ok(Date.parse(trade.exit_minute_open) >= Date.parse(trade.opened_at));
  }
  const wins = trades.filter(trade => trade.net_r > 0), losses = trades.filter(trade => trade.net_r < 0);
  const net = trades.reduce((sum, trade) => sum + trade.net_r, 0);
  close(net, result.metrics.net_r);
  assert.equal(wins.length, result.metrics.wins);
  assert.equal(losses.length, result.metrics.losses);
  let equity = 0, peak = 0, drawdown = 0;
  for (const trade of trades) { equity += trade.net_r; peak = Math.max(peak, equity); drawdown = Math.max(drawdown, peak - equity); }
  close(drawdown, result.metrics.max_closed_trade_drawdown_r);
  if (losses.length) close(wins.reduce((sum, trade) => sum + trade.net_r, 0) / -losses.reduce((sum, trade) => sum + trade.net_r, 0), result.metrics.profit_factor_r);
  assert.equal(trades.filter(trade => !trade.crossed_nonconsecutive_minutes).length, result.contiguous_path_metrics.closed_trades);
  assert.equal(trades.length + Number(result.open_position_at_end !== null)
    + Object.values(result.entry_rejections).reduce((sum, value) => sum + value, 0), result.signals);
}
const find = (version, offset, segment, cost = 'assumed-costs') => {
  const row = summary.comparisons.find(row => row.version === version && row.offset_hours === offset && row.segment === segment && row.cost === cost);
  assert.ok(row);
  return read(row.file);
};
const primary = find('v2', 3, 'full');
const early = find('v2', 3, 'first70');
const late = find('v2', 3, 'last30');
const original = find('v1', 3, 'full');
const alternative = find('v2', 0, 'full');
const split = summary.comparisons.find(row => row.version === 'v2' && row.offset_hours === 3).split_utc;
const pht = value => new Date(Date.parse(value) + 8 * 3600000).toISOString().replace('T', ' ').replace('.000Z', ' PHT');
let cumulative = 0, lossesInARow = 0, maximumLosses = 0;
const ledger = primary.trades.map((trade, index) => {
  cumulative += trade.net_r;
  lossesInARow = trade.net_r < 0 ? lossesInARow + 1 : 0;
  maximumLosses = Math.max(maximumLosses, lossesInARow);
  return { trade: index + 1, side: trade.side === 1 ? 'BUY' : 'SELL', opened_at_utc: trade.opened_at,
    opened_at_pht: pht(trade.opened_at), exit_minute_open_utc: trade.exit_minute_open, exit_minute_open_pht: pht(trade.exit_minute_open),
    entry: trade.entry, stop_loss: trade.sl, take_profit: trade.tp, exit: trade.exit, outcome: trade.outcome,
    net_r: trade.net_r, cumulative_closed_r: cumulative, crosses_gap: trade.crossed_nonconsecutive_minutes,
    exit_reason: trade.exit_reason, setup_id: trade.setup_id };
});
const csvCell = value => `"${String(value).replace(/"/g, '""')}"`;
const fields = ledger.length ? Object.keys(ledger[0]) : [];
fs.writeFileSync(path.join(directory, 'v2-primary-trades.csv'), fields.map(csvCell).join(',') + '\n'
  + ledger.map(trade => fields.map(field => csvCell(trade[field])).join(',')).join('\n') + '\n');
const metricRow = (label, metrics) => `| ${label} | ${metrics.closed_trades} | ${metrics.wins} | ${metrics.losses} | ${percent(metrics.win_rate)} | ${fixed(metrics.net_r)}R | ${fixed(metrics.profit_factor_r)} | ${fixed(metrics.max_closed_trade_drawdown_r)}R |`;
const costRows = ['gross', 'minimum-spread-only', 'assumed-costs', 'double-assumed-costs'].map(cost => {
  const result = find('v2', 3, 'full', cost);
  return `| ${cost} | ${result.metrics.wins} | ${result.metrics.losses} | ${fixed(result.metrics.net_r)}R |`;
});
const tradeRows = ledger.map(trade => `| ${trade.trade} | ${trade.side} | ${trade.opened_at_pht} | ${trade.exit_minute_open_pht} | ${trade.outcome} | ${fixed(trade.net_r)}R | ${trade.crosses_gap ? 'Yes; unseen prices unknown' : 'No'} |`);
const text = `# Gold Trend Retest V2 backtest rerun - 2 November 2026

**The primary scenario is unprofitable: ${primary.metrics.wins} wins, ${primary.metrics.losses} losses, ${fixed(primary.metrics.net_r)}R after assumed costs. V2 has not demonstrated a profitable improvement.** This rerun uses the same already-inspected historical file and the unchanged implementation, rather than new unseen data or fitted thresholds.

## Data and execution assumptions

- Source: supplied ${tick}${summary.source.file}${tick}; ${summary.source.rows.toLocaleString('en-US')} valid bid M1 bars, ${summary.source.first_open_printed} through ${summary.source.last_open_printed} as printed. File SHA-256: ${tick}${summary.source.sha256}${tick}.
- Broker wall time is assumed UTC+3 for the primary case. CSV UTC is a sensitivity, not an alternative chosen for higher returns. Neither clock is confirmed terminal metadata; the Philippines timezone for receiving alerts does not establish the CSV timezone.
- Complete M15 and H1 buckets are aggregated from that M1 file; 43 partial M15 and 44 partial H1 buckets are omitted. Native broker candles can legitimately contain fewer than a full interval's minute observations, so these aggregates need not reproduce native broker M15/H1 indicators. V2 requires 320 completed bars in each input; earlier history supplies warmup. The engine uses H1 trend alignment, a closed M15 breakout, its first qualifying one-to-four-bar retest, a structural/ATR stop, a full 2R target, and a 07:00-17:00 UTC weekday entry session.
- Enter at the immediate next M1 open only, within the frozen retest entry bounds; one position at a time, two entries per UTC day, 30-minute cooldown, spread and reward/risk limits. Missing immediate bars are rejected. Signal evaluation uses no future candle data.
- Assumed broker point size: 0.01. Minute spread is 21-28 points in the supplied file. Primary fills include that minute's reported minimum spread, $0.10/oz adverse slippage on each fill, and $0.07/oz round-trip commission. These costs and point size are assumptions, not confirmed broker charges.
- Stops use adverse opening prices if crossed at a later bar open. Both-hit M1 candles resolve stop first. The run has ${primary.ambiguous_minutes_stop_first} such ambiguous minutes and ${primary.open_position_at_end === null ? 'no open position' : 'an open position'} at the export end.

## Working UTC+3 results

| Scenario | Trades | Wins | Losses | Win rate | Net R | Profit factor in R | Closed-trade drawdown |
|---|---:|---:|---:|---:|---:|---:|---:|
${metricRow('V2 full period, assumed costs', primary.metrics)}
${metricRow('V2 excluding gap-crossing paths', primary.contiguous_path_metrics)}
${metricRow('V2 first 70% of calendar span', early.metrics)}
${metricRow('V2 final 30% of calendar span', late.metrics)}
${metricRow('V2 final 30%, excluding gap-crossing paths', late.contiguous_path_metrics)}
${metricRow('V1 full-history reference', original.metrics)}

V2 generated ${primary.signals} eligible snapshots; ${primary.entry_rejections.existing_position} were rejected because a position was already open. The ${primary.metrics.closed_trades} executed simulations were all SELL trades, so this sample does not evaluate BUY-side performance. Expectancy is ${fixed(primary.metrics.expectancy_r)}R per closed trade. The longest consecutive losing sequence was ${maximumLosses}. V1's single winner and its old state bug are insufficient evidence that V1 is superior.

**R** means each trade's initial entry-to-stop distance. Returns include the declared costs; 1R is not one dollar, one Philippine peso or an account percentage. Drawdown here is based on closed trades, not intratrade equity. Account size, lots, leverage and actual account fills were not provided.

The calendar split is ${split}, or ${pht(split)}. It splits elapsed calendar time, not trades or bar counts. Segments retain earlier warmup but reset position/daily/cooldown state. Because the final segment has already been inspected, it is a chronological diagnostic rather than an untouched holdout.

## Cost and timezone sensitivity

| UTC+3 cost model | Wins | Losses | Net R |
|---|---:|---:|---:|
${costRows.join('\n')}

Treating printed CSV time as UTC gives ${alternative.metrics.closed_trades} closed V2 trades, ${alternative.metrics.wins} wins, ${alternative.metrics.losses} losses and ${fixed(alternative.metrics.net_r)}R after assumed costs. Its final 30% has one losing trade. This large change makes broker-clock confirmation necessary; selecting the more profitable clock would bias the result. All 48 V1/V2, clock, cost and segment cases are in [comparison.csv](comparison.csv).

## Every primary V2 trade

| # | Side | Entry PHT | Exit minute begins PHT | Outcome | Net R | Crosses an absent interval? |
|---|---|---|---|---|---:|---|
${tradeRows.join('\n')}

The exit timestamps identify the one-minute bar containing the exit; the exact exit second is unknown. Download the full [trade ledger CSV](v2-primary-trades.csv), including entry, initial stop, target, fill proxy and cumulative closed R, or inspect [the complete primary JSON](v2-utcplus3-full-assumed-costs.json).

## Limits and checks

The data has ${summary.source.nonconsecutive_intervals} nonconsecutive intervals, including ordinary session breaks. One winning primary trade spans a daily gap. All-trade results assume no unseen execution during those gaps; excluding gap-crossing paths gives the separate, weaker result above. These treatments do not reconstruct unknown prices.

The MT5 export's minute minimum spread is only a proxy for ask prices. Tick order, actual opening spread, financing/swaps, historical news, broker rejections, latency, volume rounding, margin and account loss limits are not modeled. This broker-history experiment cannot reproduce Twelve Data's exact price stream or the email scheduler's minute-by-minute API availability and delivery. It is not a verified live-alert or account-performance replay. The one-position rule is an execution assumption in this backtest; an email alone does not track whether its recipient has an open broker position.

An independent check of the actual primary snapshots found all nine entries within the live 0.1R quote-drift limit and inside their stop/target brackets. Applying the production New York session filter to the UTC+3 aggregate history removes no bars. These specific gates therefore do not change the primary sample; provider-price and availability differences remain unknown.

The rerun checks that strategy/tester/parser code and the CSV remained unchanged during evaluation. This report independently reconciles all 48 scenarios' outcome counts, entry counts, return calculations, profit factors and closed-trade drawdowns against their trade logs. A separate review reconciled all 144 trade rows across those scenarios and verified that all 48 rerun JSON results exactly match the earlier frozen comparison. The relevant PHP strategy, minute-execution and offline-command checks passed: 28 tests, 91 assertions. No email, order, strategy activation or runtime-code change was performed for this backtest.

Reproduce from the repository root:

${fence}powershell
node scripts/research/compare_retest_v2.cjs 'C:\\Users\\Romark\\Downloads\\XAUUSD-STD_M1_202609010100_202610302356.csv' 'docs/research/retest-v2-backtest-2026-11-02'
node scripts/research/report_retest_backtest.cjs 'docs/research/retest-v2-backtest-2026-11-02'
${fence}

[Machine-readable summary and source provenance](comparison-summary.json).
`;
fs.writeFileSync(path.join(directory, 'REPORT.md'), text);
fs.writeFileSync(path.join(directory, 'verification.json'), JSON.stringify({ verified_at_utc: new Date().toISOString(),
  scenarios_independently_reconciled: summary.comparisons.length, source_hashes_still_match: true,
  primary_trades: primary.metrics.closed_trades, maximum_consecutive_losses: maximumLosses,
  strategy_or_cost_parameters_changed: false, emails_sent: 0, orders_sent: 0 }, null, 2) + '\n');
console.log(JSON.stringify({ report: path.join(directory, 'REPORT.md'), trade_ledger: path.join(directory, 'v2-primary-trades.csv'),
  reconciled_scenarios: summary.comparisons.length, primary_metrics: primary.metrics }, null, 2));
