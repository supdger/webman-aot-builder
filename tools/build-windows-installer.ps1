[CmdletBinding()]
param(
    [string]$Compare,
    [string]$Output,
    [string]$Revision,
    [ValidateSet('small', 'full')][string]$Flavor = 'small',
    [string]$MinimalComponent,
    [string]$PreparedTypephp,
    [string]$Result,
    [switch]$Guided,
    [switch]$Install,
    [string]$InstallRoot,
    [string]$BinDir,
    [switch]$NoPath,
    [string]$Project
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$timer = [Diagnostics.Stopwatch]::StartNew()
$failureExit = 1
$resultPendingOwned = $false
$transcriptStarted = $false
$bootstrapLog = Join-Path ([IO.Path]::GetTempPath()) ('webman-aot-source-' + [Guid]::NewGuid().ToString('N') + '.log')
try {
Start-Transcript -Path $bootstrapLog | Out-Null
$transcriptStarted = $true
Write-Output "本次源码准备日志：$bootstrapLog"

if (-not [Environment]::Is64BitOperatingSystem) {
    throw '此入口需要 Windows x64。'
}

if ($PreparedTypephp -and -not (Test-Path -LiteralPath $PreparedTypephp -PathType Container)) {
    throw 'Prepared TypePHP directory is missing.'
}

$repository = Split-Path -Parent $PSScriptRoot
$systemTar = Join-Path $env:SystemRoot 'System32\tar.exe'
if (-not (Test-Path -LiteralPath $systemTar -PathType Leaf)) {
    throw '找不到 Windows 系统 tar.exe，请确认系统解压工具可用。'
}
$runtimeLock = Get-Content -Raw -LiteralPath (Join-Path $repository 'installer\runtime.lock.json') |
    ConvertFrom-Json
$runtime = $runtimeLock.runtimes.'windows-x86_64'
if ($null -eq $runtime -or
    $runtime.archiveUrl -notmatch '^https://' -or
    $runtime.archiveSha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'Windows PHP 下载锁无效，请恢复可信源码。'
}

$inputs = Join-Path $repository 'dist\installer-inputs'
New-Item -ItemType Directory -Force -Path $inputs | Out-Null
$archive = Join-Path $inputs ([IO.Path]::GetFileName(([Uri]$runtime.archiveUrl).AbsolutePath))
$expected = [string]$runtime.archiveSha256
$expectedBytes = if ($expected -eq '2cf521fb6bcb45b634c7e9a7ab2afb6d41698b987bbc73c4975d90a065e0f16c') {
    35113790L
} else { 0L }
Write-Output '[准备] 核验 Windows 锁定 PHP；已校验缓存可以复用，无需系统 PHP。'
$verified = (Test-Path -LiteralPath $archive) -and
    ((Get-FileHash -Algorithm SHA256 -LiteralPath $archive).Hash.ToLowerInvariant() -eq $expected)
if (-not $verified) {
    $partial = $archive + '.partial'
    if ((Test-Path -LiteralPath $partial -PathType Leaf) -and
        ((Get-FileHash -Algorithm SHA256 -LiteralPath $partial).Hash.ToLowerInvariant() -eq $expected)) {
        Move-Item -Force -LiteralPath $partial -Destination $archive
        $verified = $true
        Write-Output '[成功] 完整下载缓存的 SHA-256 通过，直接复用。'
    }
}
if (-not $verified) {
    $partial = $archive + '.partial'
    if ($expectedBytes -gt 0 -and (Test-Path -LiteralPath $partial -PathType Leaf) -and
        ((Get-Item -LiteralPath $partial).Length -gt $expectedBytes)) {
        Remove-Item -Force -LiteralPath $partial
    }
    $curl = $null
    $downloadFailure = $null
    try {
        Write-Output "[下载] Windows PHP；下方每 5 秒显示实际下载量、平均速度和耗时。"
        $curlError = $partial + '.stderr'
        $curlCommand = Get-Command 'curl.exe' -CommandType Application -ErrorAction Stop |
            Select-Object -First 1
        $curlPath = [string]$curlCommand.Source
        if ([string]::IsNullOrWhiteSpace($curlPath)) {
            throw '无法确定 curl.exe 程序路径。'
        }
        for ($pass = 0; $pass -lt 2; $pass++) {
            $resume = (Test-Path -LiteralPath $partial -PathType Leaf) -and
                ((Get-Item -LiteralPath $partial).Length -gt 0)
            $curlArgs = @(
                '--fail', '--location', '--no-progress-bar', '--no-silent', '--progress-meter',
                '--retry', $(if ($resume) { '1' } else { '3' }), '--retry-all-errors'
            )
            if ($resume) { $curlArgs += @('--continue-at', '-') }
            $curlArgs += @(
                '--connect-timeout', '15',
                '--speed-limit', '1024', '--speed-time', '120',
                '--proto', '=https', '--proto-redir', '=https',
                '--output', ('"' + $partial + '"'), ('"' + $runtime.archiveUrl + '"')
            )
            $curl = Start-Process -FilePath $curlPath `
                -ArgumentList $curlArgs -NoNewWindow -PassThru -RedirectStandardError $curlError
            # Windows PowerShell 5.1 needs the handle cached before waiting for ExitCode.
            $curlHandle = $curl.Handle
            $downloadTimer = [Diagnostics.Stopwatch]::StartNew()
            $lastBytes = 0L
            $lastWidth = 0
            $nextDownloadStatus = 5.0
            $interactive = -not [Console]::IsOutputRedirected
            Write-Output ("[下载] Windows PHP：下载进程 {0} 已启动，等待锁定归档。" -f $curl.Id)
            while (-not $curl.WaitForExit(200)) {
                # Start-Process owns the stderr file until the process is drained.
                # Monitor actual payload bytes while that writer is active.
                if ($downloadTimer.Elapsed.TotalSeconds -lt $nextDownloadStatus) { continue }
                $nextDownloadStatus = $downloadTimer.Elapsed.TotalSeconds + 5.0
                $bytes = if (Test-Path -LiteralPath $partial) {
                    (Get-Item -LiteralPath $partial).Length
                } else { 0L }
                $status = if ($expectedBytes -gt 0) {
                    '[下载] Windows PHP：{0:N1}%' -f [Math]::Min(99.9, ($bytes * 100.0 / $expectedBytes))
                } else {
                    '[下载] Windows PHP：{0:N1} MiB' -f ($bytes / 1MB)
                }
                if ($bytes -lt $lastBytes) { $status += '（重新开始）' }
                $status += ('，进程 {0} 仍在下载' -f $curl.Id)
                $status += ('，已下载 {0:N2} MiB，平均 {1:N2} MiB/秒，耗时 {2:N1} 秒' -f ($bytes / 1MB), ($bytes / 1MB / [Math]::Max(0.001, $downloadTimer.Elapsed.TotalSeconds)), $downloadTimer.Elapsed.TotalSeconds)
                if ($interactive) {
                    Write-Host -NoNewline ("`r" + $status + (' ' * [Math]::Max(0, $lastWidth - $status.Length)))
                    $lastWidth = $status.Length
                } else {
                    Write-Output $status
                }
                $lastBytes = $bytes
            }
            $curl.WaitForExit()
            $curlExit = $curl.ExitCode
            $curl.Dispose()
            $curl = $null
            if ($interactive -and $lastWidth -gt 0) { Write-Host '' }
            $errorText = if (Test-Path -LiteralPath $curlError) {
                Get-Content -Raw -LiteralPath $curlError
            } else { '' }
            if ($errorText) { Write-Host -NoNewline $errorText }
            if ($curlExit -isnot [int]) {
                $failureExit = 1
                throw '无法取得下载进程的真实整数退出码，拒绝把未知状态当成成功。请保留上方日志后重试。'
            }
            if ($curlExit -eq 0) { break }

            if ($resume -and $pass -eq 0 -and
                ($curlExit -eq 33 -or $errorText -match 'support byte ranges')) {
                Remove-Item -Force -LiteralPath $partial
                Write-Output '[下载] 服务器不支持续传，从头重试。'
                continue
            }
            $failureExit = $curlExit
            $reason = switch ($curlExit) {
                6 { 'DNS 无法解析服务器' }
                7 { '无法连接服务器，请检查网络或代理' }
                22 { 'HTTP 返回错误，参阅上方状态码' }
                28 { '下载超时，请检查网络' }
                60 { 'TLS 证书校验失败，请检查系统信任或代理证书' }
                default { '下载失败，参阅上方 curl 原始错误' }
            }
            Write-Output "[失败] ${reason}；退出码 ${failureExit}。"
            throw 'Windows PHP 下载未完成。网络恢复后重跑入口；支持时续传，并复用已验证缓存。'
        }
        if (Test-Path -LiteralPath $curlError) { Remove-Item -Force -LiteralPath $curlError }
        $actual = (Get-FileHash -Algorithm SHA256 -LiteralPath $partial).Hash.ToLowerInvariant()
        if ($actual -ne $expected) {
            Remove-Item -Force -LiteralPath $partial
            throw 'Windows PHP 归档 SHA-256 不符，拒绝使用。'
        }
        Write-Output '[下载] Windows PHP 归档的 SHA-256 通过。'
        Move-Item -Force -LiteralPath $partial -Destination $archive
    } catch {
        $downloadFailure = $_
        throw
    } finally {
        try {
            if ($null -ne $curl) {
                if (-not $curl.HasExited) {
                    try { $curl.Kill() } catch [System.InvalidOperationException] {
                        if (-not $curl.HasExited) { throw }
                    }
                }
                $curl.WaitForExit()
                $curl.Dispose()
                $curl = $null
            }
            if (Test-Path -LiteralPath ($partial + '.stderr')) {
                Remove-Item -Force -LiteralPath ($partial + '.stderr')
            }
        } catch {
            if ($null -eq $downloadFailure) { throw }
            Write-Warning ("本次下载清理未完成；保留原始失败。临时日志：{0}；清理原因：{1}" -f ($partial + '.stderr'), $_.Exception.Message)
        }
        # Keep incomplete bytes for a later curl --continue-at retry.
    }
}
Write-Output '[成功] Windows PHP 归档 SHA-256 通过。'

