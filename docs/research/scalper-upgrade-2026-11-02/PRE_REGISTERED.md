# Prespecified RSI Gold Scalper filter study

Registered 2 November 2026, Asia/Manila, **before computing these candidate-filter returns**. This is development research on the already-inspected September–October M1 CSV, not a new holdout. Earlier baseline results and their limitations are known. The study cannot establish future profitability.

**Preferred engineering-upgrade hypothesis is declared in advance: trend-only, with the existing full TP1 policy unchanged.** Session-only and combined filters are comparison diagnostics. The production hypothesis will not be selected by taking the highest return among these four variants. This document records a development decision after the price history and baseline were already inspected; it is not genuine prospective preregistration on unseen data.

The unchanged `TradingService::evaluateRsiMovingAverage` supplies entry signals, using the last 200 completed, active-session M5 bars. Its SMA9/21 crossover, simple RSI14, mean ATR14, swing/ATR stop and original absolute targets remain unchanged. Only the entry eligibility filters below vary. BUY and SELL use symmetric inequalities; no short-only rule is tested.

Exactly four candidates are registered:

1. **Baseline:** all eligible original RSI entries.
2. **Trend:** BUY only when the signal M5 close is strictly above SMA200 and SMA200 is strictly above its value three completed M5 bars earlier. SELL reverses both inequalities. SMA200 at each decision uses exactly 200 completed active-session closes. The earlier SMA needs three additional bars; insufficient history rejects the candidate rather than using a shorter average. This tests avoiding reversals against a slow local trend, not an optimized MA length.
3. **Session:** entry decision must fall Monday–Friday, 07:00 through 16:59 UTC. This is the fixed London/New York research window already used elsewhere in the project, not a search over winning hours or an automatic daylight-saving session definition.
4. **Trend and session:** both fixed filters above must pass.

Before computing results, numerical comparison is fixed as follows: calculate each SMA200 from its exact 200-close window, then round the SMA to eight decimal places before the strict comparisons. This only prevents floating-point summation noise from turning an equal mean into a rising/falling mean; it retains substantially more precision than the supplied two-decimal XAUUSD quotes and their 200-bar mean. No slope magnitude threshold is fitted.

The trend filter tests whether a lagging local direction check reduces adverse countertrend entries. The session filter tests a liquidity/activity hypothesis; exported minute-minimum spreads cannot establish real intraminute execution costs. Either filter can remove profitable trades or concentrate exposure. Combining filters is an explicit fourth candidate, not a search over arbitrary combinations.

Primary policy is one position, full **TP1 or initial SL**, without position-aware strategy CLOSE. TP2 is a separately declared full-position sensitivity. The existing RSI M1 tester supplies quote drift/bracket checks, actual-entry risk, spread proxy, slippage, fees, opening-gap priority, stop-first ambiguous minutes, missing-entry rejection, and censored positions. It receives the filtered entry maps with all retained signals and their prices unchanged. No new daily cap, cooldown, minimum reward/risk, stop adjustment, target search, averaging or trailing rule is introduced.

Working clock is CSV UTC+3 throughout; CSV UTC is a sensitivity only. Point size 0.01 remains an unverified assumption. The four fixed cost models are gross, minute-minimum spread only, minimum spread plus 0.10 USD/oz adverse slippage per fill and 0.07 USD/oz round-trip fees, and doubled spread/slippage/fee assumptions. Actual broker charges, intraminute ask extremes and opening spreads remain unknown.

Evaluate the same full sample and the same elapsed-calendar first-70% / final-30% split as the baseline, retaining earlier indicator warmup and resetting positions at segment start. Both segments have already been inspected; neither is presented as untouched evidence. Report monthly and ISO-week exit summaries, entry/rejection counts, long/short trade counts, expectancy in R, profit factor in R, closed-trade drawdown, censored positions, ambiguous minutes and trades spanning data gaps. Report contiguous-path metrics separately as a subset, not a freshly simulated account.

Decision discipline: do not select a clock or target because its return is favorable. Report every registered variant and cost model. Treat a candidate as unsuitable for a profitability claim if its working-clock net expectancy is nonpositive, it depends on a few trades, the final diagnostic segment loses, or stressed costs erase the sample result. Positive results only justify further independent data and forward testing. There is no promised minimum sample size that would prove profitability here.

A time exit is **not** part of this initial filter study. The existing evaluator has no maximum holding duration. A later fixed 60-minute time-stop experiment would require a separate registration before its returns are computed; it must not be silently mixed into these four candidates.

No production evaluator, live feed, alert catalog, account or runtime configuration is changed by this study.
