# Signal audit and Gold Trend Retest research candidate

**Follow-up with supplied M1 broker candles:** [Conditional replay results and candidate performance test](../signal-replay-2026-11-01/REPORT.md). The analysis below describes the original PDF-only evidence; actual account outcomes remain unverified.

Analysis date: 1 November 2026, Asia/Manila. Inputs: all 33 pages of the four supplied signal-history PDFs and the strategy code currently in this repository. Source file hashes and page counts are in [source-manifest.json](source-manifest.json). The code inspected is the current implementation; the PDFs do not identify the code version that generated each historical alert.

**The records establish 387 unique alerts. Actual winning and losing trade counts cannot be established from these files.** Each PDF states on page 1 that execution results, profit/loss and confirmation of target/stop hits are absent. There is no continuous price path, executed fill, exit record, or transaction cost evidence. A later alert price cannot tell us whether an earlier stop or target was hit first. Unknown outcomes must remain unknown, rather than being assigned to losses or given a zero-percent win rate.

| Strategy | Source entries | Duplicates already removed | Unique alerts | BUY / SELL | Actual wins | Actual losses | Outcomes unknown |
|---|---:|---:|---:|---:|---|---|---:|
| Conservative V2 Final | 1 | 0 | 1 | 0 / 1 | Unknown | Unknown | 1 |
| Gold Risk Breakout (Research) | 3 | 0 | 3 | 0 / 3 | Unknown | Unknown | 3 |
| Box Theory Pro | 281 | 2 | 279 | 214 / 65 | Unknown | Unknown | 279 |
| RSI Gold Scalper | 146 | 42 | 104 | 44 / 60 | Unknown | Unknown | 104 |
| **Total** | **431** | **44** | **387** | **258 / 129** | **Unknown** | **Unknown** | **387** |

There are zero records of confirmed wins and zero records of confirmed losses; that describes missing evidence, not actual trading performance. The four PDFs cover different dates and incomplete subsets of alerts. These counts apply to the supplied registers, not necessarily every alert the system ever sent. An alert is also not necessarily an executed or independent trade. Conservative's one signal and Gold Breakout's three signals would be insufficient for a reliable strategy ranking even if their outcomes were known.

[signals.csv](signals.csv) contains every row, including PDF page, source document, timestamp as printed, entry, SL, targets, reward/risk and explicit unknown outcome. [summary.json](summary.json), [data-issues.csv](data-issues.csv) and [duplicate-audit.csv](duplicate-audit.csv) contain the complete statistics and audit trail. All 40 duplicate groups independently reconcile to 44 extra source copies. All 387 trade plans have the stop on the adverse side and targets on the favorable side; valid geometry is not evidence of profit.

| Strategy | Printed timestamp span | Median entry-to-SL distance | Minimum / maximum distance | Planned TP1 / TP2 reward:risk |
|---|---|---:|---:|---|
| Conservative V2 | Sep 15, 2026, one alert | 3.00000 | 3.0000 / 3.0000 | 2R / 4R |
| Gold Risk Breakout | Sep 14–24, 2026 | 22.36370 | 13.1016 / 27.6142 | Approximately 2R / absent |
| Box Theory Pro | Sep 14–Oct 12, 2026 | 2.26370 | 2.1867 / 43.2698 | Approximately 1.5R / 2.5R |
| RSI Gold Scalper | Sep 14–Nov 1, 2026 | 0.28385 | 0.2133 / 23.6729 | Approximately 1.5R / 2.5R |

Distances are XAU/USD quote-price differences in USD per ounce, not dollar account losses or Philippine-peso profits. Money results require broker contract size, actual volume, fills, fees, swaps, account currency and conversion information. The planned ratios do not establish which target would close which fraction of a position. At full-position TP1 exits only, +1.5R/-1R needs 40% wins before costs and +2R/-1R needs 33.33%; these are arithmetic break-even thresholds, not measured win rates. Partial exits and trade management change them.

**What the supplied alerts reveal:**

- Box Theory has 222 consecutive same-side alert pairs at most five minutes apart. The longest chain is 14 alerts, BOX-144 through BOX-157. These are correlated alert clusters, not 222 duplicate trades. The current box evaluator buys at/below the previous daily low and sells at/above its high, without requiring a rejection or a boundary crossing. It can keep fading the same directional move. Actual losses from doing so are not established here.
- RSI's median stop is about $0.284/oz, with a minimum of $0.2133/oz at RSI-103, PDF page 7. Execution costs could consume a substantial part of those distances. No actual broker spread was supplied, so this is a sensitivity finding, not a measured cost loss.
- 84 of 104 RSI timestamps and 4 Box timestamps fall on Saturday/Sunday dates as printed. RSI's dates include September 19–20, October 11 and November 1. Resolve timezone, session, timestamp and stale-data handling before replay. Unknown timezone prevents declaring every weekend print an invalid execution. RSI-062 and RSI-074, both PDF page 5, repeat identical entry/SL/targets at different times; this warrants review but is retained under the register's stated uniqueness rule.
- Gold Risk Breakout explicitly uses UTC; the other three registers do not establish a timezone. Cross-strategy chronological conflicts were not inferred. Several source filenames contain different dates from the printed signal timestamps; filenames are not used as signal times.

