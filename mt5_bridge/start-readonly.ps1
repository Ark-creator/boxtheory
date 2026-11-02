param(
    [string]$PythonPath = '',
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env')
)

$ErrorActionPreference = 'Stop'

# Parse plain KEY=value settings only. Never evaluate environment-file text as code.
if (Test-Path -LiteralPath $EnvFile -PathType Leaf) {
    foreach ($mt5Line in [System.IO.File]::ReadLines((Resolve-Path -LiteralPath $EnvFile).Path)) {
        if ($mt5Line -match '^\s*(MT5_[A-Z0-9_]+)\s*=(.*)$') {
            $mt5Name = $Matches[1]
            $mt5Value = $Matches[2].Trim()
            if ($mt5Value.Length -ge 2 -and
                (($mt5Value.StartsWith('"') -and $mt5Value.EndsWith('"')) -or
                 ($mt5Value.StartsWith("'") -and $mt5Value.EndsWith("'")))) {
                $mt5Value = $mt5Value.Substring(1, $mt5Value.Length - 2)
            }
            [Environment]::SetEnvironmentVariable($mt5Name, $mt5Value, 'Process')
        }
    }
}

# This launcher always keeps the bridge order endpoint disabled.
$env:MT5_ENABLE_EXECUTION = 'false'
$env:MT5_ALLOW_LIVE = 'false'
if ([string]::IsNullOrWhiteSpace($env:MT5_BRIDGE_HOST)) { $env:MT5_BRIDGE_HOST = '127.0.0.1' }
if ($env:MT5_BRIDGE_HOST -notin @('127.0.0.1', '::1', 'localhost')) {
    throw 'This launcher is for a local app connection. Configure a secured private connection separately for a remote Laravel host.'
}
if ([string]::IsNullOrWhiteSpace($env:MT5_BRIDGE_TOKEN) -or $env:MT5_BRIDGE_TOKEN.Length -lt 32) {
    throw 'Set MT5_BRIDGE_TOKEN to a random secret of at least 32 characters in mt5_bridge/.env. Do not post it in chat.'
}
if ($env:MT5_LOGIN -notmatch '^\d+$' -or [string]::IsNullOrWhiteSpace($env:MT5_SERVER)) {
    throw 'Set the exact intended MT5_LOGIN and MT5_SERVER in mt5_bridge/.env.'
}
if ([string]::IsNullOrWhiteSpace($env:MT5_SYMBOL)) { throw 'Set the exact Market Watch MT5_SYMBOL.' }
if ([string]::IsNullOrWhiteSpace($env:MT5_TERMINAL_PATH) -or
    !(Test-Path -LiteralPath $env:MT5_TERMINAL_PATH -PathType Leaf)) {
    throw 'Set MT5_TERMINAL_PATH to the intended terminal64.exe.'
}

if ([string]::IsNullOrWhiteSpace($PythonPath)) {
    $mt5ProjectPython = Join-Path $PSScriptRoot '.venv/Scripts/python.exe'
    if (Test-Path -LiteralPath $mt5ProjectPython -PathType Leaf) { $PythonPath = $mt5ProjectPython }
    else {
        $mt5PythonCommand = Get-Command python.exe -ErrorAction SilentlyContinue |
            Where-Object Source -NotLike '*\WindowsApps\*' | Select-Object -First 1
        if ($null -ne $mt5PythonCommand) { $PythonPath = $mt5PythonCommand.Source }
    }
}
if ([string]::IsNullOrWhiteSpace($PythonPath) -or !(Test-Path -LiteralPath $PythonPath -PathType Leaf)) {
    throw 'A working 64-bit Python is required. See docs/MT5_SCALPER_CONNECTION.md; use -PythonPath for an explicit interpreter.'
}
& $PythonPath -c "import importlib.util, struct, sys; sys.exit(0 if struct.calcsize('P')==8 and importlib.util.find_spec('MetaTrader5') is not None else 1)"
if ($LASTEXITCODE -ne 0) { throw 'This interpreter requires the 64-bit MetaTrader5 Python package. Install it with this interpreter -m pip install MetaTrader5.' }

Write-Host 'Starting local MT5 market-data bridge. Bridge order submission is disabled.'
Write-Host 'Keep MT5 logged into the configured account. Stop the bridge with Ctrl+C.'
& $PythonPath (Join-Path $PSScriptRoot 'mt5_bridge.py')
exit $LASTEXITCODE
