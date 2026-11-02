# Run RSI Gold Scalper V2 through the local MT5 bridge

The app can read the broker's completed M5 candles and current bid/ask, then submit eligible RSI Gold Scalper V2 orders through the authenticated Windows MT5 bridge. **RSI execution is DEMO ONLY.** Both Laravel and the bridge reject real or unverified accounts. This is an experimental strategy, with no guarantee of profitable trades.

There are two launchers: `start-demo.ps1` runs the app's demo execution loop; the existing `start-readonly.ps1` still provides market data with order submission disabled. The standalone native MT5 EA is a separate controller. **Keep that EA and other automatic controllers off this demo account while the app controls it.** It does not send its trades or tester results back to Laravel.

Run Laravel, the bridge and MT5 on this laptop in the same signed-in Windows account/session. The user connected **MetaQuotes-Demo** and enabled algorithmic trading; the local demo connection is now verified. A demo controller was started on 2026-11-02, as recorded below. **Check the existing controller before starting another copy.** Keep passwords, account details and bridge tokens local.

## Verified runtime observation

These are dated observations, not a continuing claim about orders or connectivity:

| Observation on 2026-11-02 | Result |
|---|---|
| Controller started at 15:26:52 UTC / 23:26:52 Manila | Hidden controller PID 50648; local bridge listening on loopback port 8710 |
| Actual `mt5:check` | Connected demo; `data_ready=true`; quote age 1 second; latest M5 close 15:25 UTC; 4,096 M5 bars received |
| First app poll at 15:26:59 UTC / 23:26:59 Manila | HOLD; no actual demo order had been submitted at that observation |

The verified configuration enabled app bridge execution for RSI V2 using the MT5 feed, 0.25% risk, three maximum daily entries, one open position and a 30-minute cooldown. The connected account had no position or pending order, and no standalone scalper EA was found in its saved chart profiles at startup. The app's connection environment and saved Auto Trader settings were updated by the verified configuration command, with private backups. No email task or Windows scheduled task was started.

The earlier IPC timeout `-10005` cleared after the terminal was connected. This does not establish the cause of every MT5 IPC timeout. A separate two-hour source-label discrepancy was resolved for the current feed through the explicit clock policy described below. Successful connectivity and HOLD are not a profitability test or a native MT5 backtest.

## Start demo auto trading on this laptop

1. Open the intended MT5 terminal, log into the intended **demo** account, and confirm it is connected. Check the exact gold Market Watch symbol and current bid/ask. Enable MT5 algorithmic trading and the permission for external Python API trading when you want demo orders to be submitted.
2. Open ordinary PowerShell as the same Windows user/session that runs MT5. From the project folder, run:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\mt5_bridge\start-demo.ps1 -PhpPath C:\php\php.exe
   ```

   `-ExecutionPolicy Bypass` applies only to this child PowerShell process; it does not change the laptop's saved execution policy. The PHP path above is the local app's executable. Optional parameters include `-PythonPath`, `-PhpPath`, `-EnvFile` and `-CommissionPerLot`. Python defaults to `mt5_bridge/.venv/Scripts/python.exe`, and the private bridge environment defaults to `mt5_bridge/.env`. Supply the commission reserve in account-currency units per lot; see its assumption below.

   The bootstrap probes the already open terminal and requires a connected demo account, no existing positions or pending orders, and a compatible terminal profile. It saves the pinned account/symbol and private token with demo-only execution flags. The launcher starts its bridge in a hidden helper process, then runs `php artisan mt5:configure-demo`. That command independently verifies an actual demo snapshot before configuring the app for the MT5 feed, `trend-session-v2`, canonical strategy `rsi-gold-scalper-v2`, enabled bridge execution, 0.25% risk, at most three entries per day, one position and a 30-minute cooldown.

   It polls **only** `php artisan autotrader:tick` once per minute. It does not run `schedule:work`, `schedule:run` or an email command and creates no Windows scheduled task. Existing email scheduling remains a separate process.
3. Keep MT5, the launcher and the laptop running and awake. Inspect **Admin > Auto Trader**, the launcher output, MT5's Journal and the trade audit log. WAIT or a rejected setup can be the correct result; starting the launcher does not guarantee an order. Entry decisions use completed M5 candles, even though the app checks once per minute.
4. For the hidden controller, request a graceful stop from the same Windows session:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\mt5_bridge\stop-demo.ps1 -PhpPath C:\php\php.exe
   ```

   This disables new RSI bridge entries and signals the local controller to clean up its own bridge after any current command finishes. For a foreground launcher, **Ctrl+C** also invokes cleanup. You can turn **Enabled** off in Admin > Auto Trader. Check for any other scheduler or execution process before restarting. Existing broker SL/TP orders remain active; stopping the app does not close an open position.

