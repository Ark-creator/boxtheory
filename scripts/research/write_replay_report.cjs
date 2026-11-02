const fs = require('node:fs');
const path = require('node:path');
const dir = path.resolve('docs/research/signal-replay-2026-11-01');
const replay = JSON.parse(fs.readFileSync(path.join(dir, 'replay-summary.json'), 'utf8'));
const candidate = JSON.parse(fs.readFileSync(path.join(dir, 'candidate-summary.json'), 'utf8'));
const pick = (timezone, delay = 0, costs = 'minimum_spread_only', target = 'tp1_full_exit') => {
  const result = replay.scenarios.find(s => s.timezone.id === timezone && s.legacy_delay_minutes === delay && s.costs.id === costs && s.target_policy === target);
  if (!result) throw new Error('Missing requested replay scenario.');
  return result;
};
const primary = pick('server3_payloadUTC');
const pht = pick('server3_payloadPHT');
// Narrative case studies refer to this exact supplied dataset. Refuse to publish
// stale prose if a different input or changed outcome logic alters the study.
const studyHash = 'c67ea70ca5715726daa93df84bc073632aed03643fc28f58550195df1d54f6ab';
const signature = scenario => scenario.strategies.map(g => {
  const m = g.assuming_all_gaps_are_market_closures;
  return [m.win, m.loss, m.alerts - m.resolved].join('/');
}).join('|');
const primaryCandidate = candidate.scenarios.find(s => s.offset_hours === 3 && s.segment === 'full' && s.cost === 'proxy-cost');
if (replay.price_data.sha256 !== studyHash || candidate.source.sha256 !== studyHash
    || signature(primary) !== '0/1/0|0/3/0|151/102/26|12/6/86'
    || signature(pht) !== '0/0/1|0/3/0|122/16/141|0/5/99'
    || primaryCandidate.metrics.closed_trades !== 1 || Math.abs(primaryCandidate.metrics.net_r - 1.9541545712769073) > 1e-9) {
  throw new Error('Study inputs/results changed. Review and update narrative case studies before publishing the report.');
}
const names = { 'Conservative V2 Final': 'Conservative V2', 'Gold Risk Breakout (Research)': 'Gold Risk Breakout', 'Box Theory Pro': 'Box Theory Pro', 'RSI Gold Scalper': 'RSI Gold Scalper' };
const table = (header, rows) => '| ' + header.join(' | ') + ' |\n| ' + header.map(() => '---').join(' | ') + ' |\n' + rows.map(row => '| ' + row.join(' | ') + ' |').join('\n');
const outcomeTable = scenario => table(['Strategy', 'Alerts', 'Simulated wins', 'Simulated losses', 'Not rated', 'Win rate of resolved alerts'], scenario.strategies.map(group => {
  const m = group.assuming_all_gaps_are_market_closures;
  return [names[group.strategy], m.alerts, m.win, m.loss, m.alerts - m.resolved, m.resolved ? (100 * m.win / m.resolved).toFixed(1) + '%' : 'Undefined'];
}));
const strictTable = table(['Strategy', 'Wins', 'Losses', 'Path crosses a gap', 'No entry bar', 'Invalid initial bracket', 'Beyond export'], primary.strategies.map(group => {
  const m = group.strict_contiguous;
  return [names[group.strategy], m.win, m.loss, m.unresolved_gap, m.no_entry_bar, m.unexecutable_entry, m.outside_export];
}));
const sensitivityRows = [];
for (const [label, scenario] of [
  ['At printed time, TP1, minute spread proxy', primary],
  ['Five minutes later, TP1, minute spread proxy', pick('server3_payloadUTC', 5)],
  ['At printed time, TP2 or sole target', pick('server3_payloadUTC', 0, 'minimum_spread_only', 'tp2_full_exit_when_available')],
  ['TP1, spread + assumed 0.10 slippage / 0.07 fee', pick('server3_payloadUTC', 0, 'assumed_slippage_and_fee')],
  ['TP1, doubled spread/slippage/fee assumptions', pick('server3_payloadUTC', 0, 'double_assumed_costs')],
  ['TP1, screenshot 0.37 spread floor stress', pick('server3_payloadUTC', 0, 'screenshot_spread_floor_stress')],
]) for (const group of scenario.strategies) {
  const m = group.assuming_all_gaps_are_market_closures;
  sensitivityRows.push([label, names[group.strategy], m.win, m.loss, m.alerts - m.resolved]);
}
const candidateRows = candidate.scenarios.filter(s => s.cost === 'proxy-cost').map(s => [
  'CSV UTC+' + s.offset_hours, s.segment, s.signals, s.metrics.closed_trades,
  s.metrics.wins, s.metrics.losses, s.metrics.net_r.toFixed(5) + 'R',
]);

