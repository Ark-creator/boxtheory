# Deploying GoldLogic with VT Markets MT5

The implementation is ready for configuration and demo validation. It has not been connected to your broker or deployed to a VPS. Preserve the existing `.env`; do not copy secrets into Git or chat.

For dashboard and email signals before configuring MT5, set `GOLD_SIGNAL_SOURCE=twelvedata` and use the existing Twelve Data key. See [admin signal email setup](ADMIN_SIGNAL_EMAILS.md). The local workstation uses this mode. To compare emails against broker execution, select `GOLD_SIGNAL_SOURCE=mt5`; broker execution always evaluates an MT5 snapshot and does not use the external API email feed.

## Architecture

```text
Laravel scheduler ──> Gold Risk Breakout ──> existing signal emails
        │                    │
        │                dry-run intents
        │                    │
        └── private authenticated HTTP ──> Windows Python bridge
                                               │
                                      VT Markets MT5 terminal
                                               │
                                      broker order with SL/TP
```

Laravel polls every minute. Broker SL and TP handle exits even if Laravel or the bridge goes offline. Execution is disabled in the bridge by default, and non-demo accounts have a separate lock. Dry run writes entry intents; it does not simulate positions, calculate a trading track record or call the order endpoint. It uses the configured signal source; bridge execution requires broker data.

### Entry-extension cap update

The strategy now skips entries more than `GOLD_MAX_ENTRY_EXTENSION_ATR=0.75` times the **previous candle's ATR** beyond the broken channel. This default works without editing the existing `.env`. To change it, set a positive finite value in Laravel's environment and rebuild its config cache during deployment. The same configuration is injected into live signals and `gold:backtest`; reports record it under `parameters.max_entry_extension_atr`. The `entry_extension_rejections` report counter records eligible signals rejected because their modeled execution price exceeded the bound.

Deploy the Laravel and Python changes together and restart the bridge. Updated snapshots advertise `entry_extension_limit`; Laravel refuses older bridges that cannot enforce it, and the updated bridge rejects trade payloads missing `entry_limit_price`. The bound is an upper buy price or lower sell price checked before market submission, not a guaranteed fill price. SL/TP, risk sizing and the existing email entry/stop/target fields are preserved. This update does not require a new database migration and does not enable execution.

## Laravel deployment

1. Back up the application database and configuration, then deploy the files and install the existing Composer/npm dependencies using your normal release process. Run `php artisan migrate --force` in the release environment. The additive migration registers the new strategy without changing existing trading settings. Do not rerun the general database seeders on production just to add this strategy.
2. Set the normal Laravel production settings, working email transport, HTTPS, and a persistent shared cache if you run more than one web worker. Preserve `APP_KEY` because it encrypts the bridge token. Use a single scheduler; its execution lock and bridge journal provide additional protection.
3. On Linux, run the scheduler each minute:

   ```cron
   * * * * * cd /path/to/BoxTheory && php artisan schedule:run >> /path/to/BoxTheory/storage/logs/scheduler.log 2>&1
   ```

   On Windows, use Task Scheduler for `php.exe artisan schedule:run` with the working directory set to the application. Arrange log retention. Existing scheduled email alerts continue.
4. In **Admin → Auto Trader**, select **Gold Risk Breakout (Research)**, XAUUSD, your exact Market Watch symbol, risk 0.25%, max entries 3, max open 1, cooldown 30, and **Dry run**. Existing saved settings and existing `.env` values take precedence over new defaults; review them explicitly.
5. Set the bridge URL ending in `/trade` and its shared token. Use `http://127.0.0.1:8710/trade` on the same host, or an encrypted private network/reverse proxy to the Windows host. Never expose port 8710 as an unauthenticated/public plain-HTTP service. Restrict firewall access to the application host. A token alone does not encrypt traffic.

## Windows execution host

Install VT Markets MT5 and a supported **64-bit Windows Python** with the MetaTrader5 package. Log MT5 into the intended demo account. Keep the terminal and bridge in the same Windows user session; test that remote desktop disconnection and reboot recovery preserve their operation.

```powershell
py -m pip install MetaTrader5
```

Configure these environment variables for the bridge process (actual values belong only on the VPS):

| Variable | Value / purpose |
|---|---|
| `MT5_BRIDGE_TOKEN` | Random secret, at least 32 characters; same token as Laravel |
| `MT5_LOGIN` | Exact demo account number; required even when MT5 is already logged in |
| `MT5_SERVER` | Exact server name displayed in MT5; do not guess it |
| `MT5_TERMINAL_PATH` | Path to your VT Markets `terminal64.exe`, recommended with multiple installations |
| `MT5_PASSWORD` | Optional if credentials are already saved in that terminal |
| `MT5_SYMBOL` | Exact gold symbol from Market Watch; default XAUUSD is only a placeholder |
| `MT5_MAGIC` | Default 240523; must match Laravel `AUTOTRADER_MAGIC` |
| `MT5_COMMISSION_PER_LOT` | Required round-trip commission for one lot **in account currency units**; explicitly set 0 only for a confirmed commission-free account |
| `MT5_ENABLE_EXECUTION` | `false` initially; `true` allows requests to reach order checks and submission |
| `MT5_ALLOW_LIVE` | `false`; demo is the only permitted account mode by default |
| `MT5_BRIDGE_HOST` | Default 127.0.0.1; bind a private interface only when needed |
| `MT5_BRIDGE_PORT` | Default 8710 |
| `MT5_NEWS_URL` | Optional HTTPS endpoint returning the documented calendar schema; refreshed on entry attempts at most every five minutes |
| `MT5_NEWS_TOKEN` | Optional bearer token for that calendar endpoint |
| `MT5_NEWS_PATH` | Absolute path to the maintained news JSON; default `mt5_bridge/news.json` |
| `MT5_STATE_PATH` | Durable journal path; default `mt5_bridge/bridge-state.sqlite` |