$temporary = Join-Path (Join-Path $repository 'dist') ('s-' + [Guid]::NewGuid().ToString('N').Substring(0, 12))
New-Item -ItemType Directory -Path $temporary | Out-Null
$previousTemp = $env:TEMP
$previousTmp = $env:TMP
$env:TEMP = $temporary
$env:TMP = $temporary
$buildExit = 0
try {
    Write-Output '[准备] 解压本次临时 PHP 运行时。'
    & $systemTar -xf $archive -C $temporary
    if ($LASTEXITCODE -ne 0) {
        throw 'Windows PHP 解压失败，请检查上方错误和磁盘空间。'
    }
    $php = Join-Path $temporary 'php.exe'
    if (-not (Test-Path -LiteralPath $php)) {
        throw '归档缺少 php.exe，拒绝继续。'
    }
    if ((Get-FileHash -Algorithm SHA256 -LiteralPath $php).Hash.ToLowerInvariant() -ne [string]$runtime.binarySha256) {
        throw '实际 php.exe 摘要与 runtime.lock 不一致，拒绝执行。'
    }
    $ini = Join-Path $temporary 'php.ini'
    @(
        'extension_dir=ext'
        'extension=zip'
    ) | Set-Content -LiteralPath $ini -Encoding ascii

    if ($env:WEBMAN_AOT_RELEASE_COVERAGE_CHECK -eq '1' -and $Flavor -eq 'small') {
        Write-Output '[regression] Locked native PHP generated coverage before package creation.'
        $env:TEMP = $previousTemp
        $env:TMP = $previousTmp
        try {
            & $php -n (Join-Path $repository 'tests\generated-coverage.php')
            if ($LASTEXITCODE -ne 0) { throw "Native generated coverage failed: $LASTEXITCODE" }
        } finally {
            $env:TEMP = $temporary
            $env:TMP = $temporary
        }
    }

    if ($Guided) {
        # Keep user-facing logs/results outside the temporary PHP extraction cleaned below.
        $env:TEMP = $previousTemp
        $env:TMP = $previousTmp
        $arguments = @((Join-Path $repository 'tools\guided.php'), '--mode=source')
        if ($PSBoundParameters.ContainsKey('Flavor')) { $arguments += "--flavor=$Flavor" }
        if ($Install) { $arguments += '--install' }
        if ($InstallRoot) { $arguments += "--home=$InstallRoot" }
        if ($BinDir) { $arguments += "--bin-dir=$BinDir" }
        if ($NoPath) { $arguments += '--no-path' }
        if ($Project) { $arguments += "--project=$Project" }
        Write-Output '[开始] PHP 摘要通过，进入源码构包引导。'
        $callerDirectory = (Get-Location).ProviderPath
        $previousPhpCaller = $env:WEBMAN_AOT_CALLER_CWD
        $previousSourceRuntime = $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME
        Push-Location -LiteralPath $temporary
        try {
            $env:WEBMAN_AOT_CALLER_CWD = $callerDirectory
            $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME = $temporary
            & $php -c php.ini -d extension_dir=ext (Join-Path $repository 'tools\windows-php-bootstrap.php') @arguments
            $buildExit = $LASTEXITCODE
        } finally {
            Pop-Location
            $env:WEBMAN_AOT_CALLER_CWD = $previousPhpCaller
            $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME = $previousSourceRuntime
        }
    } else {
        if ($Result) {
            if (-not [IO.Path]::IsPathRooted($Result) -or (Test-Path -LiteralPath $Result) -or (Test-Path -LiteralPath ($Result + '.pending'))) { throw 'Result 必须是未存在的绝对路径。' }
            if (-not $Revision) {
                $gitCommand = Get-Command git -ErrorAction SilentlyContinue
                $Revision = 'source-snapshot'
                if ($gitCommand) {
                    $head = & git -C $repository rev-parse HEAD 2>$null
                    if ($LASTEXITCODE -eq 0) {
                        $Revision = [string]$head
                        if (& git -C $repository status --porcelain) { $Revision += '-dirty' }
                    }
                }
            }
        }
        $arguments = @((Join-Path $repository 'tools\build-windows-installer.php'))
        if ($Result) { $resultPendingOwned = $true; $arguments += "--result=$Result" }
        if ($Compare) { $arguments += "--compare=$Compare" }
        if ($Output) { $arguments += "--output=$Output" }
        if ($Revision) { $arguments += "--revision=$Revision" }
        $arguments += "--flavor=$Flavor"
        if ($MinimalComponent) { $arguments += "--minimal-component=$MinimalComponent" }
        if ($PreparedTypephp) { $arguments += "--prepared-typephp=$PreparedTypephp" }
        Write-Output '[构包] 开始制作 Windows 安装包。'
        $callerDirectory = (Get-Location).ProviderPath
        $previousPhpCaller = $env:WEBMAN_AOT_CALLER_CWD
        $previousSourceRuntime = $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME
        Push-Location -LiteralPath $temporary
        try {
            $env:WEBMAN_AOT_CALLER_CWD = $callerDirectory
            $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME = $temporary
            & $php -c php.ini -d extension_dir=ext (Join-Path $repository 'tools\windows-php-bootstrap.php') @arguments
            $buildExit = $LASTEXITCODE
        } finally {
            Pop-Location
            $env:WEBMAN_AOT_CALLER_CWD = $previousPhpCaller
            $env:WEBMAN_AOT_SOURCE_PHP_RUNTIME = $previousSourceRuntime
        }
        if ($buildExit -eq 0) {
            $versionSource = Get-Content -Raw -LiteralPath (Join-Path $repository 'src\Version.php')
            if ($versionSource -notmatch "public const VALUE = '([^']+)'") {
                throw '无法读取当前源码版本。'
            }
            $version = $Matches[1]
            $packageDir = if ($Output) {
                if ([IO.Path]::IsPathRooted($Output)) { $Output } else { Join-Path $repository $Output }
            } else {
                Join-Path $repository 'dist\source-build'
            }
            $suffix = if ($Flavor -eq 'full') { '-full' } else { '' }
            $builtZip = Join-Path $packageDir "webman-aot-builder-$version$suffix-windows-x86_64.zip"
            if (-not (Test-Path -LiteralPath $builtZip -PathType Leaf)) {
                throw "找不到构出的安装包： $builtZip"
            }

            $smokeRoot = Join-Path $temporary 's'
            $smokePackage = Join-Path $smokeRoot 'p'
            $smokeHome = Join-Path $smokeRoot 'h'
            $smokeBin = Join-Path $smokeRoot 'b'
            New-Item -ItemType Directory -Force -Path $smokePackage | Out-Null
            Write-Output '[自检] 解压实际新包到本次私有目录。'
            & $systemTar -xf $builtZip -C $smokePackage
            if ($LASTEXITCODE -ne 0) {
                throw '实际新包解压失败，无法继续自检。'
            }
            Write-Output '[自检] 私有目录安装与校验包清单，不改用户 PATH。'
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $smokePackage 'install.ps1') `
                -InstallRoot $smokeHome -BinDir $smokeBin -NoPath
            if ($LASTEXITCODE -ne 0) {
                $failureExit = $LASTEXITCODE
                throw '私有安装自检失败，参阅上方原始错误。'
            }
            $previousHome = $env:WEBMAN_AOT_BUILDER_HOME
            $env:WEBMAN_AOT_BUILDER_HOME = $smokeHome
            try {
                Write-Output '[自检] 使用本次新安装的绝对启动器检查版本。'
                $versionOutput = & (Join-Path $smokeBin 'webman-aot.cmd') version
                if ($LASTEXITCODE -ne 0 -or $versionOutput -ne "webman-aot $version") {
                    throw "新安装版本自检失败： $versionOutput"
                }
                Write-Output "[成功] 本次新安装版本自检通过：$versionOutput"
                if ($Result) {
                    $pending = $Result + '.pending'
                    $metadata = Get-Content -Raw -LiteralPath $pending | ConvertFrom-Json
                    if ($metadata.schema -ne 'webman-aot-builder-source-build-result-v1' -or
                        $metadata.platform -ne 'windows-x86_64' -or $metadata.flavor -ne $Flavor -or
                        $metadata.revision -ne $Revision -or
                        $metadata.archive -ne [IO.Path]::GetFullPath($builtZip) -or
                        $metadata.size -ne (Get-Item -LiteralPath $builtZip).Length -or
                        $metadata.sha256 -ne (Get-FileHash -Algorithm SHA256 -LiteralPath $builtZip).Hash.ToLowerInvariant()) {
                        throw '构包结果与实际归档不一致，拒绝发布结果。'
                    }
                    $metadata.verified = @('payload-manifest', 'isolated-install-version')
                    $utf8 = New-Object System.Text.UTF8Encoding($false)
                    [IO.File]::WriteAllText($pending, ($metadata | ConvertTo-Json -Depth 8), $utf8)
                    Move-Item -LiteralPath $pending -Destination $Result
                    Write-Output "[成功] 完整自检结果已发布：$Result"
                }
            } finally {
                $env:WEBMAN_AOT_BUILDER_HOME = $previousHome
            }
        }
    } # backend (Guided uses the shared flow only)
} finally {
    if ($resultPendingOwned -and (Test-Path -LiteralPath ($Result + '.pending'))) { Remove-Item -Force -LiteralPath ($Result + '.pending') }
    $env:TEMP = $previousTemp
    $env:TMP = $previousTmp
    if (Test-Path -LiteralPath $temporary) {
        Write-Output '[清理] 删除本次构包私有临时目录。'
        Remove-Item -Recurse -Force -LiteralPath ('\\?\' + $temporary)
    }
}
if ($buildExit -isnot [int]) {
    throw '无法取得引导/构包子进程的真实整数退出码，拒绝继续。'
}
if ($buildExit -ne 0) {
    Write-Output ("[失败] Windows 构包在 {0:N1} 秒后停止，退出码 {1}。请查看上方原始错误后重试。" -f $timer.Elapsed.TotalSeconds, $buildExit)
    exit $buildExit
}
if ($Guided) {
    Write-Output ("[结束] 源码引导已结束，耗时 {0:N1} 秒；结果以上方实际阶段结论为准。" -f $timer.Elapsed.TotalSeconds)
} else {
    Write-Output ("[成功] Windows 构包与安装版本自检完成，耗时 {0:N1} 秒。" -f $timer.Elapsed.TotalSeconds)
}
} catch {
    Write-Output ("[失败] Windows 构包已停止，耗时 {0:N1} 秒。" -f $timer.Elapsed.TotalSeconds)
    Write-Output ('[失败] ' + $_.Exception.Message)
    Write-Output '请修复上方下载/输入/自检错误后重试，已校验缓存可复用。问题反馈：https://github.com/supdger/webman-aot-builder/issues'
    if ($failureExit -isnot [int] -or $failureExit -eq 0) { $failureExit = 1 }
    exit $failureExit
}
finally {
    if ($transcriptStarted) { Stop-Transcript | Out-Null }
    if ($Guided -and -not [Console]::IsInputRedirected -and -not [Console]::IsOutputRedirected) {
        Read-Host '按回车关闭窗口' | Out-Null
    }
}
