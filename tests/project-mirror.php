<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectMirror;
use WebmanAotBuilder\Project\SourceTreeSnapshot;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/'
            . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeFixture(string $path): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($path);
}

$controlFilename = PHP_OS_FAMILY === 'Windows' ? 'd-control.php' : "d-control\n.php";

if (in_array($argv[1] ?? null, ['--writer', '--activation-writer'], true)) {
    $root = $argv[2];
    $deadline = microtime(true) + 10;
    do {
        clearstatcache();
        $ready = is_file($root . '/.webman-aot-builder/write-source');
        if (($argv[1] ?? null) === '--writer') {
            $ready = false;
            foreach (glob($root . '/.webman-aot-builder/build/.project-*') ?: [] as $candidate) {
                if (is_file($candidate . '/c-modified.php')) {
                    $ready = true;
                    break;
                }
            }
        }
        if ($ready) {
            file_put_contents($root . '/a-added.php', '<?php // added');
            unlink($root . '/b-removed.php');
            file_put_contents($root . '/c-modified.php', '<?php // modified');
            file_put_contents($root . '/' . $controlFilename, '<?php // modified');
            file_put_contents($root . '/e-' . str_repeat('x', 130) . '.php', '<?php // modified');
            for ($index = 0; $index < 8; ++$index) {
                file_put_contents($root . "/f-{$index}.php", '<?php // modified');
            }
            file_put_contents($root . '/.webman-aot-builder/writer-done', 'done');
            exit(0);
        }
        usleep(1000);
    } while (microtime(true) < $deadline);
    fwrite(STDERR, "writer did not observe its copy or activation barrier\n");
    exit(1);
}

