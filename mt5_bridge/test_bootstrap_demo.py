import json
import tempfile
import unittest
from datetime import datetime, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import bootstrap_demo as bootstrap


class BootstrapDemoTests(unittest.TestCase):
    def setUp(self):
        self.now = datetime(2026, 11, 2, 10, 0, 10, tzinfo=timezone.utc)
        self.tick_counter = 0
        self.account = SimpleNamespace(login=123, server="TEST-DEMO", currency="USD", trade_mode=0,
                                       trade_allowed=True, trade_expert=True)
        self.terminal = SimpleNamespace(connected=True, data_path="unused-test-data", trade_allowed=True,
                                        tradeapi_disabled=False)
        self.symbol = SimpleNamespace(name="XAUUSD-STD", currency_base="XAU", currency_profit="USD")
        self.api = SimpleNamespace(ACCOUNT_TRADE_MODE_DEMO=0, initialize=Mock(return_value=True),
                                    account_info=Mock(side_effect=lambda: self.account),
                                    terminal_info=Mock(side_effect=lambda: self.terminal),
                                    positions_get=Mock(return_value=()), orders_get=Mock(return_value=()),
                                    symbols_get=Mock(return_value=(self.symbol,)), symbol_select=Mock(return_value=True),
                                    symbol_info_tick=Mock(side_effect=lambda symbol: self.next_tick()),
                                    shutdown=Mock(), last_error=Mock(return_value=(-10005, "timeout")),
                                    order_send=Mock(side_effect=AssertionError("Setup must never send an order")))
        self.addCleanup(patch.stopall)
        patch.object(bootstrap, "mt5", self.api).start()
        patch.object(bootstrap.Path, "is_file", return_value=True).start()
        patch.object(bootstrap, "ea_in_saved_charts", return_value=False).start()
        patch.object(bootstrap, "now_utc", return_value=self.now).start()
        patch.object(bootstrap, "sleep").start()

    def next_tick(self, shift=0):
        self.tick_counter += 1
        raw = int(self.now.timestamp()) - 1 + shift
        return SimpleNamespace(bid=2000 + self.tick_counter * .01, ask=2000.2 + self.tick_counter * .01,
                               time=raw, time_msc=raw * 1000 + self.tick_counter)

    def test_connected_demo_generates_private_pinned_settings_without_trading(self):
        settings, report = bootstrap.prepare_configuration({})
        self.assertEqual(settings["MT5_LOGIN"], "123")
        self.assertEqual(settings["MT5_MAGIC"], "2601103")
        self.assertEqual(settings["MT5_DEMO_ONLY"], "true")
        self.assertEqual(settings["MT5_ALLOW_LIVE"], "false")
        self.assertEqual(settings["MT5_ENABLE_EXECUTION"], "true")
        self.assertGreaterEqual(len(settings["MT5_BRIDGE_TOKEN"]), 32)
        self.assertEqual(float(settings["MT5_COMMISSION_PER_LOT"]), 7.0)
        self.assertTrue(report["commission_is_assumed"])
        self.assertEqual(report["orders_sent"], 0)
        self.assertNotIn(settings["MT5_BRIDGE_TOKEN"], json.dumps(report))
        self.assertNotIn("login", report)
        self.assertEqual(settings["MT5_TIME_MODE"], "utc")
        self.assertEqual(report["clock"]["advancing_price_updates_verified"], 2)
        self.api.order_send.assert_not_called()
        self.api.shutdown.assert_called_once()

    def test_real_and_contest_accounts_fail_before_config_or_order(self):
        for mode in (1, 2):
            self.account.trade_mode = mode
            with self.subTest(mode=mode), self.assertRaisesRegex(ValueError, "not DEMO"):
                bootstrap.prepare_configuration({})
        self.api.order_send.assert_not_called()

    def test_disconnection_timeout_and_missing_permissions_fail_closed(self):
        self.terminal.connected = False
        with self.assertRaisesRegex(ValueError, "disconnected"):
            bootstrap.prepare_configuration({})
        self.terminal.connected = True
        self.api.initialize.return_value = False
        with self.assertRaisesRegex(ValueError, "-10005"):
            bootstrap.prepare_configuration({})
        self.api.initialize.return_value = True
        self.terminal.tradeapi_disabled = True
        with self.assertRaisesRegex(ValueError, "external Python"):
            bootstrap.prepare_configuration({})
        self.api.order_send.assert_not_called()

    def test_existing_exposure_and_ea_profile_block_second_controller(self):
        self.api.positions_get.return_value = (object(),)
        with self.assertRaisesRegex(ValueError, "already has"):
            bootstrap.prepare_configuration({})
        self.api.positions_get.return_value = ()
        with patch.object(bootstrap, "ea_in_saved_charts", return_value=True):
            with self.assertRaisesRegex(ValueError, "standalone"):
                bootstrap.prepare_configuration({})
        self.api.order_send.assert_not_called()

    def test_explicit_reserve_and_pin_change_preserve_no_old_password(self):
        values, report = bootstrap.prepare_configuration({"MT5_LOGIN": "456", "MT5_SERVER": "OLD", "MT5_PASSWORD": "old-password"}, 9)
        self.assertEqual(values["MT5_PASSWORD"], "")
        self.assertEqual(values["MT5_COMMISSION_PER_LOT"], "9.0")
        self.assertFalse(report["commission_is_assumed"])
        self.account.currency = "USC"
        with self.assertRaisesRegex(ValueError, "account's currency"):
            bootstrap.prepare_configuration({})
        values, _ = bootstrap.prepare_configuration({"MT5_COMMISSION_PER_LOT": "700"})
        self.assertEqual(values["MT5_COMMISSION_PER_LOT"], "700.0")

    def test_plain_env_file_is_not_evaluated_as_code(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "test.env"
            path.write_text("MT5_SERVER='TEST DEMO'\nMT5_SYMBOL=$(do-not-execute)\nOTHER=ignored\n", encoding="utf-8")
            settings = bootstrap.read_environment(path)
            self.assertEqual(settings["MT5_SYMBOL"], "$(do-not-execute)")
            self.assertEqual(settings["MT5_SERVER"], "TEST DEMO")
            self.assertNotIn("OTHER", settings)

    def test_safe_config_writer_rejects_newlines_before_replacing_file(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "test.env"
            path.write_text("unchanged", encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "unsupported"):
                bootstrap.save_configuration(path, {"MT5_SERVER": "bad\nMT5_ALLOW_LIVE=true"})
            self.assertEqual(path.read_text(encoding="utf-8"), "unchanged")

    def test_known_metaquotes_demo_wall_ticks_accept_only_the_named_eet_profile(self):
        self.account.company = "MetaQuotes Ltd."
        self.account.server = "MetaQuotes-Demo"
        self.api.symbol_info_tick.side_effect = lambda symbol: self.next_tick(7200)
        settings, report = bootstrap.prepare_configuration({})
        self.assertEqual(settings["MT5_TIME_MODE"], "server_wall")
        self.assertEqual(settings["MT5_BROKER_WINTER_UTC_MINUTES"], "120")
        self.assertEqual(settings["MT5_BROKER_SUMMER_UTC_MINUTES"], "180")
        self.assertEqual(settings["MT5_BROKER_DST_RULE"], "eu")
        self.assertEqual(settings["MT5_CLOCK_HISTORY_STATUS"], "HISTORICAL_DSTRULE_NOT_CERTIFIED")
        self.assertEqual(report["clock"]["profile_basis"], "empirical_current_ticks_plus_eet_convention")
        self.assertEqual(report["clock"]["normalized_quote_age_seconds"], 1)
        self.assertEqual(report["clock"]["normalized_tick_utc"], "2026-11-02T10:00:09+00:00")
        self.api.order_send.assert_not_called()

    def test_known_metaquotes_utc_ticks_are_not_double_shifted(self):
        self.account.company = "MetaQuotes Ltd."
        self.account.server = "MetaQuotes-Demo"
        values, report = bootstrap.prepare_configuration({})
        self.assertEqual(values["MT5_TIME_MODE"], "utc")
        self.assertEqual(values["MT5_BROKER_WINTER_UTC_MINUTES"], "0")
        self.assertEqual(report["clock"]["profile_basis"], "advancing_current_ticks_verified_as_utc")

    def test_frozen_quote_cannot_become_fresh_by_selecting_an_offset(self):
        self.account.company = "MetaQuotes Ltd."
        self.account.server = "MetaQuotes-Demo"
        frozen = self.next_tick(7200)
        self.api.symbol_info_tick.side_effect = None
        self.api.symbol_info_tick.return_value = frozen
        with self.assertRaisesRegex(ValueError, "cached or frozen"):
            bootstrap.prepare_configuration({})
        self.api.order_send.assert_not_called()

    def test_unknown_broker_and_wrong_named_profile_never_fit_an_arbitrary_offset(self):
        self.api.symbol_info_tick.side_effect = lambda symbol: self.next_tick(7200)
        with self.assertRaisesRegex(ValueError, "explicit MT5_TIME_MODE"):
            bootstrap.prepare_configuration({})
        self.account.company = "MetaQuotes Ltd."
        self.account.server = "MetaQuotes-Demo"
        self.api.symbol_info_tick.side_effect = lambda symbol: self.next_tick(3600)
        with self.assertRaisesRegex(ValueError, "Neither UTC nor"):
            bootstrap.prepare_configuration({})

    def test_explicit_clock_policy_is_preserved_and_wrong_policy_fails_closed(self):
        self.api.symbol_info_tick.side_effect = lambda symbol: self.next_tick(7200)
        profile = {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": "120",
                   "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu"}
        settings, report = bootstrap.prepare_configuration(profile)
        for key, value in profile.items():
            self.assertEqual(settings[key], value)
        self.assertEqual(report["clock"]["profile_basis"], "explicit_operator_profile_verified_with_advancing_current_ticks")
        self.account.company = "MetaQuotes Ltd."
        self.account.server = "MetaQuotes-Demo"
        with self.assertRaisesRegex(ValueError, "explicitly configured"):
            bootstrap.prepare_configuration({"MT5_TIME_MODE": "utc"})

    def test_stale_advancing_ticks_and_invalid_prices_do_not_verify_a_policy(self):
        self.api.symbol_info_tick.side_effect = lambda symbol: self.next_tick(-120)
        with self.assertRaisesRegex(ValueError, "explicit MT5_TIME_MODE"):
            bootstrap.prepare_configuration({})
        for invalid in (None, SimpleNamespace(bid=float('nan'), ask=2001, time=1, time_msc=1000),
                        SimpleNamespace(bid=2001, ask=2000, time=1, time_msc=1000),
                        SimpleNamespace(bid=2000, ask=2001, time=1000, time_msc=1)):
            self.api.symbol_info_tick.side_effect = None
            self.api.symbol_info_tick.return_value = invalid
            with self.subTest(invalid=invalid), self.assertRaisesRegex(ValueError, "consistent source tick"):
                bootstrap.prepare_configuration({})


if __name__ == "__main__":
    unittest.main()
