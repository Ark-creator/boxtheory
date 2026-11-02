# Admin entry signal emails

Admin alerts check every strategy in the database for the configured default market (`TRADING_DEFAULT_PAIR=XAUUSD`). `SIGNAL_ALERT_ACTIONS=BUY,SELL` sends entry setups. HOLD, WAIT and CLOSE are excluded with this setting.

Each email identifies the strategy, market and direction and includes entry, stop loss and take-profit prices. Signals with an explicit expiry show that deadline and are skipped after expiry, including when using `--force`.

## Fresh market data checks (2 November 2026)

The current signal path uses Twelve Data spot candles and a separate `/quote` request. It does not substitute Alpha Vantage or Yahoo gold futures when that feed fails. The regular gold session is checked in `America/New_York`, including daylight saving changes, the weekend closure and the daily 17:00–18:00 maintenance break. Philippine Sunday is closed for this gold feed; reopening occurs early Monday Philippine time. Forex pairs use their separate weekly session. Provider `is_market_open` must also be true; the regular session alone does not establish holiday availability.

Only completed, correctly ordered UTC intraday candles are used. Invalid OHLC, duplicate timestamps, missing latest candles and historical candles from closed sessions are rejected or excluded. Extra lookback is requested so removing indicative weekend candles does not replace real trading history. Indicators legitimately use historical candles, but the setup must come from the latest completed candle. Box levels are calculated from complete same-provider H1 bars for the previous UTC weekday, avoiding the provider's separate daily-bar timezone convention.

The provider quote must have a valid `last_quote_at` no more than 90 seconds old. The quote endpoint's ordinary `timestamp` is the opening of the selected interval and is **not** accepted as evidence of a current quote. A fresh HTTP request time or fresh email generation time cannot make old prices valid. Missing timestamps, delayed quotes, API errors/quota limits, closed markets and unverifiable data produce `NO_DATA` and no email. Quotes are cached for at most 10 seconds and revalidated; completed candles expire at their next close. Delayed bars retry after 30 seconds. These are minute-feed checks, not a tick-stream latency guarantee or a guarantee of a broker fill.

Every production signal expires no later than three minutes after its confirmed candle close. BUY/SELL entries also require the current quote to remain inside the original stop/target bracket and within 0.1R of its planned entry. Stable signal IDs suppress the same direction from the same strategy/source/pair/candle even if quote prices change. Delivery revalidates after locking, after persistence and before each recipient. `--force` bypasses duplicate suppression only; it never bypasses sessions, freshness or expiry. Dashboard/watchlist cache entries are also revalidated rather than displayed past expiry.

Emails label the confirmed candle close, provider quote observation, email preparation and entry expiry in Philippine time (`PHT`, UTC+8). Signal payload timestamps remain UTC for auditing. An email provider may deliver an accepted message later; its displayed expiry still applies.

Read-only verification (sends **no emails or orders**):

```powershell
php artisan signals:check-data
```

This shows the session, source timestamp/quote age, latest closed bars and provider errors. Closed sessions exit normally without an API call. During open sessions, missing credentials or unverifiable data produce an unsuccessful health result and entry alerts remain blocked. Check the other machine separately using its own `.env` credentials and PHP installation. Do not copy API keys into the update bundle.

For the email machine's update procedure, see [the portable update guide](SIGNAL_FRESHNESS_UPDATE.md).

The latest successful signal signature is cached separately for each strategy, market and recipient to suppress unchanged snapshots. This cache is separate from the permanent delivery history described below. Sent history records also prevent unchanged signals from being resent after the cache is cleared. A failure for one recipient does not stop the others. A later run retries a failed delivery if that signal is still current. Overlapping sends to the same recipient are locked. Errors produce a nonzero command exit code.

## Signal delivery history

Open **Signal History** in the admin sidebar, or visit `/admin/signals/history`. After the `signal_deliveries` migration is applied, the email service automatically saves a record before each actual delivery attempt. This includes scheduled checks, manual sends and `--force` sends. VS Code does not need to be open: the Windows task starts PHP independently and writes to the same database.

