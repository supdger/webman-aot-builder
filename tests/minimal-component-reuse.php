<?php

declare(strict_types=1);

use WebmanAotBuilder\Toolchain\MinimalComponent;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
$root = realpath(getenv('WEBMAN_AOT_TEST_TMP') ?: sys_get_temp_dir()) . '/aot-component-reuse-' . bin2hex(random_bytes(6));
mkdir($root . '/old/prepared', 0700, true);
mkdir($root . '/replacement/prepared', 0700, true);
mkdir($root . '/candidates', 0700);
$count = 0;
function check(bool $condition, string $message): void {
    global $count;
    if (!$condition) { throw new RuntimeException($message); }
    $count++;
    fwrite(STDOUT, "[PASS] {$message}\n");
}
function manifest(string $directory, string $changed): string {
    file_put_contents($directory . '/toolchain.lock.json', '{}');
    file_put_contents($directory . '/prepared/unchanged', 'original trusted large component');
    file_put_contents($directory . '/prepared/changed', $changed);
    $entries = ['prepared' => ['type' => 'directory']];
    foreach (['toolchain.lock.json', 'prepared/unchanged', 'prepared/changed'] as $name) {
        chmod($directory . '/' . $name, 0600);
        $entries[$name] = ['type' => 'file', 'size' => filesize($directory . '/' . $name),
            'sha256' => hash_file('sha256', $directory . '/' . $name), 'executable' => false];
    }
    $json = json_encode(['schema' => 'webman-aot-builder-minimal-component-v1', 'host' => 'macos-arm64',
        'toolchainLockSha256' => hash('sha256', '{}'), 'entries' => $entries], JSON_THROW_ON_ERROR);
    file_put_contents($directory . '/minimal-component.json', $json);
    return hash('sha256', $json);
}
$oldHash = manifest($root . '/old', 'older prepared source');
$targetHash = manifest($root . '/replacement', 'new locked prepared source');
$component = new MinimalComponent();
$invoke = static function (string $name, string $previous, string $replacements, ?string $hash = null) use ($component, $root, $oldHash, $targetHash): void {
    $component->reuse($previous, $oldHash, hash('sha256', '{}'), $root . '/replacement/minimal-component.json',
        $replacements, $root . '/candidates/' . $name, 'macos-arm64', $hash ?? $targetHash, hash('sha256', '{}'));
};
try {
    $invoke('valid', $root . '/old/', $root . '/replacement/');
    check(file_get_contents($root . '/candidates/valid/prepared/changed') === 'new locked prepared source', 'changed target file comes from trusted bundled replacement');
    check(file_get_contents($root . '/old/prepared/changed') === 'older prepared source', 'old generation is unchanged');
    file_put_contents($root . '/candidates/valid/prepared/unchanged', 'candidate edited');
    check(file_get_contents($root . '/old/prepared/unchanged') === 'original trusted large component', 'copies do not share hardlinks with old generation');
    foreach (['bad-manifest', 'missing-replacement', 'corrupt-old', 'linked-root', 'linked-candidate'] as $case) {
        if ($case === 'missing-replacement') { rename($root . '/replacement/prepared/changed', $root . '/saved'); }
        if ($case === 'corrupt-old') { file_put_contents($root . '/old/prepared/unchanged', 'bad'); }
        if ($case === 'linked-root') { symlink($root . '/replacement', $root . '/linked'); }
        if ($case === 'linked-candidate') { symlink($root . '/old', $root . '/candidates/escape'); }
        try {
            $name = $case === 'linked-candidate' ? 'escape/new' : $case;
            $invoke($name, $root . '/old', $case === 'linked-root' ? $root . '/linked' : $root . '/replacement',
                $case === 'bad-manifest' ? str_repeat('0', 64) : null);
            throw new RuntimeException('unsafe reuse unexpectedly accepted: ' . $case);
        } catch (WebmanAotBuilder\Cli\ConfigurationException | WebmanAotBuilder\Cli\UnavailableException $error) {
            check(true, $case . ' rejected before activation');
        }
        if ($case === 'missing-replacement') { rename($root . '/saved', $root . '/replacement/prepared/changed'); }
        if ($case === 'corrupt-old') { file_put_contents($root . '/old/prepared/unchanged', 'original trusted large component'); }
    }
    $targetJson = file_get_contents($root . '/replacement/minimal-component.json');
    $implicit = json_decode($targetJson, true);
    unset($implicit['entries']['prepared']);
    $implicitJson = json_encode($implicit, JSON_THROW_ON_ERROR);
    file_put_contents($root . '/replacement/minimal-component.json', $implicitJson);
    $invoke('implicit-parent', $root . '/old', $root . '/replacement', hash('sha256', $implicitJson));
    check(is_file($root . '/candidates/implicit-parent/prepared/changed'), 'implicit directories from real component manifests are supported');
    $implicit['entries']['prepared'] = ['type' => 'link', 'target' => 'toolchain.lock.json'];
    $unsafeJson = json_encode($implicit, JSON_THROW_ON_ERROR);
    file_put_contents($root . '/replacement/minimal-component.json', $unsafeJson);
    try {
        $invoke('unsafe-parent', $root . '/old', $root . '/replacement', hash('sha256', $unsafeJson));
        throw new RuntimeException('explicit non-directory ancestor accepted');
    } catch (WebmanAotBuilder\Cli\ConfigurationException $error) {
        check(!file_exists($root . '/candidates/unsafe-parent'), 'explicit non-directory ancestor rejected before candidate creation');
    }
    file_put_contents($root . '/replacement/minimal-component.json', $targetJson);
    check(!file_exists($root . '/old/new'), 'linked candidate never writes outside its controlled root');
    fwrite(STDOUT, "{$count} checks passed\n");
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
