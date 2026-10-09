<?php

declare(strict_types=1);

$source = (string) file_get_contents(dirname(__DIR__) . '/tools/package-installers.php');
// Load the production class without invoking its packaging CLI.
$boundary = strrpos($source, "\ntry {\n");
if ($boundary === false) { throw new RuntimeException('packager CLI boundary missing'); }
eval(substr($source, strlen('<?php'), $boundary - strlen('<?php')));
$packager = new InstallerPackager(dirname(__DIR__));
$read = new ReflectionMethod($packager, 'sourceLicenses');
$root = sys_get_temp_dir() . '/webman-aot-license-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
$tests = 0;
function archive(string $path, array $entries): void
{
    $tar = new PharData($path);
    foreach ($entries as $name => $contents) { $tar->addFromString($name, $contents); }
    unset($tar);
}
function rejected(Closure $action, string $expected): void
{
    try { $action(); } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $expected)) { return; }
        throw $error;
    }
    throw new RuntimeException('unsafe source license archive accepted');
}
try {
    $members = ['ext/mbstring/libmbfl/LICENSE', 'ext/bcmath/libbcmath/LICENSE'];
    foreach (['php-8.4.25', 'php-8.5.3', 'php-dev-selected-next', 'typephp-99.7.0'] as $name) {
        $path = $root . '/' . $name . '.tar';
        $wanted = str_starts_with($name, 'typephp') ? ['LICENSE'] : $members;
        $entries = [$name . '/other/LICENSE' => 'unrelated license'];
        foreach ($wanted as $member) { $entries[$name . '/' . $member] = 'GNU LESSER GENERAL PUBLIC LICENSE / selected ' . $member; }
        archive($path, $entries);
        $licenses = $read->invoke($packager, $path, hash_file('sha256', $path), $wanted);
        foreach ($wanted as $member) {
            if (($licenses[$member] ?? null) !== $entries[$name . '/' . $member]) { throw new RuntimeException('selected license identity lost'); }
        }
        echo "PASS selected source root / {$name}\n"; $tests++;
    }
    $path = $root . '/php-8.5.3.tar';
    rejected(fn() => $read->invoke($packager, $path, str_repeat('0', 64), $members), 'digest differs');
    echo "PASS wrong selected source SHA refused\n"; $tests++;
    archive($root . '/multi.tar', ['php-8.5.3/ext/mbstring/libmbfl/LICENSE' => 'license', 'other/configure' => 'extra root']);
    rejected(fn() => $read->invoke($packager, $root . '/multi.tar', hash_file('sha256', $root . '/multi.tar'), $members), 'one source root');
    echo "PASS multiple source roots refused\n"; $tests++;
    archive($root . '/missing.tar', ['php-8.5.3/other/LICENSE' => 'unrelated license']);
    rejected(fn() => $read->invoke($packager, $root . '/missing.tar', hash_file('sha256', $root . '/missing.tar'), $members), 'license is missing');
    echo "PASS unrelated license cannot substitute required license\n"; $tests++;
    archive($root . '/unsafe.tar', ['php"-next/LICENSE' => 'license']);
    rejected(fn() => $read->invoke($packager, $root . '/unsafe.tar', hash_file('sha256', $root . '/unsafe.tar'), ['LICENSE']), 'unsafe or repeated');
    echo "PASS unsafe source root refused\n"; $tests++;
    printf("Installer source licenses: %d cases passed in %.3fs\n", $tests, microtime(true) - $started);
} finally {
    foreach (glob($root . '/*') ?: [] as $path) { unlink($path); }
    rmdir($root);
}
