# GoldLogic RSI Scalper V2 for MT5

Native MT5 port of our updated RSI Gold Scalper V2 with the combined trend and session filters. This research release runs in Strategy Tester or on a **demo account**. Real-account operation is blocked in the EA; there is no live-enable input. It runs inside MT5 and needs no PHP/Laravel deployment or API key.

The strategy is experimental. Earlier results used conditional execution assumptions and different data sources; they do not establish profitability on your broker.

Download the [source package](../../docs/releases/goldlogic-rsi-scalper-v2-mt5-2026-11-02.zip), or use this folder directly.

1. In MT5, select **File > Open Data Folder**. Copy this entire folder into `MQL5\Experts\GoldLogic_RSI_Scalper_V2\`, keeping `ScalperCore.mqh` beside `GoldLogic_RSI_Scalper_V2.mq5`.
2. Press **F4** to open MetaEditor. Open `GoldLogic_RSI_Scalper_V2.mq5` and press **F7**. Check the compiler output before proceeding; compilation creates the `.ex5` used by MT5.
3. Return to MT5 and refresh **Navigator > Expert Advisors**. Press **Ctrl+R** to open Strategy Tester and select this EA, your broker's exact USD gold symbol, **M5**, and **Every tick based on real ticks**. The symbol must report XAU as its base currency, USD as its profit currency, and Bid-based candles. Use your broker's own history and realistic deposit, account currency, commission, swap and execution settings. MetaQuotes describes these [tester settings and real-tick mode](https://www.metatrader5.com/en/terminal/help/algotrading/testing).
4. In **Inputs**, load `Tester-UTCplus3.set`. **Confirm the broker's historical server-to-UTC clock before changing `InpConfirmBrokerClock` to `true`.** The supplied preset intentionally leaves it `false`, so initialization fails until the clock has been checked.
5. Run a single test first. Read the tester Journal and trades, including rejected entries, actual costs and positions still open at the end. Leave optimization disabled for this initial check.
6. For subsequent demo forward testing, attach the compiled EA to an M5 gold chart on a demo account. Enable Algo Trading when you want it to submit demo orders. Keep the Journal and signal logs for comparison with the tester.

The preset's UTC+3 clock is an **unverified example from the earlier CSV**, not a confirmed broker setting. Offsets are minutes, not hours:

| Input | Example | Meaning |
|---|---:|---|
| `InpBrokerWinterUtcMinutes` | `180` | Server time minus UTC in the broker's winter/fixed schedule |
| `InpBrokerSummerUtcMinutes` | `180` | Server time minus UTC in its summer schedule |
| `InpBrokerDstRule` | `0` | `0` fixed; `1` US rule; `2` EU rule. Choose only a broker-confirmed convention. |
| `InpConfirmBrokerClock` | `false` | Required confirmation; change only after verifying offsets and historical DST behavior |
| `InpRiskPercent` | `0.25` | Planned percentage of account equity at risk, subject to broker volume/margin checks and sizing reserves |
| `InpCommissionPerLotRoundTurn` | `0` | Estimated round-trip commission per lot **in account currency**, reserved during sizing |
| `InpMagic` | `2601102` | Identifier for this EA's orders |
| `InpMaxDeviationPoints` | `10` | Requested execution deviation in broker points; not a guaranteed fill bound |
| `InpHistoryBars` | `4096` | History requested to obtain sufficient closed, session-valid M5 bars |
| `InpWriteSignalLog` | `true` | Enable the EA's signal audit log |

`InpCommissionPerLotRoundTurn` does **not** charge commission in the tester or simulate a fee deduction. Set actual commission separately in Strategy Tester/account settings. Zero means no extra commission reserve was specified; it does not mean the broker is free.

The EA uses explicit broker-clock configuration. It does not infer historical GMT from the tester: MetaQuotes documents that [`TimeGMT()` equals simulated server time during testing](https://www.mql5.com/en/docs/dateandtime/timegmt). The core supports 2007-2099 and rejects ambiguous or nonexistent server times under the selected DST rule. Fixed mode requires equal winter and summer offsets; US/EU modes require the summer offset to be 60 minutes higher.

For example, a broker-confirmed fixed UTC+3 history uses `180 / 180 / GL_FIXED`. A broker-confirmed UTC+2 winter / UTC+3 summer history uses `120 / 180` and its actual US or EU transition rule. US transitions are March's second Sunday at 07:00 UTC and November's first Sunday at 06:00 UTC; EU transitions are the last Sundays in March and October at 01:00 UTC. The broker must follow the selected schedule. For demo forward testing, synchronize Windows time; the EA also checks the configured broker clock against the computer's UTC clock. Do not attach multiple trading systems to the same gold symbol for this comparison.

The implemented rules are:

- Use completed M5 bars. The entry is an SMA9/SMA21 crossover with **simple RSI14** confirmation: BUY with RSI strictly between 50 and 70; SELL strictly between 30 and 50.
- BUY requires the source close above SMA200 and SMA200 above its value three completed active bars earlier. SELL reverses both inequalities. This requires 203 closed, session-valid M5 bars. SMA200 comparisons use eight decimal places.
- New entries are limited to UTC weekdays **07:00-17:00**, ending before 17:00. In Manila this is **15:00-01:00 the following day**; eligibility follows the UTC weekday. Broker/regular market-session checks still apply.
- ATR14 is the arithmetic mean of true ranges. The initial stop uses the adverse 12-bar swing plus 0.2 ATR or a 1.2 ATR distance, whichever is wider, with the original 0.8 ATR floor. The single full-position target is 1.5 times the original signal risk.
- Management uses the initial SL and full TP1. There are no partial exits, breakeven moves, trailing stops or time exits. Positions can remain overnight or across weekends; swaps and gaps matter.
- A fresh quote, a signal within its 180-second lifetime and quote drift within 0.1R are required. The maximum quote age is 90 seconds. Any existing position or pending order on the same symbol, including manual trades, blocks another entry.
- Broker tick-size alignment moves the stop outward and the target inward. Sizing uses the resulting stop and an adverse execution reserve. Minimum-volume trades that exceed the risk budget are skipped. Gaps and actual fills can still exceed planned risk.

Broker candles and executable bid/ask quotes can differ from Twelve Data spot history. Native broker M5 bars can also differ from complete-minute aggregates used in the earlier research. Compare source candle times, decisions, stops, targets, skipped orders, fees and actual fills before drawing conclusions from a return comparison.

Signals are evaluated once per completed M5 candle. Attaching the EA within the signal's lifetime can evaluate that latest closed candle; it never replays a backlog. Signals expire exactly at close +180 seconds. If the quote changes during the broker's order precheck, that candle is skipped. A persistent terminal lock protects demo restarts from repeating the same candle; Strategy Tester runs start independently. These execution checks can skip signals that appear in the historical research.

CSV output is `GoldLogic_RSI_V2_<symbol>_<magic>.csv` in the executing terminal/tester agent's `MQL5\Files` sandbox. The file is replaced on initialization. Its decision timestamps are UTC; the Journal reports order responses and confirmed fills separately. A pending or uncertain server response halts entries. A missing or invalid original stop/target triggers an emergency close request and a halt; review the actual positions, orders and Journal before restarting. No daily loss cap or economic-news filter is implemented in this frozen research model.

Server-held SL/TP remain in place across EA restarts. After a restart the EA checks that its position has a valid bracket, but it cannot reconstruct the original levels if someone manually changed them while the EA was stopped. Keep those levels unchanged when comparing this model with the research.

Optional arithmetic smoke test: copy `CoreSelfTest.mq5` and `ScalperCore.mqh` together into `MQL5\Scripts\GoldLogic_RSI_Scalper_V2\`, compile the script with **F7**, then run it from **Navigator > Scripts**. Read `GoldLogic pure core self-test: PASS` or `FAIL` and its check/failure counts in the log. It places no orders. A passing fixture test checks calculations, not profitability.

Build verification: the EA and `CoreSelfTest.mq5` compiled in MetaEditor build 6295 with **0 errors and 0 warnings**. A restricted JavaScript translation of the actual MQL calculation functions matched the actual PHP evaluator on **1,760 historical crossover frames / 21,120 numeric fields**, plus 30 rounding cases, with zero differences. Malformed-data, UTC/DST, quote-age and mocked broker-risk checks also passed. This is offline translated-source verification, not native MQL execution. The existing PHP strategy/backtester tests passed: 71 tests, 580 assertions.

See [VERIFICATION.json](VERIFICATION.json) for source hashes and validation limits. The compiled self-test assertions have not been executed in MT5, and no broker-history Strategy Tester run or demo forward test has been performed. Compile logs are included; compilation alone does not validate execution or returns.

Developer reproduction from the repository root, with the original private research datasets present:

```powershell
php -d memory_limit=1G scripts/research/export_mt5_scalper_fixtures.php
node scripts/research/verify_mt5_scalper.cjs
```

The fixture exporter evaluates the actual PHP strategy without booting Laravel. Historical prices stay in ignored private storage and are not bundled in this EA package. No API keys or application environment files are needed by the EA.
