<?php
declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
function switchGotoCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-switch-goto-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try{
    foreach([
        'grouped'=>'function fixture(string $s):int{$x=1;switch($s){case "a":case "b":goto done;case "c":$x=3;break;default:$x=4;break;}$x+=10;done:return $x;}',
        'default'=>'function fixture(string $s):int{switch($s){case "a":return 1;default:goto done;}done:return 2;}',
        'integer'=>'function fixture(int $s):int{switch($s){case 1:goto done;default:return 2;}done:return 3;}',
        'finally'=>'function fixture(string $s):int{$x=1;try{switch($s){case "a":goto done;default:break;}}finally{$x+=2;}done:return $x;}',
        'native'=>'#[Native] class JumpNative{function run(string $s):int{switch($s){case "a":goto done;default:return 2;}done:return 3;}}',
    ]as$name=>$source){$dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php '.$source);$c=TypePhp\CompilerTest::create($dir);$c->prepareFile($file);$c->convertFile($file);switchGotoCheck(str_contains(file_get_contents($c->getCppFile($file)),'goto done;'),'existing goto lowering '.$name);}
    $dir=$root.'/nonterminal';mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php function fixture(string $s):int{switch($s){case "a":echo "x";default:return 2;}return 3;}');$c=TypePhp\CompilerTest::create($dir);$c->prepareFile($file);$rejected=false;try{$c->convertFile($file);}catch(TypePhp\Exception\TestError $e){$rejected=str_contains($e->getMessage(),'switch case must end');}switchGotoCheck($rejected,'nonterminal statement retains existing rejection');
    $illegal=$root.'/illegal.php';file_put_contents($illegal,'<?php goto inner;while(false){inner:echo "x";}');$process=proc_open([PHP_BINARY,'-l',$illegal],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$out=stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);switchGotoCheck(proc_close($process)!==0&&str_contains($out,'disallowed'),'PHP forbids jumping into loop');
    echo 'PASS switch goto frontend elapsed='.round(microtime(true)-$start,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
