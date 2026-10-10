<?php

declare(strict_types=1);

use WebmanAotBuilder\Toolchain\TypePhpProjectCompiler;

require dirname(__DIR__) . '/src/Toolchain/TypePhpProjectCompiler.php';
function ensure(bool $ok, string $message): void { if (!$ok) { throw new RuntimeException($message); } }
function put(string $file, string $contents): void {
    if (!is_dir(dirname($file))) { mkdir(dirname($file), 0700, true); }
    file_put_contents($file, $contents);
}
$root = sys_get_temp_dir() . '/webman-aot-missing-dependency-' . bin2hex(random_bytes(6));
$file = $root . '/vendor/example/source/Caller.php';
$package = ['name' => 'example/source', 'version' => '1.0.0', 'install-path' => '../example/source',
    'autoload' => ['psr-4' => ['Example\\Source\\' => '']], 'suggest' => ['example/optional' => '^1.0']];
put($file, '<?php trait Caller {}');
put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package]]));
put($root . '/composer.lock', json_encode(['packages' => [$package]]));
$method = new ReflectionMethod(TypePhpProjectCompiler::class, 'missingDependencyHint');
$compiler = new TypePhpProjectCompiler();
$output = "Fatal error: Trait `Example\\Optional\\Missing` not found in {$file}:7\n";
try {
    $hint = $method->invoke($compiler, $root, $output);
    ensure(is_string($hint) && str_contains($hint, 'example/source 1.0.0') && str_contains($hint, 'Caller.php:7'), 'Real compiler failure must identify locked source');
    ensure(str_contains($hint, 'example/optional') && str_contains($hint, '元数据线索'), 'Suggestion is only metadata evidence');
    ensure($method->invoke($compiler, $root, 'Compiler error: unsupported expression') === null, 'Unrelated compiler errors remain unchanged');
    ensure($method->invoke($compiler, $root, str_replace($file, __FILE__, $output)) === null, 'Outside source cannot become dependency evidence');
    $package['version'] = '2.0.0';
    put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package]]));
    ensure($method->invoke($compiler, $root, $output) === null, 'Installed/lock drift cannot produce package ownership claims');
    $package['version'] = '1.0.0';
    $package['suggest'] = [];
    put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package]]));
    put($root . '/composer.lock', json_encode(['packages' => [$package]]));
    $hint = $method->invoke($compiler, $root, $output);
    ensure(is_string($hint) && !str_contains($hint, '可选依赖'), 'Missing provider must not invent an install target');
    ensure($method->invoke($compiler, $root, str_replace($file, $file . "\0bad", $output)) === null, 'NUL diagnostic paths cannot replace the compiler error');
    $malformedPackages = [
        ['autoload' => ['psr-4' => 'unexpected-string']],
        ['autoload' => ['psr-0' => ['Example\\' => [new stdClass()]]]],
        ['autoload' => 'unexpected-string'],
        ['source' => 'unexpected-string'],
        ['source' => ['reference' => []]],
        ['dist' => ['reference' => []]],
        ['suggest' => ['example/optional' => []]],
        ['install-path' => []],
        ['install-path' => "../example/source\0bad"],
        ['name' => "example/source\0bad"],
    ];
    foreach ($malformedPackages as $fields) {
        $invalid = array_replace($package, $fields);
        put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$invalid]]));
        put($root . '/composer.lock', json_encode(['packages' => [$invalid]]));
        ensure($method->invoke($compiler, $root, $output) === null, 'Malformed package metadata cannot replace the compiler error');
    }
    put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package], 'dev-package-names' => 'unexpected-string']));
    put($root . '/composer.lock', json_encode(['packages' => [$package]]));
    ensure($method->invoke($compiler, $root, $output) === null, 'Malformed dev metadata cannot replace the compiler error');
    put($root . '/vendor/composer/installed.json', json_encode(['packages' => [$package]]));
    if (PHP_OS_FAMILY !== 'Windows') {
        rename(dirname($file), dirname($file) . '-kept');
        symlink(dirname($file) . '-kept', dirname($file));
        ensure($method->invoke($compiler, $root, $output) === null, 'Linked source ancestor cannot establish ownership');
        unlink(dirname($file));
        rename(dirname($file) . '-kept', dirname($file));
    }
    fwrite(STDOUT, "PASS: compiler missing-dependency diagnostics (19 checks)\n");
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
