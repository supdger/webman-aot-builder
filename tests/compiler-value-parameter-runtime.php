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
$root = sys_get_temp_dir() . '/webman-aot-value-runtime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
declare(strict_types=1);
class ValueResult {}
function value_object(string $value):ValueResult{return new ValueResult();}
function value_replace(string $value):array{$before=$value;$value=value_object($value);return [$before,$value];}
function value_repeat(string $value='default'):array{$copy=$value;$value=7;$value=[];$value=new ValueResult();$value='done';return [$copy,$value];}
function value_branch(string $value,bool $object):mixed {if($object){$value=new ValueResult();}else{$value=[];}return $value;}
function value_capture(string $value):array {$first=function()use($value):string{return $value;};$value=[];$second=function()use($value):array{return $value;};return [$first,$second];}
function value_closure():Closure{return function(string $value):array{$copy=$value;$value=[];return [$copy,$value];};}

SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
declare(strict_types=1);
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';else{class ValueResult {}}
function checkValue(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$value='original';$result=value_replace($value);checkValue($value==='original'&&$result[0]==='original'&&$result[1] instanceof ValueResult,'string entry and object body retain caller value');
checkValue(value_repeat()===['default','done'],'default and repeated mixed reassignments');
checkValue(value_branch('x',true) instanceof ValueResult && value_branch('x',false)===[],'branch joins ordinary PHP values');
[$first,$second]=value_capture('captured');checkValue($first()==='captured'&&$second()===[],'capture by value preserves before and after identity');
$callback=value_closure();checkValue($callback('closure')===['closure',[]],'typed closure parameter local is mutable');
try{value_replace(17);throw new RuntimeException('wrong type accepted');}catch(TypeError $e){echo "PASS wrong entry type rejected\n";}
$reflection=new ReflectionFunction('value_replace');checkValue((string)$reflection->getParameters()[0]->getType()==='string'&&!$reflection->getParameters()[0]->isPassedByReference(),'original PHP parameter reflection unchanged');

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
    foreach (['value_object','value_replace','value_repeat','value_branch','value_capture','value_closure'] as $name) {
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
    echo 'PASS host PHP value parameter runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
