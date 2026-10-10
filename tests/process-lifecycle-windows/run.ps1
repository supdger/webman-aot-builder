[CmdletBinding()]
param(
    [Parameter(Mandatory=$true)][string]$Php,
    [Parameter(Mandatory=$true)][string]$AppRoot,
    [Parameter(Mandatory=$true)][string]$Evidence,
    [string]$PhpBootstrap = ''
)
$ErrorActionPreference = 'Stop'
if ($env:OS -ne 'Windows_NT') { throw 'This fixture requires native Windows.' }
if (-not [IO.Path]::IsPathRooted($Evidence) -or (Test-Path -LiteralPath $Evidence)) { throw 'Evidence must be a new absolute task-owned directory.' }
$utf8 = New-Object Text.UTF8Encoding($false)
New-Item -ItemType Directory -Path $Evidence | Out-Null
$runtime = Split-Path -Parent $Php
$worker = Join-Path $Evidence 'owned worker 中文.exe'
Add-Type -TypeDefinition ([IO.File]::ReadAllText((Join-Path $PSScriptRoot 'Worker.cs'))) -OutputAssembly $worker -OutputType ConsoleApplication
$caller = Join-Path $PSScriptRoot 'caller.php'
$owned = @()
$receipt = @{ success=$false; nativePlatform='Windows'; cases=@() }
function Ensure([bool]$Ok,[string]$Message) { if (-not $Ok) { throw $Message }; Write-Host ('PASS '+$Message) }
function Quote([string]$Value) {
    if ($Value.Contains('"') -or $Value.Contains("`r") -or $Value.Contains("`n")) { throw 'Orchestrator controlled argument is invalid.' }
    return '"'+$Value.TrimEnd('\')+('\' * (($Value.Length-$Value.TrimEnd('\').Length)*2))+'"'
}
function Start-Php([string]$Mode,[string]$SpecPath,[string]$Result,[string]$Name) {
    $arguments = @('-c','php.ini','-d','extension_dir=ext')
    if ($PhpBootstrap) { $arguments += $PhpBootstrap }
    $arguments += @($caller,$AppRoot,$Mode,$SpecPath,$Result)
    $info = New-Object Diagnostics.ProcessStartInfo
    $info.FileName=$Php; $info.WorkingDirectory=$runtime; $info.UseShellExecute=$false
    $info.Arguments=($arguments | ForEach-Object { Quote $_ }) -join ' '
    $info.RedirectStandardOutput=$true; $info.RedirectStandardError=$true
    if ($PhpBootstrap) { $info.EnvironmentVariables['WEBMAN_AOT_CALLER_CWD']=$Evidence }
    $p = [Diagnostics.Process]::Start($info); $null=$p.Handle
    $script:owned += @{pid=$p.Id; created=$p.StartTime.ToUniversalTime().ToFileTimeUtc()}
    return $p
}
function Finish-Php($Process,[string]$Result,[string]$Name) {
    $clock=[Diagnostics.Stopwatch]::StartNew(); $next=5
    while (-not $Process.WaitForExit(200)) {
        if ($clock.Elapsed.TotalSeconds -gt 20) { throw ($Name+' exceeded bounded fixture time') }
        if ($clock.Elapsed.TotalSeconds -ge $next) { Write-Host ('[fixture] '+$Name+' alive '+[int]$clock.Elapsed.TotalSeconds+'s'); $next += 5 }
    }
    $out=$Process.StandardOutput.ReadToEnd(); $err=$Process.StandardError.ReadToEnd()
    [IO.File]::WriteAllText((Join-Path $Evidence ($Name+'-stdout.log')),$out,$utf8)
    [IO.File]::WriteAllText((Join-Path $Evidence ($Name+'-stderr.log')),$err,$utf8)
    Ensure ($Process.ExitCode -eq 0) ($Name+' caller exit 0')
    return ([IO.File]::ReadAllText($Result) | ConvertFrom-Json)
}
function Records([string]$Directory) {
    return @(Get-ChildItem -LiteralPath $Directory -Filter '*.json' -ErrorAction SilentlyContinue | ForEach-Object { [IO.File]::ReadAllText($_.FullName) | ConvertFrom-Json })
}
function Same-Alive($Record) {
    try {
        $p=Get-Process -Id $Record.pid -ErrorAction SilentlyContinue
        if ($null -eq $p) { return $false }
        $null=$p.Handle
        return ($p.StartTime.ToUniversalTime().ToFileTimeUtc() -eq [long]$Record.created -and -not $p.HasExited)
    } catch { return $false }
    finally { if ($null -ne $p) { $p.Dispose() } }
}
function Owned-Tree([int]$RootPid) {
    $all=@(Get-CimInstance Win32_Process)
    $selected=@($RootPid); $found=@()
    do {
        foreach ($id in $selected) {
            $p=Get-Process -Id $id -ErrorAction SilentlyContinue
            if ($null -ne $p) {
                $found += @{pid=$p.Id; created=$p.StartTime.ToUniversalTime().ToFileTimeUtc(); role=$p.ProcessName}
            }
        }
        $selected=@($all | Where-Object { $_.ParentProcessId -in $selected -and $_.ProcessId -notin @($found | ForEach-Object { $_.pid }) } | ForEach-Object { [int]$_.ProcessId })
    } while ($selected.Count -gt 0)
    return $found
}
function Wait-Records([string]$Directory,[int]$Count) {
    $clock=[Diagnostics.Stopwatch]::StartNew()
    while ((Records $Directory).Count -lt $Count) {
        if ($clock.Elapsed.TotalSeconds -gt 8) { throw 'Worker readiness missing' }
        Start-Sleep -Milliseconds 20
    }
    $script:owned += Records $Directory
}
function Assert-Gone([string]$Directory) {
    $clock=[Diagnostics.Stopwatch]::StartNew()
    do {
        $alive=@(Records $Directory | Where-Object { Same-Alive $_ })
        if ($alive.Count -eq 0) { return }
        if ($clock.Elapsed.TotalSeconds -gt 6) { throw 'Product left an owned worker alive; no helper kill used for acceptance.' }
        Start-Sleep -Milliseconds 20
    } while ($true)
}
function Write-Json([string]$Path,$Value) { [IO.File]::WriteAllText($Path,(ConvertTo-Json -InputObject $Value -Depth 8),$utf8) }
function Run-Command([string]$Name,[string]$Mode,$Command) {
    $specPath=Join-Path $Evidence ($Name+'-command.json'); $result=Join-Path $Evidence ($Name+'-result.json')
    Write-Json $specPath $Command
    $p=Start-Php $Mode $specPath $result $Name
    return (Finish-Php $p $result $Name)
}
try {
    Write-Host '[fixture] Actual packaged PHP ProcessOutput; no descendant helper cleanup before assertions.'
    foreach ($case in @(@('settled','root-ok',400,0),@('leftover','root-ok',30000,78),@('failure','root-fails',30000,7),@('cancel','root-stays',30000,'cancel'))) {
        $name=[string]$case[0]; $records=Join-Path $Evidence ($name+'-pids')
        $mode=if($name -eq 'cancel') {'callback-cancel'} else {'run'}
        $r=Run-Command $name $mode @($worker,[string]$case[1],$records,[string]$case[2])
        $owned += Records $records
        if ($name -eq 'cancel') { Ensure ($r.cancelled -eq $true) 'callback exception preserved' }
        else { Ensure ($r.exit -eq $case[3]) ($name+' original or cleanup exit preserved') }
        Assert-Gone $records
        $receipt.cases += @{name=$name; result=$r; descendants=@(Records $records); aliveAfter=@()}
    }
    $expected=@('', 'space argument', '中文', 'quote"argument', 'trailing\', '&|<>%!^')
    $r=Run-Command 'argv' 'run' (@($worker,'args')+$expected)
    $actual=@(($r.output.'1' -split "`r?`n" | Select-Object -SkipLast 1) | ForEach-Object { [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($_)) })
    Ensure (($actual | ConvertTo-Json -Compress) -ceq ($expected | ConvertTo-Json -Compress)) 'argv literals unchanged'
    Ensure ($r.output.'2'.Contains('stderr-probe')) 'stderr preserved separately'
    $receipt.cases += @{name='argv'; result=$r}
    $r=Run-Command 'high-exit' 'run' @($worker,'high-exit')
    Ensure ($r.exit -eq -1073741819) 'DWORD high-bit exit remains signed bits'
    $receipt.cases += @{name='high-exit'; result=$r}

    $records=Join-Path $Evidence 'parentdeath-pids'
    $specPath=Join-Path $Evidence 'parentdeath-command.json'; $result=Join-Path $Evidence 'parentdeath-result.json'
    Write-Json $specPath @($worker,'root-stays',$records,'30000')
    $p=Start-Php 'run' $specPath $result 'parentdeath'
    Wait-Records $records 3
    $tree=@(Owned-Tree $p.Id)
    $owned += $tree
    # Force only this PHP caller. Product must settle its supervisor/job descendants itself.
    $p.Kill(); $p.WaitForExit()
    Assert-Gone $records
    $clock=[Diagnostics.Stopwatch]::StartNew()
    do {
        $alive=@($tree | Where-Object { Same-Alive $_ })
        if ($alive.Count -eq 0) { break }
        if ($clock.Elapsed.TotalSeconds -gt 6) { throw 'Product left its recorded supervisor alive after PHP died.' }
        Start-Sleep -Milliseconds 20
    } while ($true)
    $receipt.cases += @{name='PHP-parent-death'; descendants=$tree; aliveAfter=@(); helperStoppedOnlyPhp=$p.Id}
    Ensure $true 'PHP hard exit leaves no child/grandchild without helper taskkill'

    foreach ($hold in @(@('transient',500),@('persistent',15000))) {
        $name=[string]$hold[0]; $project=Join-Path $Evidence ($name+' project')
        New-Item -ItemType Directory -Path $project | Out-Null
        $prepared=Join-Path $Evidence ($name+'-prepare.json')
        $p=Start-Php 'prepare' $project $prepared ($name+'-prepare')
        $a=Finish-Php $p $prepared ($name+'-prepare')
        $records=Join-Path $Evidence ($name+'-holder-pids')
        $info=New-Object Diagnostics.ProcessStartInfo
        $info.FileName=$worker; $info.UseShellExecute=$false
        $info.Arguments=(@('hold',($a.paths.build+'\project\held-object.o'),$records,[string]$hold[1]) | ForEach-Object { Quote $_ }) -join ' '
        $holder=[Diagnostics.Process]::Start($info); $null=$holder.Handle
        Wait-Records $records 1
        $spec=Join-Path $Evidence ($name+'-cleanup-input.json')
        Write-Json $spec @{project=$project; build=$a.paths.build; cache=$a.paths.cache}
        $result=Join-Path $Evidence ($name+'-cleanup.json')
        $p=Start-Php 'cleanup' $spec $result ($name+'-cleanup')
        $r=Finish-Php $p $result ($name+'-cleanup')
        Ensure ($r.success -eq $true -and $r.exclusiveAtStart -eq $true) ($name+' starts with a real exclusive handle')
        Ensure ($r.dist -eq 'published-output' -and $r.cache -eq 'verified-cache') ($name+' preserves dist and verified cache')
        if ($name -eq 'transient') { Ensure ($r.removed -eq $true) 'temporary FileShare.None release recovers' }
        else {
            Ensure ($r.removed -eq $false -and $r.seconds -lt 8 -and $r.error) 'persistent FileShare.None has bounded explicit failure'
            Ensure ((Same-Alive (Records $records)[0]) -and (Test-Path -LiteralPath ($a.paths.build+'\project\held-object.o'))) 'external holder remains alive and held file preserved'
        }
        $receipt.cases += @{name=$name+'-FileShare.None'; result=$r}
    }
    $receipt.success=$true
} finally {
    # Only final fixture cleanup, after pass/fail evidence. Never credited as product cleanup.
    $stopped=@()
    foreach ($record in $owned | Sort-Object pid -Unique) {
        $p=$null
        try {
            $p=Get-Process -Id $record.pid -ErrorAction SilentlyContinue
            if ($null -ne $p) {
                $null=$p.Handle
                if ($p.StartTime.ToUniversalTime().ToFileTimeUtc() -eq [long]$record.created -and -not $p.HasExited) {
                    $p.Kill(); $p.WaitForExit(3000) | Out-Null; $stopped += $record.pid
                }
            }
        } finally { if ($null -ne $p) { $p.Dispose() } }
    }
    $receipt.fixtureFinallyStopped=$stopped
    $receipt.cleanupRemaining=@($owned | Where-Object { Same-Alive $_ })
    if ($receipt.cleanupRemaining.Count -ne 0) { $receipt.success=$false }
    $receipt.sourceHashes=@{}
    foreach($path in @('src\Cli\ProcessOutput.php','src\Project\ProjectWorkspace.php','installer\process-supervisor\windows.ps1')) {
        $full=Join-Path $AppRoot $path
        if(Test-Path -LiteralPath $full) { $receipt.sourceHashes[$path]=(Get-FileHash -LiteralPath $full -Algorithm SHA256).Hash.ToLowerInvariant() }
    }
    Write-Json (Join-Path $Evidence 'consumer-result.json') $receipt
}
if(-not $receipt.success) { exit 1 }
