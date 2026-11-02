# Existing RSI Gold Scalper backtest - 2 November 2026

**The fixed-TP1 primary simulation produced 55 wins, 86 losses and -7.327R after assumed costs.** This tests the current scalper implementation, without changing or selecting its strategy parameters. The result is conditional on the broker clock, execution and cost assumptions below.

## Data and actual strategy rules

Source: 60,475 bid M1 candles from the supplied `XAUUSD-STD_M1_202609010100_202610302356.csv`, 2026-09-01T01:00:00 through 2026-10-30T23:56:00 as printed. SHA-256: `c67ea70ca5715726daa93df84bc073632aed03643fc28f58550195df1d54f6ab`. This is the same September-October file used for V2, not a live quote feed or a broker account statement.

The test calls the actual `TradingService::evaluateRsiMovingAverage` method with unchanged defaults:

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
| Scalper TP1, full period | 141 | 55 | 86 | 39.01% | -7.327R | 0.916 | 15.510R |
| Scalper TP1, gap-free trade subset | 113 | 40 | 73 | 35.40% | -16.016R | 0.784 | 19.574R |
| Scalper TP1, final 30% of calendar span | 34 | 11 | 23 | 32.35% | -7.399R | 0.683 | 12.760R |
| Scalper TP2, full period | 116 | 31 | 85 | 26.72% | -10.945R | 0.873 | 20.268R |
| Scalper TP2, final 30% of calendar span | 40 | 12 | 28 | 30.00% | 0.641R | 1.023 | 12.177R |
| Scalper TP1 plus position-aware CLOSE | 450 | 232 | 218 | 51.56% | -16.248R | 0.865 | 27.329R |
| V2 full 2R target, earlier same-cost comparison | 9 | 3 | 6 | 33.33% | -0.205R | 0.966 | 4.049R |

The primary run includes 73 BUY and 68 SELL closed trades, with longest consecutive losing sequence 10. It generated 515 entry signals in the evaluation range. An open position at the end is reported separately and excluded from closed returns. There are 28 gap-crossing closed trades and 0 ambiguous both-hit minutes. Gap-free results exclude trades retrospectively; they are a subset, not a separately rerun portfolio.

The scalper has no fixed time exit in this primary model. Median closed holding time is approximately 2.567 hours and the longest is 107.667 hours, measured to the exit minute's opening. Its name does not mean every position closes within the same day; financing costs for overnight positions are unavailable and omitted.

| Primary TP1 direction | Closed trades | Wins | Losses | Net R |
|---|---:|---:|---:|---:|
| BUY | 73 | 23 | 50 | -17.823R |
| SELL | 68 | 32 | 36 | 10.496R |

This directional split describes the inspected price period. It does not establish that removing BUY trades or adding a trend filter would improve future results; that would require a separately specified test on new data.

| Entry rejection reason | Count |
|---|---:|
| existing_position | 373 |
| market_closed | 0 |
| invalid_plan | 0 |
| quote_drift | 0 |
| quote_bracket | 0 |
| fill_bracket | 0 |
| no_immediate_next_open | 0 |

**R means the actual initial entry-to-stop distance for each trade.** Returns include declared costs; they are not dollar, Philippine-peso or account-percentage profits. Drawdown uses closed trades and omits intratrade equity. Account size, volume, leverage and actual fills are unavailable.

## Position-aware CLOSE is a separate model

The scalper also contains logic to close a known long on a bearish crossover or RSI >= 70, and a known short on a bullish crossover or RSI <= 30. The current email scheduler evaluates with no position context, so those CLOSE branches do not manage recipients' trades. Fixed TP1 is the primary email-plan model. The managed scenarios deliberately supply the simulated position and execute a CLOSE at the next M1 open; they are not claims about current automated email behavior. Opening SL/TP gap events take priority over a voluntary CLOSE, with no same-bar reversal entry.

TP1, TP2 and managed policies can keep positions open for different durations, changing which later signals can be executed. Their totals therefore need not describe the same entry cohort. The V2 comparison likewise has different entry frequency, target, warmup and exposure gates. All policies and costs were declared before running; none is selected as a demonstrated superior strategy.

## Cost sensitivity: fixed TP1, CSV UTC+3

| Cost model | Closed trades | Wins | Losses | Net R |
|---|---:|---:|---:|---:|
| gross | 142 | 54 | 88 | -7.054R |
| minimum-spread-only | 141 | 55 | 86 | -4.491R |
| assumed-costs | 141 | 55 | 86 | -7.327R |
| double-assumed-costs | 139 | 53 | 86 | -13.739R |

Changing spread also changes short-side stop/target touches, holding times, eligibility and which later entries are available. These rows need not share an identical trade cohort, so the difference between them is not simply a fee subtraction from the same trades.

The CSV's spread is a minute minimum rather than a historical opening spread or tick ask series. A price printed to two decimals does not independently establish broker point size. Slippage and commission are assumptions, not verified account charges. Swaps/financing, historical news, variable intraminute spread, latency, stop-distance limits, volume rounding, margin and account loss limits are omitted.

## Clock and sample sensitivity

| CSV clock assumption | Fixed full-position exit | Closed trades | Wins | Losses | Net R |
|---|---|---:|---:|---:|---:|
| UTC+3 | TP1 | 141 | 55 | 86 | -7.327R |
| UTC+3 | TP2 | 116 | 31 | 85 | -10.945R |
| UTC+0 | TP1 | 132 | 48 | 84 | -15.315R |
| UTC+0 | TP2 | 102 | 22 | 80 | -27.604R |

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

```powershell
node scripts/research/backtest_rsi_scalper.cjs 'C:\Users\Romark\Downloads\XAUUSD-STD_M1_202609010100_202610302356.csv' 'docs/research/rsi-scalper-backtest-2026-11-02'
node scripts/research/report_rsi_scalper.cjs 'docs/research/rsi-scalper-backtest-2026-11-02'
```
