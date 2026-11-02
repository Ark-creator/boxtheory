"""Read-only VT Markets candle export for php artisan gold:backtest."""
import argparse
import json
import os
from datetime import datetime, timezone
from pathlib import Path

import terminal
from clock import ClockPolicy


def export_history(start, end, output_path):
    """Read broker candles into a new file using the explicit source-clock policy.

    SDK query datetimes carry encoded source labels. Returned records are decoded
    individually (including their historical DST date), then clipped to the
    original UTC interval. No prior CSV or research file is read or relabelled.
    """
    if start.tzinfo is None or end.tzinfo is None:
        raise ValueError("Export bounds require explicit UTC-aware datetimes")
    start, end = start.astimezone(timezone.utc), end.astimezone(timezone.utc)
    if start >= end or end > terminal.now_utc():
        raise ValueError("Dates must be ordered and in the past")
    path = Path(output_path)
    if path.exists():
        raise FileExistsError("The output already exists; historical exports are never overwritten")
    policy = ClockPolicy.from_environment()
    query_from, query_to = policy.query_bounds(start, end, domain="bar")
    terminal.initialize_mt5()
    try:
        result = {"source": "mt5", "symbol": terminal.SYMBOL, "start": start.isoformat(), "end": end.isoformat(),
                  "timestamp_assumption": "UTC after the explicit configured clock policy; raw_time retains source clock labels. Historical clock interpretation is not independently certified.",
                  "clock": {**policy.describe(), "profile_basis": os.getenv("MT5_CLOCK_PROFILE_BASIS", "explicit_configuration"),
                            "history_status": os.getenv("MT5_CLOCK_HISTORY_STATUS", "HISTORICAL_BAR_AND_DEAL_CLOCK_NOT_CERTIFIED"),
                            "sdk_query_from_raw_epoch": query_from.timestamp(), "sdk_query_to_raw_epoch": query_to.timestamp()}}
        for label, frame, seconds in (("m15", terminal.mt5.TIMEFRAME_M15, 900), ("h1", terminal.mt5.TIMEFRAME_H1, 3600)):
            rates = terminal.require_result(terminal.mt5.copy_rates_range(terminal.SYMBOL, frame, query_from, query_to), "historical candles")
            bars = []
            for row in rates:
                raw = int(row["time"])
                normalized = policy.normalize(raw, domain="bar")
                if start.timestamp() <= normalized and normalized + seconds <= end.timestamp():
                    bars.append({"timestamp": datetime.fromtimestamp(normalized, timezone.utc).isoformat(), "raw_time": raw,
                                 **{key: float(row[key]) for key in ("open", "high", "low", "close")}})
            if not bars:
                raise ValueError("No complete history in the UTC interval; load the period in MT5 and raise Max bars in chart")
            result[label] = bars
        # Serialize before exclusive creation, so invalid prices cannot leave a
        # seemingly usable partial JSON file. Exclusive create also closes races.
        encoded = json.dumps(result, allow_nan=False)
        with path.open("x", encoding="utf-8") as output:
            output.write(encoded)
        return result
    finally:
        terminal.mt5.shutdown()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--start", required=True, help="UTC date, e.g. 2022-01-01")
    parser.add_argument("--end", required=True, help="UTC date, exclusive")
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    try:
        start = datetime.fromisoformat(args.start)
        end = datetime.fromisoformat(args.end)
        if start.tzinfo is None:
            start = start.replace(tzinfo=timezone.utc)
        if end.tzinfo is None:
            end = end.replace(tzinfo=timezone.utc)
        result = export_history(start, end, args.output)
    except (ValueError, OSError) as exc:
        parser.error(str(exc))
    print(f"Exported {len(result['m15'])} M15 and {len(result['h1'])} H1 bars. Verify date coverage before testing.")


if __name__ == "__main__":
    main()
