"""Prepare a pinned local DEMO connection. Never submits an order or changes MT5 login."""
from __future__ import annotations

import argparse
import json
import math
import os
import re
import secrets
from datetime import datetime, timezone
from pathlib import Path
from time import monotonic, sleep

from clock import ClockError, ClockPolicy

try:
    import MetaTrader5 as mt5
except ImportError:
    mt5 = None


def read_environment(path):
    result = {}
    if path.is_file():
        for line in path.read_text(encoding="utf-8-sig").splitlines():
            match = re.fullmatch(r"\s*(MT5_[A-Z0-9_]+)\s*=(.*)", line)
            if match:
                value = match[2].strip()
                if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
                    value = value[1:-1]
                result[match[1]] = value
    return result


def ea_in_saved_charts(data_path):
    name = "GoldLogic_RSI_Scalper_V2"
    for relative in ("profiles/Charts", "MQL5/Profiles/Charts"):
        base = Path(data_path) / relative
        if base.is_dir():
            for chart in base.rglob("*.chr"):
                raw = chart.read_bytes()
                if name.encode() in raw or name.encode("utf-16-le") in raw:
                    return True
    return False


def now_utc():
    return datetime.now(timezone.utc)


def sample_advancing_quotes(symbol):
    """Observe genuine source updates; never manufacture a new quote timestamp."""
    deadline = monotonic() + 8.0
    first = None
    latest_msc = None
    sampled = 0
    for _ in range(33):
        if monotonic() >= deadline:
            break
        tick = mt5.symbol_info_tick(symbol)
        observed = now_utc()
        try:
            values = (tick.bid, tick.ask, tick.time, tick.time_msc)
            if any(isinstance(value, bool) for value in values):
                raise ValueError()
            bid, ask, raw, msc = (float(value) for value in values)
            if not all(math.isfinite(value) for value in (bid, ask, raw, msc)) or bid <= 0 or ask < bid:
                raise ValueError()
            if raw <= 0 or msc <= 0 or not raw.is_integer() or not msc.is_integer() or int(msc // 1000) != int(raw):
                raise ValueError()
        except (AttributeError, TypeError, ValueError, OverflowError):
            raise ValueError("A finite positive bid/ask and consistent source tick time/time_msc are required.") from None
        sampled += 1
        row = {"raw": raw, "time_msc": msc, "bid": bid, "ask": ask, "observed": observed}
        if latest_msc is not None and msc < latest_msc:
            raise ValueError("The broker source tick clock moved backwards; no clock profile was accepted.")
        latest_msc = msc
        if first is None:
            first = row
        elif msc > first["time_msc"] and (bid, ask) != (first["bid"], first["ask"]):
            return [first, row], sampled
        remaining = deadline - monotonic()
        if remaining > 0:
            sleep(min(0.25, remaining))
    raise ValueError("No two advancing source time_msc/price updates were observed within eight seconds; cached or frozen quotes cannot verify a clock profile.")


def verified_clock_configuration(account, symbol, previous):
    clock_keys = ("MT5_TIME_MODE", "MT5_BROKER_WINTER_UTC_MINUTES", "MT5_BROKER_SUMMER_UTC_MINUTES", "MT5_BROKER_DST_RULE")
    explicit = any(key in previous for key in clock_keys)
    samples, sampled = sample_advancing_quotes(symbol)

    def verify(policy):
        normalized = []
        for sample in samples:
            stamp = policy.normalize(sample["raw"], domain="tick")
            age = sample["observed"].timestamp() - stamp
            if not 0 <= age <= 90:
                raise ClockError("Source ticks remain future-dated or stale under this clock policy.")
            normalized.append((stamp, age))
        if normalized[1][0] < normalized[0][0]:
            raise ClockError("Normalized source ticks moved backwards.")
        return normalized

    if explicit:
        policy = ClockPolicy.from_environment(previous)
        try:
            normalized = verify(policy)
        except ClockError:
            raise ValueError("The explicitly configured MT5 clock policy does not produce fresh source ticks. Verify MT5_TIME_MODE and the broker offset/DST settings.") from None
        basis = "explicit_operator_profile_verified_with_advancing_current_ticks"
    else:
        policy = ClockPolicy.from_environment({})
        try:
            normalized = verify(policy)
            basis = "advancing_current_ticks_verified_as_utc"
        except ClockError:
            if getattr(account, "company", None) != "MetaQuotes Ltd." or account.server != "MetaQuotes-Demo":
                raise ValueError("Source ticks are not fresh UTC. Configure an explicit MT5_TIME_MODE and broker offset/DST policy; no arbitrary offset was inferred.") from None
            # One named, fixed convention for the known MetaQuotes demo only.
            # The offset is never fitted from now minus the quoted timestamp.
            policy = ClockPolicy.from_environment({"MT5_TIME_MODE": "server_wall",
                "MT5_BROKER_WINTER_UTC_MINUTES": "120", "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu"})
            try:
                normalized = verify(policy)
            except ClockError:
                raise ValueError("Neither UTC nor the named MetaQuotes demo EET convention validates advancing fresh source ticks. Supply a verified explicit clock policy.") from None
            basis = "empirical_current_ticks_plus_eet_convention"
    metadata = policy.describe()
    historical = "HISTORICAL_DSTRULE_NOT_CERTIFIED" if policy.mode == "server_wall" else "HISTORICAL_BAR_AND_DEAL_CLOCK_NOT_CERTIFIED"
    values = {"MT5_TIME_MODE": metadata["mode"],
              "MT5_BROKER_WINTER_UTC_MINUTES": str(metadata["winter_utc_minutes"]),
              "MT5_BROKER_SUMMER_UTC_MINUTES": str(metadata["summer_utc_minutes"]),
              "MT5_BROKER_DST_RULE": metadata["dst_rule"], "MT5_CLOCK_PROFILE_BASIS": basis,
              "MT5_CLOCK_HISTORY_STATUS": historical}
    report = {**metadata, "profile_basis": basis, "historical_status": historical,
              "advancing_price_updates_verified": len(samples), "tick_samples_observed": sampled,
              "raw_tick_epoch": samples[-1]["raw"],
              "normalized_tick_utc": datetime.fromtimestamp(normalized[-1][0], timezone.utc).isoformat(),
              "observed_at_utc": samples[-1]["observed"].isoformat(), "normalized_quote_age_seconds": normalized[-1][1]}
    return values, report


def prepare_configuration(previous, commission_override=None):
    if mt5 is None:
        raise ValueError("Install the MetaTrader5 package in the selected Python environment.")
    terminal_path = previous.get("MT5_TERMINAL_PATH") or r"C:\Program Files\MetaTrader 5\terminal64.exe"
    if not Path(terminal_path).is_file():
        raise ValueError("Set MT5_TERMINAL_PATH to the intended terminal64.exe in mt5_bridge/.env.")
    if not mt5.initialize(terminal_path, timeout=10000):
        code = mt5.last_error()[0]
        raise ValueError(f"MT5 connection failed (code {code}). Log into a connected demo account and retry. No execution was enabled.")
    try:
        account, terminal = mt5.account_info(), mt5.terminal_info()
        if account is None or terminal is None or not terminal.connected:
            raise ValueError("MT5 is disconnected. Log into the intended demo account and wait for connection.")
        if account.trade_mode != mt5.ACCOUNT_TRADE_MODE_DEMO:
            raise ValueError("The current MT5 account is not DEMO. Log into a demo account; real and contest accounts are blocked.")
        positions, orders = mt5.positions_get(), mt5.orders_get()
        if positions is None or orders is None:
            raise ValueError("Cannot verify account exposure. No automation was enabled.")
        if positions or orders:
            raise ValueError("This demo account already has a position or pending order. Manage it in MT5 before starting a new controller.")
        if ea_in_saved_charts(terminal.data_path):
            raise ValueError("A standalone GoldLogic scalper EA is saved in an MT5 chart profile. Remove that EA from the account's charts before using app execution.")
        if not account.trade_allowed or not account.trade_expert or not terminal.trade_allowed or terminal.tradeapi_disabled:
            raise ValueError("Enable Algo Trading and allow external Python trading in MT5 for this demo account, then retry. Keep the standalone EA off this account.")
        symbols = mt5.symbols_get(group="*XAU*")
        if symbols is None:
            raise ValueError("Gold instrument metadata is unavailable.")
        gold = [s for s in symbols if s.currency_base == "XAU" and s.currency_profit == "USD"]
        requested = previous.get("MT5_SYMBOL")
        symbol = next((s for s in gold if s.name == requested), None) if requested else None
        if symbol is None and not requested:
            symbol = next((s for s in gold if s.name == "XAUUSD-STD"), None)
        if symbol is None and not requested and len(gold) == 1:
            symbol = gold[0]
        if symbol is None:
            raise ValueError("Set MT5_SYMBOL to the exact XAU/USD Market Watch symbol in mt5_bridge/.env.")
        if not mt5.symbol_select(symbol.name, True):
            raise ValueError("The configured gold instrument could not be selected in Market Watch.")
        clock_values, clock_report = verified_clock_configuration(account, symbol.name, previous)
        commission = commission_override if commission_override is not None else previous.get("MT5_COMMISSION_PER_LOT")
        assumed_commission = commission is None or str(commission).strip() == ""
        if assumed_commission:
            if account.currency != "USD":
                raise ValueError("Set MT5_COMMISSION_PER_LOT to a round-trip sizing reserve in this account's currency before starting.")
            commission = 7.0  # Explicit demo sizing assumption, not a broker fee claim.
        commission = float(commission)
        if not math.isfinite(commission) or commission < 0:
            raise ValueError("MT5_COMMISSION_PER_LOT must be a finite non-negative round-trip reserve in account currency.")
        port = int(previous.get("MT5_BRIDGE_PORT") or "8710")
        if not 1 <= port <= 65535:
            raise ValueError("Invalid MT5_BRIDGE_PORT.")
        token = previous.get("MT5_BRIDGE_TOKEN", "")
        if len(token) < 32:
            token = secrets.token_urlsafe(48)
        same_login = str(account.login) == previous.get("MT5_LOGIN") and account.server == previous.get("MT5_SERVER")
        values = dict(previous)
        values.update({"MT5_LOGIN": str(account.login), "MT5_SERVER": account.server,
                       "MT5_PASSWORD": previous.get("MT5_PASSWORD", "") if same_login else "",
                       "MT5_TERMINAL_PATH": terminal_path, "MT5_SYMBOL": symbol.name,
                       "MT5_BRIDGE_TOKEN": token, "MT5_BRIDGE_HOST": "127.0.0.1", "MT5_BRIDGE_PORT": str(port),
                       "MT5_MAGIC": "2601103", "MT5_ENABLE_EXECUTION": "true", "MT5_ALLOW_LIVE": "false",
                       "MT5_DEMO_ONLY": "true", "MT5_COMMISSION_PER_LOT": str(commission)})
        values.update(clock_values)
        report = {"status": "demo_verified", "demo": True, "connected": True, "symbol": symbol.name,
                  "currency": account.currency, "commission_reserve_per_lot": commission,
                  "commission_is_assumed": assumed_commission, "clock": clock_report, "orders_sent": 0}
        return values, report
    finally:
        mt5.shutdown()


def save_configuration(path, values):
    lines = ["# Local demo bridge configuration. Contains credentials; never commit or share."]
    for key, value in sorted(values.items()):
        value = str(value)
        if not re.fullmatch(r"MT5_[A-Z0-9_]+", key) or any(char in value for char in ("\n", "\r", "\0", "'")):
            raise ValueError("A connection setting contains unsupported characters; no configuration was replaced.")
        lines.append(f"{key}='{value}'")
    if path.exists():
        backup = Path(__file__).resolve().parent.parent / "storage/app/private/mt5-setup-backups"
        backup.mkdir(parents=True, exist_ok=True)
        stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
        (backup / f"bridge-{stamp}.env").write_bytes(path.read_bytes())
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(path.name + ".pending")
    temporary.write_text("\n".join(lines) + "\n", encoding="utf-8")
    os.replace(temporary, path)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env-file", type=Path, default=Path(__file__).with_name(".env"))
    parser.add_argument("--commission-per-lot", type=float)
    args = parser.parse_args()
    try:
        values, report = prepare_configuration(read_environment(args.env_file), args.commission_per_lot)
        save_configuration(args.env_file.resolve(), values)
        print(json.dumps(report))
        return 0
    except (ValueError, OSError, TypeError) as error:
        print(json.dumps({"status": "not_enabled", "message": str(error), "orders_sent": 0}))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
