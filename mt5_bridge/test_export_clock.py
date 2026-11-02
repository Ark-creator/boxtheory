"""Exporter clock tests with a fake SDK; never connects to an account."""
import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import export_history
import terminal
from clock import ClockError, ClockPolicy

UTC = timezone.utc


def at(value):
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


def row(value, policy=None):
    stamp = at(value).timestamp()
    if policy is not None:
        stamp = policy.encode(stamp, domain="bar")
    return {"time": int(stamp), "open": 2000.0, "high": 2001.0, "low": 1999.0, "close": 2000.5}


class ExportClockTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.output = Path(self.tmp.name) / "history.json"
        self.api = SimpleNamespace(TIMEFRAME_M15=15, TIMEFRAME_H1=60, copy_rates_range=Mock(), shutdown=Mock())
        self.start = at("2026-11-02T10:00:00Z")
        self.end = at("2026-11-02T12:00:00Z")
        self.now = at("2026-11-03T12:00:00Z")

    def run_export(self, values, environment=None):
        self.api.copy_rates_range.side_effect = values
        with patch.object(terminal, "mt5", self.api), patch.object(terminal, "initialize_mt5") as initialize, \
                patch.object(terminal, "now_utc", return_value=self.now), patch.object(terminal, "SYMBOL", "XAUUSD-STD"), \
                patch.dict("os.environ", environment or {}, clear=True):
            result = export_history.export_history(self.start, self.end, self.output)
            initialize.assert_called_once()
        self.api.shutdown.assert_called_once()
        self.assertEqual(json.loads(self.output.read_text(encoding="utf-8")), result)
        return result

    def test_default_utc_keeps_instrument_prices_and_utc_bounds_and_clips_both_ends(self):
        result = self.run_export([[row("2026-11-02T09:45:00Z"), row("2026-11-02T10:00:00Z"),
                                   row("2026-11-02T11:45:00Z"), row("2026-11-02T12:00:00Z")],
                                  [row("2026-11-02T09:00:00Z"), row("2026-11-02T10:00:00Z"),
                                   row("2026-11-02T11:00:00Z"), row("2026-11-02T12:00:00Z")]])
        self.assertEqual(result["source"], "mt5")
        self.assertEqual(result["symbol"], "XAUUSD-STD")
        self.assertEqual(result["clock"]["mode"], "utc")
        self.assertEqual(result["clock"]["history_status"], "HISTORICAL_BAR_AND_DEAL_CLOCK_NOT_CERTIFIED")
        self.assertEqual([bar["timestamp"] for bar in result["m15"]], ["2026-11-02T10:00:00+00:00", "2026-11-02T11:45:00+00:00"])
        self.assertEqual(len(result["h1"]), 2)
        for bar in result["m15"] + result["h1"]:
            self.assertEqual(bar["raw_time"], int(at(bar["timestamp"]).timestamp()))
            self.assertEqual(bar["close"], 2000.5)
        for call in self.api.copy_rates_range.call_args_list:
            self.assertEqual(call.args[2:], (self.start, self.end))

    def test_wall_clock_uses_encoded_sdk_bounds_and_filters_decoded_utc(self):
        environment = {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": "120",
                       "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu",
                       "MT5_CLOCK_PROFILE_BASIS": "empirical_current_ticks_plus_eet_convention",
                       "MT5_CLOCK_HISTORY_STATUS": "HISTORICAL_DSTRULE_NOT_CERTIFIED"}
        policy = ClockPolicy.from_environment(environment)
        result = self.run_export([[row("2026-11-02T09:45:00Z", policy), row("2026-11-02T10:00:00Z", policy),
                                   row("2026-11-02T11:45:00Z", policy), row("2026-11-02T12:00:00Z", policy)],
                                  [row("2026-11-02T09:00:00Z", policy), row("2026-11-02T10:00:00Z", policy),
                                   row("2026-11-02T11:00:00Z", policy), row("2026-11-02T12:00:00Z", policy)]], environment)
        self.assertEqual(result["clock"]["dst_rule"], "eu")
        self.assertEqual(result["clock"]["history_status"], "HISTORICAL_DSTRULE_NOT_CERTIFIED")
        self.assertEqual(result["clock"]["profile_basis"], "empirical_current_ticks_plus_eet_convention")
        for call in self.api.copy_rates_range.call_args_list:
            self.assertEqual(call.args[2:], (at("2026-11-02T12:00:00Z"), at("2026-11-02T14:00:00Z")))
        self.assertEqual(len(result["m15"]), 2)
        self.assertEqual(result["m15"][0]["timestamp"], "2026-11-02T10:00:00+00:00")
        self.assertEqual(result["m15"][0]["raw_time"], int(at("2026-11-02T12:00:00Z").timestamp()))

    def test_historical_bars_use_each_dates_dst_offset_instead_of_current_offset(self):
        self.start, self.end = at("2026-03-27T12:00:00Z"), at("2026-03-30T12:00:00Z")
        environment = {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": "120",
                       "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu"}
        policy = ClockPolicy.from_environment(environment)
        result = self.run_export([[row("2026-03-27T12:00:00Z", policy), row("2026-03-30T11:45:00Z", policy)],
                                  [row("2026-03-27T12:00:00Z", policy), row("2026-03-30T11:00:00Z", policy)]], environment)
        first, last = result["m15"]
        self.assertEqual(first["raw_time"] - at(first["timestamp"]).timestamp(), 7200)
        self.assertEqual(last["raw_time"] - at(last["timestamp"]).timestamp(), 10800)

    def test_existing_exports_are_never_overwritten_or_used_to_initialize_mt5(self):
        self.output.write_text("preserved historical file", encoding="utf-8")
        with patch.object(terminal, "initialize_mt5") as initialize, patch.object(terminal, "now_utc", return_value=self.now):
            with self.assertRaises(FileExistsError):
                export_history.export_history(self.start, self.end, self.output)
        self.assertEqual(self.output.read_text(encoding="utf-8"), "preserved historical file")
        initialize.assert_not_called()
        self.api.copy_rates_range.assert_not_called()

    def test_ambiguous_wall_time_fails_without_creating_a_mislabelled_file(self):
        self.start, self.end = at("2026-10-24T12:00:00Z"), at("2026-10-26T12:00:00Z")
        environment = {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": "120",
                       "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu"}
        self.api.copy_rates_range.return_value = [row("2026-10-25T03:30:00Z")]
        with patch.object(terminal, "mt5", self.api), patch.object(terminal, "initialize_mt5"), \
                patch.object(terminal, "now_utc", return_value=self.now), patch.dict("os.environ", environment, clear=True):
            with self.assertRaises(ClockError):
                export_history.export_history(self.start, self.end, self.output)
        self.assertFalse(self.output.exists())
        self.api.shutdown.assert_called_once()

    def test_missing_history_fails_without_output_and_always_detaches_fake_sdk(self):
        self.api.copy_rates_range.return_value = []
        with patch.object(terminal, "mt5", self.api), patch.object(terminal, "initialize_mt5"), \
                patch.object(terminal, "now_utc", return_value=self.now), patch.dict("os.environ", {}, clear=True):
            with self.assertRaisesRegex(ValueError, "No complete history"):
                export_history.export_history(self.start, self.end, self.output)
        self.assertFalse(self.output.exists())
        self.api.shutdown.assert_called_once()


if __name__ == "__main__":
    unittest.main()
