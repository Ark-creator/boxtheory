param(
    [string]$PhpPath = ''
)

$ErrorActionPreference = 'Stop'
$mt5StopExitCode = 0
$mt5StopProjectRoot = Split-Path -Parent $PSScriptRoot
try {
    if ([string]::IsNullOrWhiteSpace($PhpPath)) {
        $mt5StopPhpCommand = Get-Command php.exe -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -ne $mt5StopPhpCommand) { $PhpPath = $mt5StopPhpCommand.Source }
    }
    if ([string]::IsNullOrWhiteSpace($PhpPath) -or !(Test-Path -LiteralPath $PhpPath -PathType Leaf)) {
        throw 'Supply -PhpPath with the PHP executable used for this Laravel app.'
    }
    $PhpPath = (Resolve-Path -LiteralPath $PhpPath).Path
    Push-Location -LiteralPath $mt5StopProjectRoot
    try {
        & $PhpPath artisan mt5:configure-demo --stop
        if ($LASTEXITCODE -ne 0) { throw 'The app could not confirm that new RSI entries were disabled.' }
    } finally {
        Pop-Location
    }
} catch {
    $mt5StopExitCode = 1
    Write-Warning 'App entries could not be confirmed disabled. A local controller stop will still be requested; check Admin > Auto Trader before restarting.'
} finally {
    try {
        $mt5StopHandle = [System.Threading.EventWaitHandle]::OpenExisting('Local\GoldLogicRsiDemoStop')
        try {
            $mt5StopHandle.Set() | Out-Null
            Write-Host 'Stop requested for the local demo controller. It will clean up its own bridge after any current command completes.'
        } finally {
            $mt5StopHandle.Dispose()
        }
    } catch [System.Threading.WaitHandleCannotBeOpenedException] {
        Write-Host 'No local demo controller stop handle was found in this Windows session. No process was terminated.'
    } catch {
        $mt5StopExitCode = 1
        Write-Warning 'The controller stop signal failed. Check the controller log and Admin > Auto Trader; no process was force-terminated.'
    }
}
exit $mt5StopExitCode
