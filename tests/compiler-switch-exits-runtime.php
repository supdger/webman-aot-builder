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
$root = sys_get_temp_dir() . '/webman-aot-switch-goto-runtime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
class SwitchExitGuard{public static int $count=0;function __destruct(){self::$count++;}}
function goto_group(string $s):int{$x=1;switch($s){case 'a':case 'b':goto done;case 'c':$x=3;break;default:$x=4;break;}$x+=10;done:return $x;}
function goto_integer(int $s):int{switch($s){case 1:goto done;default:return 2;}done:return 3;}
function goto_default(string $s):int{switch($s){case 'a':return 1;default:goto done;}done:return 2;}
function goto_finally(string $s):int{$x=1;try{switch($s){case 'a':goto done;default:break;}}finally{$x+=2;}done:return $x;}
function goto_scope(string $s):int{switch($s){case 'a':$guard=new SwitchExitGuard;goto done;default:return -1;}done:return SwitchExitGuard::$count;}
function goto_throw(string $s):int{switch($s){case 'a':goto done;default:return 2;}done:throw new RuntimeException('after goto');}

function goto_nested_finally(string $s):string{$trace='';try{try{switch($s){case 'a':$trace.='c';goto done;default:break;}}finally{$trace.='i';}}finally{$trace.='o';}done:return $trace;}
function goto_finally_throw(string $s):int{try{switch($s){case 'a':goto done;default:break;}}finally{throw new RuntimeException('finally wins');}done:return 7;}

