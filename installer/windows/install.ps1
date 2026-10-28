[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder'),
    [string]$BinDir = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder\bin'),
    [switch]$NoPath
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$installTimer = [Diagnostics.Stopwatch]::StartNew()

# Keep PHP startup paths ASCII without changing application relative paths.
$privatePhpExitCode = 0
function Invoke-PrivatePhp([string]$RuntimeDirectory, [string]$Entry, [string[]]$Arguments) {
    $RuntimeDirectory = [IO.Path]::GetFullPath($RuntimeDirectory)
    $Entry = [IO.Path]::GetFullPath($Entry)
    $previousCallerDirectory = $env:WEBMAN_AOT_CALLER_CWD
    $env:WEBMAN_AOT_CALLER_CWD = (Get-Location).ProviderPath
    $runtimeLocationPushed = $false
    try {
        Push-Location -LiteralPath $RuntimeDirectory
        $runtimeLocationPushed = $true
        & '.\php.exe' -c php.ini -d extension_dir=ext '..\app\tools\windows-php-bootstrap.php' $Entry @Arguments
        $script:privatePhpExitCode = $LASTEXITCODE
    } finally {
        try { if ($runtimeLocationPushed) { Pop-Location } } finally { $env:WEBMAN_AOT_CALLER_CWD = $previousCallerDirectory }
    }
}

if (-not [Environment]::Is64BitOperatingSystem -or
    $env:PROCESSOR_ARCHITECTURE -notin @('AMD64', 'x86')) {
    throw 'This package requires Windows x64.'
}

$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$full = Test-Path -LiteralPath (Join-Path $packageRoot 'payload\minimal-toolchain\component.zip') -PathType Leaf
$installPath = [IO.Path]::GetFullPath($InstallRoot)
$installDrive = [IO.Path]::GetPathRoot($installPath)
if ($installPath.TrimEnd('\') -eq $installDrive.TrimEnd('\') -or
    $installPath.TrimEnd('\') -eq $env:USERPROFILE.TrimEnd('\') -or
    ((Test-Path -LiteralPath $InstallRoot) -and
        ((Get-Item -LiteralPath $InstallRoot).Attributes -band [IO.FileAttributes]::ReparsePoint))) {
    throw "Unsafe Webman AOT Builder installation directory: $InstallRoot"
}
if ($full) {
    $freeBytes = ([IO.DriveInfo]::new($installDrive)).AvailableFreeSpace
    if ($freeBytes -lt 4GB) {
        throw ("Complete installation needs at least 4 GiB free on {0}; available: {1:N1} GiB. Choose a larger drive with -InstallRoot and -BinDir." -f
            $installDrive, ($freeBytes / 1GB))
    }
    Write-Output '[install] Complete package: installing the locked minimal toolchain offline (no downloads).'
}
$manifestPath = Join-Path $packageRoot 'payload-manifest.sha256'
$manifestLines = @(Get-Content -LiteralPath $manifestPath)
foreach ($line in $manifestLines) {
    if ($line -notmatch '^([a-f0-9]{64})  (.+)$') {
        throw "Invalid payload manifest line: $line"
    }
    $relative = $Matches[2].Replace('/', [IO.Path]::DirectorySeparatorChar)
    $path = Join-Path $packageRoot $relative
    $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $path).Hash.ToLowerInvariant()
    if ($actual -ne $Matches[1]) {
        throw "Payload digest mismatch: $relative"
    }
}
Write-Output "Package contents SHA-256 verified: $($manifestLines.Count) files"

$candidate = Join-Path $InstallRoot ('.w-' + [Guid]::NewGuid().ToString('N').Substring(0, 8))
$smallBackup = $null
$fullBackup = $null
$newCurrent = $false
$newToolchains = $false
$newLauncher = $false
$legacyLauncherMoved = $false
$smallLauncherPrepared = $false
$pathChanged = $false
$pathMarkerCreated = $false
$previousUserPath = $null
$previousProcessPath = $env:Path
try {
    $candidateCurrent = Join-Path $candidate 'current'
    New-Item -ItemType Directory -Force -Path $candidateCurrent | Out-Null
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $packageRoot 'payload\app') -Destination (Join-Path $candidateCurrent 'app')
    Copy-Item -Recurse -Force -LiteralPath (Join-Path $packageRoot 'payload\runtime') -Destination (Join-Path $candidateCurrent 'runtime')

    $previousHome = $env:WEBMAN_AOT_BUILDER_HOME
    $env:WEBMAN_AOT_BUILDER_HOME = $candidate
    try {
        Invoke-PrivatePhp (Join-Path $candidateCurrent 'runtime') `
            (Join-Path $candidateCurrent 'app\bin\webman-aot-builder.php') @('--version') | Out-Null
        if ($privatePhpExitCode -ne 0) {
            throw 'Candidate self-check failed.'
        }
    } finally {
        $env:WEBMAN_AOT_BUILDER_HOME = $previousHome
    }

    New-Item -ItemType Directory -Force -Path (Join-Path $InstallRoot '.install-backups') | Out-Null
    $current = Join-Path $InstallRoot 'current'
    New-Item -ItemType Directory -Force -Path $BinDir | Out-Null
    $launcher = Join-Path $BinDir 'webman-aot.cmd'
    $legacyLauncher = Join-Path $InstallRoot '.previous-launcher\webman-aot.cmd'
    if (Test-Path -LiteralPath $launcher) {
        $existing = Get-Item -LiteralPath $launcher
        if ($existing.Attributes -band [IO.FileAttributes]::ReparsePoint -or $existing.PSIsContainer) {
            throw "Cannot replace non-regular command: $launcher"
        }
        if (-not (Select-String -LiteralPath $launcher -SimpleMatch 'WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER' -Quiet)) {
            if (Test-Path -LiteralPath $legacyLauncher) {
                throw "A previous webman-aot command is already backed up; refusing to overwrite: $launcher"
            }
            New-Item -ItemType Directory -Force -Path (Split-Path -Parent $legacyLauncher) | Out-Null
            Move-Item -LiteralPath $launcher -Destination $legacyLauncher
            $legacyLauncherMoved = $true
            Write-Output "Previous webman-aot command saved in: $legacyLauncher"
        }
    }
    $reuse = -not $full -and -not [string]::IsNullOrWhiteSpace($env:WEBMAN_AOT_REUSE_HOME)
    if ($full -or $reuse) {
        $bundle = Join-Path $packageRoot 'payload\minimal-toolchain\component.zip'
        if ($reuse) { $bundle = '--reuse' }
        $offlineScript = Join-Path $candidateCurrent 'app\installer\offline-prepare.php'
        $previousHome = $env:WEBMAN_AOT_BUILDER_HOME
        $env:WEBMAN_AOT_BUILDER_HOME = $candidate
        try {
            Invoke-PrivatePhp (Join-Path $candidateCurrent 'runtime') $offlineScript @($bundle)
            if ($privatePhpExitCode -ne 0) { throw 'Offline toolchain preparation failed.' }
        } finally {
            $env:WEBMAN_AOT_BUILDER_HOME = $previousHome
        }
        $fullBackup = Join-Path $InstallRoot ('.install-backups\full-' +
            [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + $PID)
        New-Item -ItemType Directory -Force -Path $fullBackup | Out-Null
        foreach ($name in @('current', 'toolchains', 'versions')) {
            $old = Join-Path $InstallRoot $name
            if (Test-Path -LiteralPath $old) {
                Move-Item -LiteralPath $old -Destination (Join-Path $fullBackup $name)
            }
        }
        if (Test-Path -LiteralPath $launcher) {
            Move-Item -LiteralPath $launcher -Destination (Join-Path $fullBackup 'webman-aot.cmd')
        }
        Move-Item -LiteralPath $candidateCurrent -Destination $current
        $newCurrent = $true
        Move-Item -LiteralPath (Join-Path $candidate 'toolchains') -Destination (Join-Path $InstallRoot 'toolchains')
        $newToolchains = $true
        Copy-Item -LiteralPath (Join-Path $packageRoot 'payload\launcher\webman-aot.cmd') -Destination $launcher
        $newLauncher = $true
        if ($full) {
        $previousHome = $env:WEBMAN_AOT_BUILDER_HOME
        $env:WEBMAN_AOT_BUILDER_HOME = $InstallRoot
        try {
            Invoke-PrivatePhp (Join-Path $current 'runtime') `
                (Join-Path $current 'app\installer\offline-prepare.php') @($bundle)
            if ($privatePhpExitCode -ne 0) { throw 'Activated offline toolchain self-check failed.' }
        } finally {
            $env:WEBMAN_AOT_BUILDER_HOME = $previousHome
        }
        }
    } else {
        $smallBackup = Join-Path $InstallRoot ('.install-backups\current-' +
            [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ') + '-' + $PID)
        New-Item -ItemType Directory -Force -Path $smallBackup | Out-Null
        if (Test-Path -LiteralPath $current) {
            Move-Item -LiteralPath $current -Destination (Join-Path $smallBackup 'current')
        }
        $versions = Join-Path $InstallRoot 'versions'
        if (Test-Path -LiteralPath $versions) {
            Move-Item -LiteralPath $versions -Destination (Join-Path $smallBackup 'versions')
        }
        if (Test-Path -LiteralPath $launcher) {
            Move-Item -LiteralPath $launcher -Destination (Join-Path $smallBackup 'webman-aot.cmd')
        }
        $smallLauncherPrepared = $true
        Move-Item -LiteralPath $candidateCurrent -Destination $current
        $newCurrent = $true
        Copy-Item -Force -LiteralPath (Join-Path $packageRoot 'payload\launcher\webman-aot.cmd') -Destination $launcher
        $newLauncher = $true
    }

    if (-not $NoPath) {
        $userPath = [Environment]::GetEnvironmentVariable('Path', 'User')
        $pathWasPresent = @($userPath -split ';') -contains $BinDir
        $parts = @($userPath -split ';' | Where-Object { $_ -ne '' -and $_ -ne $BinDir })
        $newPath = ((@($BinDir) + $parts) -join ';')
        if ($userPath -ne $newPath) {
            $previousUserPath = $userPath
            $pathChanged = $true
            [Environment]::SetEnvironmentVariable('Path', $newPath, 'User')
        }
        $machinePath = [Environment]::GetEnvironmentVariable('Path', 'Machine')
        $env:Path = (($machinePath, $newPath) -join ';')
        if (-not $pathWasPresent) {
            New-Item -ItemType File -Force -Path (Join-Path $InstallRoot '.path-added-by-builder') | Out-Null
            $pathMarkerCreated = $true
        }
        $resolved = Get-Command webman-aot -ErrorAction SilentlyContinue
        if ($null -eq $resolved -or [string]::IsNullOrEmpty($resolved.Path) -or
            -not [string]::Equals(
                [IO.Path]::GetFullPath($resolved.Path),
                [IO.Path]::GetFullPath($launcher),
                [StringComparison]::OrdinalIgnoreCase
            )) {
            throw "Another webman-aot command takes precedence. Resolve the command conflict before installation; Builder did not replace it."
        }
        $env:Path = ((@($BinDir) + @($previousProcessPath -split ';' | Where-Object {
            $_ -ne '' -and $_ -ne $BinDir
        })) -join ';')
    }
} catch {
    $env:Path = $previousProcessPath
    if ($pathChanged) {
        try {
            [Environment]::SetEnvironmentVariable('Path', $previousUserPath, 'User')
        } catch {
            Write-Warning 'Unable to restore the previous user PATH; installation files will still be restored.'
        }
    }
    if ($pathMarkerCreated) {
        Remove-Item -Force -ErrorAction SilentlyContinue -LiteralPath (Join-Path $InstallRoot '.path-added-by-builder')
    }
    if ($full -and $null -ne $fullBackup) {
        Write-Warning 'Complete installation failed; restoring the previous installation.'
        if ($newLauncher -and (Test-Path -LiteralPath $launcher)) {
            Remove-Item -Force -LiteralPath $launcher
        }
        if ($newToolchains -and (Test-Path -LiteralPath (Join-Path $InstallRoot 'toolchains'))) {
            Remove-Item -Recurse -Force -LiteralPath (Join-Path $InstallRoot 'toolchains')
        }
        if ($newCurrent -and (Test-Path -LiteralPath $current)) {
            Remove-Item -Recurse -Force -LiteralPath $current
        }
        foreach ($name in @('current', 'toolchains', 'versions')) {
            $saved = Join-Path $fullBackup $name
            if (Test-Path -LiteralPath $saved) {
                Move-Item -LiteralPath $saved -Destination (Join-Path $InstallRoot $name)
            }
        }
        $savedLauncher = Join-Path $fullBackup 'webman-aot.cmd'
        if (Test-Path -LiteralPath $savedLauncher) {
            Move-Item -LiteralPath $savedLauncher -Destination $launcher
        }
    } elseif ($null -ne $smallBackup) {
        Write-Warning 'Installation failed; restoring the previous installation.'
        if ($newCurrent -and (Test-Path -LiteralPath $current)) {
            Remove-Item -Recurse -Force -LiteralPath $current
        }
        if ($smallLauncherPrepared -and (Test-Path -LiteralPath $launcher)) {
            Remove-Item -Force -LiteralPath $launcher
        }
        $savedCurrent = Join-Path $smallBackup 'current'
        if (Test-Path -LiteralPath $savedCurrent) {
            Move-Item -LiteralPath $savedCurrent -Destination $current
        }
        $savedVersions = Join-Path $smallBackup 'versions'
        if (Test-Path -LiteralPath $savedVersions) {
            Move-Item -LiteralPath $savedVersions -Destination (Join-Path $InstallRoot 'versions')
        }
        $savedLauncher = Join-Path $smallBackup 'webman-aot.cmd'
        if (Test-Path -LiteralPath $savedLauncher) {
            Move-Item -LiteralPath $savedLauncher -Destination $launcher
        }
    }
    if ($legacyLauncherMoved -and (Test-Path -LiteralPath $legacyLauncher)) {
        Move-Item -LiteralPath $legacyLauncher -Destination $launcher
    }
    throw
} finally {
    if (Test-Path -LiteralPath $candidate) {
        Remove-Item -Recurse -Force -LiteralPath $candidate
    }
}

$obsoleteLauncher = Join-Path $BinDir 'webman-aot-builder.cmd'
if ((Test-Path -LiteralPath $obsoleteLauncher -PathType Leaf) -and
    (Select-String -LiteralPath $obsoleteLauncher -SimpleMatch 'WEBMAN_AOT_BUILDER_HOME' -Quiet)) {
    Remove-Item -Force -ErrorAction SilentlyContinue -LiteralPath $obsoleteLauncher
}
Write-Output "Webman AOT Builder installed in: $InstallRoot"
Write-Output "Command installed as: $launcher"
if ($full) {
    Write-Output 'Complete offline toolchain ready. Enter a Webman project and run webman-aot build.'
}
Write-Output ('Installation completed in {0:N1} seconds.' -f $installTimer.Elapsed.TotalSeconds)
