"""Private Windows MT5 bridge. Running this module does not enable execution."""
from __future__ import annotations

import hashlib
import hmac
import json
import os
import threading
from datetime import datetime, timedelta, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from uuid import UUID
from urllib.parse import urlparse

from journal import Journal
from news import fetch_calendar
from risk import Rejected, entry_checks, news_check, number, volume_for_risk

try:
    import MetaTrader5 as mt5
except ImportError:
    mt5 = None

LOCK = threading.RLock()
SYMBOL = os.getenv("MT5_SYMBOL", "XAUUSD")
MAGIC = int(os.getenv("MT5_MAGIC", "240523"))
TOKEN = os.getenv("MT5_BRIDGE_TOKEN", "")
STATE_PATH = Path(os.getenv("MT5_STATE_PATH", str(Path(__file__).with_name("bridge-state.sqlite"))))
NEWS_PATH = os.getenv("MT5_NEWS_PATH", str(Path(__file__).with_name("news.json")))
JOURNAL = None


def journal():
    global JOURNAL
    if JOURNAL is None:
        JOURNAL = Journal(STATE_PATH)
    return JOURNAL


def now_utc():
    return datetime.now(timezone.utc)


def initialize_mt5():
    if mt5 is None:
        raise Rejected("Install the 64-bit Windows MetaTrader5 Python package first")
    login, server = os.getenv("MT5_LOGIN"), os.getenv("MT5_SERVER")
    if not login or not server:
        raise Rejected("Pin MT5_LOGIN and MT5_SERVER to the intended account")
    kwargs = {"login": int(login), "server": server, "timeout": 10000}
    if os.getenv("MT5_PASSWORD"):
        kwargs["password"] = os.environ["MT5_PASSWORD"]
    path = os.getenv("MT5_TERMINAL_PATH")
    ok = mt5.initialize(path, **kwargs) if path else mt5.initialize(**kwargs)
    if not ok:
        raise Rejected("MT5 terminal connection failed")
    account, terminal = mt5.account_info(), mt5.terminal_info()
    if account is None or terminal is None or not terminal.connected:
        raise Rejected("MT5 account or terminal is disconnected")
    if account.login != int(login) or account.server != server:
        raise Rejected("MT5 account does not match the pinned login and server")
    if not mt5.symbol_select(SYMBOL, True):
        raise Rejected("Configured gold symbol is not available in Market Watch")
    return account, terminal


def account_key(account):
    return hashlib.sha256(f"{account.login}:{account.server}".encode()).hexdigest()


def require_result(value, name):
    if value is None:
        raise Rejected(f"MT5 {name} unavailable; refusing to assume an empty result")
    return value


def bars(timeframe, count):
    rates = require_result(mt5.copy_rates_from_pos(SYMBOL, timeframe, 1, count), "candles")
    return [{"timestamp": datetime.fromtimestamp(int(row["time"]), timezone.utc).isoformat(),
             **{key: float(row[key]) for key in ("open", "high", "low", "close")}} for row in rates]


def snapshot():
    account, _ = initialize_mt5()
    tick = require_result(mt5.symbol_info_tick(SYMBOL), "current tick")
    positions = require_result(mt5.positions_get(), "positions")
    orders = require_result(mt5.orders_get(), "pending orders")
    risk_block = None
    try:
        # Observe equity and latch loss halts even when there is no entry signal.
        account_risk_check(account, {}, 0, now_utc())
    except Rejected as exc:
        risk_block = str(exc)
    return {
        "status": "ok", "source": "mt5", "observed_at": now_utc().isoformat(),
        "capabilities": ["entry_extension_limit", "quote_timestamp"],
        "quote": {"bid": float(tick.bid), "ask": float(tick.ask),
                  "timestamp": datetime.fromtimestamp(int(tick.time), timezone.utc).isoformat()},
        "account_key": account_key(account), "symbol": SYMBOL, "risk_block": risk_block,
        "account": {"equity": account.equity, "balance": account.balance, "currency": account.currency,
                    "demo": account.trade_mode == mt5.ACCOUNT_TRADE_MODE_DEMO},
        "positions": [p._asdict() for p in positions], "pending_orders": [p._asdict() for p in orders],
        "m15": bars(mt5.TIMEFRAME_M15, 300), "h1": bars(mt5.TIMEFRAME_H1, 300),
    }


