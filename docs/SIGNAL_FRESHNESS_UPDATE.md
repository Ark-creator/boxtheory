# Install the fresh-signal fix on the other PC

This package updates the application code that blocks stale quotes, closed-market signals, and expired cached setups. It does not change your `.env`, API keys, SMTP settings, database, or existing Windows task. It does not send email, place orders, or start a scheduler.

1. On the PC that actually sends the emails, pause its existing **BoxTheory Admin Signal Emails** task in Task Scheduler. Close any manually running `schedule:work` process for this app. Keep the existing task; do not create a second one.
2. Extract the ZIP into a separate folder. Keep `Apply-SignalFreshnessFix.ps1`, `manifest.json`, and the `payload` folder together.
3. Open PowerShell in the extracted folder and run the following, replacing `AppRoot` with that PC's actual Laravel folder. That folder contains `artisan` and `composer.json`.

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\Apply-SignalFreshnessFix.ps1 -AppRoot 'C:\BOX THEORY\BoxTheory' -PhpPath 'C:\xampp\php\php.exe'
```

The installer checks all target files before changing any of them. It accepts the expected original version, this same update already installed, or a missing file that is new in this update. Different local edits stop the installation for review; they are not overwritten. Windows versus Unix line endings are accepted.

Changed original files are copied to `storage\app\signal-freshness-backups\<date-and-id>\original` inside the application. The adjacent `install-state.json` records which files were updated or created. Re-running the same package skips already updated code. No package file contains your keys or mail settings.

The installer then clears Laravel's configuration/view caches and runs **`signals:check-data`**, which checks data without sending alerts or orders. Exit code **0** means the check passed. Exit code **3** means the code update is installed, but market/data health is blocking signals; the update is kept. Exit code **1** means installation/preflight failed; read the message before resuming the task. If a copy failed after installation started, keep the task paused and use the printed backup/install-state path for review.

4. Read the data-check output. A closed market or stale/missing API data should explicitly block signals. If the check reports missing credentials, use the keys already configured on this PC; do not copy another PC's `.env`. Fix any reported configuration problem before expecting new alerts.
5. Resume the **existing** email task after verifying the check and its blocking behavior. The scheduler can keep checking while the market is closed; the new guards must prevent email entries until fresh valid data returns. Do not use a forced-send command as an installation test.

You can repeat the safe check from the application folder:

```powershell
& 'C:\xampp\php\php.exe' artisan signals:check-data
```

The fix requires a quote timestamp no older than **90 seconds**, closed candles, and a setup that has not passed its **three-minute** entry window. New York market hours follow daylight-saving changes. API-based XAU/USD signals use Twelve Data; Yahoo gold futures are not substituted for spot gold. Missing or delayed data blocks signals. Cached dashboard/watchlist snapshots are checked again when read, so an old BUY/SELL cannot stay actionable after expiry or market closure.

The package also updates `mt5_bridge\terminal.py` in the application folder. **Only if this deployment already uses the MT5 bridge:** copy that updated file to the bridge's actual installed folder if it runs elsewhere, then restart the existing bridge process after reviewing the change. Preserve its own environment/settings. Do not install, start, or enable a trading bridge merely to apply this email-data fix.

The package assumes the other app already has its existing signal-delivery history database migration and dependencies. It does not run migrations or install dependencies. An unexpected local source version needs review instead of a forced overwrite.

Validation: 194 focused runtime tests passed (675 assertions). The broader suite had 315 passes and two existing authentication redirect expectation failures. The portable installer was separately checked against disposable fixtures for backups, repeat installation, unknown local edits, corrupt payloads, unsafe paths, and unhealthy-data handling; no real app, email command, or order command was executed by those installer tests.
