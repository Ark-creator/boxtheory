"""Risk rules independent of the terminal; all amounts use the account's currency units."""
from __future__ import annotations

import json
import math
from datetime import datetime, timedelta, timezone
from decimal import Decimal, ROUND_CEILING, ROUND_FLOOR
from pathlib import Path


class Rejected(ValueError):
    pass


def number(value, name, minimum=0.0, strictly_positive=True):
    try:
        result = float(value)
    except (TypeError, ValueError):
        raise Rejected(f"Invalid {name}")
    if not math.isfinite(result) or result < minimum or (strictly_positive and result <= 0):
        raise Rejected(f"Invalid {name}")
    return result


def utc_time(value):
    try:
        result = datetime.fromisoformat(str(value).replace("Z", "+00:00"))
        if result.tzinfo is None:
            raise ValueError("Missing timezone")
        return result.astimezone(timezone.utc)
    except (ValueError, TypeError):
        raise Rejected("Invalid UTC timestamp")


def news_check(path, now, data=None):
    """Coverage must explicitly include the entire +/-30 minute event window."""
    try:
        if data is None:
            data = json.loads(Path(path).read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            raise Rejected("News calendar must be a JSON object")
        if not isinstance(data.get("events"), list):
            raise Rejected("News events must be a list")
        if not now - timedelta(hours=24) <= utc_time(data["updated_at"]) <= now:
            raise Rejected("News calendar is stale")
        if utc_time(data["coverage_start"]) > now - timedelta(minutes=30) or utc_time(data["coverage_end"]) < now + timedelta(minutes=30):
            raise Rejected("News calendar coverage is incomplete")
        for event in data["events"]:
            event_time = utc_time(event["time"])
            if event["impact"] not in ("low", "medium", "high") or not isinstance(event["currency"], str) or len(event["currency"]) != 3 or event["currency"] != event["currency"].upper():
                raise Rejected("News events require normalized currency and impact fields")
            if event["currency"] == "USD" and event["impact"] == "high" and abs((event_time - now).total_seconds()) <= 1800:
                raise Rejected("High-impact USD news blackout (+/-30 minutes)")
    except (OSError, KeyError, TypeError, json.JSONDecodeError) as exc:
        raise Rejected("A valid, current news calendar is required") from exc


def volume_for_risk(equity, risk_percent, loss_per_lot, min_lot, max_lot, step, commission_per_lot):
    equity = number(equity, "equity")
    risk_percent = number(risk_percent, "risk percent")
    if risk_percent > 0.5:
        raise Rejected("Risk exceeds the 0.5% hard cap")
    cost = number(loss_per_lot, "stop loss per lot") + number(commission_per_lot, "commission", strictly_positive=False)
    minimum = number(min_lot, "minimum volume")
    maximum = number(max_lot, "maximum volume")
    if maximum < minimum:
        raise Rejected("Maximum broker volume is below its minimum")
    increment = Decimal(str(number(step, "volume step")))
    # Reserve 10% for extra execution costs; a stop is not a guaranteed loss cap.
    budget = equity * risk_percent / 100 * 0.9
    lots = float((Decimal(str(min(maximum, budget / cost))) / increment).to_integral_value(rounding=ROUND_FLOOR) * increment)
    if lots < minimum:
        raise Rejected("Minimum broker lot would exceed the risk budget")
    return lots, lots * cost


def entry_checks(payload, bid, ask, tick_time, now, tick_size, stops_distance):
    bid = number(bid, "bid")
    ask = number(ask, "ask")
    if ask < bid or not -5 <= now.timestamp() - tick_time <= 30:
        raise Rejected("Stale or invalid broker quote")
    if now.weekday() >= 5 or not 7 <= now.hour < 17:
        raise Rejected("Entries allowed only 07:00–17:00 UTC weekdays")
    issued = utc_time(payload.get("signal_timestamp"))
    expiry = utc_time(payload.get("expires_at"))
    if issued > now or issued < now - timedelta(minutes=3) or not issued < expiry <= issued + timedelta(minutes=3) or now >= expiry:
        raise Rejected("Signal expired or has invalid timestamps")
    side = payload.get("action")
    if side not in ("buy", "sell"):
        raise Rejected("Only buy/sell entries are supported; broker SL/TP manages exits")
    expected = number(payload.get("entry_price"), "signal entry")
    sl = number(payload.get("stop_loss"), "stop loss")
    tp = number(payload.get("take_profit_1"), "target")
    tick_size = number(tick_size, "tick size")
    # Nearest valid price increment, then validate risk on those actual levels.
    sl = round(round(sl / tick_size) * tick_size, 8)
    tp = round(round(tp / tick_size) * tick_size, 8)
    price = ask if side == "buy" else bid
    entry_limit = number(payload.get("entry_limit_price"), "breakout entry limit")
    if (side == "buy" and (expected > entry_limit or price > entry_limit)) or (side == "sell" and (expected < entry_limit or price < entry_limit)):
        raise Rejected("Breakout entry extension exceeds the signal's entry limit")
    if not (sl < price < tp if side == "buy" else tp < price < sl):
        raise Rejected("Stop/target are on the wrong side of the market")
    distance = abs(price - sl)
    if abs(price - expected) > 0.1 * abs(expected - sl):
        raise Rejected("Price moved more than 0.1R from the signal")
    if ask - bid > min(1.0, 0.1 * distance):
        raise Rejected("Spread exceeds $1 or 10% of stop distance")
    reference = bid if side == "buy" else ask
    if min(abs(reference - sl), abs(reference - tp)) < stops_distance or not (sl < reference < tp if side == "buy" else tp < reference < sl):
        raise Rejected("Broker minimum stop distance is not met")
    if abs(tp - price) / distance < 1.8:
        raise Rejected("Reward/risk after price drift is below 1.8")
    return price, sl, tp


def rsi_entry_checks(payload, bid, ask, tick_time, now, tick_size, stops_distance):
    """RSI V2 source levels and broker-grid validation; no breakout/news rule."""
    bid, ask = number(bid, "bid"), number(ask, "ask")
    stamp = number(tick_time, "tick timestamp")
    if ask < bid or not 0 <= now.timestamp() - stamp <= 90:
        raise Rejected("RSI quote is stale, future-dated or invalid")
    if now.weekday() >= 5 or not 7 <= now.hour < 17:
        raise Rejected("RSI entries allowed only 07:00-17:00 UTC weekdays")
    issued = utc_time(payload.get("signal_timestamp"))
    expiry = utc_time(payload.get("expires_at"))
    if issued.timestamp() % 300 != 0 or issued.timestamp() != int(now.timestamp()) // 300 * 300:
        raise Rejected("RSI signal must identify the latest completed M5 candle close")
    if expiry != issued + timedelta(seconds=180) or not issued <= now < expiry:
        raise Rejected("RSI signal expired or has invalid M5 expiry")
    side = payload.get("action")
    if side not in ("buy", "sell"):
        raise Rejected("RSI supports only buy/sell; original broker SL/full TP1 manages exits")
    direction = 1 if side == "buy" else -1
    expected = number(payload.get("entry_price"), "RSI signal entry")
    original_sl = number(payload.get("stop_loss"), "RSI stop loss")
    original_tp = number(payload.get("take_profit_1"), "RSI full TP1")
    for value in (expected, original_sl, original_tp):
        if abs(value - float(Decimal(str(value)).quantize(Decimal("0.0001")))) > 1e-9:
            raise Rejected("RSI source entry/SL/TP1 must retain four-decimal strategy precision")
    original_risk = direction * (expected - original_sl)
    reward = direction * (original_tp - expected)
    # Four-decimal entry, SL and TP rounding can propagate up to 0.00025.
    if original_risk <= 0 or reward <= 0 or abs(reward - 1.5 * original_risk) > 0.00025 + 1e-9:
        raise Rejected("RSI requires the original full TP1 at 1.5R")
    if payload.get("take_profit_2") is not None:
        raise Rejected("RSI full_tp1 protocol does not permit a second target")
    price = ask if side == "buy" else bid
    if not (original_sl < price < original_tp if side == "buy" else original_tp < price < original_sl):
        raise Rejected("RSI quote is outside the original SL/TP1 bracket")
    if abs(price - expected) > 0.1 * original_risk + 1e-9:
        raise Rejected("RSI price moved more than 0.1R from the original entry")
    increment = Decimal(str(number(tick_size, "tick size")))
    minimum_distance = number(stops_distance, "minimum stop distance", strictly_positive=False)
    rounding = ROUND_FLOOR if side == "buy" else ROUND_CEILING
    grid_price = lambda value: float((Decimal(str(value)) / increment).to_integral_value(rounding=rounding) * increment)
    sl, tp = grid_price(original_sl), grid_price(original_tp)
    reference = bid if side == "buy" else ask
    bracket = sl < price < tp and sl < reference < tp if side == "buy" else tp < price < sl and tp < reference < sl
    if not bracket or min(abs(reference - sl), abs(reference - tp)) + 1e-9 < minimum_distance:
        raise Rejected("RSI original bracket cannot satisfy broker tick/stop-distance rules")
    return price, sl, tp
