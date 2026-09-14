import json
import tempfile
import unittest
import threading
from http.client import HTTPConnection
from http.server import ThreadingHTTPServer
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

import terminal
import news
from journal import Journal
from risk import Rejected, entry_checks, news_check, volume_for_risk


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


if __name__ == "__main__":
    unittest.main()
