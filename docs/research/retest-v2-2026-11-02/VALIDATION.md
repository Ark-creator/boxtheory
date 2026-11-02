# Implementation validation, 2 November 2026

Gold Trend Retest V2 repairs stale candidate state and inconsistent rolling indicator seeds. The comparison in [README.md](README.md) remains negative in the primary assumed-cost scenario: 3 wins, 6 losses, -0.20454R. These engineering checks do not establish profitability.

- Full PHP suite: 352 passed, 2 existing failures, 1,394 assertions. The failures are the unchanged login/registration redirect assertions expecting `/dashboard` while the application redirects to `/strategies`.
- V2 live integration: 19 passed, 221 assertions. These exercise the real engine and fake provider data, including paper-only decisions, canonical strategy identity, source quote age, first retest, bounds, expiry during HTTP calls, missing history, Sunday closure, default-off and explicit paper-email registration. Mail is faked and no real email/order is sent.
- V2 strategy unit checks: 12 tests establish bounded-history/batch/prefix consistency, causal H1 trend selection, first-retest consumption and entry bounds. Existing V1 unit checks remain unchanged in behavior.
- Four transport cases verify explicit trusted CA/proxy handling, fail-closed CA errors and sanitized connection errors. TLS verification is never disabled.
- Pint checks passed for all 33 changed PHP files.
- The separate portable V2 release passed 11 installer fixture checks, including installation over the actual repository baseline, upgrade from the preserved freshness package, backup, idempotency, unknown-edit rejection and unhealthy-data handling. Fixtures invoke a stub application, never the live scheduler. All 16 runtime payload hashes match the frozen source. The earlier freshness ZIP remains unchanged.

## Actual read-only current-data check

At **2026-11-02 12:30:32 PHT** (`04:30:32 UTC`), `signals:current gold-trend-retest-v2` successfully evaluated the configured Twelve Data XAU/USD feed:

| Field | Recorded result |
|---|---|
| Source quote | $3,904.49047 per ounce |
| Provider observation | 04:30:00 UTC / 12:30:00 PHT |
| Quote age at check | 32 seconds |
| Latest verified M15 candle close | 04:30:00 UTC |
| Strategy decision | WAIT |
| Reason | Outside the 07:00-17:00 UTC weekday entry session |
| Entry/stop/target exposed | None |
| Emails/orders sent | 0 / 0 |

This is a timestamped observation, not a continuously updating quote, executable broker bid/ask, or current trade recommendation. A fresh quote does not supply a strategy entry when its rules do not qualify. Re-run the current-data command for a later decision.

The local PHP transport needed a trusted CA bundle and an explicit direct connection because its default proxy/CA configuration failed. The successful check used per-process configuration only; it did not modify `.env`, credentials, scheduling or global PHP settings. The other email PC still requires its own installation and read-only health check using its existing credentials. No deployment to that PC is claimed.

V2 paper-email evaluation remains disabled by default. The current command neither enables it nor registers a strategy, sends a message or places an order. The portable installer and usage instructions are in [the V2 update guide](../../GOLD_RETEST_V2_UPDATE.md).