def filling_mode(info):
    # SYMBOL_FILLING flags differ from ORDER_FILLING enum values.
    if info.filling_mode & 1:
        return mt5.ORDER_FILLING_FOK
    if info.filling_mode & 2:
        return mt5.ORDER_FILLING_IOC
    if info.trade_exemode != mt5.SYMBOL_TRADE_EXECUTION_MARKET:
        return mt5.ORDER_FILLING_RETURN
    raise Rejected("No supported market-order filling policy")


def account_risk_check(account, payload, estimated_loss, now):
    equity = number(account.equity, "equity")
    balance = number(account.balance, "balance")
    start_day = now.replace(hour=0, minute=0, second=0, microsecond=0)
    start_week = start_day - timedelta(days=now.weekday())
    deals = require_result(mt5.history_deals_get(start_week, now), "deal history")
    trades = [d for d in deals if d.type in (mt5.DEAL_TYPE_BUY, mt5.DEAL_TYPE_SELL)]
    # Include separately posted commissions/charges; exclude funding and credits.
    funding_types = {getattr(mt5, key, -100) for key in ("DEAL_TYPE_BALANCE", "DEAL_TYPE_CREDIT", "DEAL_TYPE_BONUS")}
    pnl_deals = [d for d in deals if d.type not in funding_types]
    cash = lambda d: d.profit + d.commission + d.swap + d.fee
    today = [d for d in trades if d.time >= start_day.timestamp()]
    today_pnl = sum(cash(d) for d in pnl_deals if d.time >= start_day.timestamp())
    week_pnl = sum(map(cash, pnl_deals))
    floating_loss = min(0.0, equity - balance)
    day_loss = max(0.0, -(today_pnl + floating_loss))
    week_loss = max(0.0, -(week_pnl + floating_loss))
    day_limit = max(0.0, balance - today_pnl) * 0.01
    if payload.get("max_daily_loss") is not None:
        day_limit = min(day_limit, number(payload["max_daily_loss"], "daily loss cap"))
    key = account_key(account)
    state = journal().get(key, {"peak": equity, "halted": False, "day": "", "week": ""})
    state["peak"] = max(number(state["peak"], "equity high-water mark"), equity)
    if equity <= state["peak"] * 0.95:
        state["halted"] = True
    week_id = start_week.date().isoformat()
    day_id = start_day.date().isoformat()
    if day_loss >= day_limit:
        state["day"] = day_id
    if week_loss >= max(0, balance - week_pnl) * 0.03:
        state["week"] = week_id
    journal().set(key, state)
    if state["halted"] or state["day"] == day_id or state["week"] == week_id:
        raise Rejected("Account loss halt is active; drawdown halts require manual review")
    if day_loss + estimated_loss > day_limit or week_loss + estimated_loss > max(0, balance - week_pnl) * 0.03:
        raise Rejected("New stop risk would exceed the daily or weekly loss budget")
    if equity - estimated_loss < state["peak"] * 0.95:
        raise Rejected("New stop risk would exceed the drawdown budget")
    entries = [d for d in today if d.entry in (mt5.DEAL_ENTRY_IN, mt5.DEAL_ENTRY_INOUT)]
    max_entries = min(3, int(number(payload.get("max_trades_per_day", 3), "daily trade count")))
    if len({d.order for d in entries}) >= max_entries:
        raise Rejected("Daily entry limit reached (account-wide)")
    cooldown = max(30, number(payload.get("cooldown_minutes", 30), "cooldown", strictly_positive=False))
    if trades and now.timestamp() - max(d.time for d in trades) < cooldown * 60:
        raise Rejected("Cooldown after the last account trade is active")