const report = `# Conditional M1 signal replay and candidate test

Updated 1 November 2026, Asia/Manila, after receiving the MT5 M1 CSV and terminal screenshot. This extends the [PDF-only audit](../signal-audit-2026-11-01/REPORT.md). All 387 unique alerts are accounted for; no new duplicate alerts were counted. The CSV permits hypothetical price-path tests. It still contains no actual account fills, trade exits or realized P/L.

**Provisional result:** under CSV UTC+3, older signal payload UTC, entry at the printed timestamp, full TP1 exit and gaps treated as market closures, Box has 151 simulated wins / 102 losses and RSI 12 / 6. Conservative is 0 / 1 and Gold Breakout 0 / 3. These are conditional simulations, not verified executed trades. A different timezone interpretation changes the answers materially, so no single final historical win rate is established.

The old payload timestamp can be the opening time of a candle still forming when an alert was generated. Entering at that opening minute can precede publication. **The headline table is therefore a time-alignment hypothetical, not a verified causal alert-time backtest.** Waiting five minutes instead gives Box 153W/80L, with 46 unrated rather than 26; that changed cohort is not proof of improved execution. Both scenarios are retained because actual send times are absent.

${outcomeTable(primary)}

"Not rated" is not a loss. It means the exact entry minute is absent, outside the export, or the original SL/TP bracket was already invalid at the executable quote. Counts include every alert independently, including overlapping alerts; the 151 Box wins are not 151 independent portfolio trades. No partial closes, break-even moves, trailing stops or exits on opposite alerts are invented.

If older signal payload timestamps actually mean **Philippine time (UTC+8)**, using the same broker UTC+3 assumption instead gives:

${outcomeTable(pht)}

Gold Breakout remains UTC in both tables because its register explicitly labels UTC. Receiving emails in the Philippines does not establish the timezone of their embedded candle timestamp. Current TradingService requests Twelve Data candles in UTC and formats Yahoo timestamps in UTC, which favors the UTC payload interpretation; it does not prove the historical code/provider version. Source entry prices also align much more closely at broker-minus-payload +3 hours. This is a price-alignment diagnostic, not selection of the most profitable timezone.

VT Markets documents server GMT+2 in winter and GMT+3 during daylight saving, with midnight aligned to New York close. This supports a UTC+3 working assumption for September–October if this is VT data, but the CSV and screenshot do not identify verified broker/timezone metadata. The screenshot shows the terminal's last Friday quote time, not a synchronized current server/local clock pair. [VT Markets server-clock explanation](https://get.vtmarkets.help/hc/en-us/articles/37317868198297-What-is-VT-Markets-GMT-offset-or-server-time).

**A stricter count excludes every position whose price path crosses a gap.** Under the UTC-payload scenario, it gives:

${strictTable}

The file has ${replay.price_data.rows.toLocaleString('en-US')} valid M1 bars, from ${replay.price_data.first_open_printed} through ${replay.price_data.last_open_printed}, across 44 printed dates. There are no weekend bars. Of its 43 nonconsecutive intervals, 34 are regular daily 62-minute absences, eight are weekend absences, and one is September 7 21:29 to September 8 01:00 (210 absent minutes). Most resemble scheduled closures. The unusual interval may be a holiday closure, but session metadata is absent. The closure-inclusive table assumes no unobserved trading inside any gap; the strict table makes no such assumption. [VT's published gold quotation and trading hours](https://www.vtmarkets.com/en-mena/notification/?cat=conditions) broadly resemble the daily pattern, but current website hours are not a verified historical schedule for this account.

In the UTC-payload TP1 scenario, Box's 26 unrated alerts comprise 10 missing entry minutes and 16 invalid brackets. RSI's 86 unrated alerts comprise 83 missing entry minutes and three November 1 alerts after the October 30 export end. Alerts in closed periods are not silently postponed to Monday or the next session. In the Philippine-payload scenario, 127 Box and 23 RSI alerts have invalid initial brackets; this is another reason timezone and source-price alignment matter.

**Replay assumptions and practical limits:**

1. Interpret CSV fields as broker wall time first. Scenario conversions are explicit. The profile file stores the original SHA256 and all gap/spread diagnostics. Alternatives include CSV UTC+2 and CSV UTC, rather than inferring a timezone solely from returns.
2. Each alert starts an independent hypothetical market trade at the exact mapped M1 open: BUY at bid plus spread, SELL at bid, followed by declared adverse slippage. Preserve its original absolute SL/TP. Reject a bracket whose SL or TP is already invalid at executable bid/ask. Do not pretend the emailed entry price was necessarily available. No retrospective 0.1R or 1.8 reward/risk filter was added to the old strategies.
3. The primary tables use the exported spread points times an **unverified point size of 0.01**, with no extra fee/slippage. Two printed decimals and the screenshot support that as an assumption, not confirmed SYMBOL_POINT metadata. Prices are assumed OTC bid bars. Short exits use proxy ask; long exits use bid.
4. MetaTrader defines the M1 spread as the **minimum** within that minute. Treating it as constant can underestimate costs and cannot recreate true historical ask highs/lows. The screenshot shows bid 3875.80 and ask 3876.17: a $0.37/oz snapshot spread, larger than the export's 0.21–0.28 implied minimums under the point-size assumption. That image does not establish the spread at other times. The separate screenshot stress uses a $0.37 floor throughout. [MetaQuotes price-data definition](https://www.metatrader5.com/en/terminal/help/trading_advanced/price_data).
5. An M1 bar touching both levels without an opening quote beyond a level remains ambiguous, with stop-first and target-first payoffs retained. Stop gaps use the opening quote; target gaps receive no favorable improvement. Tick order, intraminute spread, broker stop-distance constraints, lots, margin, commission schedules, swaps, order latency and actual publication times are unavailable.
6. Legacy printed timestamps refer to the latest five-minute candle OPEN in current code; the older evaluators allow forming candles. Therefore entering at the printed minute is a scenario, not a recovered email send time. A second scenario waits five minutes to avoid using that candle's final close before completion. That delay is also an assumption. Gold timestamps are closes and are not delayed.
7. TP1 and TP2 are **alternative full-position exits**, not simultaneous wins or a guessed partial-exit rule. For Gold, which has one target, TP2 scenarios retain that sole target. Net R uses each hypothetical actual entry-to-stop distance, so it can differ from the printed plan's R. No account-currency or PHP profit is calculated.

The following sensitivities all keep broker UTC+3 and older payload UTC, and treat gaps as closures. Unrated counts can change because the executable bracket can change with delay/spread or target. Do not compare percentages without their denominators.

${table(['Scenario', 'Strategy', 'Wins', 'Losses', 'Not rated'], sensitivityRows)}

The 0.10 per-fill slippage and 0.07 round-trip fee are USD/oz research assumptions, not verified account charges. Doubled costs double all three assumptions. Summed independent-alert R, where available in JSON, is not portfolio P/L: many Box alerts overlap and several would require simultaneous risk. Current alerting does not document position lifecycle, so independent signal evaluation cannot recover account results.

**What the outcomes add to the earlier code audit:**

- Box's apparent 151 TP1 winners are concentrated: 61 originate September 18 and 42 September 24, accounting for **68.2% of winners from just two entry dates**. All 61 winning SELL alerts originate September 18. A cluster of emails around one move must not be treated as dozens of independent confirmations of an edge.
- **116 of 151 Box TP1 winners cross a broker calendar date and a data gap.** Among alerts with quoted entry-to-stop distance above $5/oz, the result is 113 wins / 16 losses, and all 113 winners hold overnight. At distance at most $5, the result is 38 wins / 86 losses, with another 26 unrated. This is a descriptive split: stop width, date, direction and overnight exposure are confounded. It does not justify simply widening stops or increasing position risk. Swaps are absent, so overnight results are not complete net account results.
- Comparing TP1 and TP2 on the **same 253 valid Box entries** gives **151W/102L at TP1 versus 90W/163L at TP2**. Exactly 61 TP1 winners become TP2 losers; 42 come from the same September 24 BUY cluster. Raw TP2 totals are 92W/167L because six extra entries qualify, adding 2W/4L. Avoid presenting changing eligibility as a target improvement. A fixed, explicit exit matters more than a larger advertised target, and 61 correlated outcomes are not 61 independent lessons.
- RSI's 12W/6L TP1 result covers only 18 of its 104 alerts under the UTC assumption. TP2 gives 9W/9L on those 18, yet its independent-alert net R is slightly higher (13.11R versus 12.85R). A greater win rate alone does not establish a more profitable rule. No ranking is reliable for the many unavailable weekend alerts.
- The one Conservative and three Gold Breakout records all lose in the working scenario, but these sample sizes cannot establish a stable loss rate or prove which filter caused failure. The new retest confirmation is a hypothesis, not an explanation retroactively proven by these four losses.

**The new strategy was tested without changing its rules after seeing this CSV.** Complete M15/H1 bars were aggregated from M1; incomplete buckets were omitted. The frozen Gold Trend Retest candidate still uses closed H1 EMA alignment, a prior-20-bar breakout, first retest within four M15 bars, frozen ATR/channel, structural stop, full 2R target, entry bounds and exposure/frequency gates. Its exact rules and code are in the [original research specification](../signal-audit-2026-11-01/REPORT.md). The M1 tester adds minute execution and spread proxies; it sends no trades or emails.

${table(['Clock assumption', 'Calendar segment', 'Signals', 'Closed trades', 'Wins', 'Losses', 'Net R with assumed costs'], candidateRows)}

Under broker UTC+3, the single trade is a September 23 short entered at 07:30 UTC, entry 4323.71, stop 4333.96184, target 4303.50632; it reaches the target at 11:30 UTC. Its simulated net result is +1.95415R under minute minimum-spread proxy plus 0.10 slippage per fill and 0.07 round-trip fee. Doubling those costs gives +1.90919R. There are no missing-path or both-hit minutes in that trade. These are hypothetical per-risk-unit figures, not dollar profits.

The final chronological 30% has **zero candidate signals/trades** under both clock scenarios. The strict episode rearming and entry rules yield very low frequency. One winning trade does not establish a usable win rate, stable expectancy, drawdown distribution or superiority over Box/RSI. In particular, it is not legitimate to market this as a proven 100% winning strategy. The first70/final30 split is by elapsed calendar span with earlier warmup retained; positions/gates reset at the segment boundary. It is preliminary chronological diagnostics, not a robust untouched holdout or complete account simulation. No rule parameters were optimized to improve these results.

The evidence supports concrete engineering improvements: block stale/closed-market alerts, distinguish source candle time from actual send time, record fills/exits/net costs, control repeated exposure, preserve structural stops and use explicit execution prices. It does **not** yet support replacing the old strategies with a supposedly proven superior one. Box's apparent winning count needs scrutiny for correlated exposure and overnight assumptions; RSI's 104 alerts mostly cannot be traded on this broker feed at their stated times. Conservative/Gold samples are too small to rank statistically. More indicators alone cannot fix those recording and execution problems.

Reproduce from the repository root:

\`\`\`powershell
node scripts/research/mt5_m1.cjs 'C:\\Users\\Romark\\Downloads\\XAUUSD-STD_M1_202609010100_202610302356.csv'
node scripts/research/replay_signal_history.cjs 'C:\\Users\\Romark\\Downloads\\XAUUSD-STD_M1_202609010100_202610302356.csv'
node scripts/research/prepare_m1_candidate.cjs 'C:\\Users\\Romark\\Downloads\\XAUUSD-STD_M1_202609010100_202610302356.csv'
node scripts/research/write_replay_report.cjs
node --test scripts/research/mt5_m1.test.cjs scripts/research/replay_signal_history.test.cjs
php vendor/bin/pest tests/Unit/GoldRetestMinuteBacktesterTest.php --compact
\`\`\`

Audit artifacts: [all scenario summaries](replay-summary.json), [scenario comparison CSV](scenario-comparison.csv), [UTC-payload TP1 rows](server3_payloadUTC_tp1_full_exit_rows.csv), [UTC-payload TP2 rows](server3_payloadUTC_tp2_full_exit_when_available_rows.csv), [Philippine-payload TP1 rows](server3_payloadPHT_tp1_full_exit_rows.csv), [Philippine-payload TP2 rows](server3_payloadPHT_tp2_full_exit_when_available_rows.csv), [price/time alignment profile](price-profile.json), [candidate summaries](candidate-summary.json) and [candidate's single trade](candidate-utcplus3-full-proxy-cost.json). Original signal CSV actual_outcome fields remain unknown because no account execution history was supplied.

Validation: **49 targeted PHP tests (158 assertions)** and **14 Node tests** pass, including no-lookahead behavior, price/clock parsing, missing entries, invalid brackets, short ask exits, ambiguous minutes, gap censoring and cost accounting. These software tests do not establish investment performance. A second independent replay calculation reproduced the main per-strategy counts. Formatting checks pass. The previously observed unrelated authentication/registration redirect-test failures are outside this research change.
`;
fs.writeFileSync(path.join(dir, 'REPORT.md'), report);
console.log('Wrote ' + path.join(dir, 'REPORT.md'));
