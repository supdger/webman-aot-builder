<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project {
    function rmdir(string $directory): bool
    {
        $state = &$GLOBALS['cleanupFixture'];
        if ($directory === ($state['directory'] ?? null)) {
            $state['calls']++;
            if ($state['mode'] === 'transient' && $state['calls'] === 1) {
                file_put_contents($directory . '/.DS_Store', 'created after enumeration');
            } elseif ($state['mode'] === 'writer') {
                file_put_contents($directory . '/late-object.o', 'writer still active');
            } elseif ($state['mode'] === 'replace-link') {
                \rmdir($directory);
                symlink($state['external'], $directory);
                return false;
            }
        }
        return \rmdir($directory);
    }

    function unlink(string $file): bool
    {
        if ($file === ($GLOBALS['cleanupFixture']['heldFile'] ?? null)) {
            return false;
        }
        return \unlink($file);
    }
}

namespace {
    use WebmanAotBuilder\Cli\ConfigurationException;
    use WebmanAotBuilder\Project\ProjectWorkspace;

    spl_autoload_register(static function (string $class): void {
        $prefix = 'WebmanAotBuilder\\';
        if (str_starts_with($class, $prefix)) {
            require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });

    function ensure(bool $ok, string $message): void
    {
        if (!$ok) { throw new \RuntimeException($message); }
    }

    function reject(\Closure $operation, string $message): void
    {
        try { $operation(); }
        catch (ConfigurationException $error) {
            ensure(str_contains($error->getMessage(), $message), $error->getMessage());
            return;
        }
        throw new \RuntimeException('unsafe cleanup accepted: ' . $message);
    }

    function removeFixture(string $directory): void
    {
        foreach (new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) { removeFixture($entry->getPathname()); }
            else { \unlink($entry->getPathname()); }
        }
        \rmdir($directory);
    }

    $started = microtime(true);
    $root = sys_get_temp_dir() . '/webman-aot-cleanup-' . bin2hex(random_bytes(8));
    mkdir($root, 0700);
    $workspace = new ProjectWorkspace($root);
    $attempt = static function () use ($workspace): string {
        $path = $workspace->prepare(str_repeat('a', 64), str_repeat('b', 64))['build'];
        mkdir($path . '/project');
        file_put_contents($path . '/project/object', 'compiled');
        return $path;
    };
    $GLOBALS['cleanupFixture'] = [];
    try {
        $path = $attempt();
        $workspace->finishAttempt($path);
        ensure(!is_dir($path), 'normal attempt remained');

        $path = $attempt();
        $GLOBALS['cleanupFixture'] = ['directory' => $path . '/project', 'mode' => 'transient', 'calls' => 0];
        $warnings = [];
        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;
            return true;
        });
        try {
            $workspace->finishAttempt($path);
            trigger_error('handler restored', E_USER_WARNING);
        } finally { restore_error_handler(); }
        ensure(!is_dir($path) && $GLOBALS['cleanupFixture']['calls'] === 2, 'transient metadata race did not recover');
        ensure($warnings === ['handler restored'], 'rmdir warning leaked or previous error handler changed');
        echo "PASS actual metadata write between enumeration and rmdir recovers\n";

        $path = $attempt();
        $GLOBALS['cleanupFixture'] = ['directory' => $path . '/project', 'mode' => 'writer', 'calls' => 0];
        reject(static fn() => $workspace->finishAttempt($path), 'close programs writing');
        ensure($GLOBALS['cleanupFixture']['calls'] === 6 && is_file($path . '/project/late-object.o'), 'persistent writer was ignored or retry unbounded');
        echo "PASS persistent ordinary-file writer has bounded failure\n";

        $path = $attempt();
        $GLOBALS['cleanupFixture'] = ['heldFile' => $path . '/project/object'];
        reject(static fn() => $workspace->finishAttempt($path), 'unable to remove workspace file');
        ensure(is_file($path . '/project/object'), 'failed unlink was treated as success');
        echo "PASS unlink failure preserves file and diagnostic\n";

        if (PHP_OS_FAMILY !== 'Windows') {
            $path = $attempt();
            $GLOBALS['cleanupFixture'] = [];
            chmod($path . '/project', 0500);
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            try {
                reject(static fn() => $workspace->finishAttempt($path), 'unable to remove workspace file');
                ensure(is_file($path . '/project/object') && $warnings === [], 'permission error must preserve file and use the cleanup diagnostic');
            } finally {
                restore_error_handler();
                chmod($path . '/project', 0700);
            }
            echo "PASS actual directory permission failure remains an error\n";
        }

        $external = $root . '/external';
        mkdir($external);
        file_put_contents($external . '/keep', 'outside owned attempt');
        $path = $attempt();
        symlink($external, $path . '/project/external-directory');
        symlink($external . '/keep', $path . '/project/external-file');
        $GLOBALS['cleanupFixture'] = [];
        $workspace->finishAttempt($path);
        ensure(file_get_contents($external . '/keep') === 'outside owned attempt', 'cleanup followed external symlink');

        $path = $attempt();
        $GLOBALS['cleanupFixture'] = ['directory' => $path . '/project', 'mode' => 'replace-link', 'calls' => 0, 'external' => $external];
        reject(static fn() => $workspace->finishAttempt($path), 'workspace directory is unsafe');
        ensure(is_link($path . '/project') && is_file($external . '/keep'), 'retry followed replaced directory link');
        $GLOBALS['cleanupFixture'] = [];
        reject(static fn() => $workspace->finishAttempt($external), 'unrelated build attempt');
        echo "PASS external and replaced symlinks remain outside recursive cleanup\n";
        printf("PASS workspace cleanup (%.3fs)\n", microtime(true) - $started);
    } finally {
        $GLOBALS['cleanupFixture'] = [];
        removeFixture($root);
    }
}