function windowsHandle(string $path, string $ready, string $release): mixed
{
    $script = '$file = [IO.File]::Open($args[0], [IO.FileMode]::Open, '
        . '[IO.FileAccess]::Read, [IO.FileShare]::ReadWrite); '
        . 'try { [IO.File]::WriteAllText($args[1], "ready"); '
        . '$deadline = [DateTime]::UtcNow.AddSeconds(10); '
        . 'while (-not [IO.File]::Exists($args[2])) { '
        . 'if ([DateTime]::UtcNow -gt $deadline) { throw "release timed out" }; '
        . 'Start-Sleep -Milliseconds 10 } } finally { $file.Dispose() }';
    $command = ' & { ' . $script . ' } ';
    foreach ([$path, $ready, $release] as $argument) {
        $command .= "'" . str_replace("'", "''", $argument) . "' ";
    }
    $process = proc_open(
        ['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand',
            base64_encode(iconv('UTF-8', 'UTF-16LE', $command))],
        [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
        $pipes
    );
    check(is_resource($process), 'unable to start native Windows file holder');
    fclose($pipes[0]);
    $deadline = microtime(true) + 10;
    while (!is_file($ready)) {
        check(microtime(true) < $deadline, 'native Windows file holder did not become ready');
        usleep(10000);
        clearstatcache(true, $ready);
    }
    return $process;
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-mirror-test-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
$writer = null;
try {
    mkdir($root . '/nested');
    mkdir($root . '/.webman-aot-builder/build', 0700, true);
    file_put_contents($root . '/source.php', '<?php // source');
    file_put_contents($root . '/.custom', 'retained hidden input');
    file_put_contents($root . '/.DS_Store', 'metadata one');
    file_put_contents($root . '/nested/.DS_Store', 'metadata two');
    $snapshot = new SourceTreeSnapshot($root);
    $before = $snapshot->capture();
    check((new SourceTreeSnapshot($root . '/nested/..'))->capture() === $before,
        'canonical project alias changed the source snapshot');
    file_put_contents($root . '/.DS_Store', 'metadata changed');
    unlink($root . '/nested/.DS_Store');
    check($snapshot->capture() === $before, 'Finder metadata changed source fingerprint');
    file_put_contents($root . '/nested/.DS_Store', 'metadata recreated before mirror');
    $mirror = (new ProjectMirror($root))->create($root . '/.webman-aot-builder/build');
    check($mirror['files'] === 2, 'mirror omitted a source or included metadata');
    check(!file_exists($mirror['path'] . '/.DS_Store'), 'root metadata entered mirror');
    check(!file_exists($mirror['path'] . '/nested/.DS_Store'), 'nested metadata entered mirror');
    check(is_file($mirror['path'] . '/.custom'), 'other hidden inputs were excluded');
    file_put_contents($root . '/source.php', '<?php // changed');
    check($snapshot->capture() !== $before, 'PHP content change was ignored');
    $changed = $snapshot->capture();
    file_put_contents($root . '/added.php', '<?php');
    check($snapshot->capture() !== $changed, 'PHP addition was ignored');
    unlink($root . '/added.php');
    check($snapshot->capture() === $changed, 'PHP removal was ignored');
    fwrite(STDOUT, "PASS metadata exclusion, hidden input retention, PHP modification/addition/removal\n");
    try {
        (new ProjectMirror($root))->create($root . '/.webman-aot-builder/build');
        throw new RuntimeException('existing mirror was replaced');
    } catch (ConfigurationException $error) {
        check(str_contains($error->getMessage(), 'already exists'), 'existing mirror conflict missing');
    }
    removeFixture($mirror['path']);
    $moves = 0;
    $mirror = (new ProjectMirror($root, static function (string $from, string $to) use (&$moves): bool {
        ++$moves;
        return rename($from, $moves < 3 ? $to . '/unavailable/project' : $to);
    }))->create($root . '/.webman-aot-builder/build');
    check($moves === 3 && is_file($mirror['path'] . '/source.php'), 'transient activation failure did not recover');
    check($mirror['sourceSha256'] === $snapshot->capture()['sha256'], 'recovered mirror fingerprint changed');
    check((glob(dirname($mirror['path']) . '/.project-*') ?: []) === [], 'recovered candidate remains');
    removeFixture($mirror['path']);
    $moves = 0;
    try {
        (new ProjectMirror($root, static function (string $from, string $to) use (&$moves): bool {
            ++$moves;
            return rename($from, $to . '/unavailable/project');
        }))->create($root . '/.webman-aot-builder/build');
        throw new RuntimeException('permanent activation failure accepted');
    } catch (ConfigurationException $error) {
        check($moves === 6, 'activation retry bound changed');
        check(str_contains($error->getMessage(), 'rename('), 'system rename reason missing');
        check(str_contains($error->getMessage(), 'retry webman-aot build'), 'activation recovery guidance missing');
        check(!str_contains($error->getMessage(), $root), 'activation reason exposes absolute source paths');
        check(!is_dir($root . '/.webman-aot-builder/build/project'), 'permanent failure published a mirror');
        check((glob($root . '/.webman-aot-builder/build/.project-*') ?: []) === [], 'failed activation candidate remains');
    }
    $moves = 0;
    try {
        (new ProjectMirror($root, static function (string $from, string $to) use (&$moves): bool {
            ++$moves;
            mkdir($to);
            file_put_contents($to . '/other-build', 'retained');
            return false;
        }))->create($root . '/.webman-aot-builder/build');
        throw new RuntimeException('concurrent destination accepted');
    } catch (ConfigurationException $error) {
        check($moves === 1 && str_contains($error->getMessage(), 'already exists'), 'concurrent target not rejected before retry');
        check(file_get_contents($root . '/.webman-aot-builder/build/project/other-build') === 'retained', 'concurrent target replaced');
    }
    removeFixture($root . '/.webman-aot-builder/build/project');
    $moves = 0;
    try {
        (new ProjectMirror($root, static function (string $from, string $to) use ($root, &$moves): bool {
            ++$moves;
            file_put_contents($root . '/source.php', '<?php // written during activation');
            return false;
        }))->create($root . '/.webman-aot-builder/build');
        throw new RuntimeException('source change during retry accepted');
    } catch (ConfigurationException $error) {
        check($moves === 1 && str_contains($error->getMessage(), 'modified "source.php"'), 'retry accepted stale source snapshot');
        check((glob($root . '/.webman-aot-builder/build/.project-*') ?: []) === [], 'source drift candidate remains');
    }
    fwrite(STDOUT, "PASS activation retries, complete mirror recovery, bounded system error, destination conflict and retry source drift rejection\n");
    if (PHP_OS_FAMILY === 'Windows') {
        foreach ([false, true] as $permanent) {
            $holder = null;
            $moves = 0;
            $ready = $root . '/.webman-aot-builder/handle-ready';
            $release = $root . '/.webman-aot-builder/handle-release';
            $nativeMirror = new ProjectMirror($root, static function (string $from, string $to) use (
                &$holder, &$moves, $ready, $release, $permanent
            ): bool {
                ++$moves;
                if ($moves === 1) {
                    $holder = windowsHandle($from . '/source.php', $ready, $release);
                }
                $moved = rename($from, $to);
                if (!$permanent && $moves === 1) {
                    check(!$moved, 'native Windows file handle did not block directory rename');
                    file_put_contents($release, 'release');
                }
                // Release before failure cleanup so candidate removal remains a separate assertion.
                if ($permanent && $moves === 6) {
                    file_put_contents($release, 'release');
                    check(proc_close($holder) === 0, 'native file holder failed');
                    $holder = null;
                }
                return $moved;
            });
            try {
                if ($permanent) {
                    try {
                        $nativeMirror->create($root . '/.webman-aot-builder/build');
                        throw new RuntimeException('permanent native Windows file handle accepted');
                    } catch (ConfigurationException $error) {
                        check($moves === 6, 'native handle retry count differs');
                        check(str_contains($error->getMessage(), 'close programs holding files'), 'native error lacks recovery');
                        check(!is_dir($root . '/.webman-aot-builder/build/project'), 'native failure published a directory');
                    }
                } else {
                    $mirror = $nativeMirror->create($root . '/.webman-aot-builder/build');
                    check($moves > 1 && $moves <= 6, 'native file handle did not recover within retry bound');
                    check(is_file($mirror['path'] . '/source.php'), 'native recovery omitted source');
                    removeFixture($mirror['path']);
                }
            } finally {
                file_put_contents($release, 'release');
                if (is_resource($holder)) {
                    check(proc_close($holder) === 0, 'native file holder failed');
                }
                unlink($ready);
                unlink($release);
            }
            check((glob($root . '/.webman-aot-builder/build/.project-*') ?: []) === [], 'native candidate remains');
        }
        fwrite(STDOUT, "PASS actual Windows file handle contention, release recovery and permanent failure cleanup\n");
    }
    foreach (['copy', 'activation'] as $stage) {
        removeFixture($root);
        mkdir($root, 0700);
        mkdir($root . '/.webman-aot-builder/build', 0700, true);
        file_put_contents($root . '/b-removed.php', '<?php');
        file_put_contents($root . '/c-modified.php', '<?php');
        file_put_contents($root . '/' . $controlFilename, '<?php');
        file_put_contents($root . '/e-' . str_repeat('x', 130) . '.php', '<?php');
        for ($index = 0; $index < 8; ++$index) {
            file_put_contents($root . "/f-{$index}.php", '<?php');
        }
        if ($stage === 'copy') {
            mkdir($root . '/padding');
            for ($index = 0; $index < 3000; ++$index) {
                file_put_contents($root . "/padding/{$index}", 'copy time for concurrent writer');
            }
        }
        $writer = proc_open(
            [PHP_BINARY, __FILE__, $stage === 'copy' ? '--writer' : '--activation-writer', $root],
            [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR],
            $pipes
        );
        check(is_resource($writer), 'unable to start concurrent writer');
        fclose($pipes[0]);
        $move = $stage === 'copy' ? null : static function (string $from, string $to) use ($root): bool {
            file_put_contents($root . '/.webman-aot-builder/write-source', 'write');
            $deadline = microtime(true) + 10;
            while (!is_file($root . '/.webman-aot-builder/writer-done')) {
                check(microtime(true) < $deadline, 'activation writer did not finish');
                usleep(1000);
                clearstatcache();
            }
            return false;
        };
        try {
            (new ProjectMirror($root, $move))->create($root . '/.webman-aot-builder/build');
            throw new RuntimeException("mirror accepted {$stage} source changes");
        } catch (ConfigurationException $error) {
            $message = $error->getMessage();
            if ($stage === 'copy') {
                check(str_starts_with($message, 'project mirror copy drift: ')
                    || str_starts_with($message, 'project source changed while creating build mirror: '),
                    'unexpected copy concurrency rejection: ' . $message);
            } else {
                check(str_contains($message, 'added "a-added.php"'), 'addition path missing: ' . $message);
                check(str_contains($message, 'removed "b-removed.php"'), 'removal path missing');
                check(str_contains($message, 'modified "c-modified.php"'), 'modification path missing');
                check(str_contains($message, PHP_OS_FAMILY === 'Windows' ? 'd-control.php' : 'd-control\\n.php'), 'changed path was not safely reported');
                check(!str_contains($message, "\n"), 'diagnostic contains a raw newline');
                check(!str_contains($message, $root), 'diagnostic exposes absolute fixture path');
                check(str_contains($message, '(+8 more)'), 'diagnostic did not cap changed path count');
                check(strlen($message) < 1000, 'diagnostic is unbounded');
                check(str_contains($message, 'retry webman-aot build'), 'recovery guidance missing');
            }
            check(!is_dir($root . '/.webman-aot-builder/build/project'), 'failed mirror was activated');
            check((glob($root . '/.webman-aot-builder/build/.project-*') ?: []) === [], 'candidate remains after failure');
        }
        check(proc_close($writer) === 0, 'concurrent writer failed');
        $writer = null;
        check(is_file($root . '/.webman-aot-builder/writer-done'), 'source writer did not complete real changes');
        fwrite(STDOUT, $stage === 'copy'
            ? "PASS real copy concurrency safely rejects changed source and removes candidate\n"
            : "PASS synchronized writer, bounded safe changed paths, recovery guidance and candidate cleanup\n");
    }

} finally {
    if (is_resource($writer)) {
        proc_terminate($writer);
        proc_close($writer);
    }
    if (is_dir($root)) {
        removeFixture($root);
    }
}
fwrite(STDOUT, sprintf("PASS completed in %.3fs\n", microtime(true) - $started));
