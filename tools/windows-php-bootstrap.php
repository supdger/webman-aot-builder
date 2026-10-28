<?php

declare(strict_types=1);

// Startup configuration uses ASCII paths relative to the private runtime.
try {
    if (PHP_OS_FAMILY !== 'Windows') {
        throw new RuntimeException('private Windows PHP bootstrap requires Windows');
    }
    $callerDirectory = getenv('WEBMAN_AOT_CALLER_CWD');
    if (!is_string($callerDirectory) || $callerDirectory === '' || !chdir($callerDirectory)) {
        throw new RuntimeException('cannot restore the caller directory for private Windows PHP');
    }
    putenv('WEBMAN_AOT_CALLER_CWD');
    if (!extension_loaded('zip') || !class_exists(ZipArchive::class)) {
        throw new RuntimeException('private Windows PHP ZIP extension is unavailable; reinstall the complete verified package');
    }
    array_shift($argv);
    $argv = array_values($argv);
    $argc = count($argv);
    $_SERVER['argv'] = $argv;
    $_SERVER['argc'] = $argc;
    $target = $argv[0] ?? null;
    if (!is_string($target) || !is_file($target)) {
        throw new RuntimeException('private Windows PHP application entry is missing');
    }
    require $target;
} catch (Throwable $failure) {
    fwrite(STDERR, '[ERROR] ' . $failure->getMessage() . PHP_EOL);
    exit(70);
}
