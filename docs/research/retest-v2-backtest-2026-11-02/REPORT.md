# Gold Trend Retest V2 backtest rerun - 2 November 2026

**The primary scenario is unprofitable: 3 wins, 6 losses, -0.20454R after assumed costs. V2 has not demonstrated a profitable improvement.** This rerun uses the same already-inspected historical file and the unchanged implementation, rather than new unseen data or fitted thresholds.

## Data and execution assumptions

- Source: supplied `XAUUSD-STD_M1_202609010100_202610302356.csv`; 60,475 valid bid M1 bars, 2026-09-01T01:00:00 through 2026-10-30T23:56:00 as printed. File SHA-256: `c67ea70ca5715726daa93df84bc073632aed03643fc28f58550195df1d54f6ab`.
- Broker wall time is assumed UTC+3 for the primary case. CSV UTC is a sensitivity, not an alternative chosen for higher returns. Neither clock is confirmed terminal metadata; the Philippines timezone for receiving alerts does not establish the CSV timezone.
- Complete M15 and H1 buckets are aggregated from that M1 file; 43 partial M15 and 44 partial H1 buckets are omitted. Native broker candles can legitimately contain fewer than a full interval's minute observations, so these aggregates need not reproduce native broker M15/H1 indicators. V2 requires 320 completed bars in each input; earlier history supplies warmup. The engine uses H1 trend alignment, a closed M15 breakout, its first qualifying one-to-four-bar retest, a structural/ATR stop, a full 2R target, and a 07:00-17:00 UTC weekday entry session.
- Enter at the immediate next M1 open only, within the frozen retest entry bounds; one position at a time, two entries per UTC day, 30-minute cooldown, spread and reward/risk limits. Missing immediate bars are rejected. Signal evaluation uses no future candle data.
- Assumed broker point size: 0.01. Minute spread is 21-28 points in the supplied file. Primary fills include that minute's reported minimum spread, $0.10/oz adverse slippage on each fill, and $0.07/oz round-trip commission. These costs and point size are assumptions, not confirmed broker charges.
- Stops use adverse opening prices if crossed at a later bar open. Both-hit M1 candles resolve stop first. The run has 0 such ambiguous minutes and no open position at the export end.

## Working UTC+3 results

| Scenario | Trades | Wins | Losses | Win rate | Net R | Profit factor in R | Closed-trade drawdown |
|---|---:|---:|---:|---:|---:|---:|---:|
| V2 full period, assumed costs | 9 | 3 | 6 | 33.33% | -0.20454R | 0.96634 | 4.04928R |
| V2 excluding gap-crossing paths | 8 | 2 | 6 | 25.00% | -2.17554R | 0.64198 | 4.12970R |
| V2 first 70% of calendar span | 6 | 2 | 4 | 33.33% | -0.14818R | 0.96341 | 4.04928R |
| V2 final 30% of calendar span | 3 | 1 | 2 | 33.33% | -0.05637R | 0.97220 | 2.02737R |
| V2 final 30%, excluding gap-crossing paths | 2 | 0 | 2 | 0.00% | -2.02737R | 0.00000 | 2.02737R |
| V1 full-history reference | 1 | 1 | 0 | 100.00% | 1.95415R | Undefined | 0.00000R |

V2 generated 11 eligible snapshots; 2 were rejected because a position was already open. The 9 executed simulations were all SELL trades, so this sample does not evaluate BUY-side performance. Expectancy is -0.02273R per closed trade. The longest consecutive losing sequence was 4. V1's single winner and its old state bug are insufficient evidence that V1 is superior.

**R** means each trade's initial entry-to-stop distance. Returns include the declared costs; 1R is not one dollar, one Philippine peso or an account percentage. Drawdown here is based on closed trades, not intratrade equity. Account size, lots, leverage and actual account fills were not provided.

The calendar split is 2026-10-12T21:30:00+00:00, or 2026-10-13 05:30:00 PHT. It splits elapsed calendar time, not trades or bar counts. Segments retain earlier warmup but reset position/daily/cooldown state. Because the final segment has already been inspected, it is a chronological diagnostic rather than an untouched holdout.

## Cost and timezone sensitivity