**What the current code reveals:**

- In `app/Services/TradingService.php`, Conservative sets `atrUse = max(atrLtf, atrHtf)` and compares it with `0.8 * atrHtf`. For valid positive ATR values this filter always passes.
- Conservative caps a proposed ATR/structural stop with `min(riskDistance, 30 * pip)`. At the configured XAU pip size of 0.1 this is $3/oz, matching the one supplied alert. The cap can move a stop inside its intended structure. It does not prove that the supplied alert lost.
- Older candle adapters fall back independently among spot providers and Yahoo `GC=F` futures, and their older evaluators do not consistently exclude forming or stale candles. Mixed sources and timestamps can produce unreliable comparisons.
- The email history records `sent/pending/failed` delivery states. These are not wins or losses. The alert service normally requests signals without current-position context, so an opposite alert cannot automatically be treated as an actual exit.
- The existing Gold Breakout already addresses closed bars, trend alignment, entry extension and bounded risk. Its remaining false-breakout/retest hypothesis is a research question; its three supplied alerts do not demonstrate a weakness statistically.

These are observed data-quality and implementation weaknesses. They are not a loss attribution study, and no parameters below were optimized against known winning/losing labels.

**The new strategy is Gold Trend Retest (Research), slug `gold-trend-retest`.** Its evaluator is [GoldTrendRetestStrategy.php](../../../app/Services/GoldTrendRetestStrategy.php); its offline simulator is [GoldRetestBacktester.php](../../../app/Services/GoldRetestBacktester.php). It is a runnable new research candidate. It has not been added to the email catalog or broker execution allowlist, and has no measured performance result yet.

The rules are fixed starting hypotheses:

1. Use one broker's XAUUSD M15 and H1 candles, with UTC bar-opening times. Validate positive, consistent OHLC, ascending unique timestamps and timeframe alignment. Use only completed bars. Require at least 50 closed M15 and 220 closed H1 bars; provide substantially more warmup when exporting. The evaluator rejects stale latest candles. No source fallback is performed.
2. H1 long trend: close > EMA50 > EMA200, and EMA50 above its value three closed H1 bars earlier. Reverse for shorts. EMAs use SMA seeds. Trend must qualify at both breakout and retest time; a future H1 bar cannot authorize an earlier breakout.
3. Freeze the preceding 20 M15 bars' high/low and prior-bar Wilder ATR14 at breakout. Long breakout closes above the frozen high by more than 0.1 ATR with a bullish candle; mirror for shorts. The channel and breakout must be continuous. Require ATR/price between 0.05% and 0.8% and breakout range no more than 2.5 ATR. Do not enter the breakout candle.
4. Wait for the first qualifying retest during the next 1–4 consecutive M15 bars, at most 60 minutes after breakout close. Long retest low touches at/below boundary +0.1 frozen ATR and closes bullish above the boundary, aligned with H1; mirror for shorts. A missing bar expires the setup. A close more than 0.25 frozen ATR inside the channel invalidates it.
5. The first qualifying retest is consumed even if its entry limits fail. Reject retest range above 2.5 frozen ATR, close more than 0.5 frozen ATR beyond the boundary, or an excessive stop. Consumed/expired episodes wait for a close back inside the original boundary before rearming. Stable setup IDs support one execution attempt per setup.
6. Stop beyond the most adverse breakout/retest swing plus 0.2 frozen ATR, with a 1.5 ATR minimum distance. Skip a stop wider than 3 ATR; do not compress a structural stop. Full-position target is 2R from the signal close. No averaging down, partial exit, break-even move, trailing stop or time exit is implemented. Overnight and weekend holding remains possible.
7. Breakout and retest closes must both fall Monday–Friday, 07:00 through 16:59 UTC. Signal expires three minutes after retest close. The diagnostic enters only at the immediate next M15 opening quote, with spread/slippage applied. Price must remain inside the directional boundary/0.5 ATR limit and within 0.1R of signal price. Reject executable reward/risk below 1.8 or spread above the smaller of $1/oz and 10% of actual entry-to-stop distance.
8. The diagnostic allows one position, at most two entries per UTC day and at least 30 minutes between entries. Execution rejection consumes the setup. For any later demo/live implementation, size separately against account equity; 0.25% initial planned risk is a starting limit, not a demonstrated optimum. News checks, account loss budgets, broker volume/margin/stop constraints and kill switches would need integration before automated execution.

The intended improvements follow the audit: retest confirmation replaces immediate chasing, stable setup IDs and exposure gates constrain repeated alerts, frozen ATR preserves the entry reference, oversized stops are skipped, and costs determine simulated outcomes. **None establishes that the candidate makes more money or wins more often.** Filters can also remove profitable setups; the new strategy can still lose on failed retests, reversals, gaps and costs.

