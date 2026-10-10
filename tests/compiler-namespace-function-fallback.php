<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';
require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class NamespaceFallbackCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
    public function compileFixture(string $file):string{$this->fullStatic=true;try{$this->prepareFile($file);$this->convertFile($file);return file_get_contents($this->getCppFile($file));}finally{$this->fullStatic=false;}}
}
function checkNamespace(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-namespace-fallback-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try {
    $file=$root.'/fixture.php';file_put_contents($file, <<<'SOURCE'
<?php
namespace Owned {function strlen(string $text):int{return 19;}function priority():int{return strlen('x');}}
namespace RuntimeShadow {use function strlen as imported_length;
function dynamic(string $text):mixed{return strlen($text,'extra');}
function imported(string $text):int{return imported_length($text);}
function qualified(string $text):int{return \strlen($text);}
function case_lookup(string $text):mixed{return StRlEn($text);}}
SOURCE);
    $compiler=new NamespaceFallbackCompiler($root);$cpp=$compiler->compileFixture($file);
    checkNamespace(str_contains($cpp,'php_owned__strlen('),'compiled namespace declaration retains direct function ABI');
    checkNamespace(str_contains($cpp,'get_function_resolution_cache(FunctionResolutionCacheId{')&&str_contains($cpp,'zend_hash_exists(EG(function_table)'),'unresolved namespace call uses existing request selection cache');
    checkNamespace(str_contains($cpp,'get_func(RequestFuncId{')&&str_contains($cpp,'get_persistent_func(PersistentFuncId{'),'namespace target is request-owned and stable global target keeps existing cache');
    checkNamespace(str_contains($cpp,'auto *function = namespaced ?')&&strpos($cpp,'auto *function = namespaced ?')<strpos($cpp,'resolution = namespaced ? 1 : 2'),'failed pointer lookup cannot commit namespace/global selection');
    checkNamespace(str_contains($cpp,'php::fn::strlen('),'qualified and imported global builtin optimizer retained');
    $bad=$root.'/bad';mkdir($bad);$file=$bad.'/bad.php';file_put_contents($file,'<?php namespace StrictGlobal;function wrong():mixed{return \strlen("x","extra");}');
    try{(new NamespaceFallbackCompiler($bad))->compileFixture($file);throw new RuntimeException('Qualified global arity unexpectedly relaxed');}catch(TypePhp\Exception\TestError $error){checkNamespace($error->getMessage()!=='','qualified global keeps original arity diagnostic');}
    echo 'PASS namespace fallback frontend elapsed='.round(microtime(true)-$start,3)."s\n";
} finally {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($entries as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
