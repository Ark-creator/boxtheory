param(
    [Parameter(Mandatory = $true)]
    [string]$AppRoot,
    [string]$PhpPath = 'C:\xampp\php\php.exe'
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# No task registration, task execution, email command, or order command is used.
$allowedPaths = @(
    'app/Services/MarketDataGuard.php',
    'app/Services/LiveMarketDataService.php',
    'app/Services/TradingService.php',
    'app/Services/SignalAlertService.php',
    'app/Services/Mt5BridgeClient.php',
    'app/Services/SignalSnapshotCache.php',
    'app/Console/Commands/CheckSignalData.php',
    'app/Http/Controllers/Admin/AdminController.php',
    'app/Http/Controllers/WatchlistController.php',
    'resources/views/emails/signals/alert.blade.php',
    'mt5_bridge/terminal.py'
)

function Get-NormalizedSha256 {
    param([string]$LiteralPath)
    $encoding = New-Object System.Text.UTF8Encoding($false, $true)
    $text = $encoding.GetString([System.IO.File]::ReadAllBytes($LiteralPath))
    $normalized = $text.Replace("`r`n", "`n").Replace("`r", "`n")
    $hasher = [System.Security.Cryptography.SHA256]::Create()
    try {
        $hash = $hasher.ComputeHash($encoding.GetBytes($normalized))
        return ([System.BitConverter]::ToString($hash)).Replace('-', '').ToLowerInvariant()
    } finally {
        $hasher.Dispose()
    }
}

function Assert-NoLinks {
    param([string]$Root, [string]$FullPath)
    $current = $Root
    $suffix = $FullPath.Substring($Root.Length).TrimStart([char[]]'\/')
    $parts = @('')
    if ($suffix.Length -gt 0) { $parts += $suffix -split '[/\\]' }
    foreach ($part in $parts) {
        if ($part.Length -gt 0) { $current = Join-Path $current $part }
        if (Test-Path -LiteralPath $current) {
            $item = Get-Item -LiteralPath $current -Force
            if (($item.Attributes -band [System.IO.FileAttributes]::ReparsePoint) -ne 0 -or $item.LinkType -eq 'HardLink') {
                throw "Linked paths are not accepted: $current. Use the physical application/package folder."
            }
        }
    }
}

function Get-SafeChildPath {
    param([string]$Root, [string]$RelativePath)
    if ([System.IO.Path]::IsPathRooted($RelativePath) -or $RelativePath -match '(^|[/\\])\.\.?([/\\]|$)') {
        throw "Unsafe relative path: $RelativePath"
    }
    $full = [System.IO.Path]::GetFullPath((Join-Path $Root $RelativePath))
    $prefix = $Root.TrimEnd([char[]]'\/') + [System.IO.Path]::DirectorySeparatorChar
    if (-not $full.StartsWith($prefix, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Path leaves its intended folder: $RelativePath"
    }
    Assert-NoLinks -Root $Root -FullPath $full
    return $full
}

function Invoke-PhpReadOnly {
    param([string[]]$PhpArguments)
    $priorErrorPreference = $ErrorActionPreference
    try {
        # Native stderr must not bypass the exit-code handling under PowerShell 5.
        $ErrorActionPreference = 'Continue'
        $output = & $script:phpExecutable @PhpArguments 2>&1
        $code = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $priorErrorPreference
    }
    foreach ($line in @($output)) { Write-Host $line }
    return $code
}

$backupRoot = $null
$copiedCount = 0
try {
    $appItem = Get-Item -LiteralPath $AppRoot -Force
    if (-not $appItem.PSIsContainer) { throw 'AppRoot must be the existing Laravel application folder.' }
    $resolvedAppRoot = [System.IO.Path]::GetFullPath($appItem.FullName).TrimEnd([char[]]'\/')
    Assert-NoLinks -Root $resolvedAppRoot -FullPath $resolvedAppRoot
    foreach ($marker in @('artisan', 'composer.json')) {
        $markerPath = Get-SafeChildPath $resolvedAppRoot $marker
        if (-not (Test-Path -LiteralPath $markerPath -PathType Leaf)) {
            throw "AppRoot is missing $marker. Select the Laravel folder, not its public folder."
        }
    }
    $phpItem = Get-Item -LiteralPath $PhpPath -Force
    if ($phpItem.PSIsContainer) { throw 'PhpPath must identify php.exe.' }
    $script:phpExecutable = $phpItem.FullName
    $packageRoot = [System.IO.Path]::GetFullPath($PSScriptRoot).TrimEnd([char[]]'\/')
    Assert-NoLinks -Root $packageRoot -FullPath $packageRoot
    $manifestPath = Get-SafeChildPath $packageRoot 'manifest.json'
    $manifest = [System.IO.File]::ReadAllText($manifestPath) | ConvertFrom-Json
    if ($manifest.schema_version -ne 1 -or $manifest.hash_normalization -ne 'UTF-8 bytes with CRLF/CR normalized to LF; BOM preserved') {
        throw 'Unsupported update manifest.'
    }
    $entries = @($manifest.files)
    if ($entries.Count -ne $allowedPaths.Count) { throw 'Update manifest has an unexpected runtime file count.' }
    $seen = New-Object 'System.Collections.Generic.HashSet[string]' ([System.StringComparer]::OrdinalIgnoreCase)
    $plan = @()

    # Complete every hash/path/target check before creating backups or changing files.
    foreach ($entry in $entries) {
        $relative = [string]$entry.path
        if (-not ($allowedPaths -ccontains $relative) -or -not $seen.Add($relative)) {
            throw "Unexpected or duplicate runtime path: $relative"
        }
        if ([string]$entry.sha256_normalized_lf -notmatch '^[a-f0-9]{64}$') { throw "Invalid package hash for $relative" }
        $baseline = $entry.baseline_sha256_normalized_lf
        if ($null -ne $baseline -and [string]$baseline -notmatch '^[a-f0-9]{64}$') { throw "Invalid baseline hash for $relative" }
        $source = Get-SafeChildPath $packageRoot ('payload/' + $relative)
        $target = Get-SafeChildPath $resolvedAppRoot $relative
        if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { throw "Package payload is missing $relative" }
        if ((Get-NormalizedSha256 $source) -ne $entry.sha256_normalized_lf) { throw "Package payload checksum failed: $relative" }
        $exists = Test-Path -LiteralPath $target -PathType Leaf
        if ((Test-Path -LiteralPath $target) -and -not $exists) { throw "Runtime target is not a regular file: $relative" }
        $currentHash = $null
        $state = 'create'
        if ($exists) {
            $currentHash = Get-NormalizedSha256 $target
            if ($currentHash -eq $entry.sha256_normalized_lf) { $state = 'already_applied' }
            elseif ($null -ne $baseline -and $currentHash -eq $baseline) { $state = 'update' }
            else { throw "Local file differs from both the expected original and this update: $relative. No runtime files have been changed." }
        } elseif ($null -ne $baseline) {
            throw "Expected original file is missing: $relative. No runtime files have been changed."
        }
        $plan += [pscustomobject]@{ Path=$relative; Source=$source; Target=$target; State=$state; OriginalHash=$currentHash; NewHash=$entry.sha256_normalized_lf }
    }
    foreach ($item in $plan) {
        if ($item.Path.EndsWith('.php', [System.StringComparison]::OrdinalIgnoreCase)) {
            $lintCode = Invoke-PhpReadOnly -PhpArguments @('-l', $item.Source)
            if ($lintCode -ne 0) { throw "The target PHP interpreter rejected package syntax: $($item.Path)" }
        }
    }
    $changes = @($plan | Where-Object { $_.State -ne 'already_applied' })
    if ($changes.Count -gt 0) {
        $stamp = [DateTime]::UtcNow.ToString('yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N')
        $backupRelative = 'storage/app/signal-freshness-backups/' + $stamp
        $plannedBackupRoot = Get-SafeChildPath $resolvedAppRoot $backupRelative
        foreach ($item in $changes) {
            if ($item.State -eq 'update') {
                $plannedBackupFile = Get-SafeChildPath $resolvedAppRoot ($backupRelative + '/original/' + $item.Path)
                if ($PSVersionTable.PSVersion.Major -le 5 -and $plannedBackupFile.Length -ge 248) {
                    throw 'The application path leaves insufficient room for a safe backup in Windows PowerShell 5. Use a shorter physical application path or have the update reviewed manually. No runtime files have been changed.'
                }
            }
        }
        $backupRoot = $plannedBackupRoot
        New-Item -ItemType Directory -Path $backupRoot -Force | Out-Null
        foreach ($item in $changes) {
            if ($item.State -eq 'update') {
                if ((Get-NormalizedSha256 $item.Target) -ne $item.OriginalHash) { throw "Local file changed during preflight: $($item.Path)" }
                $backupFile = Get-SafeChildPath $resolvedAppRoot ($backupRelative + '/original/' + $item.Path)
                New-Item -ItemType Directory -Path ([System.IO.Path]::GetDirectoryName($backupFile)) -Force | Out-Null
                Copy-Item -LiteralPath $item.Target -Destination $backupFile
            }
        }
        $statePath = Get-SafeChildPath $resolvedAppRoot ($backupRelative + '/install-state.json')
        $stateRecord = [pscustomobject]@{ Release=$manifest.release; AppRoot=$resolvedAppRoot; CreatedAtUtc=[DateTime]::UtcNow.ToString('o'); Files=@($plan | Select-Object Path,State,OriginalHash,NewHash) }
        [System.IO.File]::WriteAllText($statePath, ($stateRecord | ConvertTo-Json -Depth 6), (New-Object System.Text.UTF8Encoding($false)))
        Write-Host "Original files backed up to: $backupRoot"
        foreach ($item in $changes) {
            $checkedTarget = Get-SafeChildPath $resolvedAppRoot $item.Path
            New-Item -ItemType Directory -Path ([System.IO.Path]::GetDirectoryName($checkedTarget)) -Force | Out-Null
            Copy-Item -LiteralPath $item.Source -Destination $checkedTarget -Force
            if ((Get-NormalizedSha256 $checkedTarget) -ne $item.NewHash) { throw "Installed checksum failed: $($item.Path)" }
            $copiedCount++
        }
        Write-Host "Applied $copiedCount runtime files."
    } else {
        Write-Host 'This update is already applied; no runtime files were copied.'
    }

    Push-Location -LiteralPath $resolvedAppRoot
    try {
        foreach ($command in @('config:clear', 'view:clear')) {
            $commandCode = Invoke-PhpReadOnly -PhpArguments @('artisan', $command, '--no-interaction')
            if ($commandCode -ne 0) { throw "Update files are present, but artisan $command failed. Review the output; the update was not rolled back." }
        }
        $healthCode = Invoke-PhpReadOnly -PhpArguments @('artisan', 'signals:check-data', '--no-interaction')
    } finally {
        Pop-Location
    }
    if ($healthCode -ne 0) {
        Write-Host 'Update applied. The data check is not healthy: signals should remain blocked until the reported market/data condition is resolved.'
        Write-Host 'This is not a rollback. Existing task settings and .env have not been changed.'
        exit 3
    }
    Write-Host 'Update applied and the read-only data check passed. Existing task settings and .env have not been changed.'
    exit 0
} catch {
    Write-Host ('Update stopped: ' + $_.Exception.Message)
    if ($null -ne $backupRoot) { Write-Host "Original-file backup and install-state record: $backupRoot" }
    if ($copiedCount -gt 0) { Write-Host 'Some runtime files were copied before the error. Keep the email task paused and review the backup/install-state record.' }
    exit 1
}