If a later probe reports IPC timeout, stop rather than assuming the connection is ready. Check the intended terminal is connected, the executable path and saved login are correct, and Python/MT5 are running in the same Windows account/session. Review MT5's permissions and Journal; an intentional terminal restart may be needed after preserving any open-position context. The successful connection recorded above does not guarantee a future reconnect.

The launcher updates the app's MT5 source/connection values and Auto Trader settings after verification, while preserving `APP_KEY`, email credentials and unrelated configuration. Private backups are retained locally. Do not copy a sample `.env` over your app's `.env`, send credentials in chat, or commit them. The bridge's required round-trip commission reserve is in **account-currency units per lot**. If no reserve is configured and the account currency is USD, bootstrap uses an **assumed USD 7 per lot** reserve; this is a conservative sizing assumption, not a verified broker fee or a fee charged by this setting. Other account currencies require a configured account-currency reserve. Use `-CommissionPerLot` to override the reserve, or set `MT5_COMMISSION_PER_LOT` privately. Replace the assumption with the confirmed broker amount when known; an explicit zero should be used only when appropriate.

## What the demo controller executes

RSI V2 requires the MT5 feed and the combined trend/session version. It uses the original strategy stop and **one full-position TP1 at 1.5R**. It does not send the legacy momentum `CLOSE`, take partial profits, move to breakeven or trail the stop. There is no RSI news-calendar gate. Gold Risk Breakout remains a separate supported strategy and retains its required current calendar and news blackout.

New RSI entries require fresh verified MT5 data, a quote no older than 90 seconds, an unexpired signal ending 180 seconds after the source M5 close, and an executable quote within 0.1R of the original entry and inside its SL/TP1 bracket. The entry window is UTC weekdays 07:00 to before 17:00, or Manila 15:00 to before 01:00 the next day. Closed markets, missing data and stale or changed quotes can block submission.

The execution bridge applies operational limits in addition to the strategy:

- At most 0.5% requested equity risk per trade; the launcher selects 0.25%. Sizing reserves 10% of the risk budget for execution costs, includes the configured commission estimate and rounds volume down. An unaffordable minimum lot is rejected.
- Account-wide 1% daily loss, 3% weekly loss and 5% drawdown checks, including floating losses and the proposed stop risk. The drawdown high-water mark is kept in the durable bridge journal; this is a block on new entries, not a guaranteed realized-loss cap.
- At most three entries per day account-wide and at least 30 minutes after the latest account trade. Any account position or pending order blocks a new entry. Required margin must not exceed half the available free margin.
- Original broker SL/full TP1, legal tick and stop distances, order checks, durable duplicate protection and reconciliation of uncertain outcomes. Gaps, commissions, swaps and actual fills can still produce larger losses.

These account limits, sizing reserves and broker checks differ from the historical research simulation. Research trade counts and returns are not a forecast of this controller's demo performance. Keep one execution bridge per account and retain its journal; do not delete it to bypass duplicate or loss blocks.

## Explicit source-clock policy

