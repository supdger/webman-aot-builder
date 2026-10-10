<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/src/Generator/UnavailableDeclarationGenerator.php';
$php = $argv[2] ?? PHP_BINARY;
$phpConfig = $argv[3] ?? 'php-config';
$compiler = $argv[4] ?? 'clang++';
function runOptional(array $command, string $cwd): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start optional declaration probe'); }
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $output . $error];
}
function ensureOptional(bool $condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
}
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-optional-interface-runtime-' . bin2hex(random_bytes(6));
$external = $root . '-external';
mkdir($root, 0700, true); mkdir($external, 0700, true);
try {
    file_put_contents($root . '/Unavailable.php', '<?php namespace Fixture; class Unavailable implements \Absent\Contract {}');
    file_put_contents($root . '/Child.php', '<?php namespace Fixture;class Child extends Unavailable{}');
    file_put_contents($root . '/Contract.php', '<?php namespace Fixture;interface Contract extends \Absent\Contract{}');
    file_put_contents($root . '/Consumer.php', '<?php namespace Fixture;class Consumer implements Contract{}');
    file_put_contents($root . '/nested.php', '<?php require __DIR__ . "/Unavailable.php";');
    file_put_contents($root . '/allowed.php', '<?php return ["value" => 7];');
    file_put_contents($external . '/Unavailable.php', '<?php return ["external" => 11];');
    symlink($root . '/Unavailable.php', $root . '/alias.php');
    $code = "#include <algorithm>\n#include <string>\nextern \"C\" {\n#include <php.h>\n#include <main/fopen_wrappers.h>\n#include <Zend/zend_virtual_cwd.h>\n}\n";
    $code .= (new TypePhp\Generator\UnavailableDeclarationGenerator())->render('guard_loader', [[
        'name' => 'Fixture\\Unavailable', 'trait' => 'Absent\\Contract', 'kind'=>'Interface', 'file' => 'Unavailable.php',
    ], [
        'name'=>'Fixture\\Child','trait'=>'Absent\\Contract','kind'=>'Interface','file'=>'Child.php',
    ], [
        'name'=>'Fixture\\Contract','trait'=>'Absent\\Contract','kind'=>'Interface','file'=>'Contract.php',
    ], [
        'name'=>'Fixture\\Consumer','trait'=>'Absent\\Contract','kind'=>'Interface','file'=>'Consumer.php',
    ]]);
    $code .= <<<'CPP'
static zend_op_array *(*experiment_original_compile_file)(zend_file_handle *, int) = nullptr;
static unsigned previous_calls = 0;
static unsigned later_calls = 0;
static zend_op_array *experiment_previous_hook(zend_file_handle *file, int type) {
    previous_calls++;
    return experiment_original_compile_file(file, type);
}
static zend_op_array *experiment_later_hook(zend_file_handle *file, int type) {
    later_calls++;
    return typephp_guard_declaration_file(file, type);
}
ZEND_BEGIN_ARG_INFO_EX(arginfo_guard_cycle, 0, 0, 0)
ZEND_END_ARG_INFO()
PHP_FUNCTION(guard_cycle) {
    auto previous = typephp_previous_compile_file;
    typephp_unavailable_request_shutdown();
    if (typephp_declaration_request_active || typephp_declaration_runtime_root || zend_compile_file != typephp_guard_declaration_file) {
        zend_error_noreturn(E_ERROR, "Invalid request shutdown state");
    }
    if (typephp_unavailable_request_init() != SUCCESS || typephp_previous_compile_file != previous) {
        zend_error_noreturn(E_ERROR, "Invalid second request initialization");
    }
    zend_compile_file = experiment_later_hook;
    if (zend_compile_file == typephp_guard_declaration_file) zend_compile_file = typephp_previous_compile_file;
    if (zend_compile_file != experiment_later_hook) zend_error_noreturn(E_ERROR, "Later hook overwritten");
    RETURN_TRUE;
}
PHP_FUNCTION(guard_counts) {
    array_init(return_value); add_next_index_long(return_value,previous_calls); add_next_index_long(return_value,later_calls);
}
static const zend_function_entry functions[] = {
    PHP_FE(guard_loader, arginfo_guard_loader)
    PHP_FE(guard_cycle, arginfo_guard_cycle)
    PHP_FE(guard_counts, arginfo_guard_cycle)
    PHP_FE_END
};
PHP_MINIT_FUNCTION(optional_guard) {
    experiment_original_compile_file = zend_compile_file;
    zend_compile_file = experiment_previous_hook;
    typephp_previous_compile_file = zend_compile_file;
    zend_compile_file = typephp_guard_declaration_file;
    return SUCCESS;
}
PHP_MSHUTDOWN_FUNCTION(optional_guard) {
    if (zend_compile_file == typephp_guard_declaration_file) zend_compile_file = typephp_previous_compile_file;
    return SUCCESS;
}
PHP_RINIT_FUNCTION(optional_guard) { return typephp_unavailable_request_init(); }
PHP_RSHUTDOWN_FUNCTION(optional_guard) { typephp_unavailable_request_shutdown(); return SUCCESS; }
zend_module_entry optional_guard_module_entry = {
    STANDARD_MODULE_HEADER,"optional_guard",functions,PHP_MINIT(optional_guard),PHP_MSHUTDOWN(optional_guard),
    PHP_RINIT(optional_guard),PHP_RSHUTDOWN(optional_guard),NULL,"0.2",STANDARD_MODULE_PROPERTIES
};
extern "C" { ZEND_GET_MODULE(optional_guard) }
CPP;
    file_put_contents($root . '/generated.cc', $code);
    [$configExit, $includes] = runOptional([$phpConfig, '--include-dir'], $root);
    ensureOptional($configExit === 0, 'Cannot obtain host PHP headers: ' . $includes);
    $includes = trim($includes);
    $command = [$compiler, '-std=c++17', '-shared', '-fPIC'];
    if (PHP_OS_FAMILY === 'Darwin') { array_push($command, '-undefined', 'dynamic_lookup'); }
    foreach (['', '/main', '/Zend', '/TSRM', '/ext', '/ext/date/lib'] as $path) { $command[] = '-I' . $includes . $path; }
    array_push($command, $root . '/generated.cc', '-o', $root . '/generated.so');
    echo "STAGE compile same production Generator as a host test extension\n";
    [$exit, $output] = runOptional($command, $root);
    ensureOptional($exit === 0, 'Host extension compilation failed: ' . $output);
    echo "PASS host extension compile\n";
    $baseline='spl_autoload_register(function($name){$files=["Fixture\\Unavailable"=>"Unavailable.php","Fixture\\Child"=>"Child.php","Fixture\\Contract"=>"Contract.php","Fixture\\Consumer"=>"Consumer.php"];if(isset($files[$name]))require $files[$name];});';
    foreach([
        'autoload'=>'class_exists("Fixture\\Unavailable",true);',
        'derived-class'=>'class_exists("Fixture\\Child",true);',
        'interface-extends'=>'interface_exists("Fixture\\Contract",true);',
        'interface-dependent'=>'class_exists("Fixture\\Consumer",true);',
        'dynamic-new'=>'$name="Fixture\\Unavailable";new $name;',
        'reflection'=>'new ReflectionClass("Fixture\\Unavailable");',
        'include'=>'include "Unavailable.php";',
        'nested-include'=>'require "nested.php";',
        'symlink'=>'include "alias.php";',
        'stream-include'=>'',
    ]as$name=>$expression){
        if($name==='stream-include')$expression='include '.var_export('php://filter/resource='.$root.'/Unavailable.php',true).';';
        $expression='try{'.$expression.'echo "LOADED";}catch(Error $error){echo get_class($error),":",$error->getMessage();}echo "|after|";var_dump(class_exists("Fixture\\Unavailable",false));';
        echo "STAGE PHP/generated catchable interface Error {$name}\n";
        $outputs=[];foreach([[$php,'-n','-r',$baseline.$expression],[$php,'-n','-d','extension='.$root.'/generated.so','-r',$expression]]as$command){[$exit,$output]=runOptional($command,$root);ensureOptional($exit===0&&str_contains($output,'Error:Interface "Absent\Contract" not found|after|bool(false)'),'Interface failure changed: '.$name.': '.$output);$outputs[]=$output;}
        ensureOptional($outputs[0]===$outputs[1],'PHP/generated output mismatch: '.$name);echo "PASS exact PHP/generated {$name}\n";
    }
    $normal=[
        'autoload-false'=>['var_dump(class_exists("Fixture\\Unavailable",false));','bool(false)'],
        'wrong-case'=>['var_dump(class_exists("fixture\\unavailable",true));','bool(false)'],
        'unknown'=>['var_dump(interface_exists("UnrelatedUnknown",true));','bool(false)'],
        'ordinary-include'=>['$value=include "allowed.php";echo $value["value"];','7'],
        'external-include'=>['$value=include '.var_export($external.'/Unavailable.php',true).';echo $value["external"];','11'],
    ];
    foreach($normal as$name=>[$expression,$expected])foreach([[$php,'-n','-r',$baseline.$expression],[$php,'-n','-d','extension='.$root.'/generated.so','-r',$expression]]as$command){[$exit,$output]=runOptional($command,$root);ensureOptional($exit===0&&str_contains($output,$expected),'Ordinary loading changed: '.$name.': '.$output);echo "PASS {$name}\n";}
    [$exit, $output] = runOptional([$php, '-n', '-d', 'extension=' . $root . '/generated.so', '-r',
        'var_dump(guard_cycle());include "allowed.php";echo json_encode(guard_counts());'], $root);
    ensureOptional($exit === 0 && str_contains($output, '[1,1]'), 'Previous/later hook chain or helper cycle failed: ' . $output);
    echo "PASS repeated request helpers, previous hook and later-hook preservation\n";
    echo 'PASS host optional interface runtime 16 cases elapsed=' . round(microtime(true) - $started, 3) . "s\n";
    echo "LIMIT host NTS evidence; helper cycling is not a full Zend cross-request test; Linux ZTS runtime and Linux case-sensitive paths require target execution\n";
} finally {
    foreach ([$root, $external] as $directory) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
}
