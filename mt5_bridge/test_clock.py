"""Pure clock-policy fixtures. No terminal, network, account or current clock."""
import math
import os
import unittest
from datetime import datetime, timedelta, timezone
from unittest.mock import patch

from clock import ClockError, ClockPolicy

UTC = timezone.utc


def epoch(text):
    return datetime.fromisoformat(text.replace("Z", "+00:00")).timestamp()


def wall_profile(rule="eu", winter=120, summer=180):
    return {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": str(winter),
            "MT5_BROKER_SUMMER_UTC_MINUTES": str(summer), "MT5_BROKER_DST_RULE": rule}


class ClockTests(unittest.TestCase):
    def test_default_utc_preserves_seconds_and_fractions_in_every_domain(self):
        policy = ClockPolicy.from_environment({})
        for value in (epoch("2026-11-02T12:15:20Z"), epoch("2026-11-02T12:15:20.125Z")):
            for domain in ("bar", "tick", "deal"):
                with self.subTest(value=value, domain=domain):
                    self.assertEqual(policy.normalize(value, domain=domain), value)
                    self.assertEqual(policy.encode(value, domain=domain), value)
        self.assertEqual(policy.describe(), {"mode": "utc", "winter_utc_minutes": 0,
                         "summer_utc_minutes": 0, "dst_rule": "fixed", "supported_utc_years": [2007, 2099]})

    def test_offsets_never_implicitly_turn_on_correction(self):
        values = wall_profile()
        values.pop("MT5_TIME_MODE")
        raw = epoch("2026-11-02T14:15:20Z")
        self.assertEqual(ClockPolicy.from_environment(values).normalize(raw), raw)
        values["MT5_TIME_MODE"] = "UTC"
        self.assertEqual(ClockPolicy.from_environment(values).normalize(raw), raw)

    def test_explicit_environment_mapping_and_process_environment_have_same_contract(self):
        values = wall_profile("us")
        with patch.dict(os.environ, values, clear=True):
            self.assertEqual(ClockPolicy.from_environment(), ClockPolicy.from_environment(values))

    def test_profile_requires_all_fields_and_valid_integers(self):
        for missing in ("MT5_BROKER_WINTER_UTC_MINUTES", "MT5_BROKER_SUMMER_UTC_MINUTES", "MT5_BROKER_DST_RULE"):
            values = wall_profile()
            values.pop(missing)
            with self.subTest(missing=missing), self.assertRaises(ClockError):
                ClockPolicy.from_environment(values)
        cases = (("MT5_TIME_MODE", "auto"), ("MT5_BROKER_DST_RULE", "automatic"),
                 ("MT5_BROKER_WINTER_UTC_MINUTES", "120.0"), ("MT5_BROKER_WINTER_UTC_MINUTES", "True"),
                 ("MT5_BROKER_WINTER_UTC_MINUTES", "841"), ("MT5_BROKER_SUMMER_UTC_MINUTES", "-841"),
                 ("MT5_BROKER_SUMMER_UTC_MINUTES", ""))
        for key, value in cases:
            with self.subTest(key=key, value=value), self.assertRaises(ClockError):
                ClockPolicy.from_environment({**wall_profile(), key: value})

    def test_fixed_requires_equal_offsets_and_constructor_cannot_bypass_validation(self):
        with self.assertRaises(ClockError):
            ClockPolicy.from_environment(wall_profile("fixed", 120, 180))
        for args in (("utc", 120, 120, "fixed"), ("server_wall", True, 180, "eu"),
                     ("server_wall", 120.0, 180, "eu"), ("server_wall", 120, 180, "unknown")):
            with self.subTest(args=args), self.assertRaises(ClockError):
                ClockPolicy(*args)

    def test_fixed_positive_negative_and_fractional_seconds_round_trip(self):
        at = epoch("2026-11-02T12:15:20.125Z")
        for offset in (120, -300, 330, 0):
            policy = ClockPolicy.from_environment(wall_profile("fixed", offset, offset))
            raw = at + offset * 60
            with self.subTest(offset=offset):
                self.assertEqual(policy.normalize(raw), at)
                self.assertEqual(policy.encode(at), raw)
                self.assertEqual(policy.metadata(raw, domain="tick")["offset_minutes"], offset)

    def test_eu_and_us_differ_in_the_transition_weeks(self):
        eu = ClockPolicy.from_environment(wall_profile("eu"))
        us = ClockPolicy.from_environment(wall_profile("us"))
        for instant, eu_offset, us_offset in (("2026-03-16T12:00:00Z", 120, 180),
                                               ("2026-10-26T12:00:00Z", 120, 180),
                                               ("2026-07-01T12:00:00Z", 180, 180),
                                               ("2026-11-02T12:00:00Z", 120, 120)):
            at = epoch(instant)
            with self.subTest(instant=instant):
                self.assertEqual(eu.encode(at), at + eu_offset * 60)
                self.assertEqual(us.encode(at), at + us_offset * 60)
                self.assertEqual(eu.normalize(eu.encode(at)), at)
                self.assertEqual(us.normalize(us.encode(at)), at)

    def test_eu_spring_gap_is_rejected_and_exact_transition_is_causal(self):
        policy = ClockPolicy.from_environment(wall_profile("eu"))
        before, after = epoch("2026-03-29T00:59:59Z"), epoch("2026-03-29T01:00:00Z")
        self.assertEqual(policy.encode(before), epoch("2026-03-29T02:59:59Z"))
        self.assertEqual(policy.encode(after), epoch("2026-03-29T04:00:00Z"))
        self.assertEqual(policy.normalize(policy.encode(before)), before)
        self.assertEqual(policy.normalize(policy.encode(after)), after)
        for raw in ("2026-03-29T03:00:00Z", "2026-03-29T03:30:00Z", "2026-03-29T03:59:59.999Z"):
            with self.subTest(raw=raw), self.assertRaisesRegex(ClockError, "Nonexistent"):
                policy.normalize(epoch(raw))

    def test_eu_autumn_overlap_is_not_resolved_using_current_time(self):
        policy = ClockPolicy.from_environment(wall_profile("eu"))
        for raw in ("2026-10-25T03:00:00Z", "2026-10-25T03:30:00Z", "2026-10-25T03:59:59.999Z"):
            with self.subTest(raw=raw), self.assertRaisesRegex(ClockError, "Ambiguous"):
                policy.normalize(epoch(raw))
        for instant in ("2026-10-24T23:59:59Z", "2026-10-25T02:00:00Z"):
            at = epoch(instant)
            self.assertEqual(policy.normalize(policy.encode(at)), at)

    def test_us_spring_gap_and_autumn_overlap_are_rejected(self):
        policy = ClockPolicy.from_environment(wall_profile("us"))
        self.assertEqual(policy.encode(epoch("2026-03-08T06:59:59Z")), epoch("2026-03-08T08:59:59Z"))
        self.assertEqual(policy.encode(epoch("2026-03-08T07:00:00Z")), epoch("2026-03-08T10:00:00Z"))
        with self.assertRaisesRegex(ClockError, "Nonexistent"):
            policy.normalize(epoch("2026-03-08T09:30:00Z"))
        with self.assertRaisesRegex(ClockError, "Ambiguous"):
            policy.normalize(epoch("2026-11-01T08:30:00Z"))

    def test_us_rule_also_supports_negative_new_york_offsets(self):
        policy = ClockPolicy.from_environment(wall_profile("us", -300, -240))
        for raw in ("2026-11-01T01:30:00Z",):
            with self.assertRaisesRegex(ClockError, "Ambiguous"):
                policy.normalize(epoch(raw))
        with self.assertRaisesRegex(ClockError, "Nonexistent"):
            policy.normalize(epoch("2026-03-08T02:30:00Z"))

    def test_equal_offsets_under_a_dst_rule_remain_uniquely_invertible(self):
        policy = ClockPolicy.from_environment(wall_profile("eu", 120, 120))
        for instant in ("2026-03-29T01:30:00Z", "2026-10-25T01:30:00Z"):
            at = epoch(instant)
            self.assertEqual(policy.normalize(policy.encode(at)), at)

    def test_range_checks_apply_to_decoded_utc_not_the_raw_wall_calendar_year(self):
        policy = ClockPolicy.from_environment(wall_profile("fixed", 120, 120))
        at = epoch("2099-12-31T23:30:00Z")
        raw = epoch("2100-01-01T01:30:00Z")
        self.assertEqual(policy.normalize(raw), at)
        self.assertEqual(policy.encode(at), raw)
        with self.assertRaises(ClockError):
            policy.normalize(epoch("2007-01-01T01:00:00Z"))
        for year in (2007, 2099):
            value = epoch(f"{year}-07-01T12:00:00Z")
            self.assertEqual(policy.normalize(policy.encode(value)), value)

    def test_invalid_epochs_and_domains_fail_closed(self):
        for policy in (ClockPolicy(), ClockPolicy.from_environment(wall_profile())):
            for invalid in (float("nan"), float("inf"), -float("inf"), 1e300, "1234567890", True, None,
                            epoch("2000-01-01T12:00:00Z")):
                with self.subTest(invalid=invalid, mode=policy.mode), self.assertRaises(ClockError):
                    policy.normalize(invalid)
            for domain in ("auto", "", None):
                with self.subTest(domain=domain), self.assertRaises(ClockError):
                    policy.normalize(epoch("2026-11-02T12:00:00Z"), domain=domain)
            with self.assertRaises(ClockError):
                policy.encode(epoch("2100-01-01T00:00:00Z"))

    def test_metadata_preserves_raw_and_normalized_epochs_and_explicit_policy(self):
        policy = ClockPolicy.from_environment(wall_profile("eu"))
        raw = epoch("2026-11-02T14:15:20.125Z")
        data = policy.metadata(raw, domain="deal")
        self.assertEqual(data["raw_epoch"], raw)
        self.assertEqual(data["utc_epoch"], epoch("2026-11-02T12:15:20.125Z"))
        self.assertEqual(data["offset_minutes"], 120)
        self.assertEqual(data["domain"], "deal")
        self.assertEqual(data["dst_rule"], "eu")

    def test_default_does_not_heal_future_data_and_explicit_conversion_does_not_heal_stale_data(self):
        now = epoch("2026-11-02T12:00:00Z")
        future_raw = now + 7200
        self.assertGreater(ClockPolicy().normalize(future_raw), now)
        policy = ClockPolicy.from_environment(wall_profile("eu"))
        stale_raw = now - 180 + 7200
        self.assertEqual(now - policy.normalize(stale_raw), 180)

    def test_utc_query_bounds_accept_aware_datetimes_and_numeric_epochs(self):
        policy = ClockPolicy()
        start = datetime(2026, 11, 2, 20, 0, tzinfo=timezone(timedelta(hours=8)))
        finish = start + timedelta(minutes=5)
        self.assertEqual(policy.query_bounds(start, finish), (start.astimezone(UTC), finish.astimezone(UTC)))
        self.assertEqual(policy.query_bounds(start.timestamp(), finish.timestamp()), policy.query_bounds(start, finish))

    def test_fixed_query_bounds_are_sdk_raw_epoch_carriers(self):
        policy = ClockPolicy.from_environment(wall_profile("fixed", 120, 120))
        start, finish = epoch("2026-11-02T12:00:00Z"), epoch("2026-11-02T13:00:00Z")
        left, right = policy.query_bounds(start, finish)
        self.assertEqual(left.tzinfo, UTC)
        self.assertEqual(left.timestamp(), start + 7200)
        self.assertEqual(right.timestamp(), finish + 7200)
        self.assertEqual(policy.normalize(left.timestamp(), domain="deal"), start)

    def test_query_bounds_cover_nonmonotonic_dst_fold_and_spring_gap(self):
        policy = ClockPolicy.from_environment(wall_profile("eu"))
        cases = (("2026-10-25T00:45:00Z", "2026-10-25T01:15:00Z", "2026-10-25T03:00:00Z", "2026-10-25T04:00:00Z"),
                 ("2026-03-29T00:45:00Z", "2026-03-29T01:15:00Z", "2026-03-29T02:45:00Z", "2026-03-29T04:15:00Z"))
        for begin, end, lower, upper in cases:
            start, finish = epoch(begin), epoch(end)
            left, right = policy.query_bounds(start, finish)
            with self.subTest(begin=begin):
                self.assertEqual(left.timestamp(), epoch(lower))
                self.assertEqual(right.timestamp(), epoch(upper))
                for at in range(int(start), int(finish) + 1, 30):
                    self.assertLessEqual(left.timestamp(), policy.encode(at))
                    self.assertGreaterEqual(right.timestamp(), policy.encode(at))

    def test_query_bounds_reject_naive_invalid_or_reversed_ranges(self):
        policy = ClockPolicy()
        start = epoch("2026-11-02T12:00:00Z")
        for first, final in ((start, start - 1), (datetime(2026, 11, 2), start), (start, math.inf)):
            with self.subTest(first=first, final=final), self.assertRaises(ClockError):
                policy.query_bounds(first, final)


if __name__ == "__main__":
    unittest.main()