MetaQuotes documents [UTC timestamps for Python historical rates](https://www.mql5.com/en/docs/python_metatrader5/mt5copyratesfrom_py). The bridge therefore defaults to `MT5_TIME_MODE=utc`, with no offset correction. On this selected MetaQuotes-Demo feed, actual raw tick and bar labels were observed approximately two hours ahead of receipt time. The configured exception is:

```dotenv
MT5_TIME_MODE=server_wall
MT5_BROKER_WINTER_UTC_MINUTES=120
MT5_BROKER_SUMMER_UTC_MINUTES=180
MT5_BROKER_DST_RULE=eu
```

Bootstrap accepts this named EET/EU convention automatically only for the exact MetaQuotes company/server identity, after observing two genuinely advancing source-time and bid/ask updates that normalize to fresh quotes. Other feeds require an explicit verified profile if the documented UTC mode fails. The code does not fit an arbitrary offset from the current time or replace a source quote time with the local receipt time. Explicit profile settings are also checked against advancing quotes.

The current offset is supported by observation. **The broker's historical DST rule is not certified**: metadata retains `HISTORICAL_DSTRULE_NOT_CERTIFIED`. The EU summer convention is configured, not established by observing one winter date. This does not confirm the old CSV's clock assumption or relabel any earlier research dataset. `server_wall` supports fixed, US and EU conventions for years 2007-2099 and rejects ambiguous or nonexistent local times.

The policy normalizes tick, candle and deal timestamps and encodes historical query bounds; deal times must use the same policy for account risk checks. Snapshots preserve raw tick/bar epochs and clock provenance, while `observed_at` remains actual UTC receipt time. Freshness checks still apply after conversion. Do not copy the native EA's clock inputs into Python configuration without verifying this feed's clock separately.

For any [historical export](../mt5_bridge/export_history.py), provide the **same configured clock environment** as the bridge. The exporter reads process environment, not the private `.env` automatically. It records the source clock profile and history-status label, retains `raw_time`, normalizes each date using the selected historical rule and filters records against the requested UTC interval. Output files are created exclusively, so prior exports are not overwritten. Historical-rule uncertainty must accompany any long-period export or return analysis; current quote freshness cannot certify past timestamps.

## Market-data-only setup

Use the steps below when you want dashboard/command/email market data without enabling the app's order loop. The MT5 source applies to XAUUSD RSI strategy signals; generic price widgets and other strategies retain their configured sources.

### Windows MT5 host

1. Open the intended MT5 terminal and log into the account you want to use as the source. Read the exact server name and exact gold symbol from MT5; the earlier screenshot shows `XAUUSD-STD`, but verify it on the machine/account you will use. Market-data reads do not require enabling automated trading.
2. Install a supported 64-bit Python from [Python.org](https://www.python.org/downloads/windows/) if there is no working interpreter. A WindowsApps alias alone is not a Python installation. In the project folder, create an isolated bridge environment and install MetaTrader's official package:

   ```powershell
   py -m venv mt5_bridge/.venv
   & ./mt5_bridge/.venv/Scripts/python.exe -m pip install -r ./mt5_bridge/requirements.txt
   if (!(Test-Path -LiteralPath mt5_bridge/.env)) {
       Copy-Item -LiteralPath mt5_bridge/.env.example -Destination mt5_bridge/.env
   }
   ```

   If your installation does not supply `py`, use its full `python.exe` path for the first command. The requirements file pins the validated MetaTrader5 and NumPy versions. Create the environment file once; preserve an existing one. The bridge environment and its credentials are ignored by Git.
3. Edit `mt5_bridge/.env` locally:

   | Setting | Required value |
   |---|---|
   | `MT5_LOGIN` | Exact account number to pin the connection |
   | `MT5_SERVER` | Exact account server name |
   | `MT5_TERMINAL_PATH` | Full path to the intended `terminal64.exe` |
   | `MT5_SYMBOL` | Exact gold Market Watch symbol |
   | `MT5_BRIDGE_TOKEN` | A random secret of at least 32 characters, also used by Laravel |
   | `MT5_PASSWORD` | Optional when the intended login is saved in that terminal |

   To generate a token privately in your own PowerShell window:

   ```powershell
   $bridgeRandom = New-Object byte[] 32
   $bridgeRng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
   $bridgeRng.GetBytes($bridgeRandom)
   $bridgeRng.Dispose()
   [Convert]::ToBase64String($bridgeRandom)
   ```

4. Start the data bridge in PowerShell:

   ```powershell
   powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\mt5_bridge\start-readonly.ps1
   ```

   The launcher loads only plain `MT5_...` settings from `mt5_bridge/.env` and forces `MT5_ENABLE_EXECUTION=false` and `MT5_ALLOW_LIVE=false`. It binds locally; the default port is 8710. Keep the process and MT5 running. Stop it with Ctrl+C. After manual verification, it can be launched by Task Scheduler in the same signed-in Windows session as MT5. This change does not create or modify scheduled tasks.

### Laravel on that PC

Preserve your existing application `.env`, `APP_KEY` and email credentials. Add or update these values with the matching local values:

```dotenv
RSI_GOLD_SCALPER_SOURCE=mt5
RSI_GOLD_SCALPER_VERSION=trend-session-v2
AUTOTRADER_BRIDGE_URL=http://127.0.0.1:8710/trade
AUTOTRADER_BRIDGE_TOKEN=the-same-private-secret
AUTOTRADER_MT5_SYMBOL_CENT=XAUUSD-STD
AUTOTRADER_MT5_SYMBOL_DOLLAR=XAUUSD-STD
```

The symbol values above are examples. If **Admin > Auto Trader** already has a saved bridge URL, token or symbol, those saved values take precedence over the environment defaults. Set those connection fields to the matching URL/token/symbol, retaining the existing Enabled/Execution Mode settings; this feed does not need Auto Trader enabled. Do not change the mode to broker execution merely to receive prices.

Reload configuration and check the connection:

```powershell
php artisan config:clear
php artisan mt5:check
php artisan signals:current rsi-gold-scalper
```

`mt5:check` reports connection status, quote age, latest M5 close, available trading-session bars, market status and whether data are ready. It prints no account identifiers, balances or tokens and sends no orders or emails. Outside the regular session, a connected terminal can correctly report `data_ready=false`. The bridge snapshot also maintains its existing local risk-monitor journal; it does not submit an order.

`signals:current` reports the actual research setup or WAIT with UTC and Manila times. On an entry it must show `source_quote.source=mt5`; the strategy source label includes the actual broker symbol. Missing, stale or future data block the setup. If the bridge fails, RSI does not substitute an external API quote or replay prior signals. Quotes must be at most 90 seconds old, and an entry expires exactly 180 seconds after the source M5 close. The existing 07:00-17:00 UTC weekday entry window still applies.

Existing email scheduling evaluates the selected feed automatically after configuration. These diagnostic commands do not send a test email. Provider-aware cache checks prevent a prior Twelve Data RSI signal from remaining displayed after an MT5 source switch. Do not run another general scheduler just to use the demo launcher: the launcher already polls the auto trader and deliberately does not start email scheduling.

To return RSI signals to Twelve Data, first disable the demo auto trader, then set `RSI_GOLD_SCALPER_SOURCE=twelvedata` and reload Laravel configuration. Selecting the feed does not change strategy mathematics. API-sourced RSI signals cannot submit demo bridge orders.

## Different machines or Hostinger

`127.0.0.1` always means the machine making the request. An app hosted on Hostinger cannot reach an MT5 bridge on your laptop using that address. The one-command demo setup above runs locally; it does not deploy or enable execution on a Linux host. For remote market-data access, MT5 and its Python bridge still need a running Windows host and a secured private connection or authenticated HTTPS reverse proxy. Keep the token private and do not expose this local plain-HTTP port directly to the internet. See the [existing MT5 deployment guide](MT5_DEPLOYMENT.md) for the deployment architecture; remote execution would require its own explicit configuration.

For remote market-data access, retain the bridge's explicit clock metadata and raw timestamps. See the clock-policy section above and MetaQuotes' [current tick interface](https://www.mql5.com/en/docs/python_metatrader5/mt5symbolinfotick_py); do not silently apply another offset in the remote app.

## Validation

On 2026-11-02, the final relevant PHP regression run passed **301 tests / 2,409 assertions**. The expanded Python suite passed **72 tests**, including clock conversion, snapshot pipeline, historical export and bootstrap checks. The start, stop and read-only PowerShell launchers passed parser checks. Automated tests use mocked broker snapshots and broker API calls; they do not establish a profitable track record. Coverage includes genuine strategy calculations, stale/future quotes, insufficient or forming history, missing capabilities, source switches, disconnection, Sunday closure, RSI demo identity, source/version, fixed TP1, price drift and broker risk checks.

The pinned Python packages were installed in the project-local Python environment; system Python was not changed. Actual demo configuration and startup occurred after the user connected the terminal, as recorded above. Private backups contain connection details and must not be published. No email scheduler or Windows scheduled task was created by the controller.

Keep checking actual connection status, skipped setups, broker fills and account risk limits during demo operation. The dated successful check demonstrates connectivity at that time; it does not guarantee uptime, execution quality or profitability.
