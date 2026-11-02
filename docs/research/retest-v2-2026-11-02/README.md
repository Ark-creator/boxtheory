# Gold Trend Retest V2: engineering repair and preliminary comparison

Study date: 2 November 2026, Asia/Manila. Input: the supplied 60,475-bar XAUUSD-STD M1 CSV, covering September 1 through October 30 as printed. This is historical research, not a current market quote or an account trading statement.

**V2 repairs stale setup state and makes bounded live input agree with historical evaluation. It has not demonstrated a profitable edge on the working clock assumption.** No strategy threshold was selected by a parameter search.

V1 retained a consumed or expired breakout until price closed inside its original frozen boundary, without an absolute maximum anchor age. This could suppress new opportunities for weeks. Its full-history evaluation produced one signal, while evaluating the same prices through rolling 300-bar snapshots produced four signals. The extra rolling signals occurred October 22, 29 and 30. [The consistency diagnostic](diagnostic-v1-rolling300-utcplus3.json) records timestamps, shared-snapshot differences and execution results; changing history length changes V1's behavior.

V2 examines only the preceding one to four breakout candidates and reconstructs their first retest locally. The oldest qualifying candidate wins, including rejection of its first retest. There is no indefinitely retained anchor. ATR and EMA seeds use fixed 300-bar contexts, with 320 completed M15 and H1 inputs required for prior-candidate context. Batch and rolling evaluation use the same rules. H1 trend tests, breakout/retest thresholds, structural stop limits, entry bounds, full 2R target and execution gates remain unchanged. The revision also gives V2 a separate strategy identity. These are implementation changes, not evidence of superior returns.

The working scenario assumes the CSV clock is UTC+3 throughout and broker point size is 0.01. The assumed-cost model uses each minute's reported minimum spread, $0.10/oz adverse slippage on each fill and $0.07/oz round-trip fees. These clock, point-size and cost assumptions are unverified. MT5 minute spread is a **minimum**, so this proxy cannot reproduce true intraminute ask extrema or opening spreads.

| Working UTC+3 scenario | Signals | Closed trades | Wins | Losses | Net R | Profit factor in R | Closed-trade drawdown |
|---|---:|---:|---:|---:|---:|---:|---:|
| V1 full history, assumed costs | 1 | 1 | 1 | 0 | +1.95415 | Undefined: no losses | 0.00000R |
| V2 full history, assumed costs | 11 | 9 | 3 | 6 | -0.20454 | 0.96634 | 4.04928R |
| V2 contiguous paths only, assumed costs | — | 8 | 2 | 6 | -2.17554 | 0.64198 | 4.12970R |
| V2 final chronological 30%, assumed costs | 3 | 3 | 1 | 2 | -0.05637 | 0.97220 | 2.02737R |
| V2 final 30%, contiguous paths only | — | 2 | 0 | 2 | -2.02737 | 0.00000 | 2.02737R |

Two of V2's eleven signals were rejected because a position was already open. There were no ambiguous both-hit minutes or positions left open at the export end. One closed winning trade crossed a daily data gap; the contiguous-path rows exclude it rather than assuming what occurred during that gap. All-trade rows assume there was no unseen execution inside absent intervals. These are alternative data-coverage treatments, not two sets of actual executed trades.

The same nine trades had approximately 0R gross and under the minimum-spread-only proxy. Assumed slippage/fees reduce the result to -0.20454R; doubling spread/slippage/fee assumptions gives -0.40551R. A higher signal count alone therefore does not establish an improvement in profitability.

The UTC-clock sensitivity produces six V2 closed trades, four wins and two losses, totaling +5.78722R under assumed costs. Its final 30% contains one losing trade (-1.01415R). A more favorable result is not a reason to select that clock interpretation: verify broker timestamp metadata independently. The two clock interpretations materially change the evaluated session and trade sample.

All 48 comparisons were specified before running: V1/V2; CSV UTC+3/UTC; full/first70/final30 elapsed calendar segments; and gross/minimum-spread-only/assumed/doubled costs. Earlier data is retained for warmup; positions and daily/cooldown gates reset at each segment start. The final 30% had already been inspected in earlier work and is **a chronological diagnostic, not an untouched holdout**. Results are too few and too assumption-sensitive to establish stable expectancy or a drawdown distribution.

Every scenario uses the same M1 prices and aggregation method. Incomplete M15/H1 buckets are omitted. Entry is at the immediate next M1 open after a confirmed retest, subject to the same entry bounds, one-position gate, two-entry daily cap and thirty-minute cooldown. Short exits use the minute ask proxy. Both-hit minutes would resolve stop first. No historical news, swaps, volume rounding, margin, equity sizing or account loss gates are modeled. Net R is not dollar or Philippine-peso profit. These results also do not establish a current XAUUSD BUY or SELL setup.

The comparison evaluates each real strategy once per clock and caches those exact signal snapshots across cost/segment simulations. It verifies every outcome and entry count, strategy identity, source hashes before and after the experiment, and an unchanged price-file hash. This avoids mixed code versions and does not replace the strategy rules with synthetic signals.

Reproduce from the repository root:

```powershell
node scripts/research/compare_retest_v2.cjs 'C:\Users\Romark\Downloads\XAUUSD-STD_M1_202609010100_202610302356.csv'
```

[All comparisons and provenance](comparison-summary.json) · [Comparison CSV](comparison.csv) · [V2 working-scenario trade details](v2-utcplus3-full-assumed-costs.json) · [V1 history-length diagnostic](diagnostic-v1-rolling300-utcplus3.json).
