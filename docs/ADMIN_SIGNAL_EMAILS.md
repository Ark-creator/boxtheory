# Admin entry signal emails

Admin alerts check every strategy in the database for the configured default market (`TRADING_DEFAULT_PAIR=XAUUSD`). `SIGNAL_ALERT_ACTIONS=BUY,SELL` sends entry setups. HOLD, WAIT and CLOSE are excluded with this setting.

Each email identifies the strategy, market and direction and includes entry, stop loss and take-profit prices. Signals with an explicit expiry show that deadline and are skipped after expiry, including when using `--force`.

Successful deliveries are remembered separately for each strategy, market and recipient. Unchanged snapshots are suppressed; a failure for one recipient does not stop the others. A later run retries a failed delivery if that signal is still current. Overlapping sends to the same recipient are locked. Errors produce a nonzero command exit code.

## Commands

Check and send current entry signals to this admin only:

```powershell
php artisan signals:email-admin --to=romark7bayan@gmail.com
```

Omitting `--to` uses `ADMIN_SIGNAL_EMAILS`, or admin users when that setting is empty. `--force` resends unchanged, unexpired signals. It still respects the action filter.

## Windows task configured on this workstation

- Task: `BoxTheory Admin Signal Emails`
- Interval: every minute, without concurrent instances
- Recipient override: `romark7bayan@gmail.com`
- Working directory: `C:\BOX THEORY\BoxTheory`
- PHP: `C:\xampp\php\php.exe`
- Latest run output: `storage/logs/admin-signals-last-run.log`

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

Box Theory Pro, RSI Gold Scalper and Conservative V2 Final retain their existing signal rules. Gold Risk Breakout (Research), slug `gold-risk-breakout`, is implemented separately and its catalog migration is now applied locally.

The local `.env` selects `GOLD_SIGNAL_SOURCE=twelvedata`, so Gold Risk Breakout can send dashboard/email signals using the existing `TWELVEDATA_API_KEY` before MT5 is set up. `GOLD_SIGNAL_SOURCE=mt5` selects broker candles instead and requires the bridge URL/token. The source appears in the email's indicators. Broker execution continues to require an MT5 snapshot regardless of the email source.

The API mode requests 300 XAU/USD bars for both M15 and H1 from Twelve Data, uses completed UTC bars, and caches each timeframe until its next close. If a new bar is delayed, it retries after 30 seconds. It does not substitute Yahoo futures or mix providers. The strategy's original three-minute entry window remains in effect: checks between entry windows return `NO_DATA` with a message to wait for a fresh M15 close, and send no email. Missing, incomplete, or delayed data cannot create an entry alert.

Alpha Vantage's existing key was tested and returned a premium-access requirement for `FX_INTRADAY`, with no candles. Its documented gold history endpoint provides daily, weekly, and monthly prices rather than the M15/H1 OHLC series this strategy requires. [Alpha Vantage documentation](https://www.alphavantage.co/documentation/#fx-intraday). Twelve Data supports the required intraday intervals and UTC bar-opening timestamps. [Twelve Data documentation](https://twelvedata.com/docs#time-series).

The new strategy uses an H1 trend filter and a confirmed M15 breakout, with a structural/ATR stop and a 2R target. Its performance remains unverified; see [the strategy implementation notes](XAUUSD_RESEARCH.md).
