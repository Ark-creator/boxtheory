// Publish the actual implementation decision and all important counterevidence.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const assert = require('node:assert/strict');
const directory = path.resolve('docs/research/scalper-upgrade-2026-11-02');
const read = file => JSON.parse(fs.readFileSync(path.join(directory, file), 'utf8'));
const development = read('comparison-summary.json');
const external = read('external-validation-results.json');
const production = read('production-parity.json');
const phpPlans = read('external-production-parity.json');
assert.equal(development.comparisons.length, 192);
assert.equal(external.cases.length, 12);
assert.equal(production.status, 'passed');
assert.equal(external.input_sha256, phpPlans.input_sha256);
for (const name of ['app/Services/RsiGoldScalperV2Strategy.php', 'app/Services/TradingService.php', 'app/Services/MarketDataGuard.php']) {
  assert.equal(crypto.createHash('sha256').update(fs.readFileSync(name)).digest('hex'),
    production.source_and_input_hashes_verified_unchanged_during_run[name], `${name} changed after production parity; verify it again.`);
}
assert.ok(external.numerical_replay_comparison, 'Publish the actual-PHP numerical replay, not the superseded JavaScript rounding case.');
const dev = (candidate, cost = 'assumed-costs', segment = 'full') => {
  const row = development.comparisons.find(row => row.candidate === candidate && row.cost === cost && row.segment === segment && row.target === 'tp1' && row.offset_hours === 3);
  assert.ok(row); return row;
};
const extra = (model, cost = 'assumed-costs') => {
  const row = external.cases.find(row => row.model === model && row.cost === cost);
  assert.ok(row && row.status === 'conditional_diagnostic'); return row;
};
const before = dev('baseline'), revised = dev('trend-session'), preferred = dev('trend');
const late = dev('trend-session', 'assumed-costs', 'last30');
const oldExtra = extra('baseline'), newExtra = extra('trend-session'), stress = extra('trend-session', 'doubled-costs');
const fmt = number => Number(number).toFixed(2);
const rate = number => `${(100 * number).toFixed(1)}%`;
const periodRow = (name, result) => `| ${name} | ${result.metrics.closed_trades} | ${result.metrics.wins} | ${result.metrics.losses} | ${fmt(result.metrics.net_r)}R | ${fmt(result.metrics.profit_factor_r)} | ${fmt(result.metrics.max_closed_trade_drawdown_r)}R |`;
const months = Object.entries(newExtra.monthly_exit_metrics);
const monthlyRows = months.map(([month, m]) => `| ${month} | ${m.closed_trades} | ${m.wins} | ${m.losses} | ${fmt(m.net_r)}R | ${fmt(stress.monthly_exit_metrics[month].net_r)}R |`);
const outsideJuly = months.filter(([month]) => month !== '2026-07').reduce((sum, [, m]) => sum + m.net_r, 0);
const pht = value => new Date(Date.parse(value) + 8 * 3600000).toISOString().replace('T', ' ').replace('.000Z', ' PHT');
let total = 0;
const trades = newExtra.trades.map((t, index) => {
  total += t.net_r;
  return { trade: index + 1, side: t.side === 1 ? 'BUY' : 'SELL', opened_at_utc: t.opened_at,
    opened_at_pht: pht(t.opened_at), exit_minute_open_utc: t.exit_minute_open, exit_minute_open_pht: pht(t.exit_minute_open),
    signal_entry_price: t.signal_entry_price, entry: t.entry, stop_loss: t.sl, full_tp1: t.tp, exit: t.exit,
    exit_reason: t.exit_reason, outcome: t.outcome, net_r: t.net_r, cumulative_closed_r: total,
    crosses_unobserved_interval: t.crossed_nonconsecutive_minutes };
});
assert.ok(Math.abs(total - newExtra.metrics.net_r) < 1e-8);
const fields = Object.keys(trades[0]), cell = value => `"${String(value ?? '').replace(/"/g, '""')}"`;
fs.writeFileSync(path.join(directory, 'external-combined-tp1-trades.csv'), fields.map(cell).join(',') + '\n'
  + trades.map(t => fields.map(f => cell(t[f])).join(',')).join('\n') + '\n');
