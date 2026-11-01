# XAUUSD strategy and automation research

Research date: 14 September 2026. Symbol: **XAUUSD**, gold against the US dollar. Implementation: `gold-risk-breakout` / “Gold Risk Breakout (Research)”.

## Decision

Use a simple trend-aligned breakout as the first candidate to test, with explicit limits on position risk and trading frequency. There is no evidence yet that this particular rule set is profitable, or superior to your existing strategies. No broker backtest, live terminal connection, or demo performance was available during implementation. Software tests use synthetic prices and mocked execution; they do not measure returns.

The research supports testing a trend hypothesis, not promising an intraday gold edge. Hurst, Ooi and Pedersen report historical evidence for trend following across multiple markets over long periods. Their instruments, diversification and horizons differ from this single-instrument M15 CFD strategy. Applying that evidence here is a design inference. [AQR, *A Century of Evidence on Trend-Following Investing*](https://www.aqr.com/Insights/Research/Journal-Article/A-Century-of-Evidence-on-Trend-Following-Investing).

| Candidate | Reason to consider it | Main weakness / decision |
|---|---|---|
| Previous-day box reversal | Matches your existing Box Theory system | Can repeatedly fade a directional gold move; retain for comparison |
| RSI/divergence scalping | Can identify short-term reversals | Sensitive to spread, slippage and parameter choices; retain for comparison |
| Trend-aligned channel breakout | Clear rules, reproducible signals and a defined initial stop | Vulnerable to false breakouts and sideways periods; implemented as the initial candidate |
| Grid or martingale recovery | Can appear to produce frequent small wins | Increasing exposure after losses conflicts with bounded position risk; excluded |

The alternatives above are design comparisons, not measured performance rankings.

## Exact implemented signal rules

1. For broker execution, read **the configured VT Markets MT5 symbol's own candles**. Dashboard/email signals can select either `GOLD_SIGNAL_SOURCE=mt5` or `GOLD_SIGNAL_SOURCE=twelvedata`; the latter uses Twelve Data XAU/USD spot candles and labels their source. Provider prices can differ from broker CFD prices. The new strategy never substitutes Yahoo gold futures or mixes providers across timeframes. Your older strategies keep their existing data sources.
2. Use only closed bars, with UTC opening timestamps. Require at least 220 H1 bars and 50 M15 bars; the live bridge supplies 300 of each and the Twelve Data adapter requests 300 of each before excluding forming bars. Reject invalid OHLC and duplicate/out-of-order timestamps. Entries are evaluated within three minutes of the most recent M15 close.
3. H1 uptrend: close > EMA50 > EMA200, and EMA50 is above its value three H1 bars earlier. Reverse all inequalities for a downtrend. EMA uses an SMA seed.
4. On M15, close above the previous **20 completed bars'** high plus 0.1 ATR for a long, or below their low minus 0.1 ATR for a short. The signal candle must close in the entry direction. It is excluded from the channel calculation. Skip a close more than **0.75 prior-bar ATR** beyond the broken boundary; equality is allowed. The prior ATR excludes the signal candle. `GOLD_MAX_ENTRY_EXTENSION_ATR` configures this experimental cap and must be positive and finite.
5. ATR is Wilder ATR14 on M15. Accept ATR/price from 0.05% to 0.8%; reject signal candles wider than 2.5 ATR. These thresholds are starting hypotheses, not optimized values.
6. New entries only Monday–Friday, **07:00 through 16:59 UTC**. These are fixed UTC research hours, not an automatic daylight-saving conversion of exchange sessions.
7. Initial stop distance is the larger of **1.5 ATR** and the distance beyond the last five-bar swing plus **0.2 ATR**. Skip stops wider than 3 ATR. Never tighten a structural stop merely to fit an oversized lot.
8. Full-position target is **2R**, where R is the initial signal entry-to-stop distance. No second target, averaging down, pyramiding, break-even move or trailing stop is implemented. Existing positions are held until the broker stop or target executes. Overnight/weekend holding is possible; swaps and gaps matter.
9. Emails keep the entry, stop and closing target. They describe signal prices, not a promise of a broker fill. Execution can reject an emailed setup.

These rules are centralized in `GoldBreakoutStrategy`; the email service and automation use the same evaluator. Their candle sources can differ when API email mode is enabled, so signals and price levels need not match. Broker execution always obtains its own MT5 snapshot.

The entry-extension cap is also checked against the MT5 entry quote (buy ask / sell bid) and the historical simulator's next-open price after spread and slippage. Signals persist an absolute `entry_limit_price`, and emails explain that bound in the trade-plan notes. Existing volatility filters, stops and 2R targets still use the original ATR including the signal candle. This isolates the new maximum-distance rule. Actual market fills can slip beyond the checked quote; the cap is not a guaranteed fill price. Trend-strength changes, retest entries and time exits remain separate, unimplemented experiments.

## Execution and account risk

The initial default is **0.25% of equity per trade**; the hard maximum is 0.5%. These are engineering starting limits pending your capital, account denomination and drawdown preferences.

- Calculate stop loss in account currency with MT5 `order_calc_profit`, using an adverse entry allowance of 30 broker points. Add explicitly configured round-trip commission per lot. Reserve a further 10% of the selected risk budget, then round volume **down** to the broker lot step. If the minimum lot exceeds the budget, skip the trade. This avoids assuming a universal gold contract size or treating cent units as dollars. [MetaQuotes: profit calculation](https://www.mql5.com/en/docs/python_metatrader5/mt5ordercalcprofit_py).
- Confirm the broker tick size, stop-distance restriction, volume limits and allowed filling policy; run `order_check` before `order_send`. A successful check still does not guarantee execution. [Symbol properties](https://www.mql5.com/en/docs/constants/environment_state/marketinfoconstants), [order_check](https://www.mql5.com/en/docs/python_metatrader5/mt5ordercheck_py).
- Reject quotes over 30 seconds old, expired signals, drift exceeding 0.1R, spreads over the smaller of $1/oz or 10% of stop distance, or executable reward/risk below 1.8 before commissions. Target remains the emailed absolute target.
- Require enough margin that the order uses at most half of free margin.
- Allow **one account position**, no pending orders, at most three account entries per UTC day, and at least 30 minutes since the latest account deal. This deliberately blocks exposure to other instruments on the same account. Use a dedicated account.
- Daily loss budget: 1%; weekly: 3%. Count net realized trading P/L and charges plus negative floating account P/L. Positive floating P/L does not offset losses. Subtract realized P/L from current balance to calculate the period reference. An optional absolute daily cap is in the account's currency units. New planned stop risk must fit the remaining budget.
- Persist a **5% equity drawdown halt** from the highest equity observed by this bridge. Daily and weekly breaches latch for their UTC period; drawdown remains latched for manual review. This peak begins when monitoring starts, not at account inception. Deposits/withdrawals can change the reference; pause and review funding changes.
- Loss limits block new entries; they do **not** forcibly liquidate existing positions. SL/TP remain on the broker. Gaps, execution delays and slippage can exceed planned risk or loss limits.
- Require a news file updated within 24 hours with explicit coverage around the current time. Block new orders 30 minutes before/after high-impact USD events. Missing or stale coverage blocks entry. This implementation accepts a reviewed JSON calendar file or automatically refreshes a configured HTTPS endpoint in the same schema. No calendar provider is configured or bundled; a provider adapter may be needed. Unscheduled news cannot be excluded.

For calendar verification, use the official [BLS CPI schedule](https://www.bls.gov/schedule/news_release/cpi.htm), [BLS Employment Situation schedule](https://www.bls.gov/schedule/news_release/empsit.htm) and [Federal Reserve FOMC calendar](https://www.federalreserve.gov/monetarypolicy/fomccalendars.htm), plus a suitable source for other high-impact USD events. Convert published times to UTC with the date's daylight-saving offset. Cover both FOMC decisions and press conferences.

## Evidence required before live use

Repeatedly selecting the most attractive backtest can fit noise. Bailey and coauthors study this selection problem; a single favorable holdout is not a guarantee. Keep a record of every variant tried, freeze parameters before evaluation, and reserve untouched data. [*The Probability of Backtest Overfitting*](https://www.davidhbailey.com/dhbpapers/backtest-prob.pdf).

1. Export several years of VT Markets M15/H1 history, including quiet, trending and stressed periods. Verify gaps and symbol changes. Load at least 300 warm-up H1 bars before each evaluation period.
2. Run the supplied bar diagnostic with realistic spread, slippage and commission assumptions. Compare annual results and stress costs at 2× and 3×. It reuses the signal engine, enters at the next bar open, models short exits at ask and treats ambiguous intrabar SL/TP hits conservatively. It reports any position left open at the end.
3. This diagnostic is **not** a full execution backtest: historical news, variable spread, swaps, volume steps, margin, account loss gates and cooldowns are absent. Reproduce execution with broker tick data and those controls before making a capital decision. Do not multiply its aggregate R result into a promised account return.
4. Use chronological rolling development/validation periods, followed by a final untouched period. Compare the existing box/RSI strategies under the same execution assumptions. Check nearby parameters rather than selecting a sharp isolated optimum.
5. Suggested research gates, not achieved results: positive net expectancy after stressed costs; out-of-sample profit factor above 1.2; drawdown within your agreed budget; reasonable stability across periods and parameter neighbors; no concentration in a handful of lucky trades. Aim for at least 100 independent out-of-sample trades, and report uncertainty rather than treating that sample count as proof.
6. Forward-test on VT Markets demo for at least 6–8 weeks **and until enough trades occur to assess execution**. Compare emailed entry with actual fill, every skipped order, realized fees, stop/target fills and restart behavior. Test the kill switch and restoration from backup.
7. Only then consider a small live allocation after explicitly selecting the account and accepting the measured limits. No live account was enabled during this work.

At a theoretical +2R winner and −1R loser, gross break-even win rate is 1/3. If average round-trip costs are c in R units, it becomes `(1 + c) / 3`. Actual fills and unequal losses change this relationship; a high win rate alone is not the objective.

## VPS feasibility

Yes: VT Markets describes MT5 support for automated trading. [VT Markets MT4/MT5 FAQ](https://www.vtmarkets.com/en-eu/faq/a-complete-faq-on-vt-markets-metatrader-4-and-metatrader-5/).

The Python package communicates with a local MT5 desktop terminal. Use a Windows VPS for this execution bridge; the Laravel application may run on that machine or on separate Linux web hosting connected privately to it. A Linux-only web server is not sufficient for the selected native Python/MT5 deployment. Confirm your hosting provider's actual OS and persistent-process support before purchasing. [MetaQuotes Python integration](https://www.mql5.com/en/docs/python_metatrader5), [terminal initialization](https://www.mql5.com/en/docs/python_metatrader5/mt5initialize_py).

See [the deployment guide](../mt5_bridge/README.md) for the exact setup and remaining account-specific configuration.
