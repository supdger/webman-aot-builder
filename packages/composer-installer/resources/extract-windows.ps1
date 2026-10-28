param([Parameter(Mandatory=$true)][string]$Archive, [Parameter(Mandatory=$true)][string]$Destination)
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.IO.Compression.FileSystem
$zip = [IO.Compression.ZipFile]::OpenRead($Archive)
try {
    $seen = @{}
    foreach ($entry in $zip.Entries) {
        $name = $entry.FullName
        if ($name -notmatch '^[A-Za-z0-9_.][A-Za-z0-9_./-]*$' -or $name -match '(^|/)\.\.?(/|$)' -or $seen.ContainsKey($name)) {
            throw 'Unsafe or duplicate ZIP entry.'
        }
        $seen[$name] = $true
        $kind = ($entry.ExternalAttributes -shr 16) -band 0xF000
        if ($kind -ne 0 -and $kind -ne 0x8000 -and $kind -ne 0x4000) { throw 'ZIP links and special files are forbidden.' }
    }
    $root = [IO.Path]::GetFullPath($Destination).TrimEnd('\') + '\'
    foreach ($entry in $zip.Entries) {
        $target = [IO.Path]::GetFullPath((Join-Path $root $entry.FullName))
        if (-not $target.StartsWith($root, [StringComparison]::OrdinalIgnoreCase)) { throw 'ZIP entry escaped destination.' }
        if ($entry.FullName.EndsWith('/')) { [IO.Directory]::CreateDirectory($target) | Out-Null; continue }
        [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($target)) | Out-Null
        [IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $target, $false)
    }
} finally { $zip.Dispose() }
