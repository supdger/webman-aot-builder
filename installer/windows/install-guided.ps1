[CmdletBinding()]
param(
    [ValidateSet('small', 'full')][string]$Flavor,
    [switch]$Install,
    [string]$InstallRoot,
    [string]$BinDir,
    [switch]$NoPath,
    [string]$Project
)
$ErrorActionPreference = 'Stop'
[Console]::OutputEncoding = [Text.UTF8Encoding]::new($false)
$packageRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$timer = [Diagnostics.Stopwatch]::StartNew()
try {
    $php = Join-Path $packageRoot 'payload\runtime\php.exe'
    if (-not (Test-Path -LiteralPath $php -PathType Leaf)) { throw '包内 PHP 缺失，请重新下载完整安装包。' }
    $arguments = @('-c', 'php.ini', '-d', 'extension_dir=ext', '..\app\tools\windows-php-bootstrap.php', (Join-Path $packageRoot 'payload\app\tools\guided.php'), '--mode=install', ('--package-root=' + $packageRoot))
    if ($Flavor) { $arguments += '--flavor=' + $Flavor }
    if ($Install) { $arguments += '--install' }
    if ($InstallRoot) { $arguments += '--home=' + $InstallRoot }
    if ($BinDir) { $arguments += '--bin-dir=' + $BinDir }
    if ($NoPath) { $arguments += '--no-path' }
    if ($Project) { $arguments += '--project=' + $Project }
    $previousCallerDirectory = $env:WEBMAN_AOT_CALLER_CWD
    $env:WEBMAN_AOT_CALLER_CWD = (Get-Location).ProviderPath
    $runtimeLocationPushed = $false
    try {
        Push-Location -LiteralPath (Split-Path -Parent $php)
        $runtimeLocationPushed = $true
        & '.\php.exe' @arguments
        $childExit = $LASTEXITCODE
    } finally {
        try { if ($runtimeLocationPushed) { Pop-Location } } finally { $env:WEBMAN_AOT_CALLER_CWD = $previousCallerDirectory }
    }
    exit $childExit
} catch {
    Write-Host ('安装入口失败（耗时 {0:N1} 秒）：{1}' -f $timer.Elapsed.TotalSeconds, $_.Exception.Message)
    Write-Host '请重新取得完整安装包。反馈问题：https://github.com/supdger/webman-aot-builder/issues'
    exit 1
} finally {
    if ($env:WEBMAN_AOT_NO_PAUSE -ne '1' -and -not [Console]::IsInputRedirected -and -not [Console]::IsOutputRedirected) {
        Write-Host '按回车关闭此窗口…' -NoNewline
        [void][Console]::ReadLine()
    }
}
