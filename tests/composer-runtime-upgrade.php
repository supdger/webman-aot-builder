<?php

declare(strict_types=1);

if (PHP_OS_FAMILY !== 'Darwin') { fwrite(STDOUT, '[SKIP] macOS native archive/installation fixture' . PHP_EOL); exit(0); }

require dirname(__DIR__) . '/packages/composer-installer/src/Process.php';
require dirname(__DIR__) . '/packages/composer-installer/src/Archive.php';
require dirname(__DIR__) . '/packages/composer-installer/src/Installer.php';

use Supdger\WebmanAotInstaller\Installer;

$root = realpath(getenv('WEBMAN_AOT_TEST_TMP') ?: sys_get_temp_dir()) . '/aot-runtime-upgrade-' . bin2hex(random_bytes(6));
mkdir($root . '/package/payload/runtime/bin', 0700, true);
mkdir($root . '/package/payload/app/bin', 0700, true);
mkdir($root . '/package/payload/minimal-toolchain', 0700, true);
$version = Installer::VERSION;
file_put_contents($root . '/package/package.json', json_encode(['schema' => 'webman-aot-builder-installer-package-v1',
    'version' => $version, 'platform' => 'macos-arm64', 'flavor' => 'complete']));
file_put_contents($root . '/package/payload-manifest.sha256', 'fixture');
file_put_contents($root . '/package/payload/minimal-toolchain/component.zip', 'fixture');
file_put_contents($root . '/package/payload/app/bin/webman-aot-builder.php', '<?php echo "fixture";');
file_put_contents($root . '/package/payload/runtime/bin/php', <<<'SH'
#!/bin/sh
if [ -n "${AOT_TEST_FAIL_ACTIVE:-}" ]; then
    case "$0" in "$AOT_TEST_FAIL_ACTIVE"/*) echo 'active self-check deliberately failed' >&2; exit 23;; esac
fi
echo 'new verified runtime fixture'
SH);
chmod($root . '/package/payload/runtime/bin/php', 0700);
file_put_contents($root . '/package/install.sh', <<<'SH'
#!/bin/sh
set -eu
home=''
bin=''
while [ "$#" -gt 0 ]; do
    case "$1" in --home) home="$2"; shift 2;; --bin-dir) bin="$2"; shift 2;; --no-path) shift;; *) exit 64;; esac
done
mkdir -p "$home/current" "$bin"
cp -R payload/runtime "$home/current/runtime"
cp -R payload/app "$home/current/app"
printf '#!/bin/sh\nexec "%s/current/runtime/bin/php" "$@"\n' "$home" > "$bin/webman-aot"
chmod 700 "$bin/webman-aot"
if [ -n "${AOT_TEST_FAIL_MARKER:-}" ]; then mkdir "$(dirname "$home")/ready.json"; fi
SH);
$members = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/package', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) { $members[] = substr($file->getPathname(), strlen($root . '/package/')); }
$archive = $root . '/fixture.tar.gz';
$p = proc_open(['/usr/bin/tar', '-czf', $archive, '-C', $root . '/package', ...$members], [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($p) || proc_close($p) !== 0) { throw new RuntimeException('fixture packaging failed'); }
$package = ['filename' => basename($archive), 'size' => filesize($archive), 'sha256' => hash_file('sha256', $archive)];
$bridge = new Installer(['schema' => 1, 'version' => $version, 'packages' => ['macos-arm64' => $package]], false);
$method = new ReflectionMethod(Installer::class, 'installArchive');
$passed = 0;
function check(bool $value, string $message): void {
    global $passed;
    if (!$value) { throw new RuntimeException($message); }
    $passed++;
    echo "[PASS] {$message}\n";
}
function oldState(string $state): void {
    mkdir($state . '/cache', 0700, true);
    mkdir($state . '/runtime', 0700);
    mkdir($state . '/bin', 0700);
    file_put_contents($state . '/runtime/old', 'old verified runtime');
    file_put_contents($state . '/bin/old', 'old proxy');
    file_put_contents($state . '/ready.json', '{"version":"older"}');
}
try {
    foreach (['success', 'active-check-failure', 'ready-write-failure', 'ready-promotion-failure'] as $case) {
        $state = $root . '/' . $case;
        oldState($state);
        if ($case === 'active-check-failure') { putenv('AOT_TEST_FAIL_ACTIVE=' . $state . '/runtime'); }
        if ($case === 'ready-write-failure') { putenv('AOT_TEST_FAIL_MARKER=1'); }
        if ($case === 'ready-promotion-failure') {
            rename($state . '/ready.json', $state . '/old-ready');
            symlink($state . '/old-ready', $state . '/ready.json');
        }
        $safe = true;
        try {
            $method->invokeArgs($bridge, [$state, 'macos-arm64', $archive, $package, false, &$safe]);
            check($case === 'success', 'successful candidate promoted');
            check(json_decode(file_get_contents($state . '/ready.json'), true)['version'] === $version, 'ready records new version only after candidate verification');
            check(str_contains(file_get_contents($state . '/bin/webman-aot'), $state . '/runtime'), 'private launcher points at promoted runtime');
            check(count(glob($state . '/install-backups/*/runtime/old')) === 1, 'old runtime retained in explicit backup');
        } catch (Throwable $error) {
            if ($case === 'success') { throw $error; }
            check($safe && file_get_contents($state . '/runtime/old') === 'old verified runtime'
                && file_get_contents($state . '/bin/old') === 'old proxy', $case . ' restores runtime and proxy');
            check(file_get_contents($state . '/ready.json') === '{"version":"older"}', $case . ' preserves old ready');
        } finally { putenv('AOT_TEST_FAIL_ACTIVE'); putenv('AOT_TEST_FAIL_MARKER'); }
    }
    copy($archive, $root . '/success/cache/' . $package['filename'] . '.' . $package['sha256'] . '.part');
    check((new ReflectionMethod(Installer::class, 'cachedPackage'))->invoke($bridge, $root . '/success', $package), 'complete SHA-matching part takes precedence over small download');
    echo "{$passed} checks passed\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        if ($file->isDir() && !$file->isLink()) { rmdir($file->getPathname()); } else { unlink($file->getPathname()); }
    }
    rmdir($root);
}
