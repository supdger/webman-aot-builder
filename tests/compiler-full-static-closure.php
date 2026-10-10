<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
$sysroot = $argv[2] ?? '';
$llvm = $argv[3] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class ClosureTargetCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root) { parent::__construct($root); $this->forTest = true; }
    public function sources(bool $fullStatic): array
    {
        $this->fullStatic = $fullStatic;
        $method = new ReflectionMethod(TypePhp\Translator::class, 'prepareNativeSourceFiles');
        $method->setAccessible(true); return $method->invoke($this, []);
    }
    public function command(string $source, string $object, string $sysroot, string $clang): string
    {
        $this->fullStatic = true; $this->cxxStd = 'c++17';
        $this->cxxFlags = '--sysroot=' . escapeshellarg($sysroot)
            . ' -isystem ' . escapeshellarg($sysroot . '/usr/include/c++/12.2.1')
            . ' -isystem ' . escapeshellarg($sysroot . '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl');
        $this->setCppCompiler($clang);
        return $this->getNativeBuilder()->compileCommand($source, $object, $this->getCompileCommandOptions(), null);
    }
}
function runClosureTarget(array|string $command, string $root): string
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start target closure stage'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    if ($error !== '') { echo $error; }
    if ($exit !== 0) { throw new RuntimeException('Target closure stage exit=' . $exit); }
    return $output;
}
function closureStrongExports(string $symbols): array
{
    preg_match_all('/^[0-9a-f]+ [TDBR] (.+)$/m', $symbols, $matches); return $matches[1];
}
$root = sys_get_temp_dir() . '/webman-aot-closure-target-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true); $started = microtime(true);
try {
    $phpx = $toolchain . '/vendor/swoole/phpx'; $source = $phpx . '/src/core/closure.cc';
    $sdk = $phpx . '/full-static/sdk'; $archive = $sdk . '/lib/libphpx.a';
    $manifest = json_decode(file_get_contents($sdk . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['target'] ?? null) !== 'linux-x64' || ($manifest['zts'] ?? null) !== true) {
        throw new RuntimeException('This regression requires the locked Linux x64 ZTS SDK');
    }
    $compiler = new ClosureTargetCompiler($root);
    if (in_array($source, $compiler->sources(false), true) || count(array_keys($compiler->sources(true), $source, true)) !== 1) {
        throw new RuntimeException('Closure source must be selected once only for full-static mode');
    }
    echo "PASS full-static source selected once; shared source list unchanged\n";
    echo "STAGE compile Linux ZTS Closure with production target options\n";
    $command = $compiler->command($source, $root . '/closure.o', $sysroot, $llvm . '/clang++');
    echo $command . "\n"; runClosureTarget($command, $root);
    $fixed = runClosureTarget([$llvm . '/llvm-nm', '--defined-only', '--extern-only', $root . '/closure.o'], $root);
    $all = runClosureTarget([$llvm . '/llvm-nm', '--defined-only', '--extern-only', $archive], $root);
    if (!preg_match('/src__core__closure\.cc\.o:\n(.*?)(?:\n\n|\z)/s', $all, $member)) {
        throw new RuntimeException('Actual SDK Closure member not found');
    }
    $original = closureStrongExports($member[1]);
    if ($original === [] || array_diff($original, closureStrongExports($fixed)) !== []) {
        throw new RuntimeException('Fixed target object does not cover all archive Closure strong exports');
    }
    echo 'PASS actual target archive member strong exports covered=' . count($original) . "\n";
    echo "STAGE link target object before archive with duplicate definitions forbidden\n";
    runClosureTarget([$llvm . '/ld.lld', '-r', $root . '/closure.o', $archive, '-Map=' . $root . '/link.map', '-o', $root . '/linked.o'], $root);
    if (str_contains(file_get_contents($root . '/link.map'), 'libphpx.a(src__core__closure.cc.o)')) {
        throw new RuntimeException('Original archive Closure member was also extracted');
    }
    echo 'PASS target link, original Closure member not extracted, elapsed=' . round(microtime(true) - $started, 3) . "s\n";
    echo "Target object/link evidence only; Linux runtime has not been executed. Nano retains its existing source runtime.\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
