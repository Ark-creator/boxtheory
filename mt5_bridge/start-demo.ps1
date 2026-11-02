param(
    [string]$PythonPath = (Join-Path $PSScriptRoot '.venv/Scripts/python.exe'),
    [string]$PhpPath = '',
    [string]$EnvFile = (Join-Path $PSScriptRoot '.env'),
    [Nullable[double]]$CommissionPerLot = $null
)

$ErrorActionPreference = 'Stop'
$mt5ProjectRoot = Split-Path -Parent $PSScriptRoot
if (!(Test-Path -LiteralPath $PythonPath -PathType Leaf)) {
    throw 'Install the project-local Python/MetaTrader5 environment or supply -PythonPath. See docs/MT5_SCALPER_CONNECTION.md.'
}
if ([string]::IsNullOrWhiteSpace($PhpPath)) {
    $mt5PhpCommand = Get-Command php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($null -ne $mt5PhpCommand) { $PhpPath = $mt5PhpCommand.Source }
}
if ([string]::IsNullOrWhiteSpace($PhpPath) -or !(Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
    throw 'Supply -PhpPath with the PHP executable used for this Laravel app.'
}
if (@(Get-Process terminal64 -ErrorAction SilentlyContinue).Count -eq 0) {
    throw 'Open MT5 and log into a connected demo account before starting.'
}

# One local controller; do not run a second polling loop on another terminal.
$mt5Mutex = New-Object System.Threading.Mutex($false, 'Local\GoldLogicRsiDemoController')
$mt5OwnMutex = $false
$mt5OwnedBridge = $null
$mt5Configured = $false
$mt5StopEvent = $null
try {
    try { $mt5OwnMutex = $mt5Mutex.WaitOne(0) }
    catch [System.Threading.AbandonedMutexException] { $mt5OwnMutex = $true }
    if (!$mt5OwnMutex) { throw 'The local demo controller is already running.' }
    $mt5StopEvent = New-Object System.Threading.EventWaitHandle -ArgumentList $false, ([System.Threading.EventResetMode]::ManualReset), 'Local\GoldLogicRsiDemoStop'
    $mt5StopEvent.Reset() | Out-Null
    Push-Location -LiteralPath $mt5ProjectRoot
    try {
        $mt5BootstrapArgs = @((Join-Path $PSScriptRoot 'bootstrap_demo.py'), '--env-file', $EnvFile)
        if ($null -ne $CommissionPerLot) {
            $mt5BootstrapArgs += @('--commission-per-lot', ([double]$CommissionPerLot).ToString([Globalization.CultureInfo]::InvariantCulture))
        }
        & $PythonPath @mt5BootstrapArgs
        if ($LASTEXITCODE -ne 0) { throw 'Demo account preparation failed. No app automation was enabled.' }
        if ($mt5StopEvent.WaitOne(0)) { Write-Host 'Demo startup stopped before enabling app entries.'; return }
        foreach ($mt5Line in [IO.File]::ReadLines((Resolve-Path -LiteralPath $EnvFile).Path)) {
            if ($mt5Line -match '^\s*(MT5_[A-Z0-9_]+)\s*=(.*)$') {
                $mt5Key = $Matches[1]
                $mt5Value = $Matches[2].Trim()
                if ($mt5Value.Length -ge 2 -and
                    (($mt5Value.StartsWith("'") -and $mt5Value.EndsWith("'")) -or
                     ($mt5Value.StartsWith('"') -and $mt5Value.EndsWith('"')))) {
                    $mt5Value = $mt5Value.Substring(1, $mt5Value.Length - 2)
                }
                [Environment]::SetEnvironmentVariable($mt5Key, $mt5Value, 'Process')
            }
        }
        $env:MT5_ALLOW_LIVE = 'false'
        $env:MT5_DEMO_ONLY = 'true'
        $env:MT5_ENABLE_EXECUTION = 'true'
        $env:MT5_BRIDGE_HOST = '127.0.0.1'
        $mt5BaseUrl = 'http://127.0.0.1:' + $env:MT5_BRIDGE_PORT
        if (Get-NetTCPConnection -LocalPort ([int]$env:MT5_BRIDGE_PORT) -State Listen -ErrorAction SilentlyContinue) {
            throw 'The bridge port is already in use. Stop the existing bridge before starting this controller.'
        }
        $mt5LogRoot = Join-Path $mt5ProjectRoot 'storage/logs'
        New-Item -ItemType Directory -Path $mt5LogRoot -Force | Out-Null
        $mt5BridgeScriptArg = '"{0}"' -f (Join-Path $PSScriptRoot 'mt5_bridge.py')
        $mt5OwnedBridge = Start-Process -FilePath $PythonPath -ArgumentList $mt5BridgeScriptArg `
            -WorkingDirectory $mt5ProjectRoot -WindowStyle Hidden -PassThru `
            -RedirectStandardOutput (Join-Path $mt5LogRoot 'mt5-demo-bridge.log') `
            -RedirectStandardError (Join-Path $mt5LogRoot 'mt5-demo-bridge-error.log')
        $mt5Healthy = $false
        for ($mt5Attempt = 0; $mt5Attempt -lt 10; $mt5Attempt++) {
            if ($mt5StopEvent.WaitOne(0)) { Write-Host 'Demo startup stopped before enabling app entries.'; return }
            if ($mt5OwnedBridge.HasExited) { throw 'The bridge exited. Check storage/logs/mt5-demo-bridge-error.log.' }
            try {
                $mt5Health = Invoke-RestMethod -Uri ($mt5BaseUrl + '/health') `
                    -Headers @{Authorization=('Bearer ' + $env:MT5_BRIDGE_TOKEN)} -TimeoutSec 5
                if ($mt5Health.status -eq 'ok' -and $mt5Health.demo -eq $true -and $mt5Health.execution_enabled -eq $true) {
                    $mt5Healthy = $true
                    break
                }
            } catch { Start-Sleep -Seconds 1 }
        }
        if (!$mt5Healthy) { throw 'The bridge did not verify a connected demo account. No app automation was enabled.' }
        if ($mt5StopEvent.WaitOne(0)) { Write-Host 'Demo startup stopped before enabling app entries.'; return }
        $mt5Configured = $true
        & $PhpPath artisan mt5:configure-demo '--bridge-env' $EnvFile
        if ($LASTEXITCODE -ne 0) { throw 'Laravel demo configuration was rejected; review the diagnostic above.' }
        Write-Host 'Demo controller is running: RSI V2, MT5 data, one position, 0.25% configured risk.'
        Write-Host 'Only autotrader:tick is polled. No email task is started. Use stop-demo.ps1 or press Ctrl+C to stop new entries.'
        while (!$mt5OwnedBridge.HasExited) {
            if ($mt5StopEvent.WaitOne(0)) { break }
            & $PhpPath artisan autotrader:tick
            if ($LASTEXITCODE -ne 0) { throw 'The app trader command failed; automatic entries are being disabled.' }
            if ($mt5StopEvent.WaitOne(60000)) { break }
        }
        if (!$mt5StopEvent.WaitOne(0)) { throw 'The demo bridge stopped; automatic entries are being disabled.' }
        Write-Host 'Demo stop requested; disabling new entries and stopping the owned bridge.'
    } finally {
        try {
            if ($mt5Configured) {
                & $PhpPath artisan mt5:configure-demo --stop
                if ($LASTEXITCODE -ne 0) {
                    Write-Warning 'App entries could not be disabled. The owned bridge is being stopped; check Admin > Auto Trader before restarting.'
                }
            }
        } catch {
            Write-Warning 'The app stop command failed. The owned bridge is being stopped; check Admin > Auto Trader before restarting.'
        } finally {
            try {
                if ($null -ne $mt5OwnedBridge -and !$mt5OwnedBridge.HasExited) {
                    Stop-Process -Id $mt5OwnedBridge.Id -ErrorAction SilentlyContinue
                }
            } finally {
                Pop-Location
            }
        }
    }
} finally {
    try {
        if ($null -ne $mt5StopEvent) { $mt5StopEvent.Dispose() }
    } finally {
        try {
            if ($mt5OwnMutex) { $mt5Mutex.ReleaseMutex() }
        } finally {
            $mt5Mutex.Dispose()
        }
    }
}
