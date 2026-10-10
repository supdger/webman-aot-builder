<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
$php = $argv[2] ?? PHP_BINARY;
$phpConfig = $argv[3] ?? 'php-config';
$clang = $argv[4] ?? 'clang++';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class ReferenceRuntimeCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root); $this->forTest = true;
    }
    public function strings(): array { return $this->literalStrings; }
    public function defaults(): string { return $this->genDefaultArgumentHelperDefinitions(); }
    public function classData():string{$this->genClassCeList();$code='';foreach($this->classCeList as$ce)$code.='static zend_class_entry *'.$ce.'=nullptr;';foreach($this->classCeInfo as$info){$class=$info['classDef']??null;if($class===null)continue;$name=$class->getNamespacedName();$code.='static zend_object_handlers property_handlers_'.$name.';static zend_object *(*create_object_'.$name.')(zend_class_entry *)=nullptr;';}return$code;}
    public function classInit():string{return$this->genClassPropertyInit();}
}
function runReference(array $command, string $root): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start reference runtime stage'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($output !== '') { echo $output; }
    if ($error !== '') { echo $error; }
    if ($exit !== 0) { throw new RuntimeException('Reference runtime stage exit=' . $exit); }
    return $output;
}
$root = sys_get_temp_dir() . '/webman-aot-destructor-runtime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
class DestructorScalar{function __destruct(){return 17;}}
class DestructorObject{function __destruct(){return $this;}}
class DestructorNull{function __destruct(){return;}}
class DestructorImplicit{function __destruct(){}}
class DestructorEffect{function __destruct(){return destructor_effect();}}
class DestructorThrow{function __destruct(){return destructor_throw();}}
class DestructorTemporaryValue{function __destruct(){destructor_temp_release();}}
class DestructorTemporaryReturn{function __destruct(){return new DestructorTemporaryValue();}}
SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';
function checkValue(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
function destructor_effect():mixed{$GLOBALS['dtor_count']++;return 19;}
function destructor_temp_release():void{$GLOBALS['temp_count']++;}
function destructor_throw():mixed{$GLOBALS['dtor_count']++;if($GLOBALS['dtor_throw'])throw new RuntimeException('destructor');return null;}
$o=new DestructorScalar;checkValue($o->__destruct()===17,'explicit destructor scalar result');unset($o);echo "PASS automatic scalar result ignored\n";
$o=new DestructorObject;checkValue($o->__destruct()===$o,'explicit destructor object identity');unset($o);echo "PASS automatic object result ignored\n";
foreach([new DestructorNull,new DestructorImplicit]as$o)checkValue($o->__destruct()===null,'bare and fallthrough destructor null');unset($o);
checkValue(!(new ReflectionMethod(DestructorScalar::class,'__destruct'))->hasReturnType(),'reflection preserves undeclared destructor return');
$GLOBALS['dtor_count']=0;$o=new DestructorEffect;checkValue($o->__destruct()===19&&$GLOBALS['dtor_count']===1,'explicit destructor expression once');unset($o);checkValue($GLOBALS['dtor_count']===2,'automatic destructor expression once');
$GLOBALS['dtor_throw']=true;$o=new DestructorThrow;try{$o->__destruct();throw new LogicException('lost explicit exception');}catch(RuntimeException$e){checkValue($e->getMessage()==='destructor'&&$GLOBALS['dtor_count']===3,'explicit destructor exception propagates');}$GLOBALS['dtor_throw']=false;unset($o);checkValue($GLOBALS['dtor_count']===4,'ordinary destruction after explicit exception');
$o=new DestructorThrow;$GLOBALS['dtor_throw']=true;try{unset($o);throw new LogicException('lost automatic exception');}catch(RuntimeException$e){checkValue($e->getMessage()==='destructor'&&$GLOBALS['dtor_count']===5,'automatic destructor exception propagates');}$GLOBALS['dtor_throw']=false;
$o=new DestructorScalar;checkValue($o->__destruct()===17,'normal destructor after exceptions');unset($o);
$GLOBALS['temp_count']=0;$o=new DestructorTemporaryReturn;$held=$o->__destruct();checkValue($held instanceof DestructorTemporaryValue&&$GLOBALS['temp_count']===0,'explicit destructor result retains temporary object');unset($held);checkValue($GLOBALS['temp_count']===1,'explicit destructor temporary releases once');unset($o);checkValue($GLOBALS['temp_count']===2,'automatic destructor temporary releases once');
CASES);
    $compiler = new ReferenceRuntimeCompiler($root);
    $compiler->prepareFile($file); $compiler->finalizePersistentReferences(); $compiler->convertFile($file);
    $include = $root . '/build/include';
    $compiler->genFunctionDeclarations($include . '/php_app_func_decl.h');
    // Supply only the request/literal tables used by the unmodified emitted functions.
    $data = "#include <phpx.h>\n#include <typephp_fiber_generator.h>\n" . $compiler->classData();
    $data .= 'static php::Str &get_str(uint32_t index){static php::Str values[]={';
    foreach ($compiler->strings() as $string => $index) {
        $data .= 'php::Str{ZEND_STRL(' . json_encode((string) $string, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '),true},';
    }
    $data .= '};return values[index];}';
    $data .= 'enum class RequestFuncId:uint32_t{};static zend_function *destructor_functions[16]{};static zend_function *get_func(RequestFuncId id,const php::Str &name){auto &slot=destructor_functions[static_cast<uint32_t>(id)];if(!slot)slot=php::getFunction(name);return slot;}';
    $data .= 'enum class FunctionCallCacheId:uint32_t{}; static php::FunctionCallCacheSlot *reference_request_cache=nullptr; '
        . 'static php::FunctionCallCacheSlot &get_function_call_cache(FunctionCallCacheId id){return reference_request_cache[static_cast<uint32_t>(id)];}';
    $data .= 'enum class MethodCallCacheId:uint32_t{}; static php::MethodCallCacheSlot *local_method_cache=nullptr; static php::MethodCallCacheSlot &get_method_call_cache(MethodCallCacheId id){return local_method_cache[static_cast<uint32_t>(id)];}';
    $data .= 'enum class PersistentClassId:uint32_t{}; static zend_class_entry *get_persistent_class(PersistentClassId,const php::Str &name){return zend_lookup_class(name.str());}';
    file_put_contents($include . '/php_app_data_decl.h', $data);
    $cpp = file_get_contents($compiler->getCppFile($file)) . "\n" . $compiler->defaults();
    $cpp .= "\n#include <" . basename(glob($include . '/*arginfo.h')[0]) . ">\n";
    $cpp .= 'static const zend_function_entry ref_functions[]={';
    $cpp .= 'PHP_FE_END};';
    $cpp .= 'PHP_MINIT_FUNCTION(reference_host){if(typephp_install_reflection_attribute_handlers()!=SUCCESS)return FAILURE;'.$compiler->classInit().'return SUCCESS;}';
    $cpp .= <<<'CPP'
PHP_MSHUTDOWN_FUNCTION(reference_host){php::unmarkCompiledUserClasses(module_number);return SUCCESS;}
PHP_RINIT_FUNCTION(reference_host){php::request_init();memset(destructor_functions,0,sizeof(destructor_functions));local_method_cache=new php::MethodCallCacheSlot[16]{};reference_request_cache=new php::FunctionCallCacheSlot[16]{};return SUCCESS;}
PHP_RSHUTDOWN_FUNCTION(reference_host){delete[] local_method_cache;local_method_cache=nullptr;delete[] reference_request_cache;reference_request_cache=nullptr;php::request_shutdown();return SUCCESS;}
zend_module_entry reference_host_module_entry={STANDARD_MODULE_HEADER,"reference_host",ref_functions,PHP_MINIT(reference_host),PHP_MSHUTDOWN(reference_host),PHP_RINIT(reference_host),PHP_RSHUTDOWN(reference_host),NULL,"0.1",STANDARD_MODULE_PROPERTIES};
extern "C" {ZEND_GET_MODULE(reference_host)}
CPP;
    file_put_contents($root . '/build/runtime.cc', $cpp);
    $phpx = $toolchain . '/vendor/swoole/phpx';
    $headers = trim(runReference([$phpConfig, '--include-dir'], $root));
    $pcreHeaders = trim(runReference(['pkg-config', '--cflags-only-I', 'libpcre2-8'], $root));
    $pcreLibraries = trim(runReference(['pkg-config', '--libs', 'libpcre2-8'], $root));
    // The shared Zend reference/Closure core is production code. Nano disables unused Python/decimal host dependencies.
    $flags = ['-std=c++17', '-fPIC', '-DPHPX_NANO', '-I' . $phpx . '/include', '-I' . $phpx . '/thirdparty/wren-gc/include', '-I' . $include];
    foreach (['', '/main', '/Zend', '/TSRM', '/ext', '/ext/date/lib'] as $path) { $flags[] = '-I' . $headers . $path; }
    array_push($flags, ...preg_split('/\s+/', $pcreHeaders));
    $sources = [$root . '/build/runtime.cc'];
    foreach (['variant', 'array', 'string', 'object', 'closure', 'type_check', 'debug', 'base', 'native_gc', 'class', 'extension', 'scope'] as $name) {
        $sources[] = $phpx . '/src/core/' . $name . '.cc';
    }
    foreach(['typephp_call','typephp_property','typephp_attribute'] as $name){$sources[]=$phpx.'/src/typephp/'.$name.'.cc';}
    $sources[] = $phpx . '/src/typephp/typephp_fiber_generator.cc';
    $objects = [];
    foreach ($sources as $source) {
        $object = $root . '/build/' . basename($source, '.cc') . '.o';
        $sourceFlags = basename($source) === 'extension.cc' ? array_values(array_diff($flags, ['-DPHPX_NANO'])) : $flags;
        echo 'STAGE compile production ' . basename($source) . "\n";
        runReference([$clang, ...$sourceFlags, '-c', $source, '-o', $object], $root);
        $objects[] = $object;
    }
    $object = $root . '/build/wren_gc.o';
    echo "STAGE compile production Wren GC\n";
    runReference(['clang', '-fPIC', '-I' . $phpx . '/thirdparty/wren-gc/include', '-c', $phpx . '/thirdparty/wren-gc/src/wren_gc.c', '-o', $object], $root);
    $objects[] = $object;
    $module = $root . '/build/runtime.so';
    $link = [$clang, '-shared'];
    if (PHP_OS_FAMILY === 'Darwin') { array_push($link, '-undefined', 'dynamic_lookup'); }
    echo "STAGE link isolated host reference extension\n";
    runReference([...$link, ...$objects, ...preg_split('/\s+/', $pcreLibraries), '-o', $module], $root);
    echo "STAGE ordinary PHP destructor baseline\n";
    runReference([$php, '-n', $root . '/cases.php'], $root);
    for ($request = 0; $request < 2; $request++) {
        echo 'STAGE compiled reference lifetime process ' . $request . "\n";
        runReference([$php, '-n', '-d', 'extension=' . $module, $root . '/cases.php'], $root);
    }
    echo 'PASS host PHP destructor values runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
