"""Explicit broker timestamp conversion; no clock reads, SDK access or offset inference.

The default is the documented UTC contract. server_wall is an operator-configured
exception for observed feeds, not a way to make stale data current. Every decoded
timestamp must still pass the caller's ordinary freshness and ordering checks.
"""
from __future__ import annotations

import math
import os
import re
from dataclasses import dataclass
from datetime import datetime, timedelta, timezone
from numbers import Real
from typing import Mapping

UTC = timezone.utc
MIN_UTC = datetime(2007, 1, 1, tzinfo=UTC).timestamp()
MAX_UTC = datetime(2100, 1, 1, tzinfo=UTC).timestamp()
DOMAINS = frozenset(("bar", "tick", "deal"))


class ClockError(ValueError):
    """A clock profile or source timestamp cannot be interpreted unambiguously."""


def _numeric_epoch(value):
    if isinstance(value, bool) or not isinstance(value, Real):
        raise ClockError("A source epoch must be a finite numeric number of seconds")
    try:
        result = float(value)
    except (OverflowError, ValueError):
        raise ClockError("A source epoch must be finite") from None
    if not math.isfinite(result):
        raise ClockError("A source epoch must be finite")
    return result


def _supported_epoch(value):
    result = _numeric_epoch(value)
    if not MIN_UTC <= result < MAX_UTC:
        raise ClockError("UTC timestamps are supported only during 2007-2099")
    return result


def _utc_input(value):
    if isinstance(value, datetime):
        if value.tzinfo is None or value.utcoffset() is None:
            raise ClockError("Query datetimes require an explicit timezone")
        return _supported_epoch(value.timestamp())
    return _supported_epoch(value)


def _configured_minutes(values, key):
    text = str(values.get(key, "")).strip()
    if not re.fullmatch(r"[+-]?\d+", text):
        raise ClockError("server_wall requires explicit integer UTC offsets in minutes")
    result = int(text)
    if not -840 <= result <= 840:
        raise ClockError("Broker UTC offsets must be within plus/minus 14 hours")
    return result


def _sunday(year, month, ordinal=None):
    if ordinal is not None:
        first = datetime(year, month, 1, tzinfo=UTC)
        return 1 + (6 - first.weekday()) % 7 + (ordinal - 1) * 7
    following = datetime(year + (month == 12), month % 12 + 1, 1, tzinfo=UTC)
    last = following - timedelta(days=1)
    return last.day - (last.weekday() + 1) % 7