function goto_inner_only(string $s):string{$trace='';try{try{switch($s){case 'a':$trace.='c';goto inside;default:break;}}finally{$trace.='i';}inside:$trace.='l';}finally{$trace.='o';}return $trace;}
function goto_same_try(string $s):string{$trace='';try{switch($s){case 'a':$trace.='c';goto inside;default:break;}inside:$trace.='l';}finally{$trace.='f';}return $trace;}
function goto_catch(string $s):string{$trace='';try{throw new RuntimeException('caught');}catch(RuntimeException $e){switch($s){case 'a':$trace.='c';goto done;default:break;}}finally{$trace.='f';}done:return $trace;}
function goto_inner_throw(string $s):string{$trace='';try{try{switch($s){case 'a':goto stale;default:break;}}finally{$trace.='i';throw new RuntimeException('cancel');}}catch(RuntimeException $e){$trace.='c';}finally{$trace.='o';}$trace.='n';return $trace;stale:return 'stale';}
function goto_finally_return(string $s):int{try{switch($s){case 'a':goto done;default:break;}}finally{return 7;}done:return 8;}
function final_default(string $s):int{$x=0;switch($s){case 'a':$x=1;break;default:$x=2;}return $x+10;}
function final_named(string $s):int{$x=0;switch($s){case 'a':$x=1;break;case 'b':$x=2;}return $x+10;}
function final_loop():int{$x=0;for($i=0;$i<2;$i++){switch('b'){case 'a':$x+=1;break;default:$x+=2;}$x+=10;}return $x;}
SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';
function checkValue(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
foreach(['a'=>1,'b'=>1,'c'=>13,'other'=>14]as$s=>$v)checkValue(goto_group($s)===$v,'grouped string switch '.$s);
foreach([1=>3,2=>2]as$s=>$v)checkValue(goto_integer($s)===$v,'integer switch '.$s);
foreach(['a'=>1,'other'=>2]as$s=>$v)checkValue(goto_default($s)===$v,'default switch '.$s);
foreach(['a','other']as$s)checkValue(goto_finally($s)===3,'finally once '.$s);
SwitchExitGuard::$count=0;checkValue(goto_scope('a')===0&&SwitchExitGuard::$count===1,'goto retains local until function exit and releases once');
try{goto_throw('a');throw new RuntimeException('expected exception');}catch(RuntimeException $e){checkValue($e->getMessage()==='after goto','throw after goto propagates');}
checkValue(goto_throw('other')===2,'normal invocation after throw');

checkValue(goto_nested_finally('a')==='cio' && goto_nested_finally('other')==='io','owned nested finally order and exactly once');
try{goto_finally_throw('a');throw new RuntimeException('expected finally');}catch(RuntimeException $e){checkValue($e->getMessage()==='finally wins','owned finally exception supersedes pending goto');}

checkValue(goto_inner_only('a')==='cilo','inner only exit leaves outer active');
checkValue(goto_same_try('a')==='clf','same try target does not execute finally early');
checkValue(goto_catch('a')==='cf','catch exits through finally once');
checkValue(goto_inner_throw('a')==='icon','inner finally throw cancels outer pending goto');
checkValue(goto_finally_return('a')===7,'finally return overrides pending goto');
foreach(['a'=>11,'b'=>12,'other'=>12]as$s=>$n)checkValue(final_default($s)===$n,'final default implicit switch exit '.$s);
foreach(['a'=>11,'b'=>12,'other'=>10]as$s=>$n)checkValue(final_named($s)===$n,'final named and no match '.$s);
checkValue(final_loop()===24,'implicit final case continues outer loop');
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
    $data .= 'enum class RequestFuncId:uint32_t{};static zend_function *constructor_functions[16]{};static zend_function *get_func(RequestFuncId id,const php::Str &name){auto &slot=constructor_functions[static_cast<uint32_t>(id)];if(!slot)slot=php::getFunction(name);return slot;}';
    $data .= 'enum class FunctionCallCacheId:uint32_t{}; static php::FunctionCallCacheSlot *reference_request_cache=nullptr; '
        . 'static php::FunctionCallCacheSlot &get_function_call_cache(FunctionCallCacheId id){return reference_request_cache[static_cast<uint32_t>(id)];}';
    $data .= 'enum class MethodCallCacheId:uint32_t{}; static php::MethodCallCacheSlot *local_method_cache=nullptr; static php::MethodCallCacheSlot &get_method_call_cache(MethodCallCacheId id){return local_method_cache[static_cast<uint32_t>(id)];}';
    $data .= 'enum class PersistentClassId:uint32_t{}; static zend_class_entry *get_persistent_class(PersistentClassId,const php::Str &name){return zend_lookup_class(name.str());}';
    $data .= 'enum class PersistentPropertyId:uint32_t{};static uint32_t get_persistent_prop(PersistentPropertyId,const php::Str &name,const php::Str &class_name){return php::getPropertyOffset(class_name,name);}';
    file_put_contents($include . '/php_app_data_decl.h', $data);
    $cpp = file_get_contents($compiler->getCppFile($file)) . "\n" . $compiler->defaults();
    $cpp .= "\n#include <" . basename(glob($include . '/*arginfo.h')[0]) . ">\n";
    $cpp .= 'static const zend_function_entry ref_functions[]={';
    foreach(['goto_group','goto_integer','goto_default','goto_finally','goto_scope','goto_throw','goto_nested_finally','goto_finally_throw','goto_inner_only','goto_same_try','goto_catch','goto_inner_throw','goto_finally_return','final_default','final_named','final_loop']as$name){$cpp.='PHP_FE('.$name.',arginfo_'.$name.')';}
    $cpp .= 'PHP_FE_END};';
    $cpp .= 'PHP_MINIT_FUNCTION(reference_host){if(typephp_install_reflection_attribute_handlers()!=SUCCESS)return FAILURE;'.$compiler->classInit().'return SUCCESS;}';
    $cpp .= <<<'CPP'
PHP_MSHUTDOWN_FUNCTION(reference_host){php::unmarkCompiledUserClasses(module_number);return SUCCESS;}
PHP_RINIT_FUNCTION(reference_host){php::request_init();memset(constructor_functions,0,sizeof(constructor_functions));local_method_cache=new php::MethodCallCacheSlot[16]{};reference_request_cache=new php::FunctionCallCacheSlot[16]{};return SUCCESS;}
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
    echo "STAGE ordinary PHP switch-goto baseline\n";
    runReference([$php, '-n', $root . '/cases.php'], $root);
    for ($request = 0; $request < 2; $request++) {
        echo 'STAGE compiled reference lifetime process ' . $request . "\n";
        runReference([$php, '-n', '-d', 'extension=' . $module, $root . '/cases.php'], $root);
    }
    echo 'PASS host PHP switch-goto runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
