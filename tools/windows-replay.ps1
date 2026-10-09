[CmdletBinding()]
param(
    [string] $Artifacts = '',

    [string] $WorkRoot = '',

    [string] $LockFile = '',

    [switch] $PrepareOnly,

    [switch] $Offline
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

function Assert-LastExitCode([string] $Step) {
    if ($LASTEXITCODE -ne 0) {
        throw "$Step failed with exit code $LASTEXITCODE"
    }
}

function Get-LockedComponent([object] $Lock, [string] $Id) {
    $component = $Lock.components | Where-Object { $_.id -eq $Id } | Select-Object -First 1
    if ($null -eq $component) {
        throw "locked component is missing: $Id"
    }
    return $component
}

function Get-ArchivePath([object] $Component, [string] $Directory) {
    $uri = [Uri] $Component.sourceUrl
    return Join-Path $Directory ([IO.Path]::GetFileName($uri.AbsolutePath))
}

function Invoke-VerifiedDownload([object] $Component, [string] $Path) {
    $partial = "$Path.partial"
    if (-not (Test-Path -LiteralPath $partial -PathType Leaf)) {
        $legacyPartial = Get-ChildItem `
            -LiteralPath (Split-Path -Parent $Path) `
            -Filter "$([IO.Path]::GetFileName($Path)).partial-*" `
            -File |
            Sort-Object Length -Descending |
            Select-Object -First 1
        if ($null -ne $legacyPartial) {
            Move-Item -LiteralPath $legacyPartial.FullName -Destination $partial
        }
    }

    $curl = Get-Command 'curl.exe' -CommandType Application -ErrorAction SilentlyContinue |
        Select-Object -First 1
    if ($null -ne $curl) {
        & $curl.Source `
            '--fail' `
            '--location' `
            '--retry' '5' `
            '--retry-delay' '2' `
            '--retry-all-errors' `
            '--connect-timeout' '30' `
            '--continue-at' '-' `
            '--output' $partial `
            $Component.sourceUrl
        Assert-LastExitCode "resumable download for $($Component.id)"
    } else {
        for ($attempt = 1; $attempt -le 3; $attempt++) {
            try {
                Remove-Item -LiteralPath $partial -Force -ErrorAction SilentlyContinue
                Invoke-WebRequest `
                    -UseBasicParsing `
                    -Uri $Component.sourceUrl `
                    -OutFile $partial
                break
            } catch {
                if ($attempt -eq 3) {
                    throw
                }
                Start-Sleep -Seconds (2 * $attempt)
            }
        }
    }

    $downloadDigest = (Get-FileHash -LiteralPath $partial -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($downloadDigest -ne ([string] $Component.sha256).ToLowerInvariant()) {
        Remove-Item -LiteralPath $partial -Force
        throw "downloaded archive digest mismatch: $partial"
    }
    Move-Item -LiteralPath $partial -Destination $Path
    Get-ChildItem `
        -LiteralPath (Split-Path -Parent $Path) `
        -Filter "$([IO.Path]::GetFileName($Path)).partial-*" `
        -File |
        Remove-Item -Force
}

function Resolve-LockedArchive([object] $Component, [string] $Directory, [bool] $Offline) {
    $path = Get-ArchivePath $Component $Directory
    if (-not (Test-Path -LiteralPath $path -PathType Leaf)) {
        if ($Offline) {
            throw "locked archive is missing in offline mode: $path"
        }
        Write-Host "Downloading $($Component.id) ..."
        Invoke-VerifiedDownload $Component $path
    }
    $actual = (Get-FileHash -LiteralPath $path -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($actual -ne ([string] $Component.sha256).ToLowerInvariant()) {
        throw "locked archive digest mismatch: $path"
    }
    return $path
}

function Get-SingleDirectory([string] $Directory, [string] $Description) {
    $directories = @(Get-ChildItem -LiteralPath $Directory -Directory)
    if ($directories.Count -ne 1) {
        throw "$Description must contain exactly one directory: $Directory"
    }
    return $directories[0].FullName
}

function Get-WorkRelativePath([string] $Root, [string] $Path) {
    $prefix = $Root.TrimEnd('\', '/') + '\'
    if (-not $Path.StartsWith($prefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "prepared tool path escaped the work root: $Path"
    }
    return $Path.Substring($prefix.Length).Replace('\', '/')
}

if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT -or
    [Environment]::Is64BitOperatingSystem -ne $true) {
    throw 'windows-replay.ps1 requires a Windows x64 host'
}

$repository = Split-Path -Parent $PSScriptRoot
$defaultLock = Join-Path $repository 'toolchain.lock.json'
if ([string]::IsNullOrWhiteSpace($LockFile)) {
    $LockFile = $defaultLock
}
$LockFile = [IO.Path]::GetFullPath($LockFile)
$systemTar = Join-Path $env:SystemRoot 'System32\tar.exe'
if (-not (Test-Path -LiteralPath $systemTar -PathType Leaf)) {
    throw "Windows system tar is unavailable: $systemTar"
}
$privateRoot = Join-Path ([Environment]::GetFolderPath('LocalApplicationData')) 'webman-aot-builder'
if ([string]::IsNullOrWhiteSpace($Artifacts)) {
    $Artifacts = Join-Path $privateRoot 'artifacts'
}
if ([string]::IsNullOrWhiteSpace($WorkRoot)) {
    $runId = '{0}-{1}' -f (
        [DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'),
        [Guid]::NewGuid().ToString('N').Substring(0, 8)
    )
    $WorkRoot = Join-Path (Join-Path $privateRoot 'replay') $runId
}
$Artifacts = [IO.Path]::GetFullPath($Artifacts)
$WorkRoot = [IO.Path]::GetFullPath($WorkRoot)
if (-not (Test-Path -LiteralPath $Artifacts -PathType Container)) {
    New-Item -ItemType Directory -Path $Artifacts | Out-Null
}
if (Test-Path -LiteralPath $WorkRoot) {
    if ((Get-ChildItem -LiteralPath $WorkRoot -Force | Measure-Object).Count -ne 0) {
        throw "work root must not exist or must be empty: $WorkRoot"
    }
} else {
    New-Item -ItemType Directory -Path $WorkRoot | Out-Null
}

$lock = Get-Content -LiteralPath $LockFile -Raw |
    ConvertFrom-Json
$componentIds = @(
    'typephp-source',
    'typephp-windows-x64',
    'php-driver-windows-x64',
    'phpx-source',
    'phpx-sdk-linux-x64',
    'llvm-windows-x64',
    'alpine-musl-dev-x86-64',
    'alpine-linux-headers-x86-64',
    'alpine-libstdcpp-dev-x86-64',
    'alpine-fortify-headers-x86-64',
    'alpine-gcc-x86-64'
)
$archives = @{}
[Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
foreach ($id in $componentIds) {
    $component = Get-LockedComponent $lock $id
    $archives[$id] = Resolve-LockedArchive $component $Artifacts $Offline
}

$hostExtract = Join-Path $WorkRoot 'typephp-host'
Write-Host 'Extracting TypePHP Windows host package ...'
New-Item -ItemType Directory -Path $hostExtract | Out-Null
& $systemTar -xf $archives['typephp-windows-x64'] -C $hostExtract
Assert-LastExitCode 'TypePHP Windows archive extraction'
$hostRoot = Get-SingleDirectory $hostExtract 'TypePHP Windows archive'
$sevenZip = Join-Path $hostRoot 'PhpManager\private\bin\7za-x64.exe'
foreach ($required in @($sevenZip)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "official TypePHP Windows package is incomplete: $required"
    }
}

$driverRoot = Join-Path $WorkRoot 'php-driver'
Write-Host 'Extracting selected PHP Windows driver ...'
New-Item -ItemType Directory -Path $driverRoot | Out-Null
& $systemTar -xf $archives['php-driver-windows-x64'] -C $driverRoot
Assert-LastExitCode 'PHP Windows driver extraction'
$php = Join-Path $driverRoot 'php.exe'
if (-not (Test-Path -LiteralPath $php -PathType Leaf)) {
    throw "locked PHP Windows driver is incomplete: $php"
}
$driverVersion = (& $php -n -r 'echo PHP_VERSION;')
Assert-LastExitCode 'PHP Windows driver version'
& $php (Join-Path $PSScriptRoot 'check-toolchain-capabilities.php') 'php' $php
Assert-LastExitCode 'PHP compiler runtime capabilities'

$sourceExtract = Join-Path $WorkRoot 'typephp-source'
Write-Host 'Extracting TypePHP source ...'
New-Item -ItemType Directory -Path $sourceExtract | Out-Null
& $systemTar -xf $archives['typephp-source'] -C $sourceExtract
Assert-LastExitCode 'TypePHP source extraction'
$typephpRoot = Get-SingleDirectory $sourceExtract 'TypePHP source archive'
Copy-Item -LiteralPath (Join-Path $hostRoot 'vendor') -Destination $typephpRoot -Recurse
$swooleVendor = Join-Path $typephpRoot 'vendor\swoole'
New-Item -ItemType Directory -Path $swooleVendor -Force | Out-Null

$phpxExtract = Join-Path $WorkRoot 'phpx-source'
Write-Host 'Extracting PHPX source ...'
New-Item -ItemType Directory -Path $phpxExtract | Out-Null
& $systemTar -xf $archives['phpx-source'] -C $phpxExtract
Assert-LastExitCode 'PHPX source extraction'
$phpxSource = Get-SingleDirectory $phpxExtract 'PHPX source archive'
$phpx = Join-Path $swooleVendor 'phpx'
Move-Item -LiteralPath $phpxSource -Destination $phpx

$sdkExtract = Join-Path $WorkRoot 'sdk-extract'
Write-Host 'Extracting Linux x64 PHPX SDK ...'
New-Item -ItemType Directory -Path $sdkExtract | Out-Null
& $sevenZip x '-y' "-o$sdkExtract" $archives['phpx-sdk-linux-x64'] | Out-Host
Assert-LastExitCode 'PHPX SDK xz extraction'
$sdkTar = Get-ChildItem -LiteralPath $sdkExtract -Filter '*.tar' -File |
    Select-Object -First 1
if ($null -eq $sdkTar) {
    throw 'PHPX SDK xz archive did not yield a tar file'
}
$sdkPayload = Join-Path $WorkRoot 'sdk-payload'
New-Item -ItemType Directory -Path $sdkPayload | Out-Null
$sdkLock = Get-LockedComponent $lock 'phpx-sdk-linux-x64'
$sdkArchiveName = [IO.Path]::GetFileName((Get-ArchivePath $sdkLock $Artifacts))
if ($sdkArchiveName -notmatch '^(webman-aot-builder-[0-9]+\.[0-9]+\.[0-9]+-derived-linux-x86_64-sdk)\.tar\.xz$') {
    throw 'PHPX SDK requires the locked builder-derived archive'
}
$sdkArchiveRoot = $Matches[1]
$approvedSdkSha256 = [string] $lock.evidence.patchedSdk.sdkSha256
if ($approvedSdkSha256 -notmatch '^[a-f0-9]{64}$') {
    throw 'PHPX SDK approved derived fingerprint is missing or invalid'
}
& $sevenZip x '-y' "-o$sdkPayload" $sdkTar.FullName | Out-Host
Assert-LastExitCode 'PHPX SDK tar extraction'
$sdkSource = Get-SingleDirectory $sdkPayload 'PHPX SDK archive'
if ([IO.Path]::GetFileName($sdkSource) -ne $sdkArchiveRoot) {
    throw 'PHPX SDK derived archive root differs from the locked filename'
}
$ncursesTarget = Join-Path $sdkSource 'include\ncursesw\curses.h'
$ncursesCopy = Join-Path $sdkSource 'include\ncursesw\ncurses.h'
foreach ($header in @($ncursesTarget, $ncursesCopy)) {
    if (-not (Test-Path -LiteralPath $header -PathType Leaf)
        -or ((Get-Item -LiteralPath $header).Attributes -band [IO.FileAttributes]::ReparsePoint)) {
        throw 'Derived PHPX SDK requires portable regular ncurses headers'
    }
    if ((Get-FileHash -LiteralPath $header -Algorithm SHA256).Hash.ToLowerInvariant()
        -ne 'd020361b2ac530ccf0494a479264976ee3a0c903ba30291b23c266ef926f85de') {
        throw 'Derived PHPX SDK ncurses header digest mismatch'
    }
}
$fullStatic = Join-Path $phpx 'full-static'
New-Item -ItemType Directory -Path $fullStatic -Force | Out-Null
$sdk = Join-Path $fullStatic 'sdk'
Move-Item -LiteralPath $sdkSource -Destination $sdk

$llvmExtract = Join-Path $WorkRoot 'llvm-extract'
Write-Host 'Extracting portable LLVM toolchain into isolated work root ...'
New-Item -ItemType Directory -Path $llvmExtract | Out-Null
& $sevenZip x '-y' "-o$llvmExtract" $archives['llvm-windows-x64'] | Out-Host
Assert-LastExitCode 'LLVM xz extraction'
$llvmTar = Get-ChildItem -LiteralPath $llvmExtract -Filter '*.tar' -File |
    Select-Object -First 1
if ($null -eq $llvmTar) {
    throw 'LLVM xz archive did not yield a tar file'
}
$llvmPayload = Join-Path $WorkRoot 'llvm-payload'
New-Item -ItemType Directory -Path $llvmPayload | Out-Null
& $sevenZip x '-y' "-o$llvmPayload" $llvmTar.FullName | Out-Host
Assert-LastExitCode 'LLVM tar extraction'
$llvmRoot = Get-SingleDirectory $llvmPayload 'LLVM archive'
$compiler = Join-Path $llvmRoot 'bin\clang++.exe'
$llvmNm = Join-Path $llvmRoot 'bin\llvm-nm.exe'
$llvmObjcopy = Join-Path $llvmRoot 'bin\llvm-objcopy.exe'
$compilerVersion = ''
foreach ($required in @($compiler, $llvmNm, $llvmObjcopy)) {
    if (-not (Test-Path -LiteralPath $required -PathType Leaf)) {
        throw "locked LLVM archive is incomplete: $required"
    }
}
$compilerVersion = (& $compiler --version 2>$null | Select-Object -First 1)
& $php (Join-Path $PSScriptRoot 'check-toolchain-capabilities.php') 'cxx' $compiler
Assert-LastExitCode 'C++17 Linux target capabilities'

Write-Host 'Stripping debug sections from the private SDK work copy ...'
$strippedSdk = & $php (Join-Path $repository 'tools\strip-sdk-debug.php') `
    "--sdk=$sdk" `
    "--objcopy=$llvmObjcopy" |
    ConvertFrom-Json
Assert-LastExitCode 'private SDK debug stripping'
if ($strippedSdk.sha256 -ne $approvedSdkSha256) {
    throw "stripped SDK digest mismatch: $($strippedSdk.sha256)"
}
$strippedSdk | ConvertTo-Json -Depth 4 | Write-Host

$phpConfig = Join-Path $WorkRoot 'php-config'
New-Item -ItemType Directory -Path $phpConfig | Out-Null
$env:PHP_HOME = $driverRoot
$env:PHPX_HOME = $phpx
$env:PHPRC = $phpConfig
$env:PATH = "$driverRoot;$(Split-Path -Parent $compiler);$env:PATH"

Write-Host 'Applying guarded TypePHP/PHPX patches ...'
& $php (Join-Path $repository 'tools\apply-typephp-patches.php') `
    "--typephp=$typephpRoot" `
    "--phpx=$phpx"
Assert-LastExitCode 'TypePHP patch application'

$sysroot = Join-Path $WorkRoot 'sysroot'
Write-Host 'Assembling locked Linux x86-64 musl sysroot ...'
& $php (Join-Path $repository 'tools\assemble-sysroot.php') `
    "--artifacts=$Artifacts" `
    "--output=$sysroot" `
    "--tar=$systemTar" `
    "--lock=$LockFile"
Assert-LastExitCode 'musl sysroot assembly'

if ($PrepareOnly) {
    $prepared = [ordered] @{
        schema = 'webman-aot-builder-prepared-toolchain-v1'
        host = 'windows-x86_64'
        lockSha256 = (Get-FileHash -LiteralPath $LockFile -Algorithm SHA256).Hash.ToLowerInvariant()
        php = (Get-WorkRelativePath $WorkRoot $php)
        typephp = (Get-WorkRelativePath $WorkRoot $typephpRoot)
        phpx = (Get-WorkRelativePath $WorkRoot $phpx)
        compiler = (Get-WorkRelativePath $WorkRoot $compiler)
        objcopy = (Get-WorkRelativePath $WorkRoot $llvmObjcopy)
        sysroot = (Get-WorkRelativePath $WorkRoot $sysroot)
        phprc = (Get-WorkRelativePath $WorkRoot $phpConfig)
        sdkSha256 = $strippedSdk.sha256
    }
    $preparedPath = Join-Path $WorkRoot 'prepared-toolchain.json'
    [IO.File]::WriteAllText(
        $preparedPath,
        ($prepared | ConvertTo-Json -Depth 8),
        [System.Text.UTF8Encoding]::new($false)
    )
    $prepared | ConvertTo-Json -Depth 8
    return
}

Write-Host 'Calculating normalized reproducibility input ...'
$normalizedInput = & $php (Join-Path $repository 'tools\reproducibility-input.php') |
    ConvertFrom-Json
Assert-LastExitCode 'normalized input calculation'

$output = Join-Path $WorkRoot 'probe'
Write-Host 'Building full-static Linux x86-64 probe ...'
& $php (Join-Path $repository 'tools\build-full-static-smoke.php') `
    "--typephp=$typephpRoot" `
    "--php=$php" `
    "--phpx=$phpx" `
    "--compiler=$compiler" `
    "--sysroot=$sysroot" `
    "--sdk=$sdk" `
    "--output=$output"
Assert-LastExitCode 'Windows full-static replay'

$artifact = Join-Path $output 'full_static_cross_smoke'
$result = [ordered] @{
    schema = 'webman-aot-builder-windows-replay-v1'
    host = 'windows-x86_64'
    containerUsed = $false
    normalizedInputSha256 = $normalizedInput.sha256
    expectedNormalizedInputSha256 = 'f18bac511781f372bb65e4fe4b4ac518535792a4d57b896f84b050f540419ba2'
    matchesMacNormalizedInput = $false
    artifact = $artifact
    artifactSize = (Get-Item -LiteralPath $artifact).Length
    artifactSha256 = (Get-FileHash -LiteralPath $artifact -Algorithm SHA256).Hash.ToLowerInvariant()
    expectedMacArtifactSha256 = '95005fdcfa288464fefa5fd88f32886a0ae4cd30f7fa698103a2647a344f56e3'
    matchesMacArtifact = $false
}
$result.matchesMacNormalizedInput = (
    $result.normalizedInputSha256 -eq $result.expectedNormalizedInputSha256
)
$result.matchesMacArtifact = $result.artifactSha256 -eq $result.expectedMacArtifactSha256
$evidence = Join-Path $WorkRoot 'windows-replay.json'
[IO.File]::WriteAllText(
    $evidence,
    ($result | ConvertTo-Json -Depth 8),
    [System.Text.UTF8Encoding]::new($false)
)
$result | ConvertTo-Json -Depth 8
if (-not $result.matchesMacNormalizedInput) {
    throw "Windows normalized input SHA-256 differs from Mac baseline; evidence: $evidence"
}
if (-not $result.matchesMacArtifact) {
    throw "Windows ELF SHA-256 differs from Mac baseline; evidence: $evidence"
}
