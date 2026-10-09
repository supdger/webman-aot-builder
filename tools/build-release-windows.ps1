[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Revision,
    [Parameter(Mandatory=$true)][string]$Output,
    [string]$ExpectedVersion = '0.4.3'
)
$ErrorActionPreference = 'Stop'
$repository = Split-Path -Parent $PSScriptRoot
$started = [DateTime]::UtcNow
try {
    if ($Revision -notmatch '^[a-f0-9]{40}$') { throw 'Revision must be a full commit SHA.' }
    $actual = (& git -C $repository rev-parse HEAD).Trim()
    if ($LASTEXITCODE -ne 0 -or $actual -ne $Revision) { throw 'Checkout does not match the requested release revision.' }
    $trackedChanges = & git -C $repository status --porcelain --untracked-files=no
    if ($LASTEXITCODE -ne 0) { throw 'Unable to check release checkout status.' }
    if ($trackedChanges) { throw 'Release checkout has tracked changes.' }
    $versionText = [IO.File]::ReadAllText((Join-Path $repository 'src\Version.php'))
    if ($versionText -notmatch "VALUE = '([0-9]+\.[0-9]+\.[0-9]+)'" -or $Matches[1] -ne $ExpectedVersion) { throw 'Release version differs from the requested version.' }
    if (-not [IO.Path]::IsPathRooted($Output) -or (Test-Path -LiteralPath $Output)) { throw 'Output must be a new absolute task directory.' }
    New-Item -ItemType Directory -Path $Output | Out-Null
    $lock = Get-Content -LiteralPath (Join-Path $repository 'toolchain\minimal-components.lock.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    $component = $lock.components.'windows-x86_64'
    $toolchainHash = (Get-FileHash -LiteralPath (Join-Path $repository 'toolchain.lock.json') -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($lock.version -ne $ExpectedVersion -or $lock.toolchainLockSha256 -ne $toolchainHash) { throw 'Component lock version or toolchain hash differs.' }
    Write-Host "[release] Fixed revision $Revision / version $ExpectedVersion"
    Write-Host "[input] Downloading the immutable, SHA-locked $ExpectedVersion Windows component."
    $inputPath = Join-Path $Output $component.archive
    $curl = Join-Path $env:SystemRoot 'System32\curl.exe'
    $url = "https://github.com/supdger/webman-aot-builder/releases/download/v$ExpectedVersion/$($component.archive)"
    if ($env:GH_TOKEN) {
        & gh release download "v$ExpectedVersion" --repo supdger/webman-aot-builder --pattern $component.archive --dir $Output
        if ($LASTEXITCODE -ne 0) { throw "Locked draft/public component download failed: $LASTEXITCODE" }
    } else {
        $download = Start-Process -FilePath $curl -ArgumentList @('--fail','--location','--proto','=https','--proto-redir','=https','--retry','2','--connect-timeout','30','--max-time','1200','--output',('"'+$inputPath+'"'),$url) -NoNewWindow -PassThru
        $downloadHandle = $download.Handle
        try {
            $download.WaitForExit()
            $downloadExit = $download.ExitCode
            if ($downloadExit -isnot [int]) { throw 'Unknown curl exit status.' }
            if ($downloadExit -ne 0) { throw "Component download failed (curl exit $downloadExit)." }
        } finally { $download.Dispose() }
    }
    $inputHash = (Get-FileHash -LiteralPath $inputPath -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($inputHash -ne $component.sha256) { throw 'Component archive SHA-256 mismatch.' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($inputPath)
    try {
        $entry = $zip.GetEntry('minimal-component.json')
        if ($null -eq $entry) { throw 'Component manifest is missing.' }
        $stream = $entry.Open(); $memory = New-Object IO.MemoryStream
        try { $stream.CopyTo($memory); $bytes = $memory.ToArray() } finally { $stream.Dispose(); $memory.Dispose() }
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $manifestHash = ([BitConverter]::ToString($sha.ComputeHash($bytes))).Replace('-','').ToLowerInvariant() } finally { $sha.Dispose() }
        $manifest = [Text.Encoding]::UTF8.GetString($bytes) | ConvertFrom-Json
        if ($manifestHash -ne $component.manifestSha256 -or $manifest.schema -ne 'webman-aot-builder-minimal-component-v1' -or $manifest.host -ne 'windows-x86_64' -or $manifest.toolchainLockSha256 -ne $toolchainHash) { throw 'Component manifest identity mismatch.' }
    } finally { $zip.Dispose() }
    Write-Host "[input] Archive and manifest verified: $inputHash"
    $provenance = @()
    $utf8 = New-Object Text.UTF8Encoding($false)
    foreach ($flavor in @('small','full')) {
        Write-Host "[package] Building $flavor from the fixed release revision"
        $result = Join-Path $Output ($flavor+'-source-result.json')
        & (Join-Path $PSScriptRoot 'build-windows-installer.ps1') -Flavor $flavor -Revision $Revision -MinimalComponent $inputPath -Output $Output -Result $result
        if ($LASTEXITCODE -ne 0) { throw "Native $flavor package/selftest failed (exit $LASTEXITCODE)." }
        $record = Get-Content -LiteralPath $result -Raw -Encoding UTF8 | ConvertFrom-Json
        if ($record.schema -ne 'webman-aot-builder-source-build-result-v1' -or $record.revision -ne $Revision -or $record.platform -ne 'windows-x86_64' -or $record.flavor -ne $flavor -or $record.verified -notcontains 'payload-manifest' -or $record.verified -notcontains 'isolated-install-version') { throw 'Native result identity or required selftests are invalid.' }
        $suffix = if ($flavor -eq 'full') { '-full' } else { '' }
        $expectedArchive = Join-Path $Output "webman-aot-builder-$ExpectedVersion$suffix-windows-x86_64.zip"
        if ($record.archive -ne [IO.Path]::GetFullPath($expectedArchive) -or (Get-Item -LiteralPath $record.archive).Length -ne $record.size -or (Get-FileHash -LiteralPath $record.archive -Algorithm SHA256).Hash.ToLowerInvariant() -ne $record.sha256) { throw 'Native output differs from the verified result.' }
        $packageZip = [IO.Compression.ZipFile]::OpenRead($record.archive)
        try {
            $identityEntries = @($packageZip.Entries | Where-Object { $_.FullName -eq 'package.json' })
            if ($identityEntries.Count -ne 1) { throw 'Package must contain exactly one package.json.' }
            $reader = New-Object IO.StreamReader($identityEntries[0].Open(), [Text.Encoding]::UTF8)
            try { $identity = $reader.ReadToEnd() | ConvertFrom-Json } finally { $reader.Dispose() }
        } finally { $packageZip.Dispose() }
        $packageFlavor = if ($flavor -eq 'full') { 'complete' } else { 'small' }
        if ($identity.schema -ne 'webman-aot-builder-installer-package-v1' -or $identity.version -ne $ExpectedVersion -or $identity.revision -ne $Revision -or $identity.platform -ne 'windows-x86_64' -or $identity.flavor -ne $packageFlavor) { throw 'Actual ZIP package identity differs from the release.' }
        # Windows emits a source result, not the Mac packager sidecar. This is
        # a derived consumer adapter; the original source result stays untouched.
        $derivedPath = Join-Path $Output ($flavor+'-derived-packager-result.json')
        $derived = @{ schema = 'webman-aot-builder-installer-package-result-v1'; revision = $Revision
            packages = @(@{ platform = 'windows-x86_64'; path = $record.archive; size = [long]$record.size; sha256 = $record.sha256 }) }
        [IO.File]::WriteAllText($derivedPath, ($derived | ConvertTo-Json -Depth 5), $utf8)
        $provenance += @{ flavor = $flavor; sourceResult = $result
            sourceResultSha256 = (Get-FileHash -LiteralPath $result -Algorithm SHA256).Hash.ToLowerInvariant()
            derivedResult = $derivedPath; derivation = 'verified-source-result-and-actual-ZIP-package.json'
            archive = $record.archive; archiveSha256 = $record.sha256; size = [long]$record.size
            version = $identity.version; revision = $identity.revision; packageFlavor = $identity.flavor }
        Write-Host "[package] Verified ${flavor}: $($record.sha256) / $($record.size) bytes"
    }
    [IO.File]::WriteAllText((Join-Path $Output 'derived-provenance.json'), ($provenance | ConvertTo-Json -Depth 5), $utf8)
    Write-Host ("[release] Both Windows packages and isolated installation/version selftests passed in {0:F1}s. Output: {1}" -f ([DateTime]::UtcNow-$started).TotalSeconds,$Output)
    exit 0
} catch {
    Write-Host ("[release] FAILED after {0:F1}s: {1}" -f ([DateTime]::UtcNow-$started).TotalSeconds,$_.Exception.Message)
    exit 1
}
