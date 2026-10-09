<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Toolchain\FullStaticProjectOverlay;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeSource(string $path, string $contents): void
{
    if (!is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    ensure(file_put_contents($path, $contents) !== false, 'cannot prepare fixture');
}

function removeFixture(string $root): void
{
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$old = $argv[1] ?? '';
$current = $argv[2] ?? '';
ensure(is_dir($old . '/crontab-1.0.6') && is_dir($old . '/carbon-doctrine-types-3.2.0'), 'Pass extracted official v1.0.6/3.2.0 archive parent as first argument');
ensure(is_dir($current . '/workerman/crontab') && is_dir($current . '/carbonphp/carbon-doctrine-types'), 'Pass installed official v1.0.7/3.2.1 vendor directory as second argument');
$started = microtime(true);
$tests = 0;
foreach (['old', 'current'] as $version) {
    $crontab = $version === 'old' ? $old . '/crontab-1.0.6' : $current . '/workerman/crontab';
    $doctrine = $version === 'old' ? $old . '/carbon-doctrine-types-3.2.0' : $current . '/carbonphp/carbon-doctrine-types';
    $cases = ['baseline', 'higher', 'unknown', 'comments', 'extra-declaration', 'dbal-present', 'other-profile',
        'bridge-reference', 'bridge-path-reference', 'bridge-string-reference', 'bridge-group-import', 'example-reference', 'autoload-files', 'autoload-classmap',
        'autoload-glob', 'bridge-execution', 'bridge-conditional', 'bridge-namespace', 'bridge-anonymous', 'example-execution', 'example-rhs-execution',
        'composer-registry', 'composer-registry-changed', 'composer-function-return', 'composer-closure-return', 'composer-conditional-return', 'composer-business-static', 'composer-initializer-execution', 'composer-registry-execution', 'composer-registry-call', 'composer-static-execution', 'composer-static-files', 'webman-directory-source', 'explicit-bridge-source', 'webman-bridge-reference', 'symlink-file', 'symlink-directory', 'sdk-missing'];
    foreach ($cases as $case) {
        $root = sys_get_temp_dir() . '/webman-aot-full-static-' . bin2hex(random_bytes(6));
        $project = $root . '/project';
        $sysroot = $root . '/sysroot';
        mkdir($project, 0700, true);
        try {
            $example = 'vendor/workerman/crontab/example/test.php';
            $prefix = 'vendor/carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/';
            writeSource($project . '/' . $example, file_get_contents($crontab . '/example/test.php'));
            writeSource($project . '/vendor/workerman/crontab/composer.json', file_get_contents($crontab . '/composer.json'));
            writeSource($project . '/vendor/carbonphp/carbon-doctrine-types/composer.json', file_get_contents($doctrine . '/composer.json'));
            foreach (glob($doctrine . '/src/Carbon/Doctrine/*.php') as $file) {
                writeSource($project . '/' . $prefix . basename($file), file_get_contents($file));
            }
            writeSource($project . '/main.php', '<?php echo "application";');
            writeSource($project . '/app/business.php', '<?php class Business { public function run(): int { return 42; } }');
            $tag = match ($case) { 'higher' => '999.0.0', 'unknown' => 'dev-future', default => $version === 'old' ? '3.2.0' : '3.2.1' };
            $packages = [
                ['name' => 'workerman/crontab', 'version' => $tag, 'source' => ['reference' => 'arbitrary-current-reference']],
                ['name' => 'carbonphp/carbon-doctrine-types', 'version' => $tag, 'source' => ['reference' => 'arbitrary-current-reference']],
            ];
            if ($case === 'dbal-present') {
                $packages[] = ['name' => 'doctrine/dbal', 'version' => 'dev-future'];
            }
            writeSource($project . '/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
            $composer = ['autoload' => ['psr-4' => ['App\\' => 'app/']]];
            if ($case === 'autoload-files') {
                $composer['autoload']['files'] = [$prefix . 'DateTimeDefaultPrecision.php'];
            } elseif ($case === 'autoload-classmap') {
                $composer['autoload']['classmap'] = ['vendor/workerman/crontab/example/../example'];
            } elseif ($case === 'autoload-glob') {
                $composer['autoload']['classmap'] = ['vendor/workerman/crontab/example/*.php'];
            }
            writeSource($project . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
            $target = $project . '/' . $prefix . 'DateTimeDefaultPrecision.php';
            if ($case === 'comments') {
                writeSource($target, file_get_contents($target) . "\n// unrelated changed comment\n");
                writeSource($project . '/' . $example, file_get_contents($project . '/' . $example) . "\n// unrelated changed comment\n");
            } elseif ($case === 'extra-declaration') {
                writeSource($project . '/' . $prefix . 'AdditionalType.php', '<?php namespace Carbon\\Doctrine; final class AdditionalType { public function extra(): string { return "} {"; } }');
            } elseif (in_array($case, ['bridge-reference', 'webman-bridge-reference'], true)) {
                writeSource($project . '/app/business.php', '<?php use Carbon\\Doctrine\\DateTimeDefaultPrecision; DateTimeDefaultPrecision::set(3);');
            } elseif ($case === 'bridge-path-reference') {
                writeSource($project . '/app/business.php', '<?php require __DIR__ . "/../vendor/carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/DateTimeDefaultPrecision.php";');
            } elseif ($case === 'bridge-string-reference') {
                writeSource($project . '/app/business.php', '<?php $class = "Carbon\\\\Doctrine\\\\DateTimeDefaultPrecision"; new $class;');
            } elseif ($case === 'bridge-group-import') {
                writeSource($project . '/app/business.php', '<?php use Carbon\\Doctrine\\{DateTimeType, CarbonType};');
            } elseif ($case === 'example-reference') {
                writeSource($project . '/main.php', '<?php require __DIR__ . "/vendor/workerman/crontab/example/test.php";');
            } elseif ($case === 'bridge-execution') {
                writeSource($target, file_get_contents($target) . "\n" . 'file_put_contents("required-business-output", "run");');
            } elseif ($case === 'bridge-conditional') {
                writeSource($target, '<?php namespace Carbon\\Doctrine; if (true) { class DateTimeDefaultPrecision {} }');
            } elseif ($case === 'bridge-namespace') {
                writeSource($target, '<?php namespace App; class RequiredBusiness {}');
            } elseif ($case === 'bridge-anonymous') {
                writeSource($target, '<?php namespace Carbon\\Doctrine; $instance = new class {};');
            } elseif ($case === 'example-execution') {
                writeSource($project . '/' . $example, file_get_contents($project . '/' . $example) . "\n" . 'file_put_contents("required-business-output", "run");');
            } elseif ($case === 'example-rhs-execution') {
                $contents = file_get_contents($project . '/' . $example);
                writeSource($project . '/' . $example, str_replace('};', '} && new class { public function __construct() { file_put_contents("output", "run"); } };', $contents));
            } elseif ($case === 'symlink-file') {
                unlink($target);
                symlink($project . '/app/business.php', $target);
            } elseif ($case === 'symlink-directory') {
                rename($project . '/' . $prefix, $root . '/bridge');
                symlink($root . '/bridge', rtrim($project . '/' . $prefix, '/'));
            }
            if (in_array($case, ['composer-registry', 'composer-registry-changed', 'composer-initializer-execution'], true)) {
                foreach (['autoload_classmap.php', 'autoload_psr4.php', 'autoload_static.php', 'autoload_namespaces.php'] as $file) {
                    writeSource($project . '/vendor/composer/' . $file, file_get_contents($current . '/composer/' . $file));
                }
                $file = $project . '/vendor/composer/autoload_static.php';
                if ($case === 'composer-registry-changed') {
                    $contents = preg_replace('/ComposerStaticInit[A-Za-z0-9_]+/', 'ComposerStaticInitFutureRegistry', file_get_contents($file));
                    $contents = str_replace('public static $classMap = array (', 'public static $classMap = array ("UnrelatedFutureClass" => __DIR__ . "/../unrelated/FutureClass.php",', $contents);
                    writeSource($file, $contents . "\n// unrelated registry comment\n");
                } elseif ($case === 'composer-initializer-execution') {
                    $contents = str_replace('return \\Closure::bind(function () use ($loader) {', 'return \\Closure::bind(function () use ($loader) { \\Carbon\\Doctrine\\DateTimeDefaultPrecision::set(3);', file_get_contents($file));
                    writeSource($file, $contents);
                }
            } elseif ($case === 'composer-function-return') {
                writeSource($project . '/vendor/composer/autoload_classmap.php', '<?php function requiredClass() { return ["Carbon\\Doctrine\\DateTimeDefaultPrecision"]; } $class = requiredClass()[0]; $class::set(3); return [];');
            } elseif ($case === 'composer-closure-return') {
                writeSource($project . '/vendor/composer/autoload_classmap.php', '<?php $factory = function () { return ["Carbon\\Doctrine\\DateTimeDefaultPrecision"]; }; $class = $factory()[0]; $class::set(3); return [];');
            } elseif ($case === 'composer-conditional-return') {
                writeSource($project . '/vendor/composer/autoload_classmap.php', '<?php if (true) { return ["Carbon\\Doctrine\\DateTimeDefaultPrecision"]; } return [];');
            } elseif ($case === 'composer-business-static') {
                writeSource($project . '/vendor/composer/autoload_static.php', '<?php class BusinessRegistry { public static $classMap = ["Carbon\\Doctrine\\DateTimeDefaultPrecision"]; public function run() { $class = self::$classMap[0]; $class::set(3); } } (new BusinessRegistry)->run();');
            } elseif ($case === 'composer-registry-execution') {
                writeSource($project . '/vendor/composer/autoload_classmap.php', '<?php Carbon\Doctrine\DateTimeDefaultPrecision::set(3); return [];');
            } elseif ($case === 'composer-registry-call') {
                writeSource($project . '/vendor/composer/autoload_psr4.php', '<?php return ["Carbon\\Doctrine\\DateTimeDefaultPrecision" => ("Carbon\\Doctrine\\DateTimeDefaultPrecision::get")()];');
            } elseif ($case === 'composer-static-execution') {
                writeSource($project . '/vendor/composer/autoload_static.php', '<?php namespace Composer\Autoload; Carbon\Doctrine\DateTimeDefaultPrecision::set(3); class ComposerStaticInitFixture { public static $classMap = []; }');
            } elseif ($case === 'composer-static-files') {
                writeSource($project . '/vendor/composer/autoload_static.php', '<?php namespace Composer\Autoload; class ComposerStaticInitFixture { public static $files = [__DIR__ . "/../carbonphp/carbon-doctrine-types/src/Carbon/Doctrine/DateTimeDefaultPrecision.php"]; }');
            }
            foreach (['/usr/include/c++/12.2.1/vector', '/usr/include/c++/12.2.1/x86_64-alpine-linux-musl/bits/c++config.h',
                '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/crtbegin.o', '/usr/lib/gcc/x86_64-alpine-linux-musl/12.2.1/libgcc.a', '/usr/lib/libstdc++.a'] as $required) {
                if ($case !== 'sdk-missing' || !str_ends_with($required, 'libstdc++.a')) {
                    writeSource($sysroot . $required, 'fixture for unchanged required sysroot structure');
                }
            }
            writeSource($root . '/sdk/include/php/main/php_version.h', "#define PHP_MAJOR_VERSION 8\n#define PHP_MINOR_VERSION 4\n");
            writeSource($root . '/sdk/manifest.json', json_encode(['schema' => 'typephp-php-runtime-layer-v1', 'target' => 'linux-x64', 'zts' => true, 'php_version' => '8.4.25'], JSON_THROW_ON_ERROR));
            $yaml = "name: application\nsources:\n  - main.php\n  - app\n  - vendor\n\nignore:\n  - unused.php\n\noutput: build/application\nmode: bin\noptimize: 2\njob: 2\ndebug: false\n";
            if ($case === 'webman-directory-source') {
                $yaml = str_replace("  - vendor\n", "  - {$prefix}\n", $yaml);
            } elseif ($case === 'explicit-bridge-source') {
                $yaml = str_replace("  - vendor\n", "  - vendor\n  - {$prefix}DateTimeDefaultPrecision.php\n", $yaml);
            }
            writeSource($project . '/project.linux.yml', $yaml);
            $digests = [];
            foreach (glob($project . '/' . $prefix . '*.php') as $file) {
                if (!is_link($file)) {
                    $digests[$file] = hash_file('sha256', $file);
                }
            }
            $failure = !in_array($case, ['baseline', 'higher', 'unknown', 'comments', 'extra-declaration', 'dbal-present', 'other-profile', 'composer-registry', 'composer-registry-changed', 'webman-directory-source', 'explicit-bridge-source'], true);
            try {
                (new FullStaticProjectOverlay())->apply($project . '/project.linux.yml', $sysroot, $case === 'other-profile' || str_starts_with($case, 'webman-') ? 'webman' : 'saiadmin', $root . '/sdk');
                ensure(!$failure, "unsafe exclusion accepted: {$version}/{$case}");
                $output = file_get_contents($project . '/project.linux.yml');
                ensure(str_contains($output, "\nsources:\n  - main.php\n  - app\n"), 'required business source entries changed');
                ensure(!str_contains($output, "\n  - app/business.php\n"), 'business source accidentally ignored');
                $ignored = explode("\noutput:", explode("\nignore:\n", $output, 2)[1], 2)[0];
                ensure(str_contains($ignored, $example) === ($case !== 'other-profile' && !str_starts_with($case, 'webman-')), 'example exclusion incorrect');
                foreach (array_keys($digests) as $file) {
                    ensure(str_contains($ignored, substr($file, strlen($project) + 1)) === ($case !== 'dbal-present'), 'bridge coverage incorrect');
                }
                ensure(str_contains($output, 'target-platform: x86_64-unknown-linux-musl'), 'toolchain overlay missing');
            } catch (ConfigurationException $error) {
                ensure($failure, "compatible official source rejected: {$version}/{$case}: " . $error->getMessage());
                ensure(file_get_contents($project . '/project.linux.yml') === $yaml, 'failure partially changed generated project');
            }
            foreach ($digests as $file => $digest) {
                ensure(hash_file('sha256', $file) === $digest, 'overlay changed vendor');
            }
            $tests++;
            echo "PASS {$version}/{$case}\n";
        } finally {
            removeFixture($root);
        }
    }
}
printf("Full-static project overlay: %d cases passed in %.3fs\n", $tests, microtime(true) - $started);