| UTC+3 cost model | Wins | Losses | Net R |
|---|---:|---:|---:|
| gross | 3 | 6 | 0.00000R |
| minimum-spread-only | 3 | 6 | 0.00000R |
| assumed-costs | 3 | 6 | -0.20454R |
| double-assumed-costs | 3 | 6 | -0.40551R |

Treating printed CSV time as UTC gives 6 closed V2 trades, 4 wins, 2 losses and 5.78722R after assumed costs. Its final 30% has one losing trade. This large change makes broker-clock confirmation necessary; selecting the more profitable clock would bias the result. All 48 V1/V2, clock, cost and segment cases are in [comparison.csv](comparison.csv).

## Every primary V2 trade

| # | Side | Entry PHT | Exit minute begins PHT | Outcome | Net R | Crosses an absent interval? |
|---|---|---|---|---|---:|---|
| 1 | SELL | 2026-09-23 15:30:00 PHT | 2026-09-23 19:30:00 PHT | win | 1.95415R | No |
| 2 | SELL | 2026-09-23 22:15:00 PHT | 2026-09-23 22:53:00 PHT | loss | -1.01259R | No |
| 3 | SELL | 2026-09-24 18:15:00 PHT | 2026-09-24 19:20:00 PHT | loss | -1.01336R | No |
| 4 | SELL | 2026-09-28 15:30:00 PHT | 2026-09-28 20:20:00 PHT | loss | -1.01318R | No |
| 5 | SELL | 2026-09-28 23:45:00 PHT | 2026-09-29 00:25:00 PHT | loss | -1.01014R | No |
| 6 | SELL | 2026-10-07 17:15:00 PHT | 2026-10-07 20:26:00 PHT | win | 1.94695R | No |
| 7 | SELL | 2026-10-22 22:30:00 PHT | 2026-10-23 08:28:00 PHT | win | 1.97100R | Yes; unseen prices unknown |
| 8 | SELL | 2026-10-30 00:30:00 PHT | 2026-10-30 04:05:00 PHT | loss | -1.01322R | No |
| 9 | SELL | 2026-10-30 15:30:00 PHT | 2026-10-30 16:48:00 PHT | loss | -1.01415R | No |

The exit timestamps identify the one-minute bar containing the exit; the exact exit second is unknown. Download the full [trade ledger CSV](v2-primary-trades.csv), including entry, initial stop, target, fill proxy and cumulative closed R, or inspect [the complete primary JSON](v2-utcplus3-full-assumed-costs.json).

## Limits and checks

The data has 43 nonconsecutive intervals, including ordinary session breaks. One winning primary trade spans a daily gap. All-trade results assume no unseen execution during those gaps; excluding gap-crossing paths gives the separate, weaker result above. These treatments do not reconstruct unknown prices.

The MT5 export's minute minimum spread is only a proxy for ask prices. Tick order, actual opening spread, financing/swaps, historical news, broker rejections, latency, volume rounding, margin and account loss limits are not modeled. This broker-history experiment cannot reproduce Twelve Data's exact price stream or the email scheduler's minute-by-minute API availability and delivery. It is not a verified live-alert or account-performance replay. The one-position rule is an execution assumption in this backtest; an email alone does not track whether its recipient has an open broker position.

An independent check of the actual primary snapshots found all nine entries within the live 0.1R quote-drift limit and inside their stop/target brackets. Applying the production New York session filter to the UTC+3 aggregate history removes no bars. These specific gates therefore do not change the primary sample; provider-price and availability differences remain unknown.

The rerun checks that strategy/tester/parser code and the CSV remained unchanged during evaluation. This report independently reconciles all 48 scenarios' outcome counts, entry counts, return calculations, profit factors and closed-trade drawdowns against their trade logs. A separate review reconciled all 144 trade rows across those scenarios and verified that all 48 rerun JSON results exactly match the earlier frozen comparison. The relevant PHP strategy, minute-execution and offline-command checks passed: 28 tests, 91 assertions. No email, order, strategy activation or runtime-code change was performed for this backtest.

Reproduce from the repository root:

```powershell
node scripts/research/compare_retest_v2.cjs 'C:\Users\Romark\Downloads\XAUUSD-STD_M1_202609010100_202610302356.csv' 'docs/research/retest-v2-backtest-2026-11-02'
node scripts/research/report_retest_backtest.cjs 'docs/research/retest-v2-backtest-2026-11-02'
```

[Machine-readable summary and source provenance](comparison-summary.json).