def prepare_trade(payload):
    account, terminal = initialize_mt5()
    if os.getenv("MT5_ENABLE_EXECUTION", "false").lower() != "true":
        raise Rejected("Bridge execution is disabled; use Laravel dry run first")
    if account.trade_mode != mt5.ACCOUNT_TRADE_MODE_DEMO and os.getenv("MT5_ALLOW_LIVE", "false").lower() != "true":
        raise Rejected("Live accounts are locked; only demo execution is enabled")
    if not account.trade_allowed or not account.trade_expert or not terminal.trade_allowed or getattr(terminal, "tradeapi_disabled", True):
        raise Rejected("MT5 algorithmic/Python trading permissions are disabled")
    if payload.get("account_key") != account_key(account) or payload.get("symbol") != SYMBOL:
        raise Rejected("Signal account or symbol does not match the bridge")
    if payload.get("strategy") != "gold-risk-breakout" or int(payload.get("magic", 0)) != MAGIC:
        raise Rejected("Unexpected strategy or magic number")
    if require_result(mt5.positions_get(), "positions") or require_result(mt5.orders_get(), "pending orders"):
        raise Rejected("An account position or pending order blocks entry")
    if journal().unresolved():
        raise Rejected("An uncertain or partial execution requires reconciliation")
    now = now_utc()
    news_check(NEWS_PATH, now, fetch_calendar(now))
    info = require_result(mt5.symbol_info(SYMBOL), "symbol specification")
    tick = require_result(mt5.symbol_info_tick(SYMBOL), "quote")
    price, sl, tp = entry_checks(payload, tick.bid, tick.ask, tick.time, now, info.trade_tick_size, info.trade_stops_level * info.point)
    side = mt5.ORDER_TYPE_BUY if payload["action"] == "buy" else mt5.ORDER_TYPE_SELL
    # Estimate at adverse execution, in account currency (also works for cent accounts).
    adverse = price + (1 if payload["action"] == "buy" else -1) * 30 * info.point
    profit = require_result(mt5.order_calc_profit(side, SYMBOL, 1.0, adverse, sl), "stop risk calculation")
    if profit >= 0:
        raise Rejected("Invalid stop risk calculation")
    commission = number(os.getenv("MT5_COMMISSION_PER_LOT"), "round-trip commission per lot", strictly_positive=False)
    volume, loss = volume_for_risk(account.equity, payload.get("risk_percent"), -profit, info.volume_min, info.volume_max, info.volume_step, commission)
    account_risk_check(account, payload, loss, now)
    margin = number(require_result(mt5.order_calc_margin(side, SYMBOL, volume, price), "margin calculation"), "margin", strictly_positive=False)
    if margin > account.margin_free * 0.5:
        raise Rejected("Entry requires more than half of free margin")
    request = {"action": mt5.TRADE_ACTION_DEAL, "symbol": SYMBOL, "volume": volume,
               "type": side, "price": price, "sl": sl, "tp": tp, "deviation": 30,
               "magic": MAGIC, "comment": "GL:" + payload["id"].replace("-", "")[:24],
               "type_time": mt5.ORDER_TIME_GTC, "type_filling": filling_mode(info)}
    check = require_result(mt5.order_check(request), "order check")
    if check.retcode != 0:
        raise Rejected(f"Broker rejected order check: {check.retcode} {check.comment}")
    return request


