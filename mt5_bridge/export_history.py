"""Read-only VT Markets candle export for php artisan gold:backtest."""
import argparse
import json
from datetime import datetime, timezone
from pathlib import Path

import terminal


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--start", required=True, help="UTC date, e.g. 2022-01-01")
    parser.add_argument("--end", required=True, help="UTC date, exclusive")
    parser.add_argument("--output", required=True)
    args = parser.parse_args()
    start = datetime.fromisoformat(args.start).replace(tzinfo=timezone.utc)
    end = datetime.fromisoformat(args.end).replace(tzinfo=timezone.utc)
    if start >= end or end > datetime.now(timezone.utc):
        parser.error("Dates must be ordered and in the past")
    terminal.initialize_mt5()
    result = {"source": "mt5", "symbol": terminal.SYMBOL, "start": start.isoformat(), "end": end.isoformat()}
    for label, frame, seconds in (("m15", terminal.mt5.TIMEFRAME_M15, 900), ("h1", terminal.mt5.TIMEFRAME_H1, 3600)):
        rates = terminal.require_result(terminal.mt5.copy_rates_range(terminal.SYMBOL, frame, start, end), "historical candles")
        result[label] = [{"timestamp": datetime.fromtimestamp(int(row["time"]), timezone.utc).isoformat(),
                          **{key: float(row[key]) for key in ("open", "high", "low", "close")}}
                         for row in rates if int(row["time"]) + seconds <= end.timestamp()]
        if not result[label]:
            parser.error("No history; load the requested period in MT5 and raise Max bars in chart")
    # Exclusive create prevents accidentally replacing an existing data set.
    with Path(args.output).open("x", encoding="utf-8") as output:
        json.dump(result, output, allow_nan=False)
    print(f"Exported {len(result['m15'])} M15 and {len(result['h1'])} H1 bars. Verify date coverage before testing.")
    terminal.mt5.shutdown()


if __name__ == "__main__":
    main()
