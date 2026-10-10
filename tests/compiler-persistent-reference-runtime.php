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
$root = sys_get_temp_dir() . '/webman-aot-reference-runtime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
declare(strict_types=1);
function ref_keep(array &$value, bool &$active): Closure {return function()use(&$value,&$active):void {if($active){$value[]='later';$active=false;}};}
function ref_forward(array &$value,bool &$active):Closure{return ref_keep($value,$active);}
function ref_outer(array &$value,bool &$active):Closure{return ref_forward($value,$active);}
function ref_wrong(bool &$active):Closure {return function()use(&$active):void {$active='later';};}
function ref_duplicate(array &$first,array &$second):Closure{return function()use(&$first,&$second):void{$first[]='first';$second[]='second';};}
function ref_defaults(array &$value=[]):Closure {return function()use(&$value):array{$value[]='default';return $value;};}
function ref_default_caller():Closure{return ref_defaults();}
function ref_local():array{$value=[];$active=true;$copy=$value;$callback=ref_outer($value,$active);$value=['reset'];$callback();return [$value,$active,$copy];}
function ref_untyped_keep(&$value):Closure{return function()use(&$value):void{$value[]='later';};}
function ref_untyped_failure(&$value):Closure{$callback=ref_untyped_keep($value);$value[]='before';throw new RuntimeException('failed');}
function ref_nested_failure(&$value):Closure {$first=ref_untyped_keep($value);$second=function()use(&$first):void{};$value[]='before';throw new RuntimeException('nested');}
function ref_failure(array &$value,bool &$active):Closure{$callback=ref_keep($value,$active);$value[]='before';throw new RuntimeException('failed');}
SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
declare(strict_types=1);
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';
function verifyRef(bool $result,string $name):void {if(!$result)throw new RuntimeException($name);echo 'PASS '.$name.PHP_EOL;}
function expectRefTypeError(Closure $callback,string $name):void {try{$callback();}catch(TypeError $error){echo 'PASS '.$name.PHP_EOL;return;}throw new RuntimeException($name.' accepted');}
$value=[];$active=true;$copy=$value;$alias=&$value;$callback=ref_outer($value,$active);$value=['reset'];$callback();
verifyRef($value===['reset','later']&&$alias===$value&&!$active&&$copy===[],'nested forward escaped callback shares caller and preserves COW');
$value=['gated'];$callback();verifyRef($value===['gated'],'caller bool reassignment is observed after return');
$value=[];$callback=ref_duplicate($value,$value);$callback();verifyRef($value===['first','second'],'duplicate argument alias is one shared slot');
$active=true;$callback=ref_wrong($active);$callback();verifyRef($active==='later','parameter type does not persist as lifetime constraint');
expectRefTypeError(function()use(&$active){ref_wrong($active);},'next typed call rejects callback wrong type');
expectRefTypeError(function(){$value=[];$active=1;ref_keep($value,$active);},'strict bool argument remains checked');
expectRefTypeError(function(){$value='bad';$active=true;ref_keep($value,$active);},'array entry remains checked');
class RefPropertyHolder {public array $value=[];public bool $active=true;}
$holder=new RefPropertyHolder;$callback=ref_outer($holder->value,$holder->active);$callback();verifyRef($holder->value===['later']&&!$holder->active,'typed property valid writes synchronize');
$holder->active=true;$callback=ref_wrong($holder->active);expectRefTypeError($callback,'typed property constraint survives callback escape');verifyRef($holder->active===true,'typed property rejected write preserves old value');
$first=ref_defaults();$second=ref_default_caller();verifyRef($first()===['default']&&$first()===['default','default']&&$second()===['default'],'omitted default owns independent escaping reference');
$value=[];$active=true;try{ref_failure($value,$active);}catch(RuntimeException $error){verifyRef($value===['before']&&$active===true&&$error->getMessage()==='failed','exception preserves prior shared mutation');}
verifyRef(ref_local()===[['reset','later'],false,[]],'compiled caller reserves persistent storage before initialization');
for($i=0;$i<3;$i++){foreach(['ref_untyped_failure','ref_nested_failure']as$failure){$value=[];try{$failure($value);}catch(RuntimeException $error){verifyRef($value===['before'],$failure.' repeated cleanup '.$i);}}}
echo 'PASS all reference lifetime cases'.PHP_EOL;

CASES);
    $compiler = new ReferenceRuntimeCompiler($root);
    $compiler->prepareFile($file); $compiler->finalizePersistentReferences(); $compiler->convertFile($file);
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
    $data .= 'enum class PersistentClassId:uint32_t{}; static zend_class_entry *get_persistent_class(PersistentClassId,const php::Str &name){return zend_lookup_class(name.str());}';
    file_put_contents($include . '/php_app_data_decl.h', $data);
    $cpp = file_get_contents($compiler->getCppFile($file)) . "\n" . $compiler->defaults();
    $cpp .= "\n#include <" . basename(glob($include . '/*arginfo.h')[0]) . ">\n";
    $cpp .= 'static const zend_function_entry ref_functions[]={';
    foreach (['ref_keep', 'ref_forward', 'ref_outer', 'ref_wrong', 'ref_duplicate', 'ref_defaults',
        'ref_default_caller', 'ref_local', 'ref_failure', 'ref_untyped_keep', 'ref_untyped_failure', 'ref_nested_failure'] as $name) {
        $cpp .= 'PHP_FE(' . $name . ',arginfo_' . $name . ')' . "\n";
    }
    $cpp .= 'PHP_FE_END};';
    $cpp .= <<<'CPP'
PHP_RINIT_FUNCTION(reference_host){php::request_init();reference_request_cache=new php::FunctionCallCacheSlot[16]{};return SUCCESS;}
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
    for ($request = 0; $request < 3; $request++) {
        echo 'STAGE compiled reference lifetime process ' . $request . "\n";
        runReference([$php, '-n', '-d', 'extension=' . $module, $root . '/cases.php'], $root);
    }
    echo 'PASS host Zend reference runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
