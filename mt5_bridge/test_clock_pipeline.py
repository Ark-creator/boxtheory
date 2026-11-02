import os
import tempfile
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import Mock, patch

import terminal
from journal import Journal
from risk import Rejected

UTC = timezone.utc
PROFILE = {"MT5_TIME_MODE": "server_wall", "MT5_BROKER_WINTER_UTC_MINUTES": "120",
           "MT5_BROKER_SUMMER_UTC_MINUTES": "180", "MT5_BROKER_DST_RULE": "eu"}


class ClockPipelineTests(unittest.TestCase):
    def test_candles_preserve_raw_labels_and_apply_historical_eu_offsets(self):
        raw = [int(datetime(2026, 10, 23, 22, 55, tzinfo=UTC).timestamp()),
               int(datetime(2026, 11, 2, 17, 10, tzinfo=UTC).timestamp())]
        rows = [{"time": value, "open": 2000, "high": 2001, "low": 1999, "close": 2000} for value in raw]
        api = SimpleNamespace(copy_rates_from_pos=Mock(return_value=rows))
        with patch.dict(os.environ, PROFILE), patch.object(terminal, "mt5", api):
            bars = terminal.bars(5, 2)
        self.assertEqual([b["raw_time"] for b in bars], raw)
        self.assertEqual(bars[0]["timestamp"], "2026-10-23T19:55:00+00:00")
        self.assertEqual(bars[1]["timestamp"], "2026-11-02T15:10:00+00:00")

    def test_snapshot_receipt_clock_remains_utc_while_tick_and_bars_are_decoded(self):
        now = datetime(2026, 11, 2, 15, 25, 20, tzinfo=UTC)
        tick_time = int(now.timestamp()) + 7200 - 1
        tick = SimpleNamespace(bid=2000.0, ask=2000.2, time=tick_time, time_msc=tick_time * 1000 + 100)
        rows = [{"time": int(now.replace(minute=25, second=0).timestamp()) + 7200 - (204-i)*300,
                 "open": 2000, "high": 2001, "low": 1999, "close": 2000} for i in range(204)]
        api = SimpleNamespace(ACCOUNT_TRADE_MODE_DEMO=0, TIMEFRAME_M5=5, TIMEFRAME_M15=15, TIMEFRAME_H1=60,
                              symbol_info_tick=Mock(return_value=tick), positions_get=Mock(return_value=()),
                              orders_get=Mock(return_value=()), copy_rates_from_pos=Mock(return_value=rows))
        account = SimpleNamespace(login=123, server="DEMO", trade_mode=0, balance=10000, equity=10000, currency="USD")
        with patch.dict(os.environ, PROFILE), patch.object(terminal, "mt5", api), \
             patch.object(terminal, "initialize_mt5", return_value=(account, None)), \
             patch.object(terminal, "account_risk_check"), patch.object(terminal, "now_utc", return_value=now):
            result = terminal.snapshot()
        self.assertEqual(result["observed_at"], now.isoformat())
        self.assertEqual(result["quote"]["timestamp"], (now-timedelta(seconds=1)).isoformat())
        self.assertEqual(result["quote"]["raw_time"], tick_time)
        self.assertEqual(result["clock"]["mode"], "server_wall")
        self.assertEqual(result["m5"][-1]["timestamp"], "2026-11-02T15:20:00+00:00")

    def test_deal_query_and_daily_loss_use_same_utc_domain(self):
        now = datetime(2026, 11, 3, 0, 10, tzinfo=UTC)
        raw_time = int(datetime(2026, 11, 2, 22, 0, tzinfo=UTC).timestamp()) + 7200
        deal = SimpleNamespace(time=raw_time, type=0, entry=1, order=1,
                               profit=-200, commission=0, swap=0, fee=0)
        api = SimpleNamespace(history_deals_get=Mock(return_value=(deal,)), DEAL_TYPE_BUY=0, DEAL_TYPE_SELL=1,
                              DEAL_TYPE_BALANCE=2, DEAL_TYPE_CREDIT=3, DEAL_TYPE_BONUS=4,
                              DEAL_ENTRY_IN=0, DEAL_ENTRY_INOUT=2)
        account = SimpleNamespace(login=123, server="DEMO", balance=10000, equity=10000)
        with tempfile.TemporaryDirectory() as directory, patch.dict(os.environ, PROFILE), \
             patch.object(terminal, "mt5", api), patch.object(terminal, "JOURNAL", Journal(Path(directory)/"state.sqlite")):
            terminal.account_risk_check(account, {}, 0, now)
            self.assertEqual(terminal.JOURNAL.get(terminal.account_key(account))["day"], "")
        query_from, query_to = api.history_deals_get.call_args.args
        self.assertEqual(query_from, datetime(2026, 11, 2, 2, 0, tzinfo=UTC))
        self.assertEqual(query_to, datetime(2026, 11, 3, 2, 10, tzinfo=UTC))

    def test_source_and_final_submission_normalize_bars_and_ticks_without_faking_recency(self):
        now = datetime(2026, 11, 2, 15, 25, 20, tzinfo=UTC)
        issued = now.replace(second=0)
        account = SimpleNamespace(login=123, server="DEMO", trade_mode=0)
        tick = SimpleNamespace(bid=2000, ask=2000.2, time=int(now.timestamp())+7200-1)
        info = SimpleNamespace(point=0.01, trade_stops_level=0, trade_tick_size=0.01)
        row = {"time": int(issued.timestamp())+7200-300, "close": 2000}
        api = SimpleNamespace(ACCOUNT_TRADE_MODE_DEMO=0, TIMEFRAME_M5=5, account_info=Mock(return_value=account),
                              copy_rates_from_pos=Mock(return_value=[row]), positions_get=Mock(return_value=()),
                              orders_get=Mock(return_value=()), symbol_info=Mock(return_value=info),
                              symbol_info_tick=Mock(return_value=tick), order_send=Mock())
        payload = {"account_key": terminal.account_key(account), "signal_timestamp": issued.isoformat(),
                   "expires_at": (issued+timedelta(seconds=180)).isoformat(), "action": "buy",
                   "entry_price": 2000, "stop_loss": 1990, "take_profit_1": 2015}
        request = {"_bridge_checked_quote": (2000, 2000.2), "price": 2000.2, "sl": 1990, "tp": 2015}
        with patch.dict(os.environ, PROFILE), patch.object(terminal, "mt5", api), patch.object(terminal, "now_utc", return_value=now):
            terminal.rsi_source_close_check(payload)
            terminal.rsi_submission_check(payload, request)
            tick.time -= 91
            with self.assertRaises(Rejected):
                terminal.rsi_submission_check(payload, request)
        api.order_send.assert_not_called()


if __name__ == "__main__":
    unittest.main()
