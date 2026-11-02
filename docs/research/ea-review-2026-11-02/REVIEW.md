# Review: XAUUSD Apex Scalper EA v3.00

Reviewed 2 November 2026, Asia/Manila. **The supplied EA is not ready for live use as written.** It combines a testable trend/pullback concept with material implementation defects. No profitability claim or win rate is established by this review.

The attachment combines Laravel environment settings with an independent MetaTrader 5 Expert Advisor. The EA begins at original attachment line 96. This review excludes the environment section and does not reproduce credentials. The Laravel environment settings do not configure the EA's MQL5 inputs. Do not save the combined paste as a single `.env` or `.mq5` file.

Only the EA was extracted to private research storage for inspection. Its normalized-LF SHA-256 is `83cde4c54f84f36a651e7be7a3af90fe1388b5030f5578638b53a3b3b8c97300`. Line references below use **EA line / original attachment line**, where original = EA +95. The existing Laravel scalper, environment, scheduler and broker permissions were not changed. No orders or emails were sent.

## What it intends to trade

Default inputs use M5 EMA20/50 trend alignment and an M1 EMA8/20 pullback/rejection trigger. RSI14 and a tick-volume-weighted intraday price envelope qualify entries. H1 ATR is intended to limit volatility. It submits a broker SL and final target, attempts a 60% partial at TP1, moves to a price-based breakeven buffer, trails, and requests a close after 15 minutes or the configured session cutoff. Risk inputs include percentage sizing, a spread threshold, daily entry count, loss streak and daily drawdown checks.

Those are hypotheses and engineering controls to verify, not evidence that the model is profitable. The descriptions "institutional" and "production" do not establish implementation quality or a trading edge.

## Critical and high-priority findings

### 1. Fixed-size arrays invalidate the claimed closed-bar indexing

Locations: **710-720 / 805-815**, **736-757 / 831-852**, and crossover **853-854 / 948-949**.

