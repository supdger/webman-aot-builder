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
$root = sys_get_temp_dir() . '/webman-aot-optional-runtime-' . bin2hex(random_bytes(6));
$external = $root . '-external';
mkdir($root, 0700, true); mkdir($external, 0700, true);
try {
    file_put_contents($root . '/Unavailable.php', '<?php namespace Fixture; class Unavailable { use \Absent\Provider; }');
    file_put_contents($root . '/nested.php', '<?php require __DIR__ . "/Unavailable.php";');
    file_put_contents($root . '/allowed.php', '<?php return ["value" => 7];');
    file_put_contents($external . '/Unavailable.php', '<?php return ["external" => 11];');
    symlink($root . '/Unavailable.php', $root . '/alias.php');
    $code = "#include <algorithm>\n#include <string>\nextern \"C\" {\n#include <php.h>\n#include <main/fopen_wrappers.h>\n#include <Zend/zend_virtual_cwd.h>\n}\n";
    $code .= (new TypePhp\Generator\UnavailableDeclarationGenerator())->render('guard_loader', [[
        'name' => 'Fixture\\Unavailable', 'trait' => 'Absent\\Provider', 'file' => 'Unavailable.php',
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
    $fatal = [
        'canonical-autoload' => 'class_exists("Fixture\\\\Unavailable", true);',
        'leading-slash' => 'class_exists("\\\\Fixture\\\\Unavailable", true);',
        'dynamic-new' => '$name="Fixture\\\\Unavailable"; new $name;',
        'static-use' => 'Fixture\\Unavailable::work();',
        'reflection' => 'new ReflectionClass("Fixture\\\\Unavailable");',
        'include-absolute' => 'include ' . var_export($root . '/Unavailable.php', true) . ';',
        'include-relative' => 'require "./Unavailable.php";',
        'include-nested' => 'require "nested.php";',
        'include-file-scheme' => 'include ' . var_export('file://' . $root . '/Unavailable.php', true) . ';',
        'include-filter-scheme' => 'include ' . var_export('php://filter/resource=' . $root . '/Unavailable.php', true) . ';',
        'include-symlink' => 'include "alias.php";',
        'uncatchable' => 'try { class_exists("Fixture\\\\Unavailable", true); } catch(Throwable $error) { echo "CAUGHT"; }',
    ];
    $baseline = 'spl_autoload_register(function($name){ if($name === "Fixture\\\\Unavailable") { require "Unavailable.php"; }});';
    foreach ($fatal as $name => $expression) {
        echo "STAGE PHP/generated runtime {$name}\n";
        foreach ([[$php, '-n', '-r', $baseline . $expression], [$php, '-n', '-d', 'extension=' . $root . '/generated.so', '-r', $expression]] as $command) {
            [$exit, $output] = runOptional($command, $root);
            ensureOptional($exit === 255 && str_contains($output, 'Trait "Absent\\Provider" not found')
                && !str_contains($output, 'CAUGHT'), 'Loading failure changed: ' . $name . ': ' . $output);
        }
        echo "PASS fatal255 {$name}\n";
    }
    $normal = [
        'wrong-case-autoload' => ['var_dump(class_exists("fixture\\\\unavailable",true));', 'bool(false)'],
        'autoload-false' => ['var_dump(class_exists("Fixture\\\\Unavailable",false));', 'bool(false)'],
        'unknown-class' => ['var_dump(class_exists("UnrelatedUnknown",true));', 'bool(false)'],
        'external-config' => ['$value=include ' . var_export($external . '/Unavailable.php', true) . ';echo $value["external"];', '11'],
        'ordinary-include' => ['$value=include "allowed.php";echo $value["value"];', '7'],
        'fallback-autoloader' => ['spl_autoload_register(function($name){if($name === "fixture\\\\unavailable"){eval("namespace Fixture; class Unavailable {}");}});var_dump(class_exists("fixture\\\\unavailable",true));', 'bool(true)'],
    ];
    foreach ($normal as $name => [$expression, $expected]) {
        echo "STAGE PHP/generated runtime {$name}\n";
        foreach ([[$php, '-n', '-r', $baseline . $expression], [$php, '-n', '-d', 'extension=' . $root . '/generated.so', '-r', $expression]] as $command) {
            [$exit, $output] = runOptional($command, $root);
            ensureOptional($exit === 0 && str_contains($output, $expected), 'Ordinary loading changed: ' . $name . ': ' . $output);
        }
        echo "PASS {$name}\n";
    }
    [$exit, $output] = runOptional([$php, '-n', '-d', 'extension=' . $root . '/generated.so', '-r',
        'var_dump(guard_cycle());include "allowed.php";echo json_encode(guard_counts());'], $root);
    ensureOptional($exit === 0 && str_contains($output, '[1,1]'), 'Previous/later hook chain or helper cycle failed: ' . $output);
    echo "PASS repeated request helpers, previous hook and later-hook preservation\n";
    echo 'PASS host optional runtime 19 cases elapsed=' . round(microtime(true) - $started, 3) . "s\n";
    echo "LIMIT host NTS evidence; helper cycling is not a full Zend cross-request test; Linux ZTS runtime and Linux case-sensitive paths require target execution\n";
} finally {
    foreach ([$root, $external] as $directory) {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
        rmdir($directory);
    }
}
