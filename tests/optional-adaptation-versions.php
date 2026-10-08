<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\DeepClonePolyfillRule;
use WebmanAotBuilder\Compatibility\MonologWebProcessorRule;
use WebmanAotBuilder\Compatibility\NativeIntlPolyfillRule;

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

function packageRoot(string $directory, string $name): string
{
    $roots = glob($directory . '/' . $name . '/*', GLOB_ONLYDIR) ?: [];
    ensure(count($roots) === 1, 'Pass the fixed official archive roots under ' . $name);
    return $roots[0];
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
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

$fixtures = $argv[1] ?? '';
$generatorSource = $argv[2] ?? '';
$sdk = $argv[3] ?? '';
ensure(is_dir($sdk), 'Pass the verified actual static SDK directory as third argument');
$repository = dirname(__DIR__);
$lock = json_decode(file_get_contents($repository . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
ensure(is_file($generatorSource) && hash_file('sha256', $generatorSource) === $lock['generator']['sourceSha256'], 'Pass the fixed original ProjectGenerator.php');
require $generatorSource;
$policy = $lock['optionalAdaptations'];
$monologPath = $policy['monolog/monolog']['path'];
$sources = [
    $monologPath => file_get_contents(packageRoot($fixtures, 'monolog') . '/src/Monolog/Processor/WebProcessor.php'),
];
ensure(hash('sha256', $sources[$monologPath]) === $policy['monolog/monolog']['sourceSha256'], 'official Monolog source digest differs');
foreach ($policy['symfony/polyfill-deepclone']['files'] as $name => $digest) {
    $path = 'vendor/symfony/polyfill-deepclone/' . $name;
    $sources[$path] = file_get_contents(packageRoot($fixtures, 'deepclone') . '/' . $name);
    ensure(hash('sha256', $sources[$path]) === $digest, 'official deepclone source digest differs: ' . $name);
}
$generator = new Tinywan\Typephp\Compiler\ProjectGenerator($fixtures);
$prepare = new ReflectionMethod($generator, 'prepareGuardedSource');
$flatten = new ReflectionMethod($generator, 'flattenGuardedSource');
$shadows = [];
foreach (['grapheme', 'idn', 'normalizer'] as $name) {
    $package = 'symfony/polyfill-intl-' . $name;
    $path = 'vendor/' . $package . '/bootstrap80.php';
    $sources[$path] = file_get_contents(packageRoot($fixtures, $name) . '/bootstrap80.php');
    ensure(hash('sha256', $sources[$path]) === $policy['symfony/native-intl-polyfill']['packages'][$package]['bootstrapSha256'], 'official intl bootstrap digest differs: ' . $name);
    $content = $prepare->invoke($generator, $path, $sources[$path]);
    $shadow = $flatten->invoke($generator, $content);
    ensure(hash('sha256', $shadow) === $policy['symfony/native-intl-polyfill']['shadows'][$name]['sourceSha256'], 'actual pinned generator shadow digest differs: ' . $name);
    $shadows['.typephp/build/symfony-' . $name . '-functions.php'] = $shadow;
}
$intlProject = "sources:\n";
foreach ($shadows as $path => $contents) {
    $intlProject .= '  - ' . $path . "\n";
}
$intlProject .= "ignore:\n";
foreach (['grapheme', 'idn', 'normalizer'] as $name) {
    $intlProject .= '  - vendor/symfony/polyfill-intl-' . $name . "/bootstrap80.php\n";
}
$started = microtime(true);
foreach (['monolog', 'deepclone', 'intl'] as $suite) {
    $previous = null;
    foreach (['baseline', 'higher', 'higher-prerelease', 'unknown-label', 'lower-label', 'source-drift', 'output-or-sdk-drift', 'duplicate'] as $case) {
        $root = sys_get_temp_dir() . '/webman-aot-optional-' . bin2hex(random_bytes(6));
        $mirror = $root . '/.webman-aot-builder/build/attempt-' . str_repeat('a', 24) . '/project';
        mkdir($mirror . '/.typephp/build', 0700, true);
        try {
            foreach ($sources as $path => $contents) {
                writeSource($mirror . '/' . $path, $contents);
            }
            foreach ($shadows as $path => $contents) {
                writeSource($mirror . '/' . $path, $contents);
            }
            $packages = [];
            $packagePolicies = match ($suite) {
                'monolog' => ['monolog/monolog' => $policy['monolog/monolog']],
                'deepclone' => ['symfony/polyfill-deepclone' => $policy['symfony/polyfill-deepclone']],
                'intl' => $policy['symfony/native-intl-polyfill']['packages'],
            };
            foreach ($packagePolicies as $name => $packagePolicy) {
                $version = match ($case) {
                    'higher' => '9999.0.0',
                    'higher-prerelease' => '9999.0.0-rc.1+fixture',
                    'unknown-label' => 'dev-next',
                    'lower-label' => '0.0.1',
                    default => $packagePolicy['version'],
                };
                $packages[] = ['name' => $name, 'version' => $version, 'source' => ['reference' => $case === 'baseline' ? $packagePolicy['reference'] : str_repeat('b', 40)]];
            }
            if ($case === 'duplicate') {
                $packages[] = $packages[0];
            }
            writeSource($mirror . '/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
            writeSource($mirror . '/project.linux.yml', $suite === 'intl' ? $intlProject : "sources:\n  - vendor\n\nignore:\n  - main.php\n");
            $target = match ($suite) {
                'monolog' => $monologPath,
                'deepclone' => 'vendor/symfony/polyfill-deepclone/DeepClone.php',
                'intl' => 'vendor/symfony/polyfill-intl-grapheme/bootstrap80.php',
            };
            if ($case === 'source-drift') {
                writeSource($mirror . '/' . $target, $sources[$target] . "\n// unknown source change\n");
            }
            $before = [];
            foreach (array_keys($sources + $shadows) as $path) {
                $before[$path] = hash_file('sha256', $mirror . '/' . $path);
            }
            $beforeProject = file_get_contents($mirror . '/project.linux.yml');
            $candidatePolicy = $policy[match ($suite) {
                'monolog' => 'monolog/monolog',
                'deepclone' => 'symfony/polyfill-deepclone',
                'intl' => 'symfony/native-intl-polyfill',
            }];
            if ($case === 'output-or-sdk-drift') {
                $candidatePolicy[match ($suite) { 'monolog' => 'adaptedSha256', 'deepclone' => 'sdkSha256', default => 'adaptedGraphemeSha256' }] = str_repeat('0', 64);
            }
            $failure = in_array($case, ['source-drift', 'output-or-sdk-drift', 'duplicate'], true);
            try {
                $result = match ($suite) {
                    'monolog' => (new MonologWebProcessorRule())->apply($mirror, $candidatePolicy),
                    'deepclone' => (new DeepClonePolyfillRule())->apply($mirror, $candidatePolicy, $sdk),
                    'intl' => (new NativeIntlPolyfillRule())->apply($mirror, $candidatePolicy),
                };
                ensure(!$failure, 'unknown input accepted: ' . $suite . '/' . $case);
                $resultDigest = $suite === 'intl' ? $result['shadowSha256'] : $result;
                if ($previous !== null) {
                    ensure($resultDigest === $previous, 'same verified source produced a different result for version metadata');
                }
                $previous = $resultDigest;
                if ($suite === 'deepclone') {
                    ensure(count($result) === 5, 'deepclone mapping coverage changed');
                    foreach ($result as $mapping) {
                        ensure(hash_file('sha256', $mirror . '/' . $mapping['path']) === $mapping['sourceSha256'], 'deepclone changed vendor');
                        token_get_all(file_get_contents($mirror . '/' . $mapping['shadow']), TOKEN_PARSE);
                    }
                }
                if ($suite === 'monolog') {
                    ensure($result === $candidatePolicy['adaptedSha256'], 'Monolog output digest changed');
                    token_get_all(file_get_contents($mirror . '/' . $target), TOKEN_PARSE);
                }
            } catch (ConfigurationException $error) {
                ensure($failure, 'verified higher source rejected: ' . $suite . '/' . $case . ': ' . $error->getMessage());
                if ($case === 'source-drift') {
                    ensure(str_contains($error->getMessage(), 'source') && str_contains($error->getMessage(), 'drift'), 'unknown source mislabeled as version rejection');
                }
                foreach ($before as $path => $digest) {
                    ensure(hash_file('sha256', $mirror . '/' . $path) === $digest, 'failed adaptation wrote source or shadow');
                }
                ensure(file_get_contents($mirror . '/project.linux.yml') === $beforeProject, 'failed adaptation wrote project');
                ensure((glob($mirror . '/.typephp/build/deepclone-*') ?: []) === [], 'failed adaptation published a shadow');
            }
            echo 'PASS ' . $suite . ' ' . $case . "\n";
        } finally {
            removeFixture($root);
        }
    }
}
echo sprintf("PASS fixed official source/output hashes, actual generator shadows, metadata-only changes and failure write guards (%.3fs)\n", microtime(true) - $started);
