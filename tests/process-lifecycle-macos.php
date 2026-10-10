<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Darwin' || $argc < 3) {
    fwrite(STDERR, "Usage: php process-lifecycle-macos.php PAYLOAD_APP NEW_EVIDENCE_DIRECTORY [--owner-worker]\n");
    exit(64);
}
[$app, $root] = array_slice($argv, 1, 2);
require $app . '/src/Cli/ProcessOutput.php';
require $app . '/src/Cli/ConfigurationException.php';
require $app . '/src/Project/ProjectWorkspace.php';
use WebmanAotBuilder\Cli\ProcessOutput;
use WebmanAotBuilder\Project\ProjectWorkspace;

function ensure(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
function groupGone(string $file): void {
    ensure(is_file($file), 'command never entered its own group');
    $pid = trim((string) file_get_contents($file));
    ensure(preg_match('/^[0-9]+$/D', $pid) === 1, 'fixture group identity is invalid');
    $process = proc_open(['/bin/kill', '-0', '-' . $pid], [0 => ['file','/dev/null','r'], 1 => ['file','/dev/null','w'], 2 => ['file','/dev/null','w']], $pipes);
    ensure(is_resource($process) && proc_close($process) !== 0, 'owned descendants remain');
}
function command(string $root, string $name, string $end): array {
    return ['/bin/sh', '-c', 'echo $$ > "$1"; /bin/sh -c "sleep 20 & wait" & echo ready; ' . $end, 'fixture', $root . '/' . $name . '.group'];
}
if (($argv[3] ?? '') === '--nested-worker') {
    ProcessOutput::run(['/bin/sh', '-c', 'echo $$ > "$1"; trap "" TERM; /bin/sh -c \'trap "" TERM; sleep 20 & wait\' & echo nested-ready; wait', 'fixture', $root . '/nested.group'],
        null, null, ['file','/dev/null','r'],
        static function(int $index, string $text): void { fwrite($index === 1 ? STDOUT : STDERR, $text); }, static fn() => null);
    exit(0);
}
if (($argv[3] ?? '') === '--owner-worker') {
    ProcessOutput::run(command($root, 'owner', 'wait'), null, null, ['file','/dev/null','r'],
        static function(int $index, string $text) use ($root): void { file_put_contents($root . '/owner.ready', $text); }, static fn() => null);
    exit(0);
}
ensure(!file_exists($root) && mkdir($root,0700,true), 'evidence directory must be new');
$results = [];
$run = static function(string $name, array $command, int $expected) use (&$results): void {
    $start = microtime(true); $stdout = ''; $stderr = '';
    $exit = ProcessOutput::run($command, null, null, ['file','/dev/null','r'],
        static function(int $index, string $text) use (&$stdout,&$stderr): void { if($index === 1){$stdout.=$text;}else{$stderr.=$text;} }, static fn() => null);
    ensure($exit === $expected, "$name exit $exit != $expected");
    $results[] = ['case'=>$name,'exit'=>$exit,'seconds'=>round(microtime(true)-$start,3),'stdout'=>$stdout,'stderr'=>$stderr];
    echo "PASS $name exit=$exit\n";
};
$run('stdio', ['/bin/sh','-c','echo output; echo error >&2; exit 7'],7);
ensure($results[0]['stdout']==="output\n" && $results[0]['stderr']==="error\n", 'stdio changed');
$run('success-descendants',command($root,'success','exit 0'),78); groupGone($root.'/success.group');
$run('failure-descendants',command($root,'failure','exit 7'),7); groupGone($root.'/failure.group');
try {
    ProcessOutput::run(command($root,'cancel','wait'),null,null,['file','/dev/null','r'],
        static function(int $index,string $text):void { if(str_contains($text,'ready')){throw new RuntimeException('fixture cancellation');} },static fn()=>null);
    throw new RuntimeException('callback cancellation ignored');
} catch(RuntimeException $error) { ensure($error->getMessage()==='fixture cancellation','original cancellation changed'); }
groupGone($root.'/cancel.group'); echo "PASS callback cancellation reaps child and grandchild\n";
try {
    ProcessOutput::run([PHP_BINARY,'-n',__FILE__,$app,$root,'--nested-worker'],null,null,['file','/dev/null','r'],
        static function(int $index,string $text):void { if(str_contains($text,'nested-ready')){throw new RuntimeException('nested cancellation');} },static fn()=>null);
    throw new RuntimeException('nested cancellation ignored');
} catch(RuntimeException $error) { ensure($error->getMessage()==='nested cancellation','nested cancellation changed'); }
groupGone($root.'/nested.group'); echo "PASS nested cancellation reaps TERM-resistant descendants\n";

$owner = proc_open([PHP_BINARY,'-n',__FILE__,$app,$root,'--owner-worker'],[0=>['file','/dev/null','r'],1=>['file',$root.'/owner.log','w'],2=>['file',$root.'/owner.log','a']],$pipes);
ensure(is_resource($owner),'owner fixture failed'); $deadline=microtime(true)+5;
while(!is_file($root.'/owner.ready')) {
    $status=proc_get_status($owner); ensure($status['running'],'owner fixture failed before ready');
    if(microtime(true)>$deadline){proc_terminate($owner);proc_close($owner);throw new RuntimeException('owner startup timed out');}
    usleep(20000);
}
proc_terminate($owner);proc_close($owner);usleep(300000);
groupGone($root.'/owner.group');echo "PASS parent death stops owned descendants\n";
$run('wrong-owner-no-start',[$app.'/bin/process-supervisor',(string)(getmypid()+100000),'--','/usr/bin/touch',$root.'/unexpected'],78);
ensure(!file_exists($root.'/unexpected'),'wrong owner ran payload');
$workspaceRoot=$root.'/workspace';mkdir($workspaceRoot);$workspace=new ProjectWorkspace($workspaceRoot);
$attempt=$workspace->prepare(str_repeat('a',64),str_repeat('b',64))['build'];mkdir($attempt.'/project');file_put_contents($attempt.'/project/open','held contents');
$handle=fopen($attempt.'/project/open','rb');$workspace->finishAttempt($attempt);
ensure(!file_exists($attempt) && stream_get_contents($handle)==='held contents','POSIX open-file unlink contract changed');fclose($handle);
echo "PASS POSIX open handle permits unlink without closing unrelated holder\n";
file_put_contents($root.'/result.json',json_encode(['success'=>true,'platform'=>'macos-native','cases'=>$results,'ownedDescendantsRemaining'=>false],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT));
