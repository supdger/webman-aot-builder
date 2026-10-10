<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class PureCallCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
    public function convertTarget(string $file):string{$this->fullStatic=true;try{$this->prepareFile($file);$this->convertFile($file);return file_get_contents($this->getCppFile($file));}finally{$this->fullStatic=false;}}
    public function plan(string $code,string $namespace=""):array{$this->resetFunction();$this->namespace=$namespace;$this->fullStatic=true;try{$nodes=(new PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php '.$code);$this->prepareObjectAssignmentJoins($nodes);return $this->context->varTypeDegradations;}finally{$this->fullStatic=false;}}
}
function pureCallCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-pure-call-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$started=microtime(true);
try{
    $file=$root.'/fixture.php';file_put_contents($file, <<<'SOURCE'
<?php
namespace PurePrediction;
function in_array(mixed $value):string{return 'shadow';}
function mixed_result():mixed{return 'unknown';}
function legal_foreach(object $node):void{foreach($node->attributes as $attribute){$localizable=\in_array($attribute->nodeName,[],true);}}
function compiled_foreach(object $node):void{foreach($node->attributes as $attribute){$value=in_array($attribute->nodeName);}}
function compiled_shadow(int $value):mixed{$value=in_array("argument");return $value;}
function unknown_result(int $value):mixed{$value=mixed_result();return $value;}
SOURCE);
    $compiler=new PureCallCompiler($root);$cpp=$compiler->convertTarget($file);
    pureCallCheck(str_contains($cpp,'legal_foreach'),'legal foreach argument scope is initialized before call emission');
    pureCallCheck(str_contains($cpp,'php::Var value = __typephp_captured_arg_value;'),'compiled namespace return declaration preserves mutable body storage');
    pureCallCheck(str_contains($cpp,'value = php_pureprediction__in_array('),'compiled namespace shadow retains its compiled call');
    $plan=$compiler->plan('$value=17;$value=\\stream_context_get_options($attribute->nodeName);');
    pureCallCheck(($plan['value']??null)===TypePhp\Type::VAR,'qualified target return prediction does not read unavailable argument scope');
    pureCallCheck(!isset($compiler->plan('$value=17;$value=\\PurePrediction\\mixed_result();')['value']),'unknown compiled return preserves the original local boundary');
    $fallback=$root.'/fallback';mkdir($fallback);$fallbackFile=$fallback.'/fixture.php';file_put_contents($fallbackFile,'<?php namespace UncompiledPrediction; function fallback(int $value,mixed $node):mixed{$value=stream_context_get_options($node);return $value;}');
    $fallbackCompiler=new PureCallCompiler($fallback);$fallbackCpp=$fallbackCompiler->convertTarget($fallbackFile);
    pureCallCheck(str_contains($fallbackCpp,'__typephp_captured_arg_value'),'namespace fallback only permits PHP body storage');
    pureCallCheck(!isset($fallbackCompiler->plan('$value=17;$value=stream_context_get_options($attribute->nodeName);','UncompiledPrediction')['value']),'namespace fallback is not a certain global return for local joining');
    $bad=$root.'/undefined';mkdir($bad);$badFile=$bad.'/fixture.php';file_put_contents($badFile,'<?php function actual_undefined():void{$localizable=\\in_array($missing->nodeName,[],true);}');
    try{(new PureCallCompiler($bad))->convertTarget($badFile);throw new RuntimeException('Real undefined variable was suppressed');}
    catch(TypePhp\Exception\TestError $error){pureCallCheck(str_contains($error->getMessage(),'missing')&&str_contains($error->getMessage(),'undefined'),'real undefined variable still fails during actual conversion');}
    echo 'PASS pure PHP call prediction elapsed='.round(microtime(true)-$started,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
