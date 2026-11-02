// Build a readable report from the frozen, prespecified RSI experiment.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const folder = path.resolve(process.argv[2] || 'docs/research/rsi-scalper-backtest-2026-11-02');
const read = file => JSON.parse(fs.readFileSync(path.join(folder, file), 'utf8'));
const summary = read('comparison-summary.json');
assert.equal(summary.comparisons.length, 96);
for (const [file, expected] of Object.entries(summary.source_hashes_before_and_after_verified_equal)) {
  assert.equal(crypto.createHash('sha256').update(fs.readFileSync(file)).digest('hex'), expected, `${file} changed after the experiment.`);
}
const find = (target = 'tp1', exit = 'fixed', segment = 'full', cost = 'assumed-costs', offset = 3) => {
  const row = summary.comparisons.find(row => row.target === target && row.exit_policy === exit
    && row.segment === segment && row.cost === cost && row.offset_hours === offset);
  assert.ok(row);
  return row;
};
const p = find(), t2 = find('tp2'), late = find('tp1', 'fixed', 'last30'), late2 = find('tp2', 'fixed', 'last30'), managed = find('tp1', 'managed');
const fmt = (value, digits = 3) => value == null ? 'Undefined' : (Math.abs(value) < 0.5 * 10 ** -digits ? 0 : Number(value)).toFixed(digits);
const rate = value => value == null ? 'Undefined' : `${(100 * value).toFixed(2)}%`;
const pht = value => new Date(Date.parse(value) + 8 * 3600000).toISOString().replace('T', ' ').replace('.000Z', ' PHT');
const csvCell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
function ledger(row, output) {
  const result = read(row.file);
  let cumulative = 0, losing = 0, maximumLosses = 0;
  const rows = result.trades.map((trade, index) => {
    cumulative += trade.net_r;
    losing = trade.net_r < 0 ? losing + 1 : 0;
    maximumLosses = Math.max(maximumLosses, losing);
    return { trade: index + 1, side: trade.side === 1 ? 'BUY' : 'SELL', opened_at_utc: trade.opened_at,
      opened_at_pht: pht(trade.opened_at), exit_minute_open_utc: trade.exit_minute_open,
      exit_minute_open_pht: pht(trade.exit_minute_open), entry: trade.entry, stop_loss: trade.sl,
      target: trade.tp, exit: trade.exit, outcome: trade.outcome, net_r: trade.net_r,
      cumulative_closed_r: cumulative, crosses_gap: trade.crossed_nonconsecutive_minutes,
      exit_reason: trade.exit_reason, signal_candle_open_utc: trade.signal_timestamp_open,
      decision_at_utc: trade.decision_at, signal_entry_price: trade.signal_entry_price,
      quote_before_slippage: trade.entry_quote_before_slippage, quote_drift_r: trade.entry_quote_drift_r,
      entry_minimum_spread_proxy: trade.entry_spread_proxy };
  });
  assert.ok(Math.abs(cumulative - result.metrics.net_r) < 1e-8);
  const fields = rows.length ? Object.keys(rows[0]) : [];
  fs.writeFileSync(path.join(folder, output), fields.map(csvCell).join(',') + '\n'
    + rows.map(row => fields.map(field => csvCell(row[field])).join(',')).join('\n') + '\n');
  const hours = result.trades.map(trade => (Date.parse(trade.exit_minute_open) - Date.parse(trade.opened_at)) / 3600000).sort((a, b) => a - b);
  const middle = Math.floor(hours.length / 2);
  const bySide = ['BUY', 'SELL'].map(side => {
    const trades = rows.filter(row => row.side === side);
    return { side, trades: trades.length, wins: trades.filter(row => row.net_r > 0).length,
      losses: trades.filter(row => row.net_r < 0).length, net_r: trades.reduce((sum, row) => sum + row.net_r, 0) };
  });
  return { maximumLosses, buys: rows.filter(row => row.side === 'BUY').length, sells: rows.filter(row => row.side === 'SELL').length,
    median_holding_hours: hours.length ? (hours.length % 2 ? hours[middle] : (hours[middle - 1] + hours[middle]) / 2) : null,
    maximum_holding_hours: hours.length ? hours.at(-1) : null, by_side: bySide };
}
const details = ledger(p, 'tp1-primary-trades.csv');
ledger(t2, 'tp2-primary-trades.csv');
const row = (label, metric) => `| ${label} | ${metric.closed_trades} | ${metric.wins} | ${metric.losses} | ${rate(metric.win_rate)} | ${fmt(metric.net_r)}R | ${fmt(metric.profit_factor_r)} | ${fmt(metric.max_closed_trade_drawdown_r)}R |`;
const costRows = ['gross', 'minimum-spread-only', 'assumed-costs', 'double-assumed-costs'].map(cost => {
  const item = find('tp1', 'fixed', 'full', cost);
  return `| ${cost} | ${item.metrics.closed_trades} | ${item.metrics.wins} | ${item.metrics.losses} | ${fmt(item.metrics.net_r)}R |`;
});
const timezoneRows = [3, 0].flatMap(offset => ['tp1', 'tp2'].map(target => {
  const item = find(target, 'fixed', 'full', 'assumed-costs', offset);
  return `| UTC+${offset} | ${target.toUpperCase()} | ${item.metrics.closed_trades} | ${item.metrics.wins} | ${item.metrics.losses} | ${fmt(item.metrics.net_r)}R |`;
}));
const rejected = Object.entries(p.entry_rejections).map(([reason, number]) => `| ${reason} | ${number} |`).join('\n');
const v2File = path.resolve('docs/research/retest-v2-backtest-2026-11-02/v2-utcplus3-full-assumed-costs.json');
const v2 = fs.existsSync(v2File) ? JSON.parse(fs.readFileSync(v2File, 'utf8')) : null;
const tick = String.fromCharCode(96), fence = tick.repeat(3);
const report = `# Existing RSI Gold Scalper backtest - 2 November 2026

**The fixed-TP1 primary simulation produced ${p.metrics.wins} wins, ${p.metrics.losses} losses and ${fmt(p.metrics.net_r)}R after assumed costs.** This tests the current scalper implementation, without changing or selecting its strategy parameters. The result is conditional on the broker clock, execution and cost assumptions below.

## Data and actual strategy rules

Source: ${summary.source.rows.toLocaleString('en-US')} bid M1 candles from the supplied ${tick}${summary.source.file}${tick}, ${summary.source.first_open_printed} through ${summary.source.last_open_printed} as printed. SHA-256: ${tick}${summary.source.sha256}${tick}. This is the same September-October file used for V2, not a live quote feed or a broker account statement.

The test calls the actual ${tick}TradingService::evaluateRsiMovingAverage${tick} method with unchanged defaults:

- Five-minute candles, short SMA9 and long SMA21. BUY only on an upward crossover with 50 < RSI < 70; SELL on a downward crossover with 30 < RSI < 50.
- RSI14 uses the last 14 simple close-to-close gains/losses. ATR14 is the arithmetic mean of the last 14 true ranges. Neither is a Wilder-smoothed calculation in this implementation.
- Stops use the larger of 1.2 ATR and the distance beyond the last 12-bar swing plus 0.2 ATR. Original rounded four-decimal stop/target levels are preserved.
- TP1 is 1.5 times planned risk; TP2 is 2.5 times planned risk. They are tested as separate full-position exits. No partial-exit split, trailing stop or break-even move is invented.
- Historical M5 candles must be complete and inside the production New York session filter. The last 200 valid completed bars are supplied at each evaluation; the first 200 available bars form the warmup. The evaluator itself requires only 23, so four otherwise evaluable early entry events are deliberately outside that warmup under each clock case.

## Execution model and results

The primary model accepts only one open position. Enter at the immediate next M1 open after the completed signal candle. BUY uses the ask proxy, SELL uses bid; the pre-slippage quote must be inside the original SL/TP1 bracket and within 0.1R of the printed entry, matching the live entry guard. Missing immediate bars or a closed session reject an entry instead of moving it into the future. The actual fill includes adverse slippage. There is no V2-specific minimum 1.8 reward/risk, two-trade daily cap, cooldown or 07:00-17:00 entry filter added to the scalper.

Primary assumptions are CSV broker UTC+3, point size 0.01, each minute's reported minimum spread, $0.10/oz slippage on each fill and $0.07/oz round-trip commission. Short exits use ask proxies. Both-hit minutes resolve stop first; adverse opening gaps fill stops at the worse opening quote, while favorable target gaps receive no extra improvement.

| Scenario | Closed trades | Wins | Losses | Win rate | Net R | Profit factor in R | Closed-trade drawdown |
|---|---:|---:|---:|---:|---:|---:|---:|
${row('Scalper TP1, full period', p.metrics)}
${row('Scalper TP1, gap-free trade subset', p.contiguous_path_metrics)}
${row('Scalper TP1, final 30% of calendar span', late.metrics)}
${row('Scalper TP2, full period', t2.metrics)}
${row('Scalper TP2, final 30% of calendar span', late2.metrics)}
${row('Scalper TP1 plus position-aware CLOSE', managed.metrics)}
${v2 ? row('V2 full 2R target, earlier same-cost comparison', v2.metrics) : ''}

The primary run includes ${details.buys} BUY and ${details.sells} SELL closed trades, with longest consecutive losing sequence ${details.maximumLosses}. It generated ${p.signals} entry signals in the evaluation range. An open position at the end is ${p.open_position_at_end === null ? 'absent' : 'reported separately and excluded from closed returns'}. There are ${p.trades_crossing_nonconsecutive_minutes} gap-crossing closed trades and ${p.ambiguous_minutes_stop_first} ambiguous both-hit minutes. Gap-free results exclude trades retrospectively; they are a subset, not a separately rerun portfolio.

The scalper has no fixed time exit in this primary model. Median closed holding time is approximately ${fmt(details.median_holding_hours)} hours and the longest is ${fmt(details.maximum_holding_hours)} hours, measured to the exit minute's opening. Its name does not mean every position closes within the same day; financing costs for overnight positions are unavailable and omitted.

| Primary TP1 direction | Closed trades | Wins | Losses | Net R |
|---|---:|---:|---:|---:|
${details.by_side.map(side => `| ${side.side} | ${side.trades} | ${side.wins} | ${side.losses} | ${fmt(side.net_r)}R |`).join('\n')}

This directional split describes the inspected price period. It does not establish that removing BUY trades or adding a trend filter would improve future results; that would require a separately specified test on new data.

| Entry rejection reason | Count |
|---|---:|
${rejected}

**R means the actual initial entry-to-stop distance for each trade.** Returns include declared costs; they are not dollar, Philippine-peso or account-percentage profits. Drawdown uses closed trades and omits intratrade equity. Account size, volume, leverage and actual fills are unavailable.

## Position-aware CLOSE is a separate model

The scalper also contains logic to close a known long on a bearish crossover or RSI >= 70, and a known short on a bullish crossover or RSI <= 30. The current email scheduler evaluates with no position context, so those CLOSE branches do not manage recipients' trades. Fixed TP1 is the primary email-plan model. The managed scenarios deliberately supply the simulated position and execute a CLOSE at the next M1 open; they are not claims about current automated email behavior. Opening SL/TP gap events take priority over a voluntary CLOSE, with no same-bar reversal entry.

TP1, TP2 and managed policies can keep positions open for different durations, changing which later signals can be executed. Their totals therefore need not describe the same entry cohort. The V2 comparison likewise has different entry frequency, target, warmup and exposure gates. All policies and costs were declared before running; none is selected as a demonstrated superior strategy.

## Cost sensitivity: fixed TP1, CSV UTC+3

| Cost model | Closed trades | Wins | Losses | Net R |
|---|---:|---:|---:|---:|
${costRows.join('\n')}

Changing spread also changes short-side stop/target touches, holding times, eligibility and which later entries are available. These rows need not share an identical trade cohort, so the difference between them is not simply a fee subtraction from the same trades.

The CSV's spread is a minute minimum rather than a historical opening spread or tick ask series. A price printed to two decimals does not independently establish broker point size. Slippage and commission are assumptions, not verified account charges. Swaps/financing, historical news, variable intraminute spread, latency, stop-distance limits, volume rounding, margin and account loss limits are omitted.

## Clock and sample sensitivity

| CSV clock assumption | Fixed full-position exit | Closed trades | Wins | Losses | Net R |
|---|---|---:|---:|---:|---:|
${timezoneRows.join('\n')}

The Philippines timezone of email delivery does not establish the CSV clock. UTC+3 is the working assumption, not a choice fitted to returns; CSV UTC is a sensitivity. Confirm historical broker metadata before treating either as the actual trading session.

The final 30% split retains earlier warmup but resets the simulated position at the segment start, using the same calendar boundary as V2. The data was already inspected, so this is a chronological diagnostic, not an untouched holdout. [Clock/filter diagnostics and all 96 cases](comparison-summary.json) and [comparison CSV](comparison.csv) retain both clocks, TP1/TP2, fixed/managed exits, full/first70/last30 segments and four cost models.

Complete-minute aggregation can omit native provider/broker candles with fewer observations. This broker-price reconstruction cannot reproduce Twelve Data's exact candles, quote timestamps, API outages, the scheduler's timing or inbox delivery. It is not a replay of actual account trades or exact historical emails.

The previous PDF-based 12-win/6-loss scalper result covered only 18 resolvable alerts out of 104. This new test generates signals across the supplied price history. Those are different cohorts and cannot be combined into one win rate.

## Files and reproduction

- [Primary TP1 trade ledger, UTC and Philippine time](tp1-primary-trades.csv)
- [Alternative TP2 trade ledger](tp2-primary-trades.csv)
- [Primary detailed simulation](utcplus3-full-tp1-fixed-assumed-costs.json)
- [Primary actual strategy entry snapshots and position-close times](signals-utcplus3.json)

The runner reconciles all 96 cases' entry counts, wins/losses, return formulas, profit factors and closed-trade drawdowns, and verifies unchanged strategy/tester/parser/CSV hashes. Full-period detail files are published here; segment detail files remain in ignored local research storage and regenerate with the commands below. No live alerts, orders, strategy settings or credentials are changed.

Fifteen focused backtester tests passed with 66 assertions, covering the actual evaluator, closed-candle causality, quotes/fills/costs, stops/targets, voluntary exits, gaps, exact entry timing, timestamp identity and input provenance. A separate JavaScript reconstruction independently matched all 515 primary entry plans and all 331 trade records across the primary full-period and final-30% TP1/TP2 cases, including their timestamps, prices, stop/target touches and returns. See [the independent verification record](independent-verification.json). A broader run including the existing live-pipeline checks passed 46 tests with 144 assertions.

${fence}powershell
node scripts/research/backtest_rsi_scalper.cjs 'C:\\Users\\Romark\\Downloads\\XAUUSD-STD_M1_202609010100_202610302356.csv' 'docs/research/rsi-scalper-backtest-2026-11-02'
node scripts/research/report_rsi_scalper.cjs 'docs/research/rsi-scalper-backtest-2026-11-02'
${fence}
`;
fs.writeFileSync(path.join(folder, 'REPORT.md'), report);
console.log(JSON.stringify({ report: path.join(folder, 'REPORT.md'), primary: p.metrics, alternative_tp2: t2.metrics,
  last30: late.metrics, primary_contiguous: p.contiguous_path_metrics, primary_breakdown: details }, null, 2));
