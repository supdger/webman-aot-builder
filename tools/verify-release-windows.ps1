[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Revision,
    [string]$NativeBuildRevision = '',
    [Parameter(Mandatory=$true)][string]$Version,
    [Parameter(Mandatory=$true)][ValidateSet('draft','public')][string]$Mode,
    [Parameter(Mandatory=$true)][string]$ChecksumsSha256,
    [Parameter(Mandatory=$true)][string]$WorkRoot
)
$ErrorActionPreference = 'Stop'
$repository = Split-Path -Parent $PSScriptRoot
$utf8 = New-Object Text.UTF8Encoding($false)
$timer = [Diagnostics.Stopwatch]::StartNew()
function Path-Digest([string]$Scope) {
    $sha = [Security.Cryptography.SHA256]::Create()
    try { return ([BitConverter]::ToString($sha.ComputeHash($utf8.GetBytes([string][Environment]::GetEnvironmentVariable('PATH',$Scope))))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
}
function Invoke-Native([string]$Stage,[string]$Entry,[string[]]$Arguments,[string]$Directory,[string]$CaptureFile = '') {
    Write-Host "[stage] $Stage"
    $quoted = @($Entry)+$Arguments | ForEach-Object {
        if ($_ -match '["\r\n%!&|<>^]') { throw 'Unsupported shell character in controlled release argument.' }
        '"'+$_+'"'
    }
    $wrapper = Join-Path $WorkRoot ('command-'+[Guid]::NewGuid().ToString('N')+'.cmd')
    $capture = if ($CaptureFile) { ' >"'+$CaptureFile+'"' } else { '' }
    [IO.File]::WriteAllText($wrapper, ('@echo off'+"`r`n"+'chcp 65001 >nul'+"`r`n"+'call '+($quoted -join ' ')+$capture+' <nul'+"`r`n"+'exit /b %errorlevel%'+"`r`n"),$utf8)
    $process = $null; $running = $false; $clock = [Diagnostics.Stopwatch]::StartNew()
    try {
        $process = Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\cmd.exe') -ArgumentList @('/d','/s','/c',('""'+$wrapper+'""')) -WorkingDirectory $Directory -NoNewWindow -PassThru
        $running = $true; $handle = $process.Handle
        while (-not $process.WaitForExit(1000)) {
            if ($clock.Elapsed.TotalSeconds -ge 1800) { throw "$Stage timed out after 1800 seconds." }
        }
        $process.WaitForExit(); $code = $process.ExitCode
        if ($code -isnot [int]) { throw "$Stage returned an unknown exit status." }
        Write-Host ("[stage] {0} exit={1}, elapsed={2:F1}s" -f $Stage,$code,$clock.Elapsed.TotalSeconds)
        if ($code -ne 0) { throw "$Stage failed (exit $code)." }
    } finally {
        if ($running -and -not $process.HasExited) { & (Join-Path $env:SystemRoot 'System32\taskkill.exe') /PID $process.Id /T /F | Out-Null; $process.WaitForExit() }
        if ($null -ne $process) { $process.Dispose() }
        Remove-Item -LiteralPath $wrapper -ErrorAction SilentlyContinue
    }
}
function Remove-OwnedDirectory([string]$Path) {
    $absolute = [IO.Path]::GetFullPath($Path)
    if (-not $absolute.StartsWith($WorkRoot.TrimEnd('\')+'\',[StringComparison]::OrdinalIgnoreCase)) { throw 'Cleanup refused a path outside WorkRoot.' }
    if (Test-Path -LiteralPath $absolute) {
        Write-Host "[cleanup] Removing task-owned path: $absolute"
        Remove-Item -LiteralPath ('\\?\'+$absolute) -Recurse -Force
        if (Test-Path -LiteralPath $absolute) { throw 'Task-owned path remains after cleanup.' }
    }
}
$userPath = Path-Digest 'User'; $machinePath = Path-Digest 'Machine'
$keys = @('PATH','TEMP','TMP','LOCALAPPDATA','WEBMAN_AOT_BUILDER_HOME','WEBMAN_AOT_NO_PAUSE','WEBMAN_AOT_CALLER_CWD','COMPOSER_HOME','COMPOSER_CACHE_DIR','CURL_HOME')
$saved = @{}; foreach ($key in $keys) { $saved[$key] = [Environment]::GetEnvironmentVariable($key,'Process') }
function Read-CompiledUnitCount([string]$Text) {
    $totals = [regex]::Matches($Text,'(?m)^Successfully compiled ([1-9][0-9]*) files\r?$')
    $cold = [regex]::Matches($Text,'(?m)^\[resume\] Reused verified objects: ([0-9]+)\r?$')
    if ($totals.Count -ne 1 -or $cold.Count -ne 1 -or $cold[0].Groups[1].Value -ne '0') { throw 'Initial build must prove one cold native compilation.' }
    $count = [int]$totals[0].Groups[1].Value
    $units = [regex]::Matches($Text,'(?m)^\[([1-9][0-9]*)/([1-9][0-9]*)\] [0-9]+% .+\.(?:cc|cpp|c)\r?$')
    if ($count -le 2 -or $units.Count -ne $count) { throw 'Initial native compilation sequence is incomplete.' }
    for ($index = 0; $index -lt $count; $index++) {
        if ([int]$units[$index].Groups[1].Value -ne ($index+1) -or [int]$units[$index].Groups[2].Value -ne $count) { throw 'Initial native compilation sequence differs from its total.' }
    }
    return $count
}
foreach ($count in @(109,110)) {
    $lines = @(for ($index = 1; $index -le $count; $index++) { "[$index/$count] 100% fixture.cc" })
    $text = ($lines -join "`n")+"`nSuccessfully compiled $count files`n[resume] Reused verified objects: 0`n"
    if ((Read-CompiledUnitCount $text) -ne $count) { throw 'Native compilation count fixture failed.' }
    foreach ($invalid in @(($text.Replace('[1/','[2/')),($text+"Successfully compiled $count files`n"),($text.Replace('Reused verified objects: 0','Reused verified objects: 1')))) {
        $rejected = $false
        try { Read-CompiledUnitCount $invalid | Out-Null } catch { $rejected = $true }
        if (-not $rejected) { throw 'Malformed or warm initial native sequence was accepted.' }
    }
}
Write-Host '[regression] Actual native unit-count parser: 109/110 sequences and malformed/ambiguous/warm negatives passed.'
$owned = $false; $receipt = @{ revision=$Revision; version=$Version; mode=$Mode; success=$false }; $failure = $null
try {
    if (-not $NativeBuildRevision) { $NativeBuildRevision = $Revision }
    if ($NativeBuildRevision -notmatch '^[a-f0-9]{40}$') { throw 'NativeBuildRevision must be a full commit SHA.' }
    $receipt.nativeBuildRevision = $NativeBuildRevision
    if ($Revision -notmatch '^[a-f0-9]{40}$' -or $Version -notmatch '^[0-9]+\.[0-9]+\.[0-9]+$' -or $ChecksumsSha256 -notmatch '^[a-f0-9]{64}$') { throw 'Invalid release identity or trusted checksum digest.' }
    $head = (& git -C $repository rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $head -ne $Revision) { throw 'Checkout differs from the fixed release revision.' }
    $changes = & git -C $repository status --porcelain --untracked-files=no
    if ($LASTEXITCODE -ne 0 -or $changes) { throw 'Release checkout status is not clean.' }
    Write-Host '[stage] Parse Windows interruption helper before downloading release inputs.'
    $parseTokens = $null; $parseErrors = $null
    [Management.Automation.Language.Parser]::ParseFile((Join-Path $repository 'tests\resumable-windows.ps1'),[ref]$parseTokens,[ref]$parseErrors) | Out-Null
    if ($parseErrors.Count -gt 0) { throw ('Interruption helper parse failed: '+($parseErrors.Message -join '; ')) }
    if (-not [IO.Path]::IsPathRooted($WorkRoot) -or (Test-Path -LiteralPath $WorkRoot)) { throw 'WorkRoot must be a new absolute task directory.' }
    New-Item -ItemType Directory -Path $WorkRoot | Out-Null; $owned = $true
    foreach ($name in @('assets','logs','temp','cache-small','cache-full','composer-home','composer-cache','curl-config')) { New-Item -ItemType Directory -Path (Join-Path $WorkRoot $name) | Out-Null }
    $env:TEMP = Join-Path $WorkRoot 'temp'; $env:TMP = $env:TEMP
    $env:COMPOSER_HOME = Join-Path $WorkRoot 'composer-home'; $env:COMPOSER_CACHE_DIR = Join-Path $WorkRoot 'composer-cache'
    $env:WEBMAN_AOT_NO_PAUSE = '1'; $env:CURL_HOME = Join-Path $WorkRoot 'curl-config'
    [IO.File]::WriteAllText((Join-Path $env:CURL_HOME '.curlrc'),'# Use ordinary system TLS checks.',$utf8)
    $gitCommand = Get-Command git.exe -ErrorAction Stop; $ghCommand = Get-Command gh.exe -ErrorAction Stop
    $env:PATH = (@("$env:SystemRoot\System32",$env:SystemRoot,"$env:SystemRoot\System32\WindowsPowerShell\v1.0",(Split-Path -Parent $gitCommand.Source))) -join ';'
    $assets = Join-Path $WorkRoot 'assets'; $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    $setupName = "webman-aot-builder-$Version-windows-x86_64-setup.cmd"
    $smallName = "webman-aot-builder-$Version-windows-x86_64.zip"
    $fullName = "webman-aot-builder-$Version-full-windows-x86_64.zip"
    $base = "https://github.com/supdger/webman-aot-builder/releases/download/v$Version"
    foreach ($name in @('SHA256SUMS',$setupName,$smallName,$fullName)) {
        if ($Mode -eq 'draft') { Invoke-Native "authenticated draft download $name" $ghCommand.Source @('release','download',('v'+$Version),'--repo','supdger/webman-aot-builder','--pattern',$name,'--dir',$assets) $repository }
        else { Invoke-Native "anonymous canonical download $name" $curl @('--fail','--location','--proto','=https','--proto-redir','=https','--connect-timeout','30','--max-time','1200','--output',(Join-Path $assets $name),($base+'/'+$name)) $repository }
    }
    $sumPath = Join-Path $assets 'SHA256SUMS'
    if ((Get-FileHash -LiteralPath $sumPath -Algorithm SHA256).Hash.ToLowerInvariant() -ne $ChecksumsSha256) { throw 'SHA256SUMS differs from the separately accepted inventory digest.' }
    $hashes = @{}
    foreach ($line in [IO.File]::ReadAllLines($sumPath,$utf8)) {
        if ($line -notmatch '^([a-f0-9]{64})  ([A-Za-z0-9._-]+)$' -or $hashes.ContainsKey($Matches[2])) { throw 'Invalid or duplicate SHA256SUMS line.' }
        $hashes[$Matches[2]] = $Matches[1]
    }
    foreach ($name in @($setupName,$smallName,$fullName)) {
        if (-not $hashes.ContainsKey($name) -or (Get-FileHash -LiteralPath (Join-Path $assets $name) -Algorithm SHA256).Hash.ToLowerInvariant() -ne $hashes[$name]) { throw "Release asset hash mismatch: $name" }
    }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    foreach ($flavor in @('small','full')) {
        $name = if ($flavor -eq 'small') { $smallName } else { $fullName }
        $zip = [IO.Compression.ZipFile]::OpenRead((Join-Path $assets $name))
        try {
            $entries = @($zip.Entries | Where-Object { $_.FullName -eq 'package.json' })
            if ($entries.Count -ne 1) { throw 'Package identity entry is not unique.' }
            $reader = New-Object IO.StreamReader($entries[0].Open(),[Text.Encoding]::UTF8)
            try { $metadata = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
            $expectedFlavor = if ($flavor -eq 'full') { 'complete' } else { 'small' }
            if ($metadata.schema -ne 'webman-aot-builder-installer-package-v1' -or $metadata.version -ne $Version -or $metadata.revision -ne $NativeBuildRevision -or $metadata.platform -ne 'windows-x86_64' -or $metadata.flavor -ne $expectedFlavor) { throw 'Actual package identity differs from the native build revision.' }
        } finally { $zip.Dispose() }
    }
    $runtimePackage = Join-Path $WorkRoot 'fixture-runtime'
    [IO.Compression.ZipFile]::ExtractToDirectory((Join-Path $assets $smallName),$runtimePackage)
    $runtime = Join-Path $runtimePackage 'payload\runtime'; $php = Join-Path $runtime 'php.exe'
    $runtimeLock = (Get-Content -LiteralPath (Join-Path $repository 'installer\runtime.lock.json') -Raw -Encoding UTF8 | ConvertFrom-Json).runtimes.'windows-x86_64'
    if ((Get-FileHash -LiteralPath $php -Algorithm SHA256).Hash.ToLowerInvariant() -ne $runtimeLock.binarySha256) { throw 'Private fixture runtime differs from its lock.' }
    $fixture = Join-Path $WorkRoot 'Webman 项目 fixture'
    Copy-Item -LiteralPath (Join-Path $repository 'tools\fixtures\guided-webman') -Destination $fixture -Recurse
    $composer = Join-Path $WorkRoot 'composer-2.9.5.phar'
    Invoke-Native 'locked Composer download' $curl @('--fail','--location','--proto','=https','--proto-redir','=https','--output',$composer,'https://getcomposer.org/download/2.9.5/composer.phar') $repository
    if ((Get-FileHash -LiteralPath $composer -Algorithm SHA256).Hash.ToLowerInvariant() -ne 'c86ce603fe836bf0861a38c93ac566c8f1e69ac44b2445d9b7a6a17ea2e9972a') { throw 'Composer differs from its official locked SHA.' }
    $fixtureLock = (Get-FileHash -LiteralPath (Join-Path $fixture 'composer.lock') -Algorithm SHA256).Hash
    $env:WEBMAN_AOT_CALLER_CWD = $fixture
    Invoke-Native 'prepare fixed no-database fixture' $php @('-c','php.ini','-d','extension_dir=ext','..\app\tools\windows-php-bootstrap.php',$composer,'install','--no-plugins','--no-scripts','--no-interaction','--prefer-dist','--no-dev') $runtime
    $env:WEBMAN_AOT_CALLER_CWD = $null
    if ((Get-FileHash -LiteralPath (Join-Path $fixture 'composer.lock') -Algorithm SHA256).Hash -ne $fixtureLock -or -not (Test-Path -LiteralPath (Join-Path $fixture 'vendor\autoload.php'))) { throw 'Prepared fixture is incomplete or changed its lock.' }
    foreach ($flavor in @('small','full')) {
        $installHome = Join-Path $WorkRoot ("工具 $flavor home"); $bin = Join-Path $WorkRoot ("工具 $flavor bin")
        $env:LOCALAPPDATA = Join-Path $WorkRoot ("cache-$flavor")
        $env:WEBMAN_AOT_BUILDER_HOME = $installHome
        $arguments = @('-Flavor',$flavor,'-Install','-InstallRoot',$installHome,'-BinDir',$bin,'-NoPath')
        if ($Mode -eq 'draft') { $arguments += @('-Archive',(Join-Path $assets $(if ($flavor -eq 'small') { $smallName } else { $fullName }))) }
        if ($flavor -eq 'full') { $arguments += @('-Project',$fixture) }
        $previousGuidedLogs = @(Get-ChildItem -LiteralPath $env:TEMP -Recurse -Filter 'guided.log' -File | ForEach-Object { $_.FullName })
        Invoke-Native "exact setup $Mode $flavor install" (Join-Path $assets $setupName) $arguments $repository
        if ($flavor -eq 'full') {
            $guidedLogs = @(Get-ChildItem -LiteralPath $env:TEMP -Recurse -Filter 'guided.log' -File | Where-Object { $_.FullName -notin $previousGuidedLogs })
            if ($guidedLogs.Count -ne 1) { throw 'Full setup did not preserve exactly one new guided log.' }
            $guidedText = [IO.File]::ReadAllText($guidedLogs[0].FullName,$utf8)
            $reports = @()
            foreach ($jsonBlock in [regex]::Matches($guidedText,'(?ms)^\{\r?\n.*?^\}')) {
                $candidate = $jsonBlock.Value | ConvertFrom-Json
                if ($candidate.schema -eq 'webman-aot-builder-verify-report-v1') { $reports += $candidate }
            }
            if ($reports.Count -ne 1) { throw 'Full setup automatic verify report is missing or ambiguous.' }
            $verify = $reports[0]
            $expectedDist = [IO.Path]::GetFullPath((Join-Path $fixture 'dist-aot'))
            $unitCount = Read-CompiledUnitCount $guidedText
            if ($verify.scope -ne 'build-host-structure-and-integrity' -or $verify.staticStructure -ne 'pass' -or [IO.Path]::GetFullPath($verify.path) -ne $expectedDist -or -not [regex]::IsMatch($guidedText,'(?m)^\[成功\] 校验本次项目产物，耗时 .+，退出码 0\r?$')) { throw 'Full setup did not prove successful automatic verify for this fixture.' }
            [IO.File]::WriteAllText((Join-Path $WorkRoot 'logs\actual-verify-report.json'),($verify | ConvertTo-Json -Depth 5),$utf8)
            $receipt.verifyScope = $verify.scope; $receipt.verifyPath = $verify.path; $receipt.compiledFiles = $unitCount
            $resumeEvidence = Join-Path $WorkRoot 'resumable-native'
            $installedRuntime = Join-Path $installHome 'current\runtime'
            $installedPhp = Join-Path $installedRuntime 'php.exe'
            $preparedManifests = @(Get-ChildItem -LiteralPath (Join-Path $installHome 'toolchains\versions') -Filter 'prepared-toolchain.json' -Recurse -File)
            if ($preparedManifests.Count -ne 1) { throw 'Installed selected SDK manifest is missing or ambiguous.' }
            Invoke-Native 'installed selected SDK authority regression' $installedPhp @(
                '-n',(Join-Path $repository 'tests\selected-sdk-generator.php'),$preparedManifests[0].FullName) $installedRuntime
            $env:WEBMAN_AOT_CALLER_CWD = $repository
            Invoke-Native 'installed runtime resumable native regression' $installedPhp @(
                '-c','php.ini','-d','extension_dir=ext','..\app\tools\windows-php-bootstrap.php',
                (Join-Path $repository 'tests\resumable-native.php'),$installHome,
                (Join-Path $bin 'webman-aot.cmd'),$fixture,$resumeEvidence) $installedRuntime
            $env:WEBMAN_AOT_CALLER_CWD = $null
            $resumeResults = Get-Content -LiteralPath (Join-Path $resumeEvidence 'results.json') -Raw -Encoding UTF8 | ConvertFrom-Json
            if ($resumeResults.Count -ne 5 -or @($resumeResults | Where-Object { $_.exit -ne 0 }).Count -ne 0 -or
                $resumeResults[0].reused -ne 0 -or $resumeResults[1].reused -ne $unitCount -or
                $resumeResults[2].reused -ne ($unitCount-2) -or $resumeResults[3].reused -ne ($unitCount-1) -or
                $resumeResults[4].reused -ne 0) { throw 'Installed runtime resume/invalidation/fresh assertions failed.' }
            $resumeLogs = Join-Path $WorkRoot 'logs\resumable-native'
            [IO.Directory]::CreateDirectory($resumeLogs) | Out-Null
            foreach ($file in Get-ChildItem -LiteralPath $resumeEvidence -File) {
                Copy-Item -LiteralPath $file.FullName -Destination $resumeLogs
            }
            $receipt.resumableNative = $resumeResults
            $parentEvidence = Join-Path $WorkRoot 'resumable-parent'
            & (Join-Path $repository 'tests\resumable-windows.ps1') -InstallHome $installHome `
                -Launcher (Join-Path $bin 'webman-aot.cmd') -Fixture $fixture -Evidence $parentEvidence -ExpectedCompilationUnits $unitCount
            if (-not $?) { throw 'Windows partial interruption helper failed.' }
            $parentResult = Get-Content -LiteralPath (Join-Path $parentEvidence 'results.json') -Raw -Encoding UTF8 | ConvertFrom-Json
            if (-not $parentResult.success) { throw 'Windows partial interruption evidence failed.' }
            $parentLogs = Join-Path $WorkRoot 'logs\resumable-parent'
            [IO.Directory]::CreateDirectory($parentLogs) | Out-Null
            foreach ($file in Get-ChildItem -LiteralPath $parentEvidence -File) {
                Copy-Item -LiteralPath $file.FullName -Destination $parentLogs
            }
            $receipt.partialInterruption = $parentResult
        }
        $versionLog = Join-Path $WorkRoot ('logs\'+$flavor+'-version.log')
        Invoke-Native "absolute new $flavor launcher version" (Join-Path $bin 'webman-aot.cmd') @('version') $repository $versionLog
        $installedVersion = [IO.File]::ReadAllText($versionLog,$utf8).Trim()
        Write-Host $installedVersion
        if ($installedVersion -ne ('webman-aot '+$Version)) { throw 'New absolute launcher version mismatch.' }
    }
    if (-not (Test-Path -LiteralPath (Join-Path $fixture 'dist-aot\manifest.json'))) { throw 'Full setup did not produce the real fixture output.' }
    $receipt.success = $true; $receipt.setupSha256 = $hashes[$setupName]; $receipt.checksumsSha256 = $ChecksumsSha256
    Write-Host '[release] Both exact setup paths and the full project build/automatic verify passed.'
} catch { $failure = $_; Write-Host ("[release] FAILED: {0}" -f $_.Exception.Message) }
finally {
    foreach ($key in $keys) { [Environment]::SetEnvironmentVariable($key,$saved[$key],'Process') }
    $receipt.persistentPathUnchanged = ((Path-Digest 'User') -eq $userPath -and (Path-Digest 'Machine') -eq $machinePath)
    if (-not $receipt.persistentPathUnchanged) { $receipt.success = $false; Write-Host '[release] Persistent PATH changed.' }
    $receipt.elapsedSeconds = $timer.Elapsed.TotalSeconds
    if ($owned) {
        $cleanupErrors = @()
        $tempRoot = Join-Path $WorkRoot 'temp'
        try {
            if ($fixture -and (Test-Path -LiteralPath $fixture)) {
                $configurationIndex = 0
                foreach ($configuration in Get-ChildItem -LiteralPath $fixture -Filter 'project.linux.yml' -Recurse -File -Force) {
                    $configurationIndex++
                    Copy-Item -LiteralPath $configuration.FullName -Destination (Join-Path $WorkRoot "logs\project-$configurationIndex.linux.yml")
                    Write-Host '[diagnostic] Actual generated compiler source lists:'
                    $sourceSection = $false
                    foreach ($line in [IO.File]::ReadAllLines($configuration.FullName,$utf8)) {
                        if ($line -eq 'sources:' -or $line -eq 'ignore:') { $sourceSection = $true; Write-Host $line; continue }
                        if ($line -ne '' -and -not $line.StartsWith(' ')) { $sourceSection = $false }
                        if ($sourceSection -and $line.StartsWith('  - ')) { Write-Host $line }
                    }
                }
            }
            foreach ($evidenceName in @('resumable-native','resumable-parent')) {
                $evidencePath = Join-Path $WorkRoot $evidenceName
                if (Test-Path -LiteralPath $evidencePath) {
                    $evidenceLogs = Join-Path (Join-Path $WorkRoot 'logs') $evidenceName
                    [IO.Directory]::CreateDirectory($evidenceLogs) | Out-Null
                    foreach ($file in Get-ChildItem -LiteralPath $evidencePath -File) {
                        Copy-Item -LiteralPath $file.FullName -Destination $evidenceLogs -Force
                    }
                }
            }
            if (Test-Path -LiteralPath $tempRoot) {
                foreach ($log in Get-ChildItem -LiteralPath $tempRoot -Filter '*.log' -Recurse -File) {
                    $relative = $log.FullName.Substring($tempRoot.Length).TrimStart('\')
                    $destination = Join-Path (Join-Path $WorkRoot 'logs\temp') $relative
                    [IO.Directory]::CreateDirectory((Split-Path -Parent $destination)) | Out-Null
                    Copy-Item -LiteralPath $log.FullName -Destination $destination
                }
            }
        } catch { $cleanupErrors += $_.Exception.Message }
        foreach ($item in Get-ChildItem -LiteralPath $WorkRoot) {
            if ($item.Name -ne 'logs') {
                try { Remove-OwnedDirectory $item.FullName } catch { $cleanupErrors += $_.Exception.Message }
            }
        }
        if ($cleanupErrors.Count -gt 0) {
            $receipt.success = $false; $receipt.cleanupErrors = $cleanupErrors
            Write-Host ('[cleanup] FAILED: '+($cleanupErrors -join '; '))
        }
        [IO.File]::WriteAllText((Join-Path $WorkRoot 'logs\consumer-result.json'),($receipt | ConvertTo-Json -Depth 5),$utf8)
        Write-Host ("[release] Result: {0}; elapsed {1:F1}s; cleanup checked, logs retained." -f $receipt.success,$timer.Elapsed.TotalSeconds)
    }
}
if ($null -ne $failure -or -not $receipt.success) { exit 1 }
exit 0