The EA calls `ArraySetAsSeries(...,true)` on fixed arrays such as `double rsi[2]`, `double fastEma[3]` and `MqlRates htfRates[2]`. MetaQuotes documents that static arrays cannot acquire the series flag, and copying puts the oldest requested element first in physical memory. Those failed direction changes are not checked. [ArraySetAsSeries](https://www.mql5.com/en/docs/array/arraysetasseries), [CopyBuffer](https://www.mql5.com/en/docs/series/copybuffer), [CopyRates](https://www.mql5.com/en/docs/series/copyrates).

| Requested fixed array | Actual index 0 | Actual index 1 | Actual index 2 |
|---|---|---|---|
| Three values from shift 0: M1 EMA/rates | Closed bar 2 | Closed bar 1 | Forming bar 0 |
| Two values from shift 0: ATR, H1 ATR, RSI, HTF EMA/rates | Closed bar 1 | Forming bar 0 | Not present |

Consequently the RSI, H1 ATR and higher-timeframe trend use forming values. The alternate crossover mode compares the closed bar with the current forming bar rather than two completed bars, potentially interpreting the crossing in the wrong temporal direction. The default pullback's M1 candle happens to be closed because it uses the middle element, but its other confirmations are not all closed.

This is not necessarily future information in a real-tick terminal: the forming values exist at that tick. It does break the described closed-candle strategy, and a simplified historical replay that substitutes each forming bar's final OHLC would model a different, potentially biased system. Correct the buffer conventions first, using explicit closed-bar copy offsets and verified indexing or dynamic series arrays.

### 2. The risk percentage is not enforced when minimum volume is too large

Location: **461-490 / 556-585**.

Calculated volume below the broker minimum is increased to that minimum. Invalid tick-size/tick-value data also returns the minimum volume. A desired risk of $0.50 therefore does not stop a trade whose minimum permitted lot risks $3 or $4. These amounts are illustrative; the actual contract, account currency and minimum volume must be read from the broker.

The trade should be skipped when the minimum executable size exceeds its risk budget, or when reliable loss evaluation is unavailable. Quantize downward by the actual lot step and verify the estimated stop loss in account currency, allowing separately for costs/slippage. [OrderCalcProfit](https://www.mql5.com/en/docs/trading/ordercalcprofit), [symbol trade properties](https://www.mql5.com/en/docs/constants/environment_state/marketinfoconstants).

### 3. Partial-close state can record success when no reduction occurred

Locations: **554-568 / 649-663**, **586-600 / 681-695**.

`PositionClosePartial` and breakeven modification results are ignored. The ticket is then marked partially closed unconditionally, including when minimum-volume checks prevented any partial request. With a 0.01 minimum and a 0.01 position, no partial reduction is possible. With 0.02 lots and a 0.01 step, a requested 60% floors to 0.01, meaning 50%, not 60%.

The CTrade partial-close method is documented for hedging accounts, but the EA neither validates that mode nor implements a netting-compatible reduction. A successful method return also does not establish server execution. Confirm return codes and the resulting deal/position volume before recording stage success; keep reduction, breakeven and trailing state distinct. [PositionClosePartial](https://www.mql5.com/en/docs/standardlibrary/tradeclasses/ctrade/ctradepositionclosepartial).

### 4. Trailing can immediately weaken a successful breakeven stop

Locations: cached SL **517 / 612**, modifications **564-576 / 659-671**, mirrored sell logic **596-608 / 691-703**.

The function captures `currentSl`, sends a breakeven modification, then tests trailing against the old cached stop. For a BUY with ATR 0.50 and gross price profit 0.60, it can first set entry+0.20 and then overwrite it with entry+0.10, if the broker's stop-distance rules permit both. The second comparison passes against the original losing stop.

Refresh the confirmed position after modification or compute one monotonic final stop that cannot weaken the most recently accepted stop or breakeven floor. Failed modifications must not advance management state. [PositionModify](https://www.mql5.com/en/docs/standardlibrary/tradeclasses/ctrade/ctradepositionmodify).

### 5. Trade acceptance, fills and modifications are not reliably confirmed

Locations: new orders **886-895 / 981-990**; close requests **524,534 / 619,629**; modifications **566,576,598,608 / 661,671,693,703**.

New orders log only when `Buy`/`Sell` returns false. MetaQuotes explicitly distinguishes successful structure checks from executed trades. Close/modify results are mostly ignored entirely. Rejections, partial fills, delayed execution and unsuccessful timed exits therefore lack reliable reconciliation and retry state. Check accepted server return codes and resulting orders/deals/positions, with explicit handling for partial execution. [Buy result semantics](https://www.mql5.com/en/docs/standardlibrary/tradeclasses/ctrade/ctradebuy), [server return codes](https://www.mql5.com/en/docs/constants/errorswarnings/enum_trade_return_codes).

### 6. Netting can change another strategy's exposure

Location: **617-628 / 712-723**.

The position counter ignores any position without this magic number. In a netting account there is only one aggregate position per symbol; an order can therefore increase, reduce or reverse an existing manual/other-EA position that the counter ignored. Require exclusive ownership or block entries whenever that netting symbol already has exposure. [Netting versus hedging](https://www.mql5.com/en/docs/standardlibrary/tradeclasses/ctrade/ctradepositionclose).

### 7. Enabled data-dependent protections can silently disappear

VWAP locations: **761 / 856**, **794-807 / 889-902**. History locations: **357-403 / 452-498**.

When VWAP calculation fails, both directional permissions remain true even with the VWAP filter enabled. A failed history selection similarly leaves daily entry/loss counters zero. Block new entries when required data is unavailable; distinguish unavailable protection from a genuinely passing filter. [HistorySelect result](https://www.mql5.com/en/docs/trading/historyselect).

## Additional correctness and modeling findings

- **The new-bar marker is late:** **725-728,863-864 / 820-823,958-959**. It advances only after a signal reaches the order-attempt branch. Most rejected or waiting bars are reevaluated on every tick. Because some buffers are forming, a later tick can create an intrabar entry despite the closed-bar claim. It also repeats history/VWAP work unnecessarily. Data-readiness retries and one-evaluation-per-closed-bar state should be separate.
- **TP1 moves after entry:** **542 / 637** recomputes its distance from the current tick's ATR, while the initial broker target was set at entry. Persist the entry-based TP1 definition if that is the intended rule; dynamic trailing ATR can be modeled separately.
- **State is not durable:** **253-262 / 348-357** resets the in-memory partial-ticket list after restart and on a new server day. A surviving position can receive another partial reduction. Reconstruct or persist management stages from confirmed position/deal history.
- **Daily accounting counts deals, not completed positions:** **371-384 / 466-479**. Partial winning exits reset the loss streak before a losing remainder; entry commissions, `DEAL_FEE`, and close-by exits are omitted from the realized tally. The separate equity check mitigates some missing charges but does not make the streak correct. [Deal properties](https://www.mql5.com/en/docs/constants/tradingconstants/dealproperties).
- **The 2.5% daily control is a new-entry gate:** **396-403 / 491-498** does not itself liquidate exposure or persist a latched halt. The day's reference balance is set at EA startup/first tick and changes after an intraday restart. It is not a guaranteed daily loss ceiling.
- **Volume/price grids need broker-specific normalization:** volume is rounded to two decimals regardless of `SYMBOL_VOLUME_STEP`; prices are normalized by digits rather than tick size. Entry SL distance is calculated from Ask for BUY and Bid for SELL without validating the final distance from the stop-trigger side. At a forced broker minimum distance, spread can make the SL too close. [Symbol properties](https://www.mql5.com/en/docs/constants/environment_state/marketinfoconstants).
- **Time/news handling is not calendar-aware:** **268-329 / 363-424** uses a manually fixed server-minus-GMT offset and a fixed 13:25-13:40 GMT pause. US 08:30 Eastern changes UTC hour seasonally; a fixed window and offset cannot automatically follow both broker and US DST. Errors can even cancel temporarily without making the conversion reliable. There is no event calendar or protection for releases at other times. MetaTrader calendar timestamps use trade-server time. [BLS CPI release schedule](https://www.bls.gov/schedule/news_release/cpi.htm), [MQL5 economic calendar time semantics](https://www.mql5.com/en/docs/calendar).
- **VWAP is a broker-data proxy:** **414-449 / 509-544** anchors to server midnight and weights bar typical prices by broker tick volume, with zero volume replaced by one. That is not a consolidated gold exchange-volume VWAP or a London-opening anchor. Describe and test that exact proxy.
- **Time limits require ticks and successful execution:** the 15-minute and session-close paths cannot guarantee a fill during disconnects, gaps or closure. Broker SL/TP remain important, but close outcomes must be checked.
- **Optimization score is not validation:** **904-920 / 999-1015** uses valid tester-statistic identifiers, but a no-loss profit factor can reach `DBL_MAX`, and the multiplication has no finite guard. The over-15% drawdown branch only discounts fitness rather than rejecting the run. A 30-trade minimum and maximizing in-sample fitness do not establish out-of-sample profitability. [Tester statistics](https://www.mql5.com/en/docs/constants/environment_state/statistics).

## Parameter-fit check against the supplied gold history

This is a diagnostic of the intended completed-bar volatility filter, **not a backtest of the buggy, tick-managed EA**. The source is the same 60,475-row September-October M1 broker CSV, SHA-256 `c67ea70ca5715726daa93df84bc073632aed03643fc28f58550195df1d54f6ab`. The working broker clock is UTC+3, not confirmed metadata. Complete aggregation produces 966 H1 bars; 44 partial buckets are omitted.

The default H1 ATR band is **1.80-5.50 USD/oz** at **60-61 / 155-156**. After ATR warmup:

| Completed H1 ATR14 proxy | Observations | Minimum | Median | Maximum | Passing the EA band |
|---|---:|---:|---:|---:|---:|
| Simple mean of 14 true ranges | 952 | 8.47 | 15.74 | 30.68 | **0 / 952** |
| Wilder-smoothed sensitivity | 952 | 10.35 | 16.07 | 25.56 | **0 / 952** |

The same rejection persists after a longer 100-bar warmup and in the checked default/corrected session windows. These proxies strongly indicate poorly matched default volatility limits for this dataset. They do not establish exact native MT5 indicator values or the actual bugged EA's trade count. Indicator seed/history, incomplete aggregation and the forming-bar bug prevent that claim. Do not simply raise the threshold until a backtest becomes profitable. [MetaQuotes ATR description](https://www.mql5.com/en/code/12).

The M1 ATR7 proxy during default windows has median about 2.08. Its 1.5x ATR stop exceeds the hard $4 cap on approximately **28.96%** of those observations. Compressing a volatility-derived stop to a fixed cap changes the intended risk geometry; skipping oversized setups is a distinct hypothesis to test, not a guaranteed improvement.

## The advertised targets are not the realized reward/risk

For an illustrative fixed entry ATR=2.00 with no broker-distance override, the initial SL distance is 3.00, TP1 is 2.40 (0.8R), and TP2 is 5.00 (1.667R). If a true 60% partial fills at TP1 and the remaining 40% reaches TP2, the weighted gross gain is approximately **1.147R**. If the remaining part instead exits at entry+0.20, it is about **0.507R** before fees/slippage. Actual volume quantization, changing ATR, trailing, spread-side paths and the management bugs alter those payoffs. A displayed 2.5x ATR target is not a 2.5R whole-position result.

The numerical [ATR and payoff diagnostic](atr-and-payoff-diagnostic.json) preserves every assumption and qualification. It does not contain entry simulations, winning-trade counts, account profit or a fitted replacement parameter set.

## Review limits and required sequence

No MetaEditor compiler was found in the checked PATH and bounded MetaTrader/VT installation locations, so this EA **was not compiled**. No trading terminal or EA was launched. The checks were source review, official-reference verification and a limited historical parameter-fit calculation. The presence of serious code findings is verified; profitability after corrections remains unknown.

Before a meaningful performance assessment, correct array indexing, fail-closed sizing/data gates, confirmed execution/account-mode handling, durable stages and monotonic stops. Then compile on the intended MT5 build, test rejected and partial orders plus restart behavior, and run the corrected version using broker real ticks with actual contract specifications, costs, slippage and date-correct sessions/news. Keep separate untouched or forward data and disclose all optimization trials. The supplied M1 OHLC CSV alone cannot reproduce this EA's tick-by-tick partial/breakeven/trailing behavior faithfully.
