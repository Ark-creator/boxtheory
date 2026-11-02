# GoldLogic MT5 bridge

For RSI Gold Scalper V2 broker data and demo auto trading, use the [MT5 scalper connection guide](../docs/MT5_SCALPER_CONNECTION.md). `start-demo.ps1` verifies the local demo account before starting app execution; `start-readonly.ps1` keeps order submission disabled.

Start with the [VPS deployment and configuration guide](../docs/MT5_DEPLOYMENT.md) and [XAUUSD research and validation plan](../docs/XAUUSD_RESEARCH.md).

The bridge defaults to execution disabled and requires a pinned account, authentication token and current news coverage. Demo execution is supported; live accounts remain locked by default.

Run `py mt5_bridge/mt5_bridge.py` from the project directory after configuring the documented environment variables.