The old guessed `XAUUST.STD` default was removed. Neither suffix nor account-currency denomination should be inferred from the word “cent”. Confirm the actual symbol properties in your terminal.

Run from the project directory:

```powershell
py mt5_bridge/mt5_bridge.py
```

Configure Task Scheduler to start it in the same signed-in user session as MT5, restart on failure, and use the same durable state path every time. Keep **one execution bridge per account**. Back up the SQLite journal and the Laravel database together. Deleting the journal removes duplicate protection and the equity high-water history.

Authenticated endpoints:

- `GET /health`: actual terminal connection, account fingerprint, demo mode and execution setting.
- `GET /snapshot`: broker bars, account positions/pending orders and current risk-block reason.
- `GET /orders/{uuid}`: durable submission outcome for reconciliation.
- `POST /trade`: guarded execution; used only by Laravel bridge mode.

After dry-run inspection and historical diagnostics, switch to **bridge mode on demo**, set `MT5_ENABLE_EXECUTION=true`, and enable MT5's algorithmic trading plus external Python API trading permission. No startup, health check, snapshot or data export places trades. The Laravel Enabled switch and bridge execution flag must both allow new orders.

## News calendar

Configure `MT5_NEWS_URL` for automatic HTTPS refresh of a calendar endpoint using the schema below, or maintain the local calendar file atomically. No provider is bundled or configured; an existing provider may need an adapter into this schema. With a URL configured, failed refreshes pause entry rather than falling back to an older file. Redirects are refused to protect feed credentials. The source is refreshed on entry attempts at most every five minutes; data must still have been updated within 24 hours. Missing, malformed or incomplete coverage blocks entry. A reliable calendar source is required for unattended multi-day execution.

Schema example below uses deliberately expired dates, so it cannot accidentally authorize current trading:

```json
{
  "updated_at": "2020-01-01T00:00:00Z",
  "coverage_start": "2020-01-01T00:00:00Z",
  "coverage_end": "2020-01-02T00:00:00Z",
  "events": [
    {"time": "2020-01-01T13:30:00Z", "currency": "USD", "impact": "high", "title": "EXAMPLE ONLY"}
  ]
}
```

Coverage must include the full interval from 30 minutes before to 30 minutes after the current UTC time, including a genuinely reviewed empty event list on event-free days. Do not merely replace the timestamps to bypass the filter.

## Historical diagnostics

On the Windows terminal host, export broker data (read-only):

```powershell
py mt5_bridge/export_history.py --start 2022-01-01 --end 2026-09-01 --output gold-history.json
```

The export refuses to overwrite an existing file. Verify its first/last dates and gaps: MT5 history availability and “Max bars in chart” can truncate the requested period. Transfer the file privately to the Laravel host, then run:

```text
php artisan gold:backtest gold-history.json --spread=0.30 --slippage=0.10 --fee-per-unit=0.07
```

Numbers above are **examples**, not observed VT Markets costs. Costs are gold price units per ounce; `fee-per-unit` is round-trip account USD commission divided by ounces per lot for a USD account. Verify conversion for other account denominations. Slippage is applied separately to entry and exit. This command sends neither orders nor emails.

The JSON report includes net R expectancy, win rate, profit factor, closed-trade R drawdown, annual results, individual trades, ambiguous bars and any unclosed position. It is a signal diagnostic with simplified costs, not a complete execution simulation. See the research document for the omitted effects and live-readiness gates.

## Recovery and operation

After a network timeout, Laravel marks the submission `unknown` and blocks further trading. Before a later run it asks the bridge for the **same UUID**. If the journal proves execution or rejection, Laravel updates the record. An order that remains unknown or partial continues to block entry. No automatic resubmission occurs.

If the process died after reserving the UUID but before recording the broker result, inspect MT5 orders/deals, the request UUID/comment (`GL:` plus the first 24 UUID hex characters), and the actual position. The journal deliberately cannot infer whether a missing response means no fill. An operator must reconcile both stores from broker evidence before clearing the halt. Do not delete the row or journal and retry blindly.

Stop new entries by disabling Auto Trader or setting `MT5_ENABLE_EXECUTION=false` and restarting the bridge. Existing broker stops and targets remain active. This version does not provide forced liquidation, trailing exits or automatic drawdown resets. Use MT5 itself to manage an existing position when needed.

Monitor scheduler logs, bridge process uptime, clock synchronization, stale-data messages, news coverage, spread rejections, actual SL/TP levels and account equity. The admin order history records the bridge result and actual filled volume when confirmed. The preview reads actual broker positions in bridge mode; an unavailable snapshot shows unknown position context.

Run software tests without an MT5 account:

```text
php artisan test --filter=Gold
py -m unittest discover -s mt5_bridge -p "test_*.py" -v
```

None of this setup enables or proves profitable live trading. Account-specific configuration, historical execution testing and a demo forward test remain required.
