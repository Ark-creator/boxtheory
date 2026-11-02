# RSI Gold Scalper V2: code update and paper validation

Updated 2 November 2026, Asia/Manila. The existing **XAUUSD RSI Gold Scalper aliases now use the trend-plus-session V2 research model by default**. No new catalog record, migration, dependency, task or broker order permission is required. The original evaluator remains available for reproduction and rollback. Other currency pairs retain their original RSI handler.

This is an experimental paper revision. Positive conditional backtests are not proof of live profitability. One additional-history month lost money, gains are concentrated, and exact broker bid/ask, swaps, holiday availability and fills are not verified. The findings did not meet every predeclared robustness criterion, so the application labels these decisions as paper research rather than a validated profitable strategy.

## What changes

- Retain the existing completed-M5 SMA9/21 crossover, simple RSI14 momentum check and original swing/ATR stop.
- Qualify BUY only when the M5 close is above SMA200 and that SMA is rising relative to three active bars earlier. SELL reverses both tests. Both directions remain available.
- Limit new entries to **07:00-17:00 UTC weekdays**, or **15:00-01:00 Philippine time**. This is a fixed model window, not a permanent claim about the best gold session. The regular market-session and holiday/provider checks still apply.
- Use one full target at **TP1 = 1.5R** or the original stop. The revised plan removes TP2 because it is not part of the selected, tested policy. There is no invented partial split, trailing stop, break-even move or automatic holding-time exit.
- Request 250 completed-history M5 bars and require 203 valid bars for the SMA200 slope. A blocked crossover exposes no old entry/stop/target plan.
- Retain actual quote-observation age <=90 seconds, latest completed candles, the 0.1R entry-drift/bracket guard, Sunday/maintenance blocking, stable IDs and a maximum three-minute candle-based entry window.

V2 payloads carry `strategy=rsi-gold-scalper-v2`, `research_only=true`, the filter name, source evidence and `exit_policy=full_tp1`. Email subjects/body and strategy screens identify paper research. The read-only current command returns `RESEARCH_SETUP` for eligible paper entries, `paper_setup_available=true`, and `can_enter=false`. No RSI broker execution is added. Existing email scheduling can send eligible **paper** alerts; the updater does not send anything itself.

The optional known-position CLOSE logic is retained for explicit position context. The scheduled email evaluator does not know recipients' positions and does not automate those exits. The primary performance figures use full TP1/SL only. Entry expiry does not close an existing position; some simulated holdings lasted overnight or longer. News-calendar filtering and a timed holding exit are not implemented or claimed as tested protection.

## Pull onto the email PC

Commit the new files as well as modifications, including `config/scalper.php`, the V2 service, current-signal changes, mail/view changes and the shared research-note component. Do not copy `.env` or API/SMTP credentials into Git. The downloaded source data stays in ignored private research storage.

Pause the existing email task while pulling. From the Laravel app folder on that PC, run:

```powershell
$php = 'C:\xampp\php\php.exe'
& $php artisan config:clear
& $php artisan view:clear
& $php artisan cache:clear
& $php artisan signals:check-data
& $php artisan signals:current rsi-gold-scalper
```

The current command sends no email or order and does not register a strategy. A healthy `HOLD`/`WAIT` or an expected closed-market block is normal. An unavailable or expired setup must not be used as an entry. Review any open-session data errors before resuming the existing task. Do not add a second task or use a forced-send command for installation testing.

The default is `RSI_GOLD_SCALPER_VERSION=trend-session-v2`. An existing explicit environment override takes precedence. `trend-v2` exposes the trend-only diagnostic; it failed cost/drawdown robustness and is not the selected model. To restore the prior RSI rules, set `RSI_GOLD_SCALPER_VERSION=legacy` and clear configuration/cache. The legacy override does not turn V2 into a validated strategy. The explicit `rsi-gold-scalper-v2` command alias can still request its research handler; ordinary `rsi-gold-scalper` follows the configured mode.

If this PC needs PHP TLS/proxy configuration, use a trusted CA bundle and its working network settings as described in [the admin email guide](ADMIN_SIGNAL_EMAILS.md). TLS verification is never disabled. The one-off research download settings are not copied into deployment configuration.

## Evidence and limits

The [primary-source review](research/scalper-upgrade-2026-11-02/RESEARCH.md) covers gold-market structure, session liquidity, announcement effects, source/execution differences and backtest selection bias. The exact [development protocol](research/scalper-upgrade-2026-11-02/PRE_REGISTERED.md) and all failed comparisons are preserved.

The advance trend-only hypothesis failed robustness. The combined filter became a development-selected contender and was frozen before acquiring additional June-August history; that selection is explicit in [the additional-history plan](research/scalper-upgrade-2026-11-02/EXTERNAL_VALIDATION_PLAN.md). The new history chronologically precedes development and uses a different price source, so it is not forward trading or a post-training chronological holdout.

See [the final implementation and validation report](research/scalper-upgrade-2026-11-02/IMPLEMENTATION.md) for costs, results, monthly failures, provider provenance, numerical parity and test status. R is normalized initial trade risk, not dollar, peso or account-percentage profit. Do not aggregate the different-provider studies into an executed-account return.
