<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class PhpLocalCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
    public function plan(string $code):array{$this->resetFunction();$nodes=(new PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$code);$this->prepareObjectAssignmentJoins($nodes);return $this->context->varTypeDegradations;}
}
function localCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-php-locals-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try{
$file=$root.'/fixture.php';file_put_contents($file, <<<'SOURCE'
<?php
class LocalNumber {public static function make(string $bytes):LocalNumber{return new LocalNumber();}public function value():string{return 'number';}}
function local_scalar(bool $flag):mixed{if($flag){$value=17;}else{$value='text';}return $value;}
function local_array(bool $flag):mixed{$value=17;if($flag){$value=[];}return $value;}
function local_object(bool $flag,mixed $bits):mixed{if($flag){$value=$bits&0x7FFF;}else{$value=LocalNumber::make('bytes');$result=$value->value();}return $value;}
function local_closure():Closure{return function(bool $flag):mixed{if($flag){$value=17;}else{$value='text';}return $value;};}
function local_generator():Generator{$value=17;yield $value;$value='text';yield $value;}
function local_known_ref(&$value):void{}
function local_unknown_result():mixed{return 'x';}
SOURCE);
$compiler=new PhpLocalCompiler($root);$compiler->prepareFile($file);
foreach(['scalar'=>'$value=1;$value="text";','array'=>'$value=[];$value=17;','object'=>'$value=17;$value=LocalNumber::make("bytes");','branch'=>'if($flag){$value=1;}else{$value="text";}return $value;','mask'=>'$value=$bits&0x7FFF;$value=new LocalNumber();']as$name=>$code)localCheck(($compiler->plan($code)['value']??null)===TypePhp\Type::VAR,'known mixed PHP local '.$name);
foreach(['unknown-rhs'=>'$value=1;$value=local_unknown_result();','unknown-args'=>'$value=1;unknown_local_call($value);$value="text";','byref-args'=>'$value=1;local_known_ref($value);$value="text";','alias'=>'$value=1;$alias=&$value;$value="text";','compound'=>'$value=1;$value+=1;$value="text";','read-before-write'=>'echo $value;$value=1;$value="text";','partial-branch'=>'if($flag){$value=1;}else{echo $value;$value="text";}','loop-definition'=>'while($flag){$value=1;}$value="text";return $value;','goto-definition'=>'goto skip;$value=1;skip:echo $value;$value="text";return $value;','nested-goto'=>'try{goto skip;}finally{}$value=1;try{skip:echo $value;}finally{}$value="text";return $value;']as$name=>$code)localCheck(!isset($compiler->plan($code)['value']),'original boundary '.$name);
$compiler->convertFile($file);$cpp=file_get_contents($compiler->getCppFile($file));localCheck(str_contains($cpp,'php::Var value;'),'real mixed local conversion uses PHP value storage');localCheck(str_contains($cpp,'php::toObject(value).call(')||str_contains($cpp,'typephp_call_method_cached('),'ordinary object branch keeps dynamic method dispatch');localCheck(str_contains($cpp,'php_local_generator')&&str_contains($cpp,'php_local_closure'),'existing closure and Fiber Generator lowering retains mixed locals');
echo 'PASS known PHP local value joins elapsed='.round(microtime(true)-$start,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
