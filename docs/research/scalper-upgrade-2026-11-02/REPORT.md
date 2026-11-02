# RSI scalper upgrade: fixed-filter development study

2 November 2026, Asia/Manila. **The advance trend-only hypothesis does not establish a robust profitable strategy.** It improves this sample's endpoint from -7.32693R to +0.29577R, but its profit factor is only 1.00447, doubled costs turn it negative, October loses, and closed-trade drawdown increases substantially. A comparison combining trend and session looks better over the full sample, but loses in the later diagnostic segment and depends heavily on early winning weeks. Neither result establishes future profitability.

The exact hypotheses and the advance preference for trend-only with unchanged TP1 were recorded in [PRE_REGISTERED.md](PRE_REGISTERED.md) before candidate-filter returns were computed. The entire September–October price file and baseline results had already been inspected. This is **a prespecified development comparison after seeing the data, not genuine prospective preregistration or an untouched holdout**. The best-returning row is not automatically selected as the production hypothesis.

The four fixed variants use the existing RSI evaluator without changing its SMA9/21 crossover, simple RSI14, mean ATR14, swing/ATR stop, or targets:

- Baseline: all 515 eligible causal entry signals under the working clock assumption.
- Trend: BUY when the completed M5 close is above SMA200 and SMA200 is above its value three active bars earlier; SELL reverses both inequalities. This is symmetric and is not a short-only fit. Exactly 200 closes seed each mean, requiring 203 active bars for the slope; SMA comparisons use eight decimal places to eliminate floating-point equality noise.
- Session: entries Monday–Friday, 07:00 through 16:59 UTC. This is a fixed research window, not a verified universally superior gold trading session or an hour-by-hour search.
- Trend and session: both conditions. This was registered as a comparison diagnostic, not a return-selected replacement.

The baseline uses 200 completed active-session M5 bars per evaluation. Four of the underlying evaluator's earlier signals are omitted by that declared warmup policy. Only the entry map is filtered; each retained original signal, SL and target is unchanged. The existing one-position M1 execution model then determines accepted trades. No additional RSI risk/reward threshold, spread cap, daily cap, cooldown, partial exit, averaging, trailing rule or time exit is introduced.

The working clock assumes CSV broker time is UTC+3; UTC is only a sensitivity. Point size 0.01 is assumed. The primary assumed-cost model uses each minute's reported minimum spread, 0.10 USD/oz adverse slippage on each fill and 0.07 USD/oz round-trip fees. Those are not verified broker execution charges or a true historical ask series.

| Full-period TP1 model, CSV UTC+3 | Eligible signals | Closed trades | Wins / losses | Net R | Profit factor in R | Closed-trade drawdown | Net R at doubled costs |
|---|---:|---:|---:|---:|---:|---:|---:|
| Baseline | 515 | 141 | 55 / 86 | -7.32693 | 0.91588 | 15.51036R | -13.73862 |
| **Trend: advance preferred hypothesis** | 219 | 111 | 46 / 65 | +0.29577 | 1.00447 | 27.41812R | -4.03214 |
| Session | 228 | 98 | 35 / 63 | -13.07227 | 0.79511 | 17.07389R | -18.30896 |
| Trend and session | 95 | 70 | 34 / 36 | +12.69160 | 1.34741 | 11.76253R | +9.01496 |

The full assumed-cost runs leave one position open for baseline, trend and combined; session leaves none. Open positions are censored, not counted as wins or losses. Higher costs can change entry eligibility, ask-triggered exit timing and accepted trade cohorts, so stressed-cost closed-trade counts can differ. These are not simply extra fees subtracted from an identical list of trades.

The actual quoted entry is checked against the original SL/TP1 and the existing 0.1R live quote-drift gate before slippage, even in TP2 cases. Filled entry must remain inside the selected target/stop bracket. BUY enters at the ask proxy and SELL at bid; shorts exit at the ask proxy. No signal is filled before its M5 close, and a missing immediate M1 opening quote is not deferred to a later session. The primary policy closes the whole position at TP1 or SL, without strategy CLOSE; TP2 is a separately declared full-position alternative. These simulations do not reconstruct actual emails, historical API availability, recipient positions or account trades.

**Trend-only robustness is weak.** Its approximately +0.30R over 111 closed trades is only +0.00266R per trade, and doubled assumed costs produce -4.03214R with profit factor 0.94077 and 30.50250R closed-trade drawdown. September contributes +5.92388R but October contributes -5.62811R. Longs total -9.25896R while shorts total +9.55473R, despite symmetric entry rules. The UTC-clock sensitivity produces -3.61929R for the full trend/TP1 model. This evidence does not support labeling it reliably profitable or a proven drawdown improvement.