Each record belongs to one recipient and one attempt. A retry or forced resend creates another record. The saved snapshot includes the strategy, market, direction, signal time, recipient, full signal payload and trade plan, including entry, stop loss and targets when available. Changing a strategy or generating a later signal does not replace the earlier snapshot.

Delivery states mean:

- **Pending:** the attempt was recorded and its final outcome has not been confirmed. An interrupted process can leave this state; the message may already have reached the mail server.
- **Sent:** the configured mailer accepted the message without an error. With SMTP this means mail-server acceptance, not confirmation that it reached the inbox or was read. A development `log` mailer writes an email locally instead of delivering it.
- **Failed:** the mailer reported an error. A later eligible retry has its own record.

Unchanged snapshots, expired signals, excluded actions, `NO_DATA` and other checks that do not attempt email delivery do not create history records. The dashboard's current signal and the latest-run summary are not delivery-history entries.

The history page lists the newest attempts first, with 25 records per page. Filter by strategy, market, action, status, recipient or an inclusive date range in Asia/Taipei time. Expand a record to inspect its original message, timestamps, expiry, complete signal JSON, mailer, error and forced-send flag. **Export CSV** downloads all records matching the current filters, including records beyond the displayed page. The export includes the full payload and entry, stop loss and target fields.

History starts when this feature is installed. Older messages from inboxes or the limited historical email copies in `storage/logs/laravel.log` are not imported automatically. The service stores records in the MySQL `signal_deliveries` table; include this table in database backups to preserve the history. Clearing the signature cache or replacing the latest-run log does not clear these records.

## Commands

Check and send current entry signals to this admin only:

```powershell
php artisan signals:email-admin --to=romark7bayan@gmail.com
```

Omitting `--to` uses `ADMIN_SIGNAL_EMAILS`, or admin users when that setting is empty. `--force` resends unchanged, unexpired signals. It still respects the action filter.

## Windows task on the email machine

The email scheduler runs on a different machine. The following name and paths are previously documented settings; verify them in Task Scheduler on that machine. They are not installed or verified by this checkout.

- Task: `BoxTheory Admin Signal Emails`
- Interval: every minute, without concurrent instances
- Recipient override: `romark7bayan@gmail.com`
- Working directory: `C:\BOX THEORY\BoxTheory`
- PHP: `C:\xampp\php\php.exe`
- Latest run output: `storage/logs/admin-signals-last-run.log` (overwritten each run; use Signal History for retained delivery records)

The task runs hidden while the Windows user is signed in. Its action uses `wscript.exe //B //Nologo` with the local `storage/app/windows/admin-signals-hidden.js` launcher. The launcher starts the original PowerShell command with window style `0`, waits for completion, and returns its exit code. This prevents the brief console flash that occurred when Task Scheduler launched PowerShell directly with `-WindowStyle Hidden`. The command, recipient, output log, schedule, and Windows account are preserved.

The launcher is workstation-specific and lives in ignored storage. The original task XML is backed up alongside it as `admin-signals-task-before-hidden-<timestamp>.xml`. Keep the launcher when maintaining this workstation's storage; a fresh checkout does not include it.

The computer must be awake, connected to the internet, and the XAMPP MySQL database must be running. Start MySQL in XAMPP after restarting the computer. This task runs `signals:email-admin` directly; it does not invoke the auto trader.

Check Windows task status:

```powershell
Get-ScheduledTask -TaskName 'BoxTheory Admin Signal Emails'
Get-ScheduledTaskInfo -TaskName 'BoxTheory Admin Signal Emails'
Get-Content -LiteralPath storage/logs/admin-signals-last-run.log
```

`LastTaskResult = 0` means the command completed successfully. A successful run can send zero messages when strategies have no new BUY/SELL setup. A successful SMTP send means the mail server accepted the message; inbox placement is controlled by the receiving provider.

Laravel's existing `schedule:run` configuration also schedules admin email checks. Use one scheduling arrangement when deploying. The Windows task is specific to this workstation and is not installed by checking out the repository elsewhere.

## Strategy availability

