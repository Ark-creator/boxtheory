import json
import sqlite3
import tempfile
import unittest
import threading
from http.client import HTTPConnection
from http.server import ThreadingHTTPServer
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import terminal
import news
from journal import Journal
from risk import Rejected, entry_checks, news_check, rsi_entry_checks, volume_for_risk


class BridgeTests(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.now = datetime(2026, 9, 14, 10, 0, 10, tzinfo=timezone.utc)
        self.payload = {"id": "5ebf037b-2723-462f-80ae-bdb6d171679e", "signal_key": "a" * 64,
                        "action": "buy", "entry_price": 2000, "stop_loss": 1995, "take_profit_1": 2010,
                        "entry_limit_price": 2000.25,
                        "signal_timestamp": self.now.replace(second=0).isoformat(),
                        "expires_at": (self.now + timedelta(seconds=170)).isoformat()}

    def test_journal_connections_close_after_commit_and_rollback(self):
        dbpath = Path(self.tmp.name) / "lifecycle.sqlite"
        durable = Journal(dbpath)
        with durable.connect() as db:
            db.execute("INSERT INTO state VALUES (?, ?)", ("committed", json.dumps({"saved": True})))
        with self.assertRaises(sqlite3.ProgrammingError):
            db.execute("SELECT 1")
        self.assertEqual(durable.get("committed"), {"saved": True})

        with self.assertRaisesRegex(RuntimeError, "abort transaction"):
            with durable.connect() as rolled_back:
                rolled_back.execute("INSERT INTO state VALUES (?, ?)", ("aborted", json.dumps(True)))
                raise RuntimeError("abort transaction")
        with self.assertRaises(sqlite3.ProgrammingError):
            rolled_back.execute("SELECT 1")
        self.assertIsNone(durable.get("aborted"))
        # A fresh instance must see the committed record after all handles close.
        self.assertEqual(Journal(dbpath).get("committed"), {"saved": True})

    def snapshot_api(self, m5_count=250, m5_unavailable=False):
        """Terminal-free native UTC bars and an independently timestamped tick."""
        tick_time = int((self.now - timedelta(seconds=3)).timestamp())

        def copy_rates(symbol, frame, start, count):
            if frame == 5 and m5_unavailable:
                return None
            length = m5_count if frame == 5 else count
            seconds = frame * 60
            latest_open = int(self.now.timestamp()) // seconds * seconds - seconds
            return [{"time": latest_open - (length - 1 - i) * seconds,
                     "open": 2000.0, "high": 2001.0, "low": 1999.0, "close": 2000.5}
                    for i in range(length)]

        api = SimpleNamespace(
            TIMEFRAME_M5=5, TIMEFRAME_M15=15, TIMEFRAME_H1=60, ACCOUNT_TRADE_MODE_DEMO=0,
            symbol_info_tick=Mock(return_value=SimpleNamespace(bid=2000.4, ask=2000.5, time=tick_time)),
            positions_get=Mock(return_value=()), orders_get=Mock(return_value=()),
            copy_rates_from_pos=Mock(side_effect=copy_rates),
            order_send=Mock(side_effect=AssertionError("Snapshot must never send an order")),
        )
        account = SimpleNamespace(login=123, server="unit-test-demo", equity=10000, balance=10000,
                                  currency="USD", trade_mode=0)
        return api, account, tick_time

    def test_snapshot_includes_sufficient_completed_m5_and_actual_tick_time(self):
        api, account, tick_time = self.snapshot_api()
        with patch.object(terminal, "mt5", api), \
                patch.object(terminal, "initialize_mt5", return_value=(account, None)), \
                patch.object(terminal, "account_risk_check"), \
                patch.object(terminal, "now_utc", return_value=self.now):
            data = terminal.snapshot()

        self.assertEqual(data["status"], "ok")
        self.assertEqual(data["source"], "mt5")
        self.assertIn("entry_extension_limit", data["capabilities"])
        self.assertIn("quote_timestamp", data["capabilities"])
        self.assertIn("m5_candles", data["capabilities"])
        self.assertIn("rsi_scalper_v2_demo_execution", data["capabilities"])
        self.assertEqual(len(data["m5"]), 250)
        api.copy_rates_from_pos.assert_any_call(terminal.SYMBOL, api.TIMEFRAME_M5, 1, 4096)
        api.copy_rates_from_pos.assert_any_call(terminal.SYMBOL, api.TIMEFRAME_M15, 1, 300)
        api.copy_rates_from_pos.assert_any_call(terminal.SYMBOL, api.TIMEFRAME_H1, 1, 300)
        self.assertEqual(data["m5"][-1]["timestamp"], "2026-09-14T09:55:00+00:00")
        self.assertEqual(data["quote"]["timestamp"], datetime.fromtimestamp(tick_time, timezone.utc).isoformat())
        self.assertNotEqual(data["quote"]["timestamp"], data["observed_at"])
        self.assertEqual(data["observed_at"], self.now.isoformat())
        self.assertEqual(data["quote"]["bid"], 2000.4)
        self.assertEqual(data["quote"]["ask"], 2000.5)
        self.assertTrue(all(datetime.fromisoformat(bar["timestamp"]) + timedelta(minutes=5) <= self.now
                            for bar in data["m5"]))
        self.assertEqual([bar["timestamp"] for bar in data["m5"]], sorted(bar["timestamp"] for bar in data["m5"]))
        api.order_send.assert_not_called()

    def test_snapshot_m5_unavailable_empty_or_short_fails_closed_without_orders(self):
        for count, unavailable in ((0, True), (0, False), (202, False)):
            with self.subTest(count=count, unavailable=unavailable):
                api, account, _ = self.snapshot_api(m5_count=count, m5_unavailable=unavailable)
                with patch.object(terminal, "mt5", api), \
                        patch.object(terminal, "initialize_mt5", return_value=(account, None)), \
                        patch.object(terminal, "account_risk_check"), \
                        patch.object(terminal, "now_utc", return_value=self.now):
                    with self.assertRaisesRegex(Rejected, "candles|203"):
                        terminal.snapshot()
                api.order_send.assert_not_called()

    def test_snapshot_accepts_exactly_203_closed_m5_bars_without_trading_permissions(self):
        api, account, _ = self.snapshot_api(m5_count=203)
        # No trade_allowed/trade_expert/terminal permissions are supplied: a
        # market-data snapshot must not require enabling algorithmic execution.
        with patch.object(terminal, "mt5", api), \
                patch.object(terminal, "initialize_mt5", return_value=(account, None)), \
                patch.object(terminal, "account_risk_check"), \
                patch.object(terminal, "now_utc", return_value=self.now), \
                patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "false", "MT5_ALLOW_LIVE": "false"}):
            data = terminal.snapshot()
        self.assertEqual(len(data["m5"]), 203)
        self.assertTrue(data["account"]["demo"])
        api.order_send.assert_not_called()

    def test_volume_rounds_down_and_cent_units_are_consistent(self):
        lots, risk = volume_for_risk(10000, .25, 500, .01, 100, .01, 7)
        self.assertEqual(lots, .04)
        self.assertLessEqual(risk, 22.5)
        self.assertEqual(volume_for_risk(1000000, .25, 50000, .01, 100, .01, 700)[0], lots)

    def test_below_minimum_never_rounds_up(self):
        with self.assertRaises(Rejected):
            volume_for_risk(100, .25, 500, .01, 100, .01, 7)
        for invalid in (float('nan'), float('inf'), -1, 0):
            with self.assertRaises(Rejected):
                volume_for_risk(10000, invalid, 500, .01, 100, .01, 7)

    def test_entry_rejects_expiry_stale_quote_spread_drift_and_bad_stop(self):
        def check(p=None, bid=1999.9, ask=2000.1, tick=None):
            return entry_checks(p or self.payload, bid, ask, tick or self.now.timestamp(), self.now, .01, .05)
        self.assertEqual(check()[1:], (1995, 2010))
        for kwargs in ({"p": {**self.payload, "expires_at": self.now.isoformat()}},
                       {"tick": self.now.timestamp() - 31}, {"bid": 1999, "ask": 2000.1},
                       {"bid": 2000.7, "ask": 2000.8}, {"p": {**self.payload, "stop_loss": 2001}}):
            with self.assertRaises(Rejected):
                check(**kwargs)

    def test_news_missing_stale_and_blackout_fail_closed(self):
        path = Path(self.tmp.name) / "news.json"
        with self.assertRaises(Rejected):
            news_check(path, self.now)
        data = {"updated_at": self.now.isoformat(), "coverage_start": (self.now - timedelta(hours=1)).isoformat(),
                "coverage_end": (self.now + timedelta(hours=1)).isoformat(), "events": []}
        path.write_text(json.dumps(data))
        news_check(path, self.now)
        data["events"] = [{"time": self.now.isoformat(), "currency": "USD", "impact": "high"}]
        path.write_text(json.dumps(data))
        with self.assertRaises(Rejected):
            news_check(path, self.now)
        data["events"] = []
        data["updated_at"] = (self.now - timedelta(days=2)).isoformat()
        path.write_text(json.dumps(data))
        with self.assertRaises(Rejected):
            news_check(path, self.now)

    def test_entry_extension_checks_buy_ask_and_sell_bid_inclusively(self):
        for side in ('buy', 'sell'):
            payload = {**self.payload, 'action': side}
            if side == 'sell':
                payload.update(entry_limit_price=1999.75, stop_loss=2005, take_profit_1=1990)
            bid, ask = (2000.15, 2000.25) if side == 'buy' else (1999.75, 1999.85)
            with self.subTest(side=side):
                result = entry_checks(payload, bid, ask, self.now.timestamp(), self.now, .01, .05)
                self.assertEqual(result[0], payload['entry_limit_price'])
                bid, ask = (2000.16, 2000.26) if side == 'buy' else (1999.74, 1999.84)
                with self.assertRaisesRegex(Rejected, 'entry extension'):
                    entry_checks(payload, bid, ask, self.now.timestamp(), self.now, .01, .05)

    def test_entry_extension_metadata_is_required_and_finite(self):
        for value in (None, '', 0, -1, float('nan'), float('inf')):
            with self.subTest(value=value), self.assertRaisesRegex(Rejected, 'entry limit'):
                entry_checks({**self.payload, 'entry_limit_price': value}, 1999.9, 2000.1, self.now.timestamp(), self.now, .01, .05)
        # A retracing quote must not rescue a signal that was already out of bounds.
        with self.assertRaisesRegex(Rejected, 'entry extension'):
            entry_checks({**self.payload, 'entry_price': 2000.3}, 1999.9, 2000.1, self.now.timestamp(), self.now, .01, .05)

    def test_duplicate_id_and_duplicate_signal_submit_once_across_restart(self):
        dbpath = Path(self.tmp.name) / "journal.sqlite"
        api = SimpleNamespace(TRADE_RETCODE_DONE=10009, order_send=lambda request: SimpleNamespace(retcode=10009, comment="Done", order=123, deal=456, volume=.01, price=2000))
        with patch.object(terminal, "JOURNAL", Journal(dbpath)), patch.object(terminal, "mt5", api), patch.object(terminal, "prepare_trade", return_value={}) as prepare:
            first = terminal.execute_trade(self.payload)
            terminal.JOURNAL = Journal(dbpath)
            self.assertEqual(terminal.execute_trade(self.payload), first)
            self.assertEqual(terminal.execute_trade({**self.payload, "id": "7ebf037b-2723-462f-80ae-bdb6d171679e"}), first)
            prepare.assert_called_once()

    def test_unknown_outcome_is_durable_and_never_retried(self):
        with patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / "journal.sqlite")), patch.object(terminal, "mt5", SimpleNamespace(order_send=lambda request: None)), patch.object(terminal, "prepare_trade", return_value={}) as prepare:
            self.assertEqual(terminal.execute_trade(self.payload)["status"], "unknown")
            self.assertEqual(terminal.execute_trade(self.payload)["status"], "unknown")
            self.assertTrue(terminal.journal().unresolved())
            prepare.assert_called_once()

    def test_filling_mode_uses_symbol_flags(self):
        api = SimpleNamespace(ORDER_FILLING_FOK=0, ORDER_FILLING_IOC=1, ORDER_FILLING_RETURN=2, SYMBOL_TRADE_EXECUTION_MARKET=2)
        with patch.object(terminal, "mt5", api):
            self.assertEqual(terminal.filling_mode(SimpleNamespace(filling_mode=2, trade_exemode=2)), 1)
            with self.assertRaises(Rejected):
                terminal.filling_mode(SimpleNamespace(filling_mode=0, trade_exemode=2))

    def test_daily_budget_includes_floating_loss_and_new_stop_risk(self):
        api = SimpleNamespace(history_deals_get=lambda *args: [], DEAL_TYPE_BUY=0, DEAL_TYPE_SELL=1, DEAL_ENTRY_IN=0, DEAL_ENTRY_INOUT=2)
        account = SimpleNamespace(equity=9920, balance=10000, login=123, server="demo")
        with patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / "journal.sqlite")), patch.object(terminal, "mt5", api):
            with self.assertRaises(Rejected):
                terminal.account_risk_check(account, {}, 25, self.now)
            terminal.account_risk_check(account, {}, 10, self.now)

    def test_live_account_is_locked_without_explicit_opt_in(self):
        account = SimpleNamespace(trade_mode=2)
        with patch.object(terminal, "initialize_mt5", return_value=(account, None)), patch.object(terminal, "mt5", SimpleNamespace(ACCOUNT_TRADE_MODE_DEMO=0)), patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_ALLOW_LIVE": "false"}):
            with self.assertRaisesRegex(Rejected, "Live accounts"):
                terminal.prepare_trade(self.payload)

    def test_news_remote_refresh_failure_does_not_reuse_expired_cache(self):
        with patch.dict('os.environ', {"MT5_NEWS_URL": "https://calendar.example.test/feed"}), patch.object(news, "_cached", {"events": []}), patch.object(news, "_cached_url", "https://calendar.example.test/feed"), patch.object(news, "_fetched_at", self.now - timedelta(minutes=6)), patch.object(news, "build_opener", side_effect=OSError("offline")):
            with self.assertRaises(Rejected):
                news.fetch_calendar(self.now)

    def test_news_refuses_plain_http_and_redirects(self):
        with patch.dict('os.environ', {"MT5_NEWS_URL": "http://calendar.example.test/feed"}):
            with self.assertRaises(Rejected):
                news.fetch_calendar(self.now)
        with self.assertRaises(Rejected):
            news.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://elsewhere.test')

    def test_http_requires_authentication_before_accessing_mt5(self):
        server = ThreadingHTTPServer(('127.0.0.1', 0), terminal.Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            with patch.object(terminal, 'TOKEN', 'x' * 32), patch.object(terminal, 'snapshot') as snapshot:
                conn = HTTPConnection('127.0.0.1', server.server_port, timeout=3)
                conn.request('GET', '/snapshot')
                response = conn.getresponse()
                self.assertEqual(response.status, 401)
                response.read()
                conn.close()
                snapshot.assert_not_called()
        finally:
            server.shutdown()
            server.server_close()
            thread.join(timeout=3)

    def test_daily_halt_latches_and_drawdown_survives_restart(self):
        path = Path(self.tmp.name) / 'journal.sqlite'
        api = SimpleNamespace(history_deals_get=lambda *args: [], DEAL_TYPE_BUY=0, DEAL_TYPE_SELL=1, DEAL_ENTRY_IN=0, DEAL_ENTRY_INOUT=2)
        account = SimpleNamespace(equity=10000, balance=10000, login=123, server='demo')
        with patch.object(terminal, 'JOURNAL', Journal(path)), patch.object(terminal, 'mt5', api):
            terminal.account_risk_check(account, {}, 0, self.now)
            account.equity = 9490
            with self.assertRaises(Rejected):
                terminal.account_risk_check(account, {}, 0, self.now)
            terminal.JOURNAL = Journal(path)
            account.equity = 10000
            with self.assertRaises(Rejected):
                terminal.account_risk_check(account, {}, 0, self.now + timedelta(days=8))

    def rsi_payload(self, side="buy"):
        return {**self.payload, "strategy": "rsi-gold-scalper-v2", "strategy_slug": "rsi-gold-scalper-v2",
                "strategy_version": 2, "exit_policy": "full_tp1", "demo_only": True,
                "freshness": {"source": "mt5"}, "symbol": terminal.SYMBOL,
                "account_key": terminal.account_key(SimpleNamespace(login=123, server="unit-test-demo")),
                "magic": terminal.MAGIC, "risk_percent": .25, "action": side,
                "stop_loss": 1995 if side == "buy" else 2005,
                "take_profit_1": 2007.5 if side == "buy" else 1992.5,
                "entry_limit_price": None}

    def rsi_api(self, payload):
        account = SimpleNamespace(login=123, server="unit-test-demo", trade_mode=0, equity=10000,
                                  balance=10000, currency="USD", margin_free=10000,
                                  trade_allowed=True, trade_expert=True)
        terminal_info = SimpleNamespace(trade_allowed=True, tradeapi_disabled=False)
        info = SimpleNamespace(currency_base="XAU", currency_profit="USD", point=.01,
                               trade_tick_size=.01, trade_stops_level=5, volume_min=.01,
                               volume_max=100, volume_step=.01, filling_mode=1, trade_exemode=2)
        tick = SimpleNamespace(bid=1999.9 if payload["action"] == "buy" else 2000,
                               ask=2000 if payload["action"] == "buy" else 2000.1,
                               time=self.now.timestamp())
        api = SimpleNamespace(
            ACCOUNT_TRADE_MODE_DEMO=0, TIMEFRAME_M5=5, ORDER_TYPE_BUY=0, ORDER_TYPE_SELL=1,
            ORDER_FILLING_FOK=0, ORDER_FILLING_IOC=1, ORDER_FILLING_RETURN=2,
            SYMBOL_TRADE_EXECUTION_MARKET=2, TRADE_ACTION_DEAL=1, ORDER_TIME_GTC=0,
            TRADE_RETCODE_DONE=10009, TRADE_RETCODE_DONE_PARTIAL=10010,
            positions_get=Mock(return_value=()), orders_get=Mock(return_value=()),
            account_info=Mock(return_value=account), symbol_info=Mock(return_value=info),
            symbol_info_tick=Mock(return_value=tick),
            copy_rates_from_pos=Mock(return_value=[{"time": self.now.timestamp() // 300 * 300 - 300, "close": 2000}]),
            order_calc_profit=Mock(side_effect=lambda side, symbol, lots, entry, stop: (stop - entry) * (1 if side == 0 else -1) * 100 * lots),
            order_calc_margin=Mock(return_value=100),
            order_check=Mock(return_value=SimpleNamespace(retcode=0, comment="Done")),
            order_send=Mock(return_value=SimpleNamespace(retcode=10009, comment="Done", order=123,
                                                       deal=456, volume=.04, price=2000)),
        )
        return api, account, terminal_info

    def test_rsi_protocol_is_strict_and_missing_strategy_remains_legacy(self):
        self.assertEqual(terminal.strategy_protocol({}), "gold-risk-breakout")
        self.assertEqual(terminal.strategy_protocol(self.rsi_payload()), "rsi-gold-scalper-v2")
        for changes in ({"strategy_version": "2"}, {"strategy_version": True}, {"strategy_version": 2.0},
                        {"exit_policy": "full_tp2"}, {"demo_only": "true"}, {"demo_only": False},
                        {"freshness": {"source": "twelvedata"}}, {"strategy_slug": "rsi-scalper"},
                        {"strategy": "gold-risk-breakout"}):
            with self.subTest(changes=changes), patch.object(terminal, "initialize_mt5") as initialize:
                with self.assertRaises(Rejected):
                    terminal.prepare_trade({**self.rsi_payload(), **changes})
                initialize.assert_not_called()

    def test_rsi_uses_original_one_point_five_r_not_breakout_reward_or_extension_rules(self):
        for side in ("buy", "sell"):
            payload = self.rsi_payload(side)
            bid, ask = (2000.39, 2000.49) if side == "buy" else (1999.51, 1999.61)
            price, sl, tp = rsi_entry_checks(payload, bid, ask, self.now.timestamp(), self.now, .01, .05)
            self.assertEqual(sl, payload["stop_loss"])
            self.assertEqual(tp, payload["take_profit_1"])
            self.assertLess(abs(tp - price) / abs(price - sl), 1.8)
            with self.assertRaisesRegex(Rejected, "0.1R"):
                rsi_entry_checks(payload, 2000.50 if side == "buy" else 1999.49,
                                 2000.51 if side == "buy" else 1999.59,
                                 self.now.timestamp(), self.now, .01, .05)
        with self.assertRaisesRegex(Rejected, "1.5R"):
            rsi_entry_checks({**self.rsi_payload(), "take_profit_1": 2010}, 1999.9, 2000,
                             self.now.timestamp(), self.now, .01, .05)
        with self.assertRaisesRegex(Rejected, "second target"):
            rsi_entry_checks({**self.rsi_payload(), "take_profit_2": 2012.5}, 1999.9, 2000,
                             self.now.timestamp(), self.now, .01, .05)

    def test_rsi_quote_age_and_latest_m5_expiry_are_exclusive(self):
        for age in (0, 90):
            rsi_entry_checks(self.rsi_payload(), 1999.9, 2000, self.now.timestamp() - age, self.now, .01, .05)
        cases = [(self.rsi_payload(), self.now, self.now.timestamp() - 91),
                 (self.rsi_payload(), self.now, self.now.timestamp() + 1),
                 (self.rsi_payload(), self.now.replace(minute=3, second=0), self.now.replace(minute=3, second=0).timestamp()),
                 ({**self.rsi_payload(), "signal_timestamp": self.now.replace(minute=1, second=0).isoformat()}, self.now, self.now.timestamp()),
                 ({**self.rsi_payload(), "signal_timestamp": (self.now - timedelta(minutes=5)).replace(second=0).isoformat()}, self.now, self.now.timestamp()),
                 ({**self.rsi_payload(), "expires_at": (self.now + timedelta(seconds=169)).isoformat()}, self.now, self.now.timestamp()),
                 (self.rsi_payload(), self.now.replace(hour=17), self.now.replace(hour=17).timestamp()),
                 (self.rsi_payload(), self.now + timedelta(days=6), (self.now + timedelta(days=6)).timestamp())]
        for payload, at, tick in cases:
            with self.subTest(at=at, tick=tick), self.assertRaises(Rejected):
                rsi_entry_checks(payload, 1999.9, 2000, tick, at, .01, .05)

    def test_rsi_grid_is_conservative_and_stop_rules_use_bid_ask(self):
        for side in ("buy", "sell"):
            direction = 1 if side == "buy" else -1
            payload = {**self.rsi_payload(side), "stop_loss": 2000 - direction * 5.0001,
                       "take_profit_1": 2000 + direction * 7.5002}
            bid, ask = (1999.9, 2000) if side == "buy" else (2000, 2000.1)
            _, stop, target = rsi_entry_checks(payload, bid, ask, self.now.timestamp(), self.now, .01, .05)
            self.assertGreaterEqual(direction * (payload["stop_loss"] - stop), 0)
            self.assertGreaterEqual(direction * (payload["take_profit_1"] - target), 0)
            with self.assertRaisesRegex(Rejected, "stop-distance"):
                rsi_entry_checks(payload, bid, ask, self.now.timestamp(), self.now, .01, 5.1)
        for grid in (0, -1, float('nan')):
            with self.assertRaises(Rejected):
                rsi_entry_checks(self.rsi_payload(), 1999.9, 2000, self.now.timestamp(), self.now, grid, .05)

    def test_rsi_live_and_contest_are_locked_even_when_live_opt_in_is_true(self):
        payload = self.rsi_payload()
        api, account, info = self.rsi_api(payload)
        for mode in (1, 2):
            account.trade_mode = mode
            with self.subTest(mode=mode), patch.object(terminal, "mt5", api), \
                    patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                    patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_ALLOW_LIVE": "true"}):
                with self.assertRaisesRegex(Rejected, "DEMO-only"):
                    terminal.prepare_trade(payload)
        api.order_send.assert_not_called()

    def test_rsi_demo_prepare_uses_source_m5_and_skips_only_the_legacy_news_gate(self):
        for side in ("buy", "sell"):
            payload = self.rsi_payload(side)
            api, account, info = self.rsi_api(payload)
            with self.subTest(side=side), patch.object(terminal, "mt5", api), \
                    patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                    patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / (side + '.sqlite'))), \
                    patch.object(terminal, "account_risk_check") as risk, \
                    patch.object(terminal, "news_check") as news_gate, \
                    patch.object(terminal, "fetch_calendar") as calendar, \
                    patch.object(terminal, "now_utc", return_value=self.now), \
                    patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_COMMISSION_PER_LOT": "0"}):
                request = terminal.prepare_trade(payload)
            self.assertEqual(request["tp"], payload["take_profit_1"])
            self.assertEqual(request["sl"], payload["stop_loss"])
            self.assertEqual(request["type"], api.ORDER_TYPE_BUY if side == "buy" else api.ORDER_TYPE_SELL)
            self.assertGreater(request["volume"], 0)
            api.copy_rates_from_pos.assert_called_once_with(terminal.SYMBOL, api.TIMEFRAME_M5, 1, 1)
            risk.assert_called_once()
            news_gate.assert_not_called()
            calendar.assert_not_called()
            api.order_send.assert_not_called()

    def test_rsi_requires_the_actual_source_m5_close(self):
        for bars in (None, [], [{"time": self.now.timestamp() // 300 * 300 - 600, "close": 2000}],
                     [{"time": self.now.timestamp() // 300 * 300 - 300, "close": 2000.01}]):
            payload = self.rsi_payload()
            api, account, info = self.rsi_api(payload)
            api.copy_rates_from_pos.return_value = bars
            with self.subTest(bars=bars), patch.object(terminal, "mt5", api), \
                    patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                    patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / 'source.sqlite')), \
                    patch.object(terminal, "now_utc", return_value=self.now), \
                    patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true"}):
                with self.assertRaises(Rejected):
                    terminal.prepare_trade(payload)
            api.order_check.assert_not_called()
            api.order_send.assert_not_called()

    def test_rsi_rechecks_quote_time_and_demo_account_after_order_check(self):
        for defect in ("quote", "expiry", "account"):
            payload = self.rsi_payload()
            api, account, info = self.rsi_api(payload)
            if defect == "quote":
                old = api.symbol_info_tick.return_value
                api.symbol_info_tick.side_effect = [old, SimpleNamespace(bid=old.bid - .01, ask=old.ask, time=old.time)]
            if defect == "account":
                api.account_info.return_value = SimpleNamespace(login=123, server="unit-test-demo", trade_mode=2)
            times = [self.now, self.now.replace(minute=3, second=0)] if defect == "expiry" else None
            with self.subTest(defect=defect), patch.object(terminal, "mt5", api), \
                    patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                    patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / (defect + '.sqlite'))), \
                    patch.object(terminal, "account_risk_check"), \
                    patch.object(terminal, "now_utc", side_effect=times, return_value=self.now), \
                    patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_COMMISSION_PER_LOT": "0"}):
                with self.assertRaises(Rejected):
                    terminal.prepare_trade(payload)
            api.order_check.assert_called_once()
            api.order_send.assert_not_called()

    def test_rsi_durable_reservation_cannot_send_after_expiry_or_be_retried(self):
        payload = self.rsi_payload()
        api, account, info = self.rsi_api(payload)
        durable = Journal(Path(self.tmp.name) / 'expiry.sqlite')
        with patch.object(terminal, "mt5", api), patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                patch.object(terminal, "JOURNAL", durable), patch.object(terminal, "account_risk_check"), \
                patch.object(terminal, "now_utc", side_effect=[self.now, self.now, self.now.replace(minute=3, second=0)]), \
                patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_COMMISSION_PER_LOT": "0"}):
            result = terminal.execute_trade(payload)
            self.assertEqual(result["status"], "failed")
            self.assertEqual(terminal.execute_trade(payload), result)
        api.order_send.assert_not_called()
        self.assertFalse(durable.unresolved())

    def test_rsi_demo_submission_is_mocked_and_remains_at_most_once(self):
        payload = self.rsi_payload()
        api, account, info = self.rsi_api(payload)
        with patch.object(terminal, "mt5", api), patch.object(terminal, "initialize_mt5", return_value=(account, info)), \
                patch.object(terminal, "JOURNAL", Journal(Path(self.tmp.name) / 'submitted.sqlite')), \
                patch.object(terminal, "account_risk_check"), patch.object(terminal, "now_utc", return_value=self.now), \
                patch.dict('os.environ', {"MT5_ENABLE_EXECUTION": "true", "MT5_COMMISSION_PER_LOT": "0"}):
            result = terminal.execute_trade(payload)
            self.assertEqual(result["status"], "executed")
            self.assertEqual(terminal.execute_trade(payload), result)
        api.order_send.assert_called_once()
        broker_request = api.order_send.call_args.args[0]
        self.assertNotIn("_bridge_checked_quote", broker_request)
        self.assertEqual(broker_request["tp"], payload["take_profit_1"])


if __name__ == "__main__":
    unittest.main()
