[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$CommandFile, [Parameter(Mandatory=$true)][int]$OwnerPid)
$ErrorActionPreference = 'Stop'
Add-Type -TypeDefinition @'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class CommandJob {
    [StructLayout(LayoutKind.Sequential)] public struct Startup {
        public uint size; public IntPtr reserved, desktop, title; public uint x,y,xsize,ysize,xchars,ychars,fill,flags;
        public short show,reservedSize; public IntPtr reservedBytes,input,output,error;
    }
    [StructLayout(LayoutKind.Sequential)] public struct Process {
        public IntPtr handle,thread; public uint id,threadId;
    }
    [StructLayout(LayoutKind.Sequential)] public struct BasicLimits {
        public long processTime,jobTime; public uint flags; public UIntPtr min,max; public uint active;
        public UIntPtr affinity; public uint priority,scheduling;
    }
    [StructLayout(LayoutKind.Sequential)] public struct IoCounters { public ulong a,b,c,d,e,f; }
    [StructLayout(LayoutKind.Sequential)] public struct Limits {
        public BasicLimits basic; public IoCounters io; public UIntPtr processMemory,jobMemory,peakProcess,peakJob;
    }
    [StructLayout(LayoutKind.Sequential)] public struct Accounting {
        public long user,kernel,periodUser,periodKernel; public uint faults,total,active,terminated;
    }
    [DllImport("kernel32.dll",SetLastError=true)] public static extern IntPtr CreateJobObject(IntPtr attributes,string name);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool SetInformationJobObject(IntPtr job,int type,ref Limits value,uint size);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool QueryInformationJobObject(IntPtr job,int type,out Accounting value,uint size,IntPtr returned);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool AssignProcessToJobObject(IntPtr job,IntPtr process);
    [DllImport("kernel32.dll",SetLastError=true,CharSet=CharSet.Unicode)] public static extern bool CreateProcess(string application,StringBuilder command,IntPtr processAttributes,IntPtr threadAttributes,bool inherit,uint flags,IntPtr environment,string directory,ref Startup startup,out Process process);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern uint ResumeThread(IntPtr thread);
    [DllImport("kernel32.dll")] public static extern IntPtr GetStdHandle(int type);
    [StructLayout(LayoutKind.Sequential)] public struct ProcessBasic {
        public IntPtr reserved,peb,reserved2,reserved3,processId,parentId;
    }
    [DllImport("ntdll.dll")] public static extern int NtQueryInformationProcess(IntPtr process,int type,out ProcessBasic value,int size,out int returned);
    [DllImport("kernel32.dll")] public static extern IntPtr GetCurrentProcess();
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool GetProcessTimes(IntPtr process,out long creation,out long exit,out long kernel,out long user);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern IntPtr OpenProcess(uint access,bool inherit,int pid);
    [DllImport("kernel32.dll")] public static extern uint WaitForSingleObject(IntPtr handle,uint time);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool GetExitCodeProcess(IntPtr process,out uint code);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool TerminateJobObject(IntPtr job,uint code);
    [DllImport("kernel32.dll",SetLastError=true)] public static extern bool TerminateProcess(IntPtr process,uint code);
    [DllImport("kernel32.dll")] public static extern bool CloseHandle(IntPtr handle);
    public static IntPtr NewJob() { return CreateJobObject(IntPtr.Zero, null); }
    public static bool Start(string application, string command, ref Startup startup, out Process process) {
        return CreateProcess(application, new StringBuilder(command), IntPtr.Zero, IntPtr.Zero, true,
            0x204, IntPtr.Zero, null, ref startup, out process);
    }
    public static string ReadCommand(string path) {
        using (var file = new System.IO.FileStream(path, System.IO.FileMode.Open, System.IO.FileAccess.Read,
                System.IO.FileShare.ReadWrite | System.IO.FileShare.Delete))
        using (var reader = new System.IO.StreamReader(file, Encoding.UTF8, true)) {
            return reader.ReadToEnd();
        }
    }
    public static string Quote(string value) {
        StringBuilder result=new StringBuilder("\""); int slashes=0;
        foreach(char c in value) {
            if(c=='\\') { slashes++; continue; }
            if(c=='"') { result.Append('\\',slashes*2+1); result.Append(c); }
            else { result.Append('\\',slashes); result.Append(c); }
            slashes=0;
        }
        result.Append('\\',slashes*2); result.Append('"'); return result.ToString();
    }
}
'@
$job = [IntPtr]::Zero; $owner = [IntPtr]::Zero
$process = New-Object CommandJob+Process
$result = 78; $assigned = $false
try {
    $decoded = ConvertFrom-Json -InputObject ([CommandJob]::ReadCommand($CommandFile))
    $arguments = @($decoded)
    if ($arguments.Count -eq 0) { throw 'Command is empty.' }
    foreach ($argument in $arguments) { if ($argument -isnot [string] -or $argument.Contains([char]0)) { throw 'Command argument is invalid.' } }
    $self = [CommandJob]::GetCurrentProcess()
    $basic = New-Object CommandJob+ProcessBasic
    [int]$returned = 0
    if ([CommandJob]::NtQueryInformationProcess($self,0,[ref]$basic,[Runtime.InteropServices.Marshal]::SizeOf($basic),[ref]$returned) -ne 0 -or $basic.parentId.ToInt64() -ne $OwnerPid) { throw 'Command owner identity differs.' }
    $owner = [CommandJob]::OpenProcess(0x101000,$false,$OwnerPid)
    if ($owner -eq [IntPtr]::Zero) { throw 'Command owner has already exited.' }
    [long]$ownerCreation=0; [long]$selfCreation=0; [long]$exitTime=0; [long]$kernelTime=0; [long]$userTime=0
    if (-not [CommandJob]::GetProcessTimes($owner,[ref]$ownerCreation,[ref]$exitTime,[ref]$kernelTime,[ref]$userTime) -or
        -not [CommandJob]::GetProcessTimes($self,[ref]$selfCreation,[ref]$exitTime,[ref]$kernelTime,[ref]$userTime) -or
        $ownerCreation -gt $selfCreation -or [CommandJob]::WaitForSingleObject($owner,0) -ne 258) { throw 'Command owner has already exited or changed.' }
    $job = [CommandJob]::NewJob()
    if ($job -eq [IntPtr]::Zero) { throw 'Cannot create command lifetime boundary.' }
    $limits = New-Object CommandJob+Limits
    $limits.basic.flags = 0x2000
    $size = [Runtime.InteropServices.Marshal]::SizeOf($limits)
    if ($size -ne 144 -or -not [CommandJob]::SetInformationJobObject($job,9,[ref]$limits,$size)) { throw 'Cannot configure command cleanup.' }
    $startup = New-Object CommandJob+Startup
    $startup.size = [Runtime.InteropServices.Marshal]::SizeOf($startup)
    $startup.flags = 0x100
    $startup.input = [CommandJob]::GetStdHandle(-10); $startup.output = [CommandJob]::GetStdHandle(-11); $startup.error = [CommandJob]::GetStdHandle(-12)
    $line = ($arguments | ForEach-Object { [CommandJob]::Quote($_) }) -join ' '
    if (-not [CommandJob]::Start($arguments[0],$line,[ref]$startup,[ref]$process)) { throw ('Cannot start command: '+[Runtime.InteropServices.Marshal]::GetLastWin32Error()) }
    if (-not [CommandJob]::AssignProcessToJobObject($job,$process.handle)) { throw ('Cannot isolate command: '+[Runtime.InteropServices.Marshal]::GetLastWin32Error()) }
    $assigned = $true
    if ([CommandJob]::WaitForSingleObject($owner,0) -ne 258) { throw 'Command owner exited before command startup.' }
    if ([CommandJob]::ResumeThread($process.thread) -eq [uint32]::MaxValue) { throw 'Cannot resume isolated command.' }
    $timer = [Diagnostics.Stopwatch]::StartNew(); $rootEnd = $null; $stopAt = $null; $warned = $false
    while ($true) {
        if ($null -eq $rootEnd -and [CommandJob]::WaitForSingleObject($process.handle,0) -eq 0) {
            [uint32]$code = 0
            if (-not [CommandJob]::GetExitCodeProcess($process.handle,[ref]$code)) { throw 'Cannot read command exit status.' }
            if ($null -eq $stopAt) { $result = [BitConverter]::ToInt32([BitConverter]::GetBytes($code),0) }
            $rootEnd = $timer.Elapsed.TotalSeconds
        }
        $accounting = New-Object CommandJob+Accounting
        if (-not [CommandJob]::QueryInformationJobObject($job,1,[ref]$accounting,[Runtime.InteropServices.Marshal]::SizeOf($accounting),[IntPtr]::Zero)) { throw 'Cannot check command descendants.' }
        if ($accounting.active -eq 0) { break }
        $now = $timer.Elapsed.TotalSeconds
        if ($null -eq $stopAt -and ([CommandJob]::ReadCommand($CommandFile) -eq 'cancel' -or [CommandJob]::WaitForSingleObject($owner,0) -eq 0 -or ($null -ne $rootEnd -and $now-$rootEnd -ge 2))) {
            $stopAt = $now
            if ($result -eq 0 -or $null -eq $rootEnd) { $result = 78 }
            [Console]::Error.WriteLine('[清理] 本次命令的子进程未退出，正在停止并保留构建恢复信息。')
            if (-not [CommandJob]::TerminateJobObject($job,78)) { throw 'Cannot stop this command.' }
        }
        if ($null -ne $stopAt -and $now-$stopAt -ge 4) { throw 'Command descendants did not stop.' }
        if ($null -ne $rootEnd -and $null -eq $stopAt -and -not $warned) { [Console]::Error.WriteLine('[清理] 正在等待本次命令的子进程退出。'); $warned = $true }
        Start-Sleep -Milliseconds 20
    }
} catch {
    [Console]::Error.WriteLine('[失败] 本次命令未能安全结束：'+$_.Exception.Message)
    $result = 78
} finally {
    if ($process.handle -ne [IntPtr]::Zero -and -not $assigned) { [CommandJob]::TerminateProcess($process.handle,78) | Out-Null }
    if ($job -ne [IntPtr]::Zero) { [CommandJob]::CloseHandle($job) | Out-Null }
    if ($process.thread -ne [IntPtr]::Zero) { [CommandJob]::CloseHandle($process.thread) | Out-Null }
    if ($process.handle -ne [IntPtr]::Zero) { [CommandJob]::CloseHandle($process.handle) | Out-Null }
    if ($owner -ne [IntPtr]::Zero) { [CommandJob]::CloseHandle($owner) | Out-Null }
}
exit $result
