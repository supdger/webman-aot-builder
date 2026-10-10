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
$root = sys_get_temp_dir() . '/webman-aot-goto-temporaries-runtime-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
declare(strict_types=1);
namespace ShadowScalar {function scalar_value(int $options,mixed $context):mixed{$before=$options;$options=stream_context_get_options($context);return [$before,$options];}}
namespace ShadowObject {function object_value(int $options,mixed $context):mixed{$before=$options;$options=stream_context_get_options($context);return [$before,$options];}}
namespace TargetGlobal {use function stream_context_get_options as ctx_options;
function fq_value(int $options,mixed $context):mixed{$before=$options;$options=\stream_context_get_options($context);return [$before,$options];}
function alias_value(int $options,mixed $context):mixed{$before=$options;$options=ctx_options($context);return [$before,$options];}}
namespace RefValue {function reference_value():mixed{$matches=[];$result=preg_match('~x~','x',$matches);return [$result,$matches];}}
namespace RefWrite {function reference_write():mixed{$matches=[];$result=preg_match('~x~','x',$matches);return [$result,$matches];}}
namespace RefFallback {function reference_fallback():mixed{$matches=[];$result=preg_match('~x~','x',$matches);return [$result,$matches];}}
namespace ExtraCount {function extra_count(array $args):mixed{return strlen(...$args);}}
namespace LateProbe {function same_callsite():mixed{return strlen('x');} function different_callsite():mixed{return StRlEn('x');}}
namespace EvalMissing {function missing_eval():mixed{return absent_target_function(side_effect());}function conditional_eval(bool $execute):mixed{return $execute?absent_target_function(side_effect()):7;}function short_eval(bool $execute):mixed{return $execute&&absent_target_function(side_effect());}}
namespace EvalOnce {function nested_eval():mixed{return stream_context_get_options(side_effect());}}
namespace MissingProbe {function missing_call():mixed{return absent_target_function();}}
namespace JumpRuntime {function skip_lookup(bool $skip,mixed $value):mixed{if($skip)goto done;$value=identity(side_effect($value));done:return $value;} function skip_switch(bool $skip,string $value):int{$result=0;if($skip)goto done;switch($value[0]){case 'x':$result=1;break;default:$result=2;}done:return $result;}}
namespace {function global_value(int $options,mixed $context):mixed{$before=$options;$options=stream_context_get_options($context);return [$before,$options];}}

