# Install Gold Trend Retest V2 on the email PC

This is a code update for the PC that runs your existing email scheduler. It adds the V2 research strategy and a safe current-signal command. It also includes the stale-data fix. It preserves `.env`, API/SMTP credentials, existing strategy/history records, task settings, and the MT5 execution allowlist. The installer does not send emails, place orders, register tasks, run migrations, or activate the new strategy automatically.

**V2 remains a disabled research candidate and is not proven better.** In the primary cost scenario it produced **3 wins and 6 losses across 9 closed trades, net -0.20454R**. This is a conditional historical simulation, not executed account results; clock and cost assumptions still need broker verification. The small inspected sample was unprofitable and is not a reliable return forecast. V2 fixes candidate-history and indicator-seeding behavior so live and batch evaluation can agree; that engineering improvement is not evidence of a trading edge. Do not use this package as a recommendation to activate real trading.

1. On the email PC, pause the existing **BoxTheory Admin Signal Emails** task and stop any manually running `schedule:work` instance for this app.
2. Extract the ZIP into a separate folder. Keep its installer, `manifest.json`, and `payload` together.
3. Open PowerShell in that extracted folder. Replace the app path below with the actual Laravel folder containing `artisan` and `composer.json`:

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Apply-GoldRetestV2Update.ps1 -AppRoot 'C:\BOX THEORY\BoxTheory' -PhpPath 'C:\xampp\php\php.exe'
```

The installer accepts the expected original source, the preserved 2 November freshness fix, or this V2 update already installed. It checks every target before changing code. Unknown local edits stop installation for review instead of being overwritten. Windows/Unix line endings are accepted.

Original changed files are backed up under `storage\app\gold-retest-v2-backups\<date-and-id>\original`, with an `install-state.json` record. Repeat installation skips matching updated files. The earlier freshness ZIP is preserved separately; it does not need to be installed first.

The installer clears configuration/view caches, then runs only **`signals:check-data`**. Exit **0** means that safe check passed, including an expected closed-market block. Exit **3** means the update is installed but the data check reports unhealthy data; the update stays installed. Exit **1** means preflight/installation failed; read the message before resuming the task. No task is started by the installer.

4. From the application's folder, check the current V2 setup without sending anything:

```powershell
& 'C:\xampp\php\php.exe' artisan signals:current gold-trend-retest-v2
```

A current verified V2 BUY/SELL is labelled **RESEARCH_SETUP** and includes the quote, entry, stop, target, expiry and Philippine time. It is a paper setup; automatic entry remains disabled. **WAIT/NO_VERIFIED_SETUP/MARKET_CLOSED means there is no verified entry now.** Missing credentials, an old quote, incomplete/delayed candles, market closure or an expired setup must block an actionable entry; old CSV prices are not substituted for current prices.

A verified current price can appear even when no entry setup qualifies. That quote is not a recommended entry. If `source_quote` is null or `source_quote_error` is reported, the command has not verified a live price; use the reported reason rather than a historical candle or CSV price.

API evaluation uses the same Twelve Data XAU/USD feed for 320 closed M15 and H1 candles, together with a fresh quote. The PC keeps its own API key in its existing `.env`. A quote must be within 90 seconds and a setup within its three-minute entry window. New York market hours follow daylight-saving changes. Cached signals are rechecked on every read.

If the check reports an HTTPS certificate error, configure `TWELVEDATA_CA_BUNDLE` with a readable **trusted CA certificate bundle on that PC**. An existing XAMPP installation may provide `C:/xampp/apache/bin/curl-ca-bundle.crt`; verify the file before selecting it. TLS verification remains enabled. `TWELVEDATA_PROXY` can specify an approved proxy; explicitly setting `TWELVEDATA_PROXY=""` disables an inherited proxy when direct access is intended. Leave working connection settings alone, and run `artisan config:clear` after any deliberate `.env` adjustment. The package neither includes a CA bundle nor copies diagnostic proxy settings from this machine.

V2 paper emails default to **off**, even if a V2 catalog row already exists or a forced-send option is used. The installer does not change this flag or register the strategy. After verifying the stale-data checks, resume the existing email task for its existing strategies; do not create a duplicate task or use a forced-send command to test installation.

**Optional paper-alert experiment only:** if you deliberately want V2 research emails despite the negative sample result, add `RETEST_V2_EMAILS_ENABLED=true` to this PC's existing `.env`, then run these commands manually from the application folder:

```powershell
& 'C:\xampp\php\php.exe' artisan config:clear
& 'C:\xampp\php\php.exe' artisan signals:register-retest-v2 --paper-alerts
```

The registration command requires that explicit flag, creates the research catalog row only if missing, and sends no email or order itself. The existing scheduler can then consider future eligible V2 paper alerts. To stop V2 paper emails, set `RETEST_V2_EMAILS_ENABLED=false` and clear the configuration cache again. These opt-in commands are never run by the installer.

If this deployment already uses an MT5 bridge in another folder, copy the updated `mt5_bridge\terminal.py` there and restart that existing bridge only after reviewing the update. Preserve its environment. V2 is not added to the broker order allowlist by this package.

This patch assumes the application's existing dependencies and signal-delivery history migration are already installed. It does not migrate the database or install packages. Reported current signals and broker execution remain separate: receiving a setup is not confirmation of a broker fill.
