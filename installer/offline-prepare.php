<?php

declare(strict_types=1);

use WebmanAotBuilder\Platform\UserDirectoryLayout;
use WebmanAotBuilder\Toolchain\MacosToolchainPreparer;
use WebmanAotBuilder\Toolchain\MinimalComponentLock;
use WebmanAotBuilder\Toolchain\MinimalComponentManager;
use WebmanAotBuilder\Toolchain\NativeDownloader;
use WebmanAotBuilder\Toolchain\WindowsToolchainPreparer;

$app = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($app): void {
    $prefix = 'WebmanAotBuilder\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $app . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$started = microtime(true);
try {
    $reuseHome = getenv('WEBMAN_AOT_REUSE_HOME');
    $reuse = ($argv[1] ?? null) === '--reuse' && is_string($reuseHome) && $reuseHome !== '';
    if (count($argv) !== 2 || (!$reuse && (!is_file($argv[1]) || is_link($argv[1])))) {
        throw new RuntimeException('bundled minimal component ZIP is missing or unsafe');
    }
    $host = match (true) {
        PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64' => 'macos-arm64',
        PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8 => 'windows-x86_64',
        default => throw new RuntimeException('unsupported offline installation host'),
    };
    $layout = UserDirectoryLayout::detect();
    $preparer = $host === 'macos-arm64'
        ? new MacosToolchainPreparer($app . '/tools/macos-prepare.php', $layout->root())
        : new WindowsToolchainPreparer($app . '/tools/windows-replay.ps1', $layout->root());
    $component = (new MinimalComponentLock())->forHost(
        $app . '/toolchain/minimal-components.lock.json',
        $app . '/toolchain.lock.json',
        $host
    );
    if ($component === null) {
        throw new RuntimeException("this package has no locked minimal component for {$host}");
    }
    $manager = new MinimalComponentManager(
        $layout,
        $host,
        $component,
        $preparer,
        new NativeDownloader()
    );
    fwrite(STDERR, "[offline] Installing verified minimal {$host} toolchain from this package...\n");
    $progress = static fn (string $message): int => fwrite(STDERR, "[offline] {$message}\n");
    if ($reuse) {
        if (is_link($reuseHome) || !is_dir($reuseHome)) { throw new RuntimeException('previous runtime is unsafe'); }
        $oldApp = $reuseHome . '/current/app';
        $oldLock = json_decode((string) file_get_contents($oldApp . '/toolchain/minimal-components.lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $oldComponent = $oldLock['components'][$host] ?? null;
        if (($oldLock['schema'] ?? null) !== 'webman-aot-builder-minimal-components-lock-v1'
            || !is_array($oldComponent) || !is_string($oldComponent['manifestSha256'] ?? null)
            || ($oldLock['toolchainLockSha256'] ?? null) !== hash_file('sha256', $oldApp . '/toolchain.lock.json')) {
            throw new RuntimeException('previous runtime component lock differs');
        }
        $generations = glob($reuseHome . '/toolchains/versions/*', GLOB_ONLYDIR) ?: [];
        rsort($generations, SORT_STRING);
        $previous = null;
        foreach ($generations as $path) {
            if (is_link($path) || !is_file($path . '/minimal-component.json')) { continue; }
            try {
                (new \WebmanAotBuilder\Toolchain\MinimalComponent())->verifyGeneration(
                    $path, $host, $oldComponent['manifestSha256'], $oldLock['toolchainLockSha256']
                );
                $previous = $path;
                break;
            } catch (Throwable $failure) {
                $progress('Previous generation cannot be reused: ' . $failure->getMessage());
            }
        }
        if ($previous === null) { throw new RuntimeException('previous runtime has no verified reusable generation'); }
        $upgrade = $app . '/toolchain/minimal-upgrade';
        $generation = $manager->reuse([
            'generation' => $previous, 'manifestSha256' => $oldComponent['manifestSha256'],
            'toolchainLockSha256' => $oldLock['toolchainLockSha256'],
        ], $upgrade . '/minimal-component.json', $upgrade . '/files', $progress);
    } else {
        $generation = $manager->ensure($argv[1], $progress);
    }
    fwrite(STDOUT, sprintf(
        "[OK] Offline toolchain ready: %s; %.1f seconds\n",
        basename($generation),
        microtime(true) - $started
    ));
} catch (Throwable $error) {
    fwrite(STDERR, sprintf(
        "[ERROR] Offline toolchain preparation failed after %.1f seconds: %s\n",
        microtime(true) - $started,
        $error->getMessage()
    ));
    exit(1);
}