const report = `# Implemented RSI Gold Scalper V2 and validation outcome

2 November 2026, Asia/Manila. The current XAUUSD scalper now defaults to the **trend-plus-session V2 paper research model**, retaining the original evaluator and a legacy rollback. Its aggregate modeled results improved in both the inspected development period and additional, previously uninspected history. **This does not establish reliable live profitability.** Monthly losses, return concentration, simulated costs, provider differences and unverified fills remain important.

## Actual revised rules

Keep the existing completed-M5 SMA9/21 crossover and simple RSI14 confirmation. BUY requires price above a rising SMA200; SELL requires price below a falling SMA200, comparing the mean with its value three active bars earlier. The calculation needs 203 valid completed bars; the live pipeline requests 250. It keeps the original swing/ATR stop.

New entries are restricted to **07:00-17:00 UTC weekdays (15:00-01:00 PHT)**, within the separate regular market-session/provider gates. This is a fixed research rule, not a claim that other gold sessions are illiquid. The whole position's modeled target is **TP1=1.5R** or the original stop. V2 removes TP2 from its displayed plan; no partial exit, trailing stop or break-even rule is invented. Known-position CLOSE remains optional and is not the scheduled email policy.

Current quotes must still be observed within 90 seconds, the signal must use the latest completed candle, entry drift must remain within 0.1R and inside the original bracket, and entry expires within three minutes of candle close. Market closure, missing/invalid history, drift, stale quotes and expired setups cannot turn into an entry. Blocked crossovers clear the previous entry/stop/target rather than displaying a rejected BUY plan.

The existing RSI catalog aliases use the revision for XAUUSD. Other currency pairs retain their original rules. Payloads, CLI, email subject/body and signal screens identify paper research; no RSI automatic order execution or new task is enabled. Entry expiry is not a timed exit from an existing position. Some modeled positions hold overnight or longer, and news-calendar protection is not implemented or claimed.

## Cost-adjusted comparisons

| Period and model | Closed trades | Wins | Losses | Net R | Profit factor in R | Closed-trade drawdown |
|---|---:|---:|---:|---:|---:|---:|
${periodRow('September-October broker CSV: old scalper', before)}
${periodRow('September-October broker CSV: revised combined model', revised)}
${periodRow('Additional June-August provider history: old scalper', oldExtra)}
${periodRow('Additional June-August provider history: revised combined model', newExtra)}
${periodRow('Additional June-August: revised model, doubled costs', stress)}

The development comparison assumes CSV broker UTC+3 and point size 0.01, exported minute-minimum spread, $0.10/oz adverse slippage per fill and $0.07/oz round-trip fees. The CSV timezone and true bid/ask history are not verified. Its final inspected 30% still loses ${fmt(late.metrics.net_r)}R for the revised model (${late.metrics.wins} wins / ${late.metrics.losses} losses). One full-period combined position remains open and is excluded from closed results.

The additional history comes from the existing Twelve Data XAU/USD API, explicitly requested in UTC for **June 1 through August 31, 2026**, with May 29-31 retained for warmup. This history was not inspected before the models were frozen, but it chronologically precedes development and is a different source from the broker CSV. It is not forward testing or a chronological post-training holdout.

Provider OHLC is not confirmed as broker bid/ask. The external execution model therefore explicitly assumes a constant **$0.37/oz spread**, plus $0.10/oz slippage on each fill and $0.07/oz round-trip fees. The doubled case doubles all three assumptions. These are not verified broker charges. Tick ask prices, swap/financing, source-specific historical holidays/early closes, news, margin, lot sizes, latency and realized account P/L are unavailable. Different cost models can change fills, holding durations and accepted entry cohorts.

The additional source contains 136,800 calendar-minute observations including warmup. It publishes indicative records during closed sessions; **44,340 off-session minutes were removed before both M1 execution and M5 aggregation**, leaving 91,080 active evaluation minutes plus 1,380 warmup minutes. Closed-session indicative prices never execute a Sunday stop/target in this test. Regular-session coverage is complete under the existing NY template, but that template cannot establish source-specific historical holiday tradability.

R is each trade's actual initial entry-to-stop risk. These totals are not dollar, Philippine-peso or account-percentage profits. Drawdown uses closed trades and omits floating equity. Do not sum the different-source studies into an account return.

## Counterevidence and model choice

The advance preferred hypothesis was trend-only, which returned only ${fmt(preferred.metrics.net_r)}R in development and became negative under doubled costs. The combined filter was initially a comparison diagnostic. It became a **development-selected contender**, and that change of research stage was recorded in the [additional-history plan](EXTERNAL_VALIDATION_PLAN.md) before new prices were inspected. No rules, dates, target or preferred direction were retuned after the new results.

| Revised combined model, by external EXIT month | Closed trades | Wins | Losses | Ordinary modeled net R | Doubled modeled net R |
|---|---:|---:|---:|---:|---:|
${monthlyRows.join('\n')}

Without July, the ordinary external result is ${fmt(outsideJuly)}R. June loses, July contributes most of the profit, and the prior development final segment loses. Thus the result **does not meet every predeclared robustness criterion**, particularly avoiding dependence on one month. Positive full-period and doubled-cost totals support a promising paper contender, not a certified profitable edge. No DSR/PBO confidence statistic or future return forecast is fabricated.

## Implementation and numerical verification

- The complete development family retains all 192 scenarios and all failures. An independent implementation matched those cases and 1,000 causal filter decisions.
- The actual production class was checked on those 1,000 decisions under both filters: 2,000 evaluations, zero action/indicator/filter/TP1-plan differences. See [production parity](production-parity.json).
- Every normalized provider observation reconciles with all 32 captured source chunks. Actual PHP independently discovers all 760 external crossovers and agrees on the trend/session decisions and every stop/target/indicator.
- The independent JavaScript implementation initially rounded three printed five-decimal entry half-ties differently from PHP by $0.0001. The original result is archived. The final replay uses the actual PHP snapshots and records the correction; **none of the 12 cases changes trade count, win/loss count, schedule or net R**. This is numerical compatibility, not a retuned strategy. See [canonical PHP audit](external-production-parity.json) and [final external replay](external-validation-results.json).
- Software verification: **423 tests passed; two existing authentication redirect assertions still fail**, expecting /dashboard while the unchanged application redirects to /strategies. The new strategy/live integration subset has 56 tests; all pass. Formatting and diff checks pass.
- A read-only real API check at **2026-11-02 16:57:40 PHT** returned a verified quote observed 40 seconds earlier and a completed 16:55 PHT candle. The revised scalper returned HOLD/WAIT with no entry plan, no email and no order. This is a timestamped observation, not a continuously live quote or a current recommendation.

## Use and further validation

[Pull/update instructions and legacy rollback](../../RSI_SCALPER_UPDATE.md) explain the existing email PC's config/view/cache refresh and read-only health/current checks. No task, .env, credentials, actual email, order or remote deployment was changed during this work. Test mail delivery uses fakes. The code is ready for the user's commit/push/pull workflow; include new files.

Continue with the frozen revision on paper and record actual broker quotes/fills, spread/slippage, fees, swaps, skipped entries and open positions. Additional genuinely later data and observed execution can assess whether the development benefit persists. Do not remove losing months, increase risk or keep changing rules until a backtest becomes positive.

[Primary-source research](RESEARCH.md) · [Original development results](REPORT.md) · [External combined TP1 trade CSV](external-combined-tp1-trades.csv) · [All external cases and provenance](external-validation-results.json).

Reproduction uses the repository's research scripts. The original CSV is user supplied; source-provider history and credentials stay in ignored private storage. The manual historical downloader requires an explicit --probe, --warmup or --batch=0..5 action and honors provider errors. Run one batch at a time within the existing API quota, including any other machine using that key. It does not purchase or upgrade access. Normalize complete captured chunks with scripts/research/normalize_external_history.cjs, then run scripts/research/validate_external_scalper.cjs with the normalized file, output file and --canonical-plans docs/research/scalper-upgrade-2026-11-02/external-production-parity.json. The runner requires the recorded protocol hash and reports provenance, closed-session removals, costs, gaps and open positions.
`;
fs.writeFileSync(path.join(directory, 'IMPLEMENTATION.md'), report);
console.log(JSON.stringify({ report: path.join(directory, 'IMPLEMENTATION.md'), external_trade_ledger: path.join(directory, 'external-combined-tp1-trades.csv'),
  development_revised_net_r: revised.metrics.net_r, additional_revised_net_r: newExtra.metrics.net_r,
  additional_doubled_net_r: stress.metrics.net_r, outside_july_net_r: outsideJuly,
  deployment: 'Local code updated; email PC not deployed; revised alerts explicitly paper research.' }, null, 2));
