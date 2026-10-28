<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ExitCode;
use WebmanAotBuilder\Cli\Runtime;
use WebmanAotBuilder\Platform\UserDirectoryLayout;

$root = dirname(__DIR__);
if (($argv[1] ?? '') === 'uninstall') {
    require $root . '/packages/composer-installer/src/Uninstaller.php';
    exit((new Supdger\WebmanAotInstaller\Uninstaller())->run(array_slice($argv, 2)));
}

$loader = static function (string $class) use ($root): void {
    $prefix = 'WebmanAotBuilder\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = $root . '/src/' . $relative . '.php';
    if (is_file($path)) {
        require $path;
    }
};
spl_autoload_register($loader);

try {
    if (getenv('WEBMAN_AOT_BUILDER_BOOTSTRAPPED') !== '1') {
        $override = getenv('WEBMAN_AOT_BUILDER_HOME');
        if (is_string($override) && trim($override) !== '') {
            $home = rtrim(str_replace('\\', '/', trim($override)), '/');
        } elseif (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
            $userHome = getenv('HOME');
            if (!is_string($userHome) || trim($userHome) === '') {
                throw new RuntimeException('cannot resolve the current user home directory');
            }
            $home = rtrim(str_replace('\\', '/', $userHome), '/')
                . '/Library/Application Support/webman-aot-builder';
        } elseif (PHP_OS_FAMILY === 'Windows' && PHP_INT_SIZE === 8) {
            $localAppData = getenv('LOCALAPPDATA');
            if (!is_string($localAppData) || trim($localAppData) === '') {
                throw new RuntimeException('cannot resolve the current user data directory');
            }
            $home = rtrim(str_replace('\\', '/', $localAppData), '/') . '/webman-aot-builder';
        } else {
            throw new RuntimeException(sprintf(
                'unsupported build host: %s %s',
                PHP_OS_FAMILY,
                php_uname('m')
            ));
        }
        $activeEntry = null;
        $generations = glob($home . '/versions/*', GLOB_ONLYDIR);
        if (is_array($generations)) {
            rsort($generations, SORT_STRING);
            foreach ($generations as $generation) {
                $manifestContents = @file_get_contents($generation . '/manifest.json');
                if (!is_string($manifestContents)) {
                    continue;
                }
                try {
                    $manifest = json_decode(
                        $manifestContents,
                        true,
                        flags: JSON_THROW_ON_ERROR
                    );
                } catch (JsonException) {
                    continue;
                }
                $entry = $generation . '/app/bin/webman-aot-builder.php';
                if (is_array($manifest)
                    && ($manifest['schema'] ?? null) === 'webman-aot-builder-cli-generation-v1'
                    && is_file($entry)
                    && is_file($generation . '/app/src/Version.php')
                ) {
                    $activeEntry = $entry;
                    break;
                }
            }
        }
        $currentEntry = realpath(__FILE__);
        $resolvedActive = is_string($activeEntry) ? realpath($activeEntry) : false;
        if (is_string($currentEntry)
            && is_string($resolvedActive)
            && $resolvedActive !== $currentEntry
        ) {
            spl_autoload_unregister($loader);
            putenv('WEBMAN_AOT_BUILDER_BOOTSTRAPPED=1');
            require $resolvedActive;
            throw new RuntimeException('active CLI generation returned unexpectedly');
        }
    }

    $layout = UserDirectoryLayout::detect();
    exit((new Runtime($layout))->run($argv));
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(ExitCode::forFailure($throwable));
}