@dataclass(frozen=True)
class ClockPolicy:
    mode: str = "utc"
    winter_utc_minutes: int = 0
    summer_utc_minutes: int = 0
    dst_rule: str = "fixed"

    def __post_init__(self):
        if self.mode not in ("utc", "server_wall") or self.dst_rule not in ("fixed", "us", "eu"):
            raise ClockError("Unsupported broker clock mode or DST rule")
        for value in (self.winter_utc_minutes, self.summer_utc_minutes):
            if type(value) is not int or not -840 <= value <= 840:
                raise ClockError("Broker UTC offsets must be integer minutes within plus/minus 14 hours")
        if self.mode == "utc" and (self.winter_utc_minutes != 0 or self.summer_utc_minutes != 0 or self.dst_rule != "fixed"):
            raise ClockError("UTC mode does not apply broker wall-clock offsets")
        if self.dst_rule == "fixed" and self.winter_utc_minutes != self.summer_utc_minutes:
            raise ClockError("A fixed clock rule requires identical winter and summer offsets")

    @classmethod
    def from_environment(cls, mapping: Mapping | None = None):
        values = os.environ if mapping is None else mapping
        mode = str(values.get("MT5_TIME_MODE", "utc")).strip().lower()
        if mode == "utc":
            # Merely having offset variables must never turn on a correction.
            return cls()
        if mode != "server_wall":
            raise ClockError("MT5_TIME_MODE must be utc or server_wall")
        winter = _configured_minutes(values, "MT5_BROKER_WINTER_UTC_MINUTES")
        summer = _configured_minutes(values, "MT5_BROKER_SUMMER_UTC_MINUTES")
        rule = str(values.get("MT5_BROKER_DST_RULE", "")).strip().lower()
        return cls(mode, winter, summer, rule)

    def describe(self):
        return {"mode": self.mode, "winter_utc_minutes": self.winter_utc_minutes,
                "summer_utc_minutes": self.summer_utc_minutes, "dst_rule": self.dst_rule,
                "supported_utc_years": [2007, 2099]}

    def _transitions(self, year):
        if self.dst_rule == "us":
            return (datetime(year, 3, _sunday(year, 3, 2), 7, tzinfo=UTC).timestamp(),
                    datetime(year, 11, _sunday(year, 11, 1), 6, tzinfo=UTC).timestamp())
        if self.dst_rule == "eu":
            return (datetime(year, 3, _sunday(year, 3), 1, tzinfo=UTC).timestamp(),
                    datetime(year, 10, _sunday(year, 10), 1, tzinfo=UTC).timestamp())
        return ()

    def _offset(self, utc_epoch):
        if self.mode == "utc":
            return 0
        if self.dst_rule == "fixed":
            return self.winter_utc_minutes
        year = datetime.fromtimestamp(utc_epoch, UTC).year
        begin, finish = self._transitions(year)
        return self.summer_utc_minutes if begin <= utc_epoch < finish else self.winter_utc_minutes

    @staticmethod
    def _domain(domain):
        if domain not in DOMAINS:
            raise ClockError("Timestamp domain must be bar, tick or deal")

    def normalize(self, raw_epoch, domain="bar"):
        """Decode numeric seconds; preserve fractional seconds without truncation."""
        self._domain(domain)
        raw = _numeric_epoch(raw_epoch)
        if self.mode == "utc":
            return _supported_epoch(raw)
        candidates = set()
        for minutes in set((self.winter_utc_minutes, self.summer_utc_minutes)):
            candidate = raw - minutes * 60
            if MIN_UTC <= candidate < MAX_UTC and self._offset(candidate) == minutes:
                candidates.add(candidate)
        if not candidates:
            raise ClockError("Nonexistent broker wall time or timestamp outside 2007-2099")
        if len(candidates) != 1:
            raise ClockError("Ambiguous broker wall time during a DST overlap")
        return candidates.pop()

    def encode(self, utc_epoch, domain="bar"):
        """Encode actual UTC as the configured SDK source epoch; never infer a rule."""
        self._domain(domain)
        utc = _supported_epoch(utc_epoch)
        return utc + self._offset(utc) * 60

    def metadata(self, raw_epoch, domain="bar"):
        utc = self.normalize(raw_epoch, domain=domain)
        return {**self.describe(), "domain": domain, "offset_minutes": self._offset(utc),
                "raw_epoch": float(raw_epoch), "utc_epoch": utc}

    def query_bounds(self, utc_from, utc_to, domain="deal"):
        """Return aware SDK datetime carriers covering the encoded source range.

        Their numeric epochs represent SOURCE clock labels, not normalized UTC
        observations. Across a DST fold/gap the encoded range is non-monotonic;
        include both transition limits. Normalize every returned record and filter
        it against the caller's original UTC interval after the SDK query.
        """
        self._domain(domain)
        start, finish = _utc_input(utc_from), _utc_input(utc_to)
        if finish < start:
            raise ClockError("UTC query bounds must be ordered")
        raw_points = [self.encode(start, domain), self.encode(finish, domain)]
        if self.mode == "server_wall" and self.dst_rule != "fixed":
            first_year = datetime.fromtimestamp(start, UTC).year
            final_year = datetime.fromtimestamp(finish, UTC).year
            for year in range(first_year, final_year + 1):
                for edge in self._transitions(year):
                    if start < edge <= finish:
                        raw_points.extend((edge + self._offset(edge - 0.001) * 60,
                                           edge + self._offset(edge) * 60))
        return (datetime.fromtimestamp(min(raw_points), UTC), datetime.fromtimestamp(max(raw_points), UTC))
