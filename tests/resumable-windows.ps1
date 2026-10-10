[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$InstallHome,
    [Parameter(Mandatory=$true)][string]$Launcher,
    [Parameter(Mandatory=$true)][string]$Fixture,
    [Parameter(Mandatory=$true)][string]$Evidence,
    [int]$ExpectedCompilationUnits = 0
)
$ErrorActionPreference = 'Stop'
$utf8 = New-Object Text.UTF8Encoding($false)
if (-not [IO.Path]::IsPathRooted($Evidence) -or (Test-Path -LiteralPath $Evidence)) { throw 'Evidence must be a new absolute owned directory.' }
New-Item -ItemType Directory -Path $Evidence | Out-Null
$project = Join-Path $Evidence 'hardkill Webman 项目'
New-Item -ItemType Directory -Path $project | Out-Null
foreach ($name in @('composer.json','composer.lock','start.php','app','config','vendor')) {
    Copy-Item -LiteralPath (Join-Path $Fixture $name) -Destination $project -Recurse
}
$runtime = Join-Path $InstallHome 'current\runtime'
$php = Join-Path $runtime 'php.exe'
$builder = Join-Path $InstallHome 'current\app\bin\webman-aot-builder.php'
$bootstrap = Join-Path $InstallHome 'current\app\tools\windows-php-bootstrap.php'
$keys = @('WEBMAN_AOT_BUILDER_HOME','WEBMAN_AOT_CALLER_CWD','WEBMAN_AOT_BUILDER_BOOTSTRAPPED')
$saved = @{}; foreach ($key in $keys) { $saved[$key] = [Environment]::GetEnvironmentVariable($key,'Process') }
$env:WEBMAN_AOT_BUILDER_HOME = $InstallHome
$env:WEBMAN_AOT_CALLER_CWD = $project
$env:WEBMAN_AOT_BUILDER_BOOTSTRAPPED = '1'
$ownedProcesses = @()
$offsets = @{}
$receipt = @{ success=$false; termination='builder PHP parent and its recorded native child tree force-stopped before public retry'; publicRetryLauncher=$Launcher }
function Read-Log([string]$Path) {
    if (-not (Test-Path -LiteralPath $Path)) { return '' }
    $stream = New-Object IO.FileStream($Path,[IO.FileMode]::Open,[IO.FileAccess]::Read,[IO.FileShare]::ReadWrite)
    $reader = New-Object IO.StreamReader($stream,$utf8)
    try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
}
function Show-Logs([string]$Prefix) {
    foreach ($suffix in @('stdout','stderr')) {
        $path = Join-Path $Evidence ($Prefix+'-'+$suffix+'.log')
        $text = Read-Log $path
        $offset = if ($offsets.ContainsKey($path)) { $offsets[$path] } else { 0 }
        if ($text.Length -gt $offset) { Write-Host -NoNewline $text.Substring($offset); $offsets[$path] = $text.Length }
    }
}
function Record-Tree([int]$RootPid) {
    $all = @(Get-CimInstance Win32_Process)
    $selected = @($RootPid); $found = @()
    do {
        $new = @($all | Where-Object { $_.ProcessId -in $selected -and $_.ProcessId -notin @($found | ForEach-Object { $_.ProcessId }) })
        $found += $new
        $selected = @($all | Where-Object { $_.ParentProcessId -in $selected } | ForEach-Object { [int]$_.ProcessId })
    } while ($selected.Count -gt 0)
    return $found
}
function Start-Builder([string]$Prefix) {
    $arguments = @('-c','php.ini','-d','extension_dir=ext',$bootstrap,$builder,'build') | ForEach-Object {
        if ($_ -match '["\r\n]') { throw 'Controlled builder argument contains quote/newline.' }
        '"'+$_+'"'
    }
    $process = Start-Process -FilePath $php -ArgumentList $arguments -WorkingDirectory $runtime -NoNewWindow -PassThru `
        -RedirectStandardOutput (Join-Path $Evidence ($Prefix+'-stdout.log')) -RedirectStandardError (Join-Path $Evidence ($Prefix+'-stderr.log'))
    $null = $process.Handle
    return $process
}
function Wait-Owned([Diagnostics.Process]$Process,[string]$Prefix) {
    $clock = [Diagnostics.Stopwatch]::StartNew(); $next = 5
    while (-not $Process.WaitForExit(200)) {
        Show-Logs $Prefix
        if ($clock.Elapsed.TotalSeconds -gt 1800) { throw "$Prefix exceeded 1800 seconds." }
        if ($clock.Elapsed.TotalSeconds -ge $next) { Write-Host ("[test] {0} alive {1:F0}s" -f $Prefix,$clock.Elapsed.TotalSeconds); $next += 5 }
    }
    $Process.WaitForExit(); Show-Logs $Prefix
    return $Process.ExitCode
}
$parent = $null; $retry = $null; $concurrent = $null
try {
    Write-Host '[test] Starting installed builder parent; waiting for completed native checkpoints.'
    $parent = Start-Builder 'interrupted'
    $ownedProcesses += Record-Tree $parent.Id
    $clock = [Diagnostics.Stopwatch]::StartNew(); $next = 5; $lockChecked = $false
    do {
        Start-Sleep -Milliseconds 100
        Show-Logs 'interrupted'
        if ($parent.HasExited) { throw 'Builder completed before the partial interruption trigger.' }
        $text = (Read-Log (Join-Path $Evidence 'interrupted-stdout.log'))+(Read-Log (Join-Path $Evidence 'interrupted-stderr.log'))
        if (-not $lockChecked -and $text.Contains('[build] fingerprint')) {
            Write-Host '[test] Checking same-project concurrent build refuses before compilation.'
            $concurrent = Start-Builder 'concurrent'
            $ownedProcesses += Record-Tree $concurrent.Id
            $code = Wait-Owned $concurrent 'concurrent'
            $lockText = (Read-Log (Join-Path $Evidence 'concurrent-stdout.log'))+(Read-Log (Join-Path $Evidence 'concurrent-stderr.log'))
            if ($code -eq 0 -or -not $lockText.Contains('此项目正在构建')) { throw 'Concurrent build did not safely refuse with the project lock.' }
            $receipt.concurrentExit = $code; $lockChecked = $true
        }
        $cache = Join-Path $project '.webman-aot-builder\cache\objects'
        $completed = @(Get-ChildItem -LiteralPath $cache -Filter complete.json -File -Recurse -ErrorAction SilentlyContinue)
        if ($clock.Elapsed.TotalSeconds -gt 1800) { throw 'Checkpoint trigger exceeded 1800 seconds.' }
        if ($clock.Elapsed.TotalSeconds -ge $next) { Write-Host ("[test] parent alive {0:F0}s; completed checkpoints={1}" -f $clock.Elapsed.TotalSeconds,$completed.Count); $next += 5 }
    } while ($completed.Count -lt 5)
    $progress = [regex]::Matches($text,'(?m)^\[([1-9][0-9]*)/([1-9][0-9]*)\] [0-9]+% .+\.(?:cc|cpp|c)\r?$')
    $totals = @($progress | ForEach-Object { [int]$_.Groups[2].Value } | Sort-Object -Unique)
    if ($totals.Count -ne 1 -or $totals[0] -le 2 -or ($ExpectedCompilationUnits -gt 0 -and $totals[0] -ne $ExpectedCompilationUnits)) { throw 'Interrupted native plan count is missing or differs from the verified initial build.' }
    $unitCount = $totals[0]
    if (-not $lockChecked -or $completed.Count -ge $unitCount) { throw 'Requires a locked concurrent refusal and a genuinely partial compile.' }
    $receipt.compilationUnits = $unitCount
    $attempts = @(Get-ChildItem -LiteralPath (Join-Path $project '.webman-aot-builder\build') -Directory)
    if ($attempts.Count -ne 1) { throw 'Interrupted compile did not own exactly one attempt.' }
    $oldAttempt = $attempts[0].FullName
    $tree = @(Record-Tree $parent.Id); $ownedProcesses += $tree
    Stop-Process -Id $parent.Id -Force
    $alive = @($tree | Where-Object { $_.ProcessId -ne $parent.Id -and (Get-Process -Id $_.ProcessId -ErrorAction SilentlyContinue) })
    $receipt.completedAtKill = $completed.Count; $receipt.parentPid = $parent.Id
    $receipt.oldChildrenAliveAtKill = @($alive | ForEach-Object { @{ pid=[int]$_.ProcessId; name=$_.Name } })
    $receipt.oldAttempt = $oldAttempt
    Write-Host ("[test] Killed builder parent; complete={0}; recorded old children alive={1}; stopping only its owned native child tree." -f $completed.Count,$alive.Count)
    $stopped = @()
    foreach ($record in $tree | Where-Object { $_.ParentProcessId -eq $parent.Id } | Sort-Object ProcessId -Unique) {
        $current = Get-CimInstance Win32_Process -Filter ("ProcessId="+$record.ProcessId) -ErrorAction SilentlyContinue
        if ($null -ne $current -and $current.CreationDate -eq $record.CreationDate) {
            $stopped += [int]$record.ProcessId
            & (Join-Path $env:SystemRoot 'System32\taskkill.exe') /PID $record.ProcessId /T /F | Out-Host
        }
    }
    if ($stopped.Count -eq 0) { throw 'No recorded native child tree remained to force-stop at interruption.' }
    $receipt.oldNativeRootsStoppedBeforeRetry = $stopped
    $receipt.oldChildrenStopTimeUtc = [DateTime]::UtcNow.ToString('o')
    $receipt.completedAtOldChildrenStop = @(Get-ChildItem -LiteralPath $cache -Filter complete.json -File -Recurse).Count
    $parent.WaitForExit(); Show-Logs 'interrupted'
    Write-Host '[test] Forced interruption complete; retrying the public launcher from the partial checkpoints.'
    if ($Launcher -match '["\r\n%!&|<>^]') { throw 'Controlled public launcher contains shell syntax.' }
    $wrapper = Join-Path $project 'resume-parent-retry.cmd'
    [IO.File]::WriteAllText($wrapper,("@echo off`r`nchcp 65001 >nul`r`ncall `"$Launcher`" build`r`nexit /b %errorlevel%`r`n"),$utf8)
    $retry = Start-Process -FilePath (Join-Path $env:SystemRoot 'System32\cmd.exe') -ArgumentList @('/d','/c','resume-parent-retry.cmd') `
        -WorkingDirectory $project -NoNewWindow -PassThru -RedirectStandardOutput (Join-Path $Evidence 'retry-stdout.log') -RedirectStandardError (Join-Path $Evidence 'retry-stderr.log')
    $null = $retry.Handle
    $ownedProcesses += Record-Tree $retry.Id
    $code = Wait-Owned $retry 'retry'
    $retryText = Read-Log (Join-Path $Evidence 'retry-stdout.log')
    if ($code -ne 0 -or $retryText -notmatch 'Reused verified objects: ([0-9]+)') { throw 'Public retry failed or omitted its native reuse count.' }
    $reused = [int]$Matches[1]
    if ($reused -lt 1 -or $reused -ge $unitCount -or -not $retryText.Contains("Successfully compiled $unitCount files")) { throw 'Retry did not prove partial native reuse plus remaining compilation.' }
    if (-not (Test-Path -LiteralPath $oldAttempt) -or -not (Test-Path -LiteralPath (Join-Path $project 'dist-aot\manifest.json'))) { throw 'Retry lost the interrupted attempt or did not publish the verified distribution.' }
    $receipt.retryExit = $code; $receipt.reused = $reused; $receipt.compiledRemaining = $unitCount-$reused
    if ($retryText -notmatch 'attempt-[a-f0-9]{24}' -or $Matches[0] -eq (Split-Path -Leaf $oldAttempt)) {
        throw 'Public retry did not prove a new isolated attempt.'
    }
    $receipt.retryAttempt = $Matches[0]
    $receipt.success = $true
} finally {
    foreach ($record in @($ownedProcesses | Sort-Object ProcessId -Unique)) {
        $current = Get-CimInstance Win32_Process -Filter ("ProcessId="+$record.ProcessId) -ErrorAction SilentlyContinue
        if ($null -ne $current -and $current.CreationDate -eq $record.CreationDate) {
            $ownedProcesses += Record-Tree ([int]$record.ProcessId)
        }
    }
    foreach ($record in $ownedProcesses | Sort-Object ProcessId -Unique) {
        $current = Get-CimInstance Win32_Process -Filter ("ProcessId="+$record.ProcessId) -ErrorAction SilentlyContinue
        if ($null -ne $current -and $current.CreationDate -eq $record.CreationDate) {
            Write-Host ("[cleanup] Stopping task-owned process {0} ({1})" -f $record.ProcessId,$record.Name)
            & (Join-Path $env:SystemRoot 'System32\taskkill.exe') /PID $record.ProcessId /T /F
        }
    }
    $remaining = @($ownedProcesses | Sort-Object ProcessId -Unique | Where-Object {
        $current = Get-CimInstance Win32_Process -Filter ("ProcessId="+$_.ProcessId) -ErrorAction SilentlyContinue
        $null -ne $current -and $current.CreationDate -eq $_.CreationDate
    } | ForEach-Object { [int]$_.ProcessId })
    $receipt.cleanupRemaining = $remaining
    if ($remaining.Count -gt 0) { $receipt.success = $false }
    foreach ($process in @($parent,$concurrent,$retry)) { if ($null -ne $process) { $process.Dispose() } }
    foreach ($key in $keys) { [Environment]::SetEnvironmentVariable($key,$saved[$key],'Process') }
    [IO.File]::WriteAllText((Join-Path $Evidence 'results.json'),($receipt | ConvertTo-Json -Depth 5),$utf8)
    Write-Host ("[test] Windows parent interruption result: {0}; evidence={1}" -f $receipt.success,$Evidence)
}
if (-not $receipt.success) { exit 1 }
