<?php
declare(strict_types=1);
$toolchain=$argv[1]??'';require$toolchain.'/vendor/autoload.php';require$toolchain.'/src/gen_stub.php';
final class ForeachListCompiler extends TypePhp\CompilerTest{public array$plans=[];public function __construct(string$r){parent::__construct($r);$this->forTest=true;}protected function prepareObjectAssignmentJoins(PhpParser\NodeAbstract|array|null$body):void{$before=$this->context->varTypeDegradations;parent::prepareObjectAssignmentJoins($body);$this->plans[strtolower($this->functionDef->name)]=array_diff_assoc($this->context->varTypeDegradations,$before);}}
function listCheck(bool$ok,string$message):void{if(!$ok)throw new RuntimeException($message);echo'PASS '.$message.PHP_EOL;}
$r=sys_get_temp_dir().'/webman-aot-foreach-list-'.bin2hex(random_bytes(6));mkdir($r,0700,true);$start=microtime(true);
try{foreach([
 'scalar'=>'function probe(array$rows):mixed{$value=false;foreach($rows as[$value]){}return$value;}',
 'nested'=>'function probe(array$rows):mixed{$value=false;foreach($rows as["entry"=>[$value]]){}return$value;}',
 'two-loops'=>'function probe(array$rows):array{$order=[];foreach($rows as$row){$value=$row===1&&$row>0;$order[]=[$value];}foreach($order as[$value]){if($value){$order[]=["kept"];}}return$order;}',
 'body-read'=>'function probe(array$rows):array{$value=false;$out=[];foreach($rows as[$value]){$out[]=$value;}return[$value,$out];}',
 ]as$name=>$source){$dir=$r.'/'.$name;mkdir($dir);$f=$dir.'/fixture.php';file_put_contents($f,'<?php '.$source);$c=new ForeachListCompiler($dir);$c->prepareFile($f);$c->convertFile($f);listCheck(($c->plans['probe']['value']??null)===TypePhp\Type::VAR,'ordinary local dynamic list binding '.$name);}
 foreach([
 'unknown-rhs'=>'function probe(array$rows,mixed$unknown):mixed{$value=$unknown;foreach($rows as[$value]){}return$value;}',
 'parameter'=>'function probe(bool$value,array$rows):mixed{foreach($rows as[$value]){}return$value;}',
 'alias'=>'function probe(array$rows):mixed{$value=false;$alias=&$value;foreach($rows as[$value]){}return$value;}',
 'byref-call'=>'function changeListValue(&$v){}function probe(array$rows):mixed{$value=false;foreach($rows as[$value]){}changeListValue($value);return$value;}',
 'conditional-first'=>'function probe(bool$run,array$rows):mixed{if($run){$value=false;}foreach($rows as[$value]){}return$value;}',
 'loop-first'=>'function probe(array$rows):mixed{foreach($rows as$row){$value=false;}foreach($rows as[$value]){}return$value;}',
 'read-first'=>'function probe(array$rows):mixed{echo$value;$value=false;foreach($rows as[$value]){}return$value;}',
 'native-owner'=>'#[Native]class NativeListOwner{function probe(array$rows):mixed{$value=false;foreach($rows as[$value]){}return$value;}}',
 'ref-list'=>'function probe(array$rows):mixed{$value=false;foreach($rows as[&$value]){}return$value;}',
 'property'=>'class ListProperty{public bool$value=false;function probe(array$rows):mixed{foreach($rows as[$this->value]){}return$this->value;}}',
 'boolean-without-list'=>'function probe(mixed$x):mixed{$value=$x===1;$value="text";return$value;}',
 ]as$name=>$source){$dir=$r.'/'.$name;mkdir($dir);$f=$dir.'/fixture.php';file_put_contents($f,'<?php '.$source);$c=new ForeachListCompiler($dir);$c->prepareFile($f);try{$c->convertFile($f);}catch(TypePhp\Exception\TestError$e){}listCheck(!isset($c->plans['probe']['value']),'existing binding boundary '.$name);}
 echo'PASS foreach list frontend elapsed='.round(microtime(true)-$start,3).'s'.PHP_EOL;
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($r,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($r);}
