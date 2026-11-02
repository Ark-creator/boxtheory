# Additional-history validation plan

Recorded before fetching or inspecting additional price history, 2 November 2026.

The originally preferred trend-only change failed robustness checks: its inspected September-October result was only +0.29577R, became negative under doubled costs, and had worse closed-trade drawdown. The trend-plus-session comparison returned +12.6916R in that development sample but lost in its final 30%. It is a **development-selected contender**, not a retroactively preferred or validated winner.

The next check freezes three models: unchanged baseline, symmetric SMA200/three-bar-slope trend filter, and that trend filter plus 07:00-17:00 UTC weekday entries. Default entry parameters, simple RSI/ATR formulas, full TP1=1.5R, initial stop, one-position gate, source-close timing and 0.1R pre-fill quote guard stay unchanged. No short-only strategy, threshold search, target selection or date selection by returns is allowed.

The fixed additional-history period is **1 June through 31 August 2026**, inclusive, with the last complete available trading session before June retained for warmup if available. This is previously uninspected history but chronologically precedes development; it is not forward trading or a chronological post-training holdout. Its provider can differ from the user's broker, which is a separate limitation.

Preferred acquisition: official Dukascopy historical bid/ask M1 data. Fallback: the existing Twelve Data XAU/USD historical M1 API, requested in UTC, using the user's already-configured key without exposing it or changing credentials. Network failures, entitlement limits, empty responses or missing periods must be recorded; they must not be interpreted as zero trades or fabricated prices. No paid data purchase, new subscription or credential is authorized by this plan.

Actual bid/ask candles, if available, must be used directly with their source metadata. Bid-only data must carry an explicitly assumed spread: $0.37/oz constant (the user's prior broker screenshot, not a historical spread measurement), plus $0.10/oz adverse slippage per fill and $0.07/oz round-trip fee. Also report doubled spread/slippage/fees and gross results. Do not label a third-party price feed as MT5 account data. All source responses/hashes and UTC coverage are preserved.

Report all three models, monthly results, full net R, closed trades, win/loss counts, profit factor, closed-trade drawdown, gaps and open positions. A useful candidate must at minimum have positive net results under ordinary and doubled costs, avoid reliance on one month, and improve practical risk characteristics. A small or conflicting sample cannot certify an edge. No modeled performance is account profit or a guarantee.

**Provider-quality clarification before any additional-history performance is computed:** the first acquired Twelve Data windows contain a record for every calendar minute, including ordinary weekend closures. Those records are indicative prices, not evidence that gold was executable. Exclude closed-session M1 quotes from both execution and M5 aggregation using the already existing New York regular-session guard. Do not simulate Sunday stop/target fills on indicative data. Preserve the unfiltered source and report removal counts. Historical holiday/early-close status is not available and remains a limitation. Provider OHLC is not independently confirmed as broker bid; the bid-side execution convention and constant spread are explicitly hypothetical.

Do not change model parameters after viewing this period. If no model meets the criteria, record that failure and keep the implementation explicitly experimental. A new period may guide later research but cannot be reused as an untouched confirmation of a retuned model.