**The combined row is promising development evidence, with substantial qualifications.** Its first two ISO exit weeks alone contribute +18.96207R, more than its full +12.69160R result. Week 38 has nine losing trades and no winners. September contributes +10.36831R and October +2.32329R, but its final chronological 30% loses -0.98572R; doubled costs worsen that segment to -1.45362R. The contiguous-path subset of that later segment loses -4.42114R. Under the UTC-clock sensitivity, the later segment also loses (-2.60229R). The full positive endpoint therefore does not establish stable performance on later data, and it is not a reason to quietly switch the advance production hypothesis.

| Already-inspected chronological diagnostic, TP1 / assumed costs / UTC+3 | First 70% net R | Final 30% net R | Final 30% closed trades | Final 30% wins / losses |
|---|---:|---:|---:|---:|
| Baseline | -3.41903 | -7.39889 | 34 | 11 / 23 |
| Trend | -0.94297 | +0.21937 | 29 | 12 / 17 |
| Session | -12.36379 | +0.30014 | 24 | 10 / 14 |
| Trend and session | +14.69243 | -0.98572 | 18 | 7 / 11 |

Each segment retains earlier warmup but starts flat and resets its execution state. Segment returns therefore need not sum to the full run. The split is elapsed calendar time and occurs at October 12, 21:30 UTC under the working UTC+3 interpretation. All of this period was already inspected; the final 30% is not an independent out-of-sample success test.

Changing to full TP2 does not rescue the working-clock full sample. Assumed-cost results are -10.94460R baseline, -22.23406R trend, -25.45802R session, and -19.69703R combined. A different target changes holding time, accepted later entries and the executed cohort; it is not a clean matched experiment on the same trades. No target is chosen by the best observed return.

The primary full-period contiguous-path subsets produce -16.01607R baseline, +3.99469R trend, -13.33754R session, and +11.36580R combined. Those subsets exclude positions crossing absent minute intervals. They describe subsets of the same simulated portfolios: excluded positions still affected which subsequent entries the one-position model could accept. They are not newly simulated gap-free accounts. The full raw cases retain each trade's gap flag, original signal prices, actual simulated fill and net R.

The holding-time evidence also challenges the word “scalper.” With TP1 and assumed costs, median holding proxies are 154 minutes baseline, 127 trend, 118 session, and 92 combined. Every variant contains a maximum completed-trade proxy of 6,460 minutes, approximately 107 hours 40 minutes. Durations use the opening timestamp of the minute containing the exit; tick-level hit time is unknown, so these are minute-band proxies, not exact durations. No 60-minute limit was added or backtested in this study. Any such revision needs a separately declared experiment rather than being silently mixed into these results.

All 192 cases were evaluated: four fixed filters × two clocks × two full-position targets × three chronological segments × four fixed cost models. There was no RSI-period, MA-length, stop, target, slope-threshold, directional-only or winning-hour grid. Filters were not revised after returns were observed. Source/protocol hashes and the price-file hash were verified unchanged during the experiment; entry counts, realized net R, profit factor and closed-trade drawdown reconcile. A separate independent implementation verified all 192 case results and 1,000 source entry-filter decisions, including monthly, ISO-week, side and holding summaries, with zero differences.

The source contains 60,475 M1 bars. Under UTC+3, complete aggregation gives 12,071 active M5 bars, with 43 partial M5 buckets omitted. Native broker/API M5 bars can include valid partial end-of-session candles, so this strict aggregation is not guaranteed to recreate the native series exactly. The quote feed used by the current application can differ from this broker data. Market-session filtering, assumed clocks and data gaps must be reviewed before treating simulated opportunities as live alerts.

Missing broker tick order, true intraminute spreads, swaps, historical news, volume steps, margin, latency and account equity sizing remain material limitations. There is no reliable conversion of these R totals into Philippine-peso profit or an account return. The entry model's signal prices and costs are hypotheses about execution, not proof that the fills occurred.

The justified outcome is a better-documented research decision: retain the original evaluator for comparison, keep trend-only explicitly experimental if implemented, do not promise profitability, and obtain additional independent market history and forward execution evidence. The combined filter merits further independent testing; its later losses and profit concentration prevent presenting the positive full sample as confirmation. These results do not establish a current XAUUSD BUY or SELL setup.

Reproduce from the repository root:

```powershell
node scripts/research/research_scalper_candidates.cjs 'C:\Users\Romark\Downloads\XAUUSD-STD_M1_202609010100_202610302356.csv' docs/research/scalper-upgrade-2026-11-02
```

[All 192 summaries and provenance](comparison-summary.json) · [Comparison CSV](comparison.csv) · [Working-clock filter decisions](filter-decisions-utcplus3.json) · [Trend TP1 primary trades](trend-utcplus3-full-tp1-assumed-costs.json) · [Combined TP1 comparison trades](trend-session-utcplus3-full-tp1-assumed-costs.json). Every summary records the raw case path and SHA256; full primary-clock assumed/doubled-cost trade details are also published in this directory.