Box Theory Pro and Conservative V2 Final retain their existing signal rules. XAUUSD RSI Gold Scalper aliases now default to the **V2 trend-plus-session paper research model**, with the original RSI evaluator retained for comparison and the `RSI_GOLD_SCALPER_VERSION=legacy` rollback. V2 keeps SMA9/21 and RSI14, adds symmetric SMA200 direction/slope qualification, restricts new entries to 07:00-17:00 UTC weekdays, and uses full TP1=1.5R only. Alerts/screens identify paper research; live profitability is unverified. Other currency pairs keep the original RSI rules. Read [the update and validation guide](RSI_SCALPER_UPDATE.md).

Gold Risk Breakout (Research), slug `gold-risk-breakout`, is implemented separately and its catalog migration is now applied locally.

Set `GOLD_SIGNAL_SOURCE=twelvedata` to let Gold Risk Breakout use `TWELVEDATA_API_KEY` before MT5 is set up. The default `GOLD_SIGNAL_SOURCE=mt5` selects broker candles instead and requires the bridge URL/token. The source appears in the email's indicators. Broker execution continues to require an MT5 snapshot regardless of the email source. MT5 signal emails now also require the actual bid/ask tick timestamp; the snapshot creation time cannot substitute for a fresh tick. Update/restart the bridge if using that source.

The API mode retains up to 300 completed trading-session XAU/USD bars for both M15 and H1 from Twelve Data, requesting additional lookback to remove closed-session history. It caches each timeframe until its next close. If a new bar is delayed, it retries after 30 seconds. It does not substitute Yahoo futures or mix providers. The strategy's original three-minute entry window remains in effect: checks between entry windows return `NO_DATA` with a message to wait for a fresh M15 close, and send no email. Missing, incomplete, or delayed data cannot create an entry alert. The separate current-quote check applies to all strategies.

Gold Trend Retest V2 (`gold-trend-retest-v2`) is a separate paper research candidate using 320 completed bars per timeframe. It repairs the earlier candidate's indefinitely retained setup and inconsistent indicator history. Its inspected primary historical cost scenario returned 3 wins, 6 losses and -0.20454R; a profitable improvement has not been established. V2 email alerts default off, including forced runs and pre-existing catalog rows. See [the comparison](research/retest-v2-2026-11-02/README.md) and [the V2 update and optional paper registration](GOLD_RETEST_V2_UPDATE.md).

Read the current decision without sending an email, placing an order or registering a catalog row:

```powershell
php artisan signals:current gold-trend-retest-v2
```

The output distinguishes a fresh provider price from an eligible strategy entry. It can show a verified current quote while withholding an expired/unverified plan. Verified V2 entries are labelled `RESEARCH_SETUP` with `paper_setup_available=true` and `can_enter=false`. `WAIT`, `NO_VERIFIED_SETUP`, and `MARKET_CLOSED` expose no entry plan. Quote age, UTC and Manila check times, and entry expiry are explicit. The command does not continuously stream prices or deliver notifications itself.

If PHP reports a certificate verification error, configure `TWELVEDATA_CA_BUNDLE` to a trusted readable CA bundle on that PC (or repair its normal PHP CA configuration). Verification is never disabled. `TWELVEDATA_PROXY` optionally specifies an explicit proxy; its empty string setting means direct access. Leave it unset to retain normal HTTP client proxy behavior. Clear Laravel configuration after changing these environment settings. Transport errors are sanitized to avoid leaking the API key.

Twelve Data documents that intraday `datetime`/`timestamp` identify an interval opening and `last_quote_at` identifies the latest minute observation; daily intervals ignore the `timezone` parameter. [Official API documentation](https://twelvedata.com/docs#quote).

Alpha Vantage's existing key was tested and returned a premium-access requirement for `FX_INTRADAY`, with no candles. Its documented gold history endpoint provides daily, weekly, and monthly prices rather than the M15/H1 OHLC series this strategy requires. [Alpha Vantage documentation](https://www.alphavantage.co/documentation/#fx-intraday). Twelve Data supports the required intraday intervals and UTC bar-opening timestamps. [Twelve Data documentation](https://twelvedata.com/docs#time-series).

The new strategy uses an H1 trend filter and a confirmed M15 breakout, with a structural/ATR stop and a 2R target. Its performance remains unverified; see [the strategy implementation notes](XAUUSD_RESEARCH.md).
