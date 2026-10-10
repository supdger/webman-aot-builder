<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
$php = $argv[2] ?? PHP_BINARY;
$phpConfig = $argv[3] ?? 'php-config';
$clang = $argv[4] ?? 'clang++';
require $toolchain . '/vendor/autoload.php';

require $toolchain . '/src/gen_stub.php';

final class TargetValueRuntimeCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root); $this->forTest = true;
    }
    public function targetConvert(string $file):void { $this->fullStatic=true; try {$this->prepareFile($file);$this->convertFile($file);} finally {$this->fullStatic=false;} }
    public function strings(): array { return $this->literalStrings; }
    public function defaults(): string { return $this->genDefaultArgumentHelperDefinitions(); }
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
$root = sys_get_temp_dir() . '/webman-aot-switch-selector-lifetime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
function selector_exit(callable $make,callable $observe):void{switch($make()){default:goto done;}done:$observe('goto');}
function selector_return(callable $make):int{switch($make()){default:return 7;}}
function selector_throw(callable $make,callable $observe):void{try{switch($make()){default:throw new \RuntimeException('selector exit');}}catch(\RuntimeException $e){$observe('caught');}}
function selector_loop(callable $make,callable $observe):void{for($i=0;$i<2;$i++){switch($make()){default:break;}$observe('iteration');}}
function selector_lifetime(bool $enter,callable $make,callable $observe):void{if($enter){switch($make()){default:break;}$observe('inside');}$observe('outside');}

SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';
class SelectorGuard{public static int $released=0;function __destruct(){self::$released++;}}
$events=[];$weak=null;$make=function()use(&$weak){$object=new SelectorGuard;$weak=WeakReference::create($object);return $object;};
$observe=function($where)use(&$events,&$weak){$events[$where]=[SelectorGuard::$released,$weak->get()===null];};
selector_lifetime(true,$make,$observe);$events['returned']=[SelectorGuard::$released,$weak->get()===null];echo 'LIFETIME '.json_encode($events).PHP_EOL;foreach(['inside','outside','returned']as$where){if($events[$where]!==[1,true])throw new RuntimeException('selector lifetime '.$where.' '.json_encode($events[$where]));}echo 'PASS selector released at PHP switch end'.PHP_EOL;
foreach(['goto'=>'selector_exit','throw'=>'selector_throw']as$label=>$fn){SelectorGuard::$released=0;$events=[];$weak=null;$fn($make,$observe);if(SelectorGuard::$released!==1||$weak->get()!==null||count($events)!==1)throw new RuntimeException($label.' exit lifetime');echo 'PASS selector '.$label.' exit releases once'.PHP_EOL;}
SelectorGuard::$released=0;$weak=null;if(selector_return($make)!==7||SelectorGuard::$released!==1||$weak->get()!==null)throw new RuntimeException('return exit lifetime');echo 'PASS selector return preserves value and releases once'.PHP_EOL;
SelectorGuard::$released=0;$weak=null;$observed=[];$counting=function($where)use(&$observed,&$weak){$observed[]=[SelectorGuard::$released,$weak->get()===null];};selector_loop($make,$counting);if($observed!==[[1,true],[2,true]])throw new RuntimeException('loop selector lifetime '.json_encode($observed));echo 'PASS loop selector releases before next iteration'.PHP_EOL;

CASES);
    $compiler = new TargetValueRuntimeCompiler($root);
    $compiler->targetConvert($file);
    $include = $root . '/build/include';
    $compiler->genFunctionDeclarations($include . '/php_app_func_decl.h');
    // Supply only the request/literal tables used by the unmodified emitted functions.
    $data = "#include <phpx.h>\n";
    $data .= 'static php::Str &get_str(uint32_t index){static php::Str values[]={';
    foreach ($compiler->strings() as $string => $index) {
        $data .= 'php::Str{ZEND_STRL(' . json_encode((string) $string, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '),true},';
    }
    $data .= '};return values[index];}';
    $data .= 'enum class FunctionCallCacheId:uint32_t{}; static php::FunctionCallCacheSlot *reference_request_cache=nullptr; '
        . 'static php::FunctionCallCacheSlot &get_function_call_cache(FunctionCallCacheId id){return reference_request_cache[static_cast<uint32_t>(id)];}';
    $data .= 'enum class FunctionResolutionCacheId:uint32_t{}; static uint8_t resolution_slots[64]{}; static uint8_t &get_function_resolution_cache(FunctionResolutionCacheId id){return resolution_slots[static_cast<uint32_t>(id)];}';
    $data .= 'enum class RequestFuncId:uint32_t{}; static zend_function *request_slots[64]{}; static zend_function *get_func(RequestFuncId id,const php::Str &name){auto &slot=request_slots[static_cast<uint32_t>(id)];if(slot==nullptr){slot=php::getFunction(name);}return slot;}';
    $data .= 'enum class PersistentFuncId:uint32_t{}; static zend_function *get_persistent_func(PersistentFuncId,const php::Str &name){return php::getFunction(name);}';
    $data .= 'enum class PersistentClassId:uint32_t{}; static zend_class_entry *get_persistent_class(PersistentClassId,const php::Str &name){return zend_lookup_class(name.str());}';
    file_put_contents($include . '/php_app_data_decl.h', $data);
    $cpp = file_get_contents($compiler->getCppFile($file)) . "\n" . $compiler->defaults();
    $cpp .= "\n#include <" . basename(glob($include . '/*arginfo.h')[0]) . ">\n";
    $cpp .= 'static const zend_function_entry ref_functions[]={';
    foreach ([''=>['selector_lifetime','selector_exit','selector_return','selector_throw','selector_loop']] as $namespace=>$functions) {
        foreach ($functions as $name) {
            $symbol=strtolower(str_replace('\\','_',$namespace).($namespace!==''?'_':'').$name);
            $cpp .= ($namespace===''?'ZEND_NAMED_FE('.$name:'ZEND_NS_NAMED_FE('.json_encode($namespace).','.$name).',ZEND_FN('.$symbol.'),arginfo_'.$symbol.')' . "\n";
        }
    }
    $cpp .= 'PHP_FE_END};';
    $cpp .= <<<'CPP'
PHP_RINIT_FUNCTION(reference_host){std::memset(resolution_slots,0,sizeof(resolution_slots));std::memset(request_slots,0,sizeof(request_slots));php::request_init();reference_request_cache=new php::FunctionCallCacheSlot[16]{};return SUCCESS;}
PHP_RSHUTDOWN_FUNCTION(reference_host){delete[] reference_request_cache;reference_request_cache=nullptr;php::request_shutdown();return SUCCESS;}
zend_module_entry reference_host_module_entry={STANDARD_MODULE_HEADER,"reference_host",ref_functions,NULL,NULL,PHP_RINIT(reference_host),PHP_RSHUTDOWN(reference_host),NULL,"0.1",STANDARD_MODULE_PROPERTIES};
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
    $sources[] = $phpx . '/src/typephp/typephp_call.cc';
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
    echo "STAGE ordinary PHP strict reference baseline\n";
    runReference([$php, '-n', $root . '/cases.php'], $root);
    for ($request = 0; $request < 2; $request++) {
        echo 'STAGE compiled reference lifetime process ' . $request . "\n";
        runReference([$php, '-n', '-d', 'extension=' . $module, $root . '/cases.php'], $root);
    }
    echo 'PASS host PHP switch selector lifetime runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