SOURCE);
    file_put_contents($root . '/cases.php', <<<'CASES'
<?php
declare(strict_types=1);
namespace ShadowScalar {function stream_context_get_options($context){return 17;}}
namespace ShadowObject {function stream_context_get_options($context){return (object)['value'=>'shadow'];}}
namespace EvalMissing {function side_effect(){$GLOBALS['missing_count']++;return null;}}
namespace EvalOnce {function side_effect(){$GLOBALS['once_count']++;return null;}function stream_context_get_options($value){return 31;}}
namespace JumpRuntime {function identity($value){return $value;}function side_effect($value){$GLOBALS['jump_count']++;return $value;}}
namespace RefValue {function preg_match($pattern,$subject,$matches){$matches='changed';return 7;}}
namespace RefWrite {function preg_match($pattern,$subject,&$matches){$matches=['shadow'];return 9;}}
namespace ExtraCount {function strlen(...$values){return count($values);}}
namespace {
if(!extension_loaded('reference_host'))require __DIR__.'/fixture.php';
function checkTarget(bool $ok,string $message):void{if(!$ok)throw new \RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$GLOBALS['jump_count']=0;checkTarget(JumpRuntime\skip_lookup(true,'kept')==='kept'&&$GLOBALS['jump_count']===0,'goto skips namespaced lookup and nested argument');checkTarget(JumpRuntime\skip_lookup(false,'called')==='called'&&$GLOBALS['jump_count']===1,'fallthrough lookup and nested argument exactly once');checkTarget(JumpRuntime\skip_switch(true,'x')===0&&JumpRuntime\skip_switch(false,'x')===1&&JumpRuntime\skip_switch(false,'y')===2,'goto bypasses dynamic switch selector and retains match semantics');
$context=stream_context_create(['http'=>['method'=>'GET']]);$caller=3;
checkTarget(ShadowScalar\scalar_value($caller,$context)===[3,17]&&$caller===3,'namespace scalar shadow keeps runtime dispatch and caller');
$result=ShadowObject\object_value($caller,$context);checkTarget($result[0]===3&&$result[1] instanceof \stdClass&&$result[1]->value==='shadow'&&$caller===3,'namespace object shadow keeps runtime return and caller');
$expected=[3,['http'=>['method'=>'GET']]];
checkTarget(TargetGlobal\fq_value($caller,$context)===$expected&&TargetGlobal\alias_value($caller,$context)===$expected&&global_value($caller,$context)===$expected,'qualified alias global calls keep array return');
try{ShadowScalar\scalar_value([], $context);throw new \RuntimeException('wrong entry type accepted');}catch(\TypeError $error){echo "PASS wrong entry type rejected\n";}
checkTarget(RefValue\reference_value()===[7,[]],'global output-ref carrier respects namespace byvalue argument');
checkTarget(RefWrite\reference_write()===[9,['shadow']],'global output-ref carrier preserves compatible namespace byref update');
checkTarget(RefFallback\reference_fallback()===[1,['x']],'unshadowed global output reference keeps caller update');
checkTarget(ExtraCount\extra_count(['x','y','z'])===3,'namespace signature dynamic unpack count bypasses global arity');
checkTarget(LateProbe\same_callsite()===1,'first late callsite resolves global builtin');
eval('namespace LateProbe; function strlen($value){return 42;}');
checkTarget(LateProbe\same_callsite()===1 && LateProbe\different_callsite()===42,'same callsite retains global while later callsite resolves namespace case-insensitively');
try{MissingProbe\missing_call();throw new \RuntimeException('missing function accepted');}catch(\Error $error){checkTarget(str_contains(strtolower($error->getMessage()),'undefined'),'missing namespace and global function raises PHP error');}
eval('namespace MissingProbe; function absent_target_function(){return 23;}');checkTarget(MissingProbe\missing_call()===23,'failed unresolved lookup does not freeze later namespace declaration');

$GLOBALS['missing_count']=0;checkTarget(EvalMissing\conditional_eval(false)===7&&EvalMissing\short_eval(false)===false&&$GLOBALS['missing_count']===0,'unexecuted conditional and short-circuit calls do not resolve missing functions');try{EvalMissing\missing_eval();throw new \RuntimeException('missing eval accepted');}catch(\Error $error){checkTarget($GLOBALS['missing_count']===0,'undefined fallback rejects before evaluating nested argument');}
$GLOBALS['once_count']=0;checkTarget(EvalOnce\nested_eval()===31&&$GLOBALS['once_count']===1,'resolved namespace nested argument evaluated exactly once');
$reflection=new \ReflectionFunction('ShadowScalar\\scalar_value');checkTarget((string)$reflection->getParameters()[0]->getType()==='int'&&!$reflection->getParameters()[0]->isPassedByReference(),'original int entry reflection unchanged');
}

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
    foreach (['JumpRuntime'=>['skip_lookup','skip_switch'],'ShadowScalar'=>['scalar_value'],'ShadowObject'=>['object_value'],'TargetGlobal'=>['fq_value','alias_value'],'RefValue'=>['reference_value'],'RefWrite'=>['reference_write'],'RefFallback'=>['reference_fallback'],'ExtraCount'=>['extra_count'],'LateProbe'=>['same_callsite','different_callsite'],'MissingProbe'=>['missing_call'],'EvalMissing'=>['missing_eval','conditional_eval','short_eval'],'EvalOnce'=>['nested_eval'],''=>['global_value']] as $namespace=>$functions) {
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
    echo 'PASS host PHP target metadata value storage runtime, independent processes, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
