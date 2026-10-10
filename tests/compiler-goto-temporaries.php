<?php
 declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
function temporaryCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-goto-temporaries-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try{
 $file=$root.'/fixture.php';file_put_contents($file,<<<'SOURCE'
<?php
namespace Fixture;
function lookup(bool $skip,mixed $v):mixed{if($skip)goto done;$v=absent_call($v);done:return$v;}
function selector(bool $skip,string $v):int{$n=0;if($skip)goto done;switch($v[0]){case 'a':$n=1;break;default:$n=2;}done:return$n;}
SOURCE);
 $c=TypePhp\CompilerTest::create($root);$c->prepareFile($file);$c->convertFile($file);$cpp=file_get_contents($c->getCppFile($file));
 temporaryCheck(preg_match('/zend_function \* tmp_var_[0-9]+;/',$cpp)===1,'function pointer temporary uses function preamble declaration');
 temporaryCheck(!preg_match('/zend_function \*tmp_var_[0-9]+ = \(\[&\]/',$cpp),'lookup executes by assignment without inline declaration');
 temporaryCheck(str_contains($cpp,'php::equals('),'dynamic switch comparisons preserve PHP values');
 
 temporaryCheck(str_contains($cpp,'goto done;')&&str_contains($cpp,'get_function_resolution_cache'),'source goto and request-local function selection preserved');
 echo 'PASS goto temporary frontend elapsed='.round(microtime(true)-$start,3).'s'.PHP_EOL;
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
