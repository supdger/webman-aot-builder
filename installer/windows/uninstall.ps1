[CmdletBinding()]
param(
    [string]$InstallRoot = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder'),
    [string]$BinDir = (Join-Path $env:LOCALAPPDATA 'webman-aot-builder\bin'),
    [switch]$List,
    [switch]$NoPath
)
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$temporary = $null
$exitCode = 70
try {
    $sourceRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
    $engine = Join-Path $sourceRoot 'packages\composer-installer\src\Uninstaller.php'
    if (-not (Test-Path -LiteralPath $engine -PathType Leaf)) {
        $engine = Join-Path $PSScriptRoot 'payload\app\packages\composer-installer\src\Uninstaller.php'
    }
    if (-not (Test-Path -LiteralPath $engine -PathType Leaf)) {
        throw 'Uninstall engine missing; use the new source or Composer entry.'
    }
    $runtime = Join-Path $InstallRoot 'current\runtime'
    $php = Join-Path $runtime 'php.exe'
    $temporary = Join-Path ([IO.Path]::GetTempPath()) ('webman-aot-uninstall-' + [Guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $temporary | Out-Null
    if (Test-Path -LiteralPath $php -PathType Leaf) {
        Write-Output '[prepare] Copying private PHP to a temporary directory before uninstalling the selected installation.'
        # Reject reparse points before Copy-Item can traverse a junction.
        $probe = [IO.Path]::GetFullPath($runtime)
        while ($probe -and (Test-Path -LiteralPath $probe)) {
            if ((Get-Item -LiteralPath $probe).Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Runtime ancestor is a reparse point: $probe" }
            $parent = Split-Path -Parent $probe
            if ($parent -eq $probe) { break }
            $probe = $parent
        }
        foreach ($item in Get-ChildItem -LiteralPath $runtime -Force -Recurse) {
            if ($item.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw "Runtime contains a reparse point: $($item.FullName)" }
        }
        $engineRoot = Split-Path -Parent (Split-Path -Parent $engine)
        $templates = Get-Content -LiteralPath (Join-Path $engineRoot 'resources\uninstall-launchers.json') -Raw | ConvertFrom-Json
        $expected = $templates.runtimes.'windows-x86_64'
        $actual = (Get-FileHash -LiteralPath $php -Algorithm SHA256).Hash.ToLowerInvariant()
        if (-not $expected -or $actual -ne $expected) { throw 'Private PHP hash is not trusted by this entry; installation retained. Use the Composer entry.' }
        Copy-Item -LiteralPath $runtime -Destination (Join-Path $temporary 'runtime') -Recurse
        $php = Join-Path $temporary 'runtime\php.exe'
    } else {
        $command = Get-Command php.exe -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
        if ($null -eq $command) { throw 'Private and system PHP are missing; use the Composer uninstall entry.' }
        $php = $command.Source
    }
    $engineRoot = Split-Path -Parent (Split-Path -Parent $engine)
    New-Item -ItemType Directory -Path (Join-Path $temporary 'src'), (Join-Path $temporary 'resources') | Out-Null
    Copy-Item -LiteralPath $engine -Destination (Join-Path $temporary 'src\Uninstaller.php')
    Copy-Item -LiteralPath (Join-Path $engineRoot 'resources\uninstall-launchers.json') -Destination (Join-Path $temporary 'resources\uninstall-launchers.json')
    $arguments = @('-n', (Join-Path $temporary 'src\Uninstaller.php'), "--home=$InstallRoot", "--bin-dir=$BinDir")
    if ($List) { $arguments += '--list' }
    # NoPath is retained for script compatibility; this uninstaller never changes PATH.
    & $php @arguments
    $exitCode = $LASTEXITCODE
} catch {
    Write-Error "Uninstall failed: $_" -ErrorAction Continue
} finally {
    if ($temporary -and (Test-Path -LiteralPath $temporary)) {
        try { Remove-Item -LiteralPath $temporary -Recurse -Force }
        catch { Write-Error "Temporary runtime cleanup failed: $temporary : $_" -ErrorAction Continue; $exitCode = 70 }
    }
}
exit $exitCode