To reproduce the PDF audit from the workspace root in PowerShell:

```powershell
node scripts/research/extract_signal_registers.cjs --output-dir storage/app/private/signal-research 'C:\Users\Romark\Downloads\Conservative_V2_Final_Signal_History.pdf' 'C:\Users\Romark\Downloads\Gold_Risk_Breakout_Research_Signal_History.pdf' 'C:\Users\Romark\Downloads\Box_Theory_Pro_Signal_History.pdf' 'C:\Users\Romark\Downloads\RSI_Gold_Scalper_Signal_History.pdf'
node scripts/research/analyze_signal_registers.cjs
```

The extractor is narrowly designed for these ReportLab registers with standard Helvetica text, not arbitrary PDFs or OCR. The parser verifies every header total, direction total, sequential ID, source mapping, chronological order and duplicate count. The registers say their prices were transcribed from rendered original email PDFs with broken encoding; this audit validates the supplied register values, not those unavailable original pages.

To test the new strategy, export broker candles with the existing read-only `mt5_bridge/export_history.py`. Include earlier warmup and inspect coverage. The JSON must declare `source: "mt5"`, the broker's XAUUSD symbol, and `m15`/`h1` arrays of `{timestamp, open, high, low, close}`. Timestamps represent UTC opens. A broker symbol named exactly `GOLD` additionally needs `quote_currency: "USD"`. Non-USD gold is unsupported.

Then run, replacing the file, period and all cost numbers with verified inputs:

```powershell
php artisan gold:retest-backtest 'path/to/mt5-gold-history.json' --spread=0.30 --slippage=0.10 --fee-per-unit=0.07 --from=2026-09-14 --to=2026-11-01
```

Those cost values are examples, not measured VT Markets costs. All three cost arguments must be explicit. `--from` retains earlier bars for warmup; `--to` is exclusive and censors remaining positions. The CLI emits JSON only and sends no orders or alerts. Outputs include simulated wins/losses/breakeven, open positions, rejected entries, net R, expectancy, profit factor in R, closed-trade drawdown, annual totals and nonconsecutive intervals for coverage review. A target hit counts as a loss if fees make its net result negative.

The diagnostic uses bid bars, ask exits for shorts, adverse slippage on both fills, stop-first treatment of ambiguous intrabar SL/TP hits and adverse opening quotes on gap stops. Gap targets get no favorable price improvement. It is not a full broker execution backtest: fixed costs, no historical news, swaps, volume rounding, margin, equity sizing, account loss gates, floating drawdown or latency. Nonconsecutive bars can be scheduled closures or missing prices; they are reported but cannot be distinguished without broker session evidence. Open positions are excluded from closed-trade metrics. Profit factor is normalized R, not account-currency profit factor. R totals must not be translated directly into promised account returns.

For actual historical win/loss counts, obtain an MT5 deal/position export with entry and exit time, direction, volume, actual prices, commissions, fees and swaps, plus a way to match each position to a strategy/signal. Split closes must be aggregated by position. Define a win as positive realized net P/L, loss as negative, breakeven as zero; keep open, unmatched and unexecuted alerts separate. If the alerts were never traded, use a separate hypothetical replay with complete same-source price history, resolved timezones, entry timing and an explicit TP1/TP2/partial-exit policy. Tick data is needed to resolve first-hit ambiguity rather than guessing from candles. The PDFs alone cannot supply that evidence.

Before calling the candidate better, freeze parameters, compare all strategies on the same broker data and cost model, record every attempted variant, and reserve untouched chronological evaluation periods. The supplied September–November alerts are now development evidence. Report net expectancy, R profit factor, drawdown, rejected and censored trades, uncertainty and concentration of returns. Check 1x/2x/3x costs and nearby parameters. A suggested research gate is positive expectancy and R profit factor above 1.2 on at least 100 independent out-of-sample closed trades, followed by demo evaluation long enough to inspect actual fills. These are proposed gates, not achieved results or proof of a durable edge. [MetaQuotes documents real-tick and forward testing](https://www.metatrader5.com/en/terminal/help/algotrading/testing); [CFTC explains the limits of hypothetical system performance](https://www.cftc.gov/LearnAndProtect/AdvisoriesAndArticles/fraudadv_tradingsystem.html).

Software tests exercise closed-bar/no-lookahead behavior, mirrored shorts, first-retest consumption, expiry, data validation, oversized-stop rejection, fill costs, short ask exits, stop/target ambiguity, gaps, entry bounds, position/daily/cooldown gates and censored positions. Their synthetic prices validate implementation, not investment performance.

Validation on 1 November 2026: all **41 new tests passed (125 assertions)**; formatting and the frontend build passed. The complete PHP suite finished with **155 passing and 2 failing tests**. Both failures are in unchanged authentication/registration tests expecting `/dashboard`, while the existing controllers redirect customers to `/strategies`. They do not exercise this strategy or its diagnostic. No measured broker backtest or forward-trading result was available.