def execute_trade(payload):
    if not isinstance(payload, dict):
        raise Rejected("JSON object required")
    try:
        request_id = str(UUID(payload["id"]))
        signal_key = str(payload["signal_key"])
        if request_id != payload["id"] or len(signal_key) != 64 or any(c not in "0123456789abcdef" for c in signal_key):
            raise ValueError()
    except (KeyError, ValueError, TypeError, AttributeError):
        raise Rejected("A UUID and SHA-256 signal key are required")
    with LOCK:
        previous = journal().existing(request_id, signal_key)
        if previous is not None:
            return previous
        request = prepare_trade(payload)
        # Commit before order_send. Never retry automatically after this boundary.
        journal().reserve(request_id, signal_key)
        try:
            result = mt5.order_send(request)
            if result is None:
                response = {"status": "unknown", "message": "MT5 returned no result; reconcile with broker history"}
            else:
                if result.retcode == mt5.TRADE_RETCODE_DONE:
                    status = "executed"
                elif result.retcode == mt5.TRADE_RETCODE_DONE_PARTIAL:
                    status = "partial"
                elif result.retcode in {getattr(mt5, code, -1) for code in (
                    "TRADE_RETCODE_REQUOTE", "TRADE_RETCODE_REJECT", "TRADE_RETCODE_CANCEL",
                    "TRADE_RETCODE_INVALID", "TRADE_RETCODE_INVALID_VOLUME", "TRADE_RETCODE_INVALID_PRICE",
                    "TRADE_RETCODE_INVALID_STOPS", "TRADE_RETCODE_TRADE_DISABLED", "TRADE_RETCODE_MARKET_CLOSED",
                    "TRADE_RETCODE_NO_MONEY", "TRADE_RETCODE_PRICE_CHANGED", "TRADE_RETCODE_PRICE_OFF",
                    "TRADE_RETCODE_INVALID_FILL", "TRADE_RETCODE_CLIENT_DISABLES_AT", "TRADE_RETCODE_SERVER_DISABLES_AT") }:
                    status = "failed"
                else:
                    status = "unknown"
                response = {"status": status, "message": str(result.comment), "retcode": int(result.retcode),
                            "order": int(result.order), "deal": int(result.deal), "volume": float(result.volume),
                            "price": float(result.price), "request": request}
            journal().finish(request_id, response)
            return response
        except Exception:
            # The persisted unknown reservation survives crashes and response failures.
            return {"status": "unknown", "message": "Uncertain broker outcome; inspect MT5 and the journal before resuming"}


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(15)

    def respond(self, code, payload):
        body = json.dumps(payload, allow_nan=False).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def authorized(self):
        if not TOKEN or not hmac.compare_digest(self.headers.get("Authorization", ""), "Bearer " + TOKEN):
            self.respond(401, {"status": "rejected", "message": "Unauthorized"})
            return False
        return True

    def do_GET(self):
        if not self.authorized():
            return
        path = urlparse(self.path).path
        try:
            with LOCK:
                if path == "/snapshot":
                    self.respond(200, snapshot())
                elif path == "/health":
                    account, _ = initialize_mt5()
                    self.respond(200, {"status": "ok", "account_key": account_key(account), "symbol": SYMBOL,
                                       "demo": account.trade_mode == mt5.ACCOUNT_TRADE_MODE_DEMO,
                                       "execution_enabled": os.getenv("MT5_ENABLE_EXECUTION", "false").lower() == "true"})
                elif path.startswith("/orders/"):
                    request_id = str(UUID(path.rsplit("/", 1)[-1]))
                    result = journal().existing(request_id, "")
                    self.respond(200 if result else 404, result or {"status": "unknown", "message": "No durable record; do not infer a broker rejection"})
                else:
                    self.respond(404, {"status": "not_found"})
        except Exception as exc:
            self.respond(503, {"status": "rejected", "message": str(exc)})

    def do_POST(self):
        if not self.authorized():
            return
        if self.path != "/trade":
            self.respond(404, {"status": "not_found"})
            return
        try:
            length = int(self.headers.get("Content-Length", "0"))
            if not 0 < length <= 16384:
                raise Rejected("Request body must be 1–16384 bytes")
            if self.headers.get_content_type() != "application/json":
                raise Rejected("Content-Type must be application/json")
            payload = json.loads(self.rfile.read(length))
            self.respond(200, execute_trade(payload))
        except (Rejected, ValueError, KeyError, TypeError) as exc:
            self.respond(422, {"status": "rejected", "message": str(exc)})
        except Exception:
            self.respond(500, {"status": "unknown", "message": "Bridge error; reconcile before retrying"})


def main():
    if len(TOKEN) < 32:
        raise SystemExit("Set a random MT5_BRIDGE_TOKEN of at least 32 characters")
    host = os.getenv("MT5_BRIDGE_HOST", "127.0.0.1")
    port = int(os.getenv("MT5_BRIDGE_PORT", "8710"))
    # Serialize terminal access across HTTP worker threads. Run exactly one bridge per account.
    journal()
    print(f"GoldLogic bridge listening on {host}:{port}; execution defaults to disabled")
    ThreadingHTTPServer((host, port), Handler).serve_forever()


if __name__ == "__main__":
    main()
