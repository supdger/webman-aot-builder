<?php
declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class CatchValueCompiler extends TypePhp\CompilerTest{
 public array $plans=[];public function __construct(string$r){parent::__construct($r);$this->forTest=true;}
 protected function prepareObjectAssignmentJoins(PhpParser\NodeAbstract|array|null$body):void{$before=$this->context->varTypeDegradations;parent::prepareObjectAssignmentJoins($body);$this->plans[strtolower($this->functionDef->name)]=array_diff_assoc($this->context->varTypeDegradations,$before);}
}
function catchValueCheck(bool$ok,string$m):void{if(!$ok)throw new RuntimeException($m);echo 'PASS '.$m.PHP_EOL;}
$r=sys_get_temp_dir().'/webman-aot-catch-storage-'.bin2hex(random_bytes(6));mkdir($r,0700,true);$start=microtime(true);
try{foreach([
 'bool'=>'function probe(callable$cb):void{$value=false;try{$cb();}catch(Exception$value){}if($value){throw$value;}}',
 'scalar'=>'function probe(callable$cb):mixed{$value="none";try{$cb();}catch(Exception$value){}return$value;}',
 'multi'=>'function probe(callable$cb):mixed{$value=false;try{$cb();}catch(Exception|Error$value){}return$value;}',
 'separate'=>'function probe(callable$cb):mixed{$value=false;try{$cb();}catch(Exception$value){}catch(Error$value){}return$value;}',
 'source'=>'class CatchLocalException extends Exception{}function probe(callable$cb):mixed{$value=false;try{$cb();}catch(CatchLocalException$value){}return$value;}',
 ]as$n=>$body){$dir=$r.'/'.$n;mkdir($dir);$f=$dir.'/fixture.php';file_put_contents($f,'<?php '.$body);$c=new CatchValueCompiler($dir);$c->prepareFile($f);$c->convertFile($f);catchValueCheck(($c->plans['probe']['value']??null)===TypePhp\Type::VAR,'known PHP catch local reserves Var '.$n);}
 foreach([
 'conditional-only'=>'function probe(callable$cb):mixed{try{$cb();}catch(Exception$value){}return$value;}',
 'unknown-rhs'=>'function probe(callable$cb):mixed{$value=unknownCatchValue();try{$cb();}catch(Exception$value){}return$value;}',
 'unknown-type'=>'function probe(callable$cb):mixed{$value=false;try{$cb();}catch(UnknownCatchType$value){}return$value;}',
 'parameter'=>'function probe(bool$value,callable$cb):mixed{try{$cb();}catch(Exception$value){}return$value;}',
 'alias'=>'function probe(callable$cb):mixed{$value=false;$alias=&$value;try{$cb();}catch(Exception$value){}return$value;}',
 'byref-call'=>'function changeCatchValue(&$x){}function probe(callable$cb):mixed{$value=false;try{$cb();}catch(Exception$value){}changeCatchValue($value);return$value;}',
 'native-type'=>'#[Native]class CatchNative{}function probe(callable$cb):mixed{$value=false;try{$cb();}catch(CatchNative$value){}return$value;}',
 'native-owner'=>'#[Native]class CatchNative{function probe(callable$cb):mixed{$value=false;try{$cb();}catch(Exception$value){}return$value;}}',
 ]as$n=>$body){$dir=$r.'/'.$n;mkdir($dir);$f=$dir.'/fixture.php';file_put_contents($f,'<?php '.$body);$c=new CatchValueCompiler($dir);$c->prepareFile($f);try{$c->convertFile($f);}catch(TypePhp\Exception\TestError$e){}catchValueCheck(!isset($c->plans['probe']['value']),'existing catch boundary '.$n);}
 echo 'PASS catch storage frontend elapsed='.round(microtime(true)-$start,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($r);}
