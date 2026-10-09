<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\PluginSourceCompletion;
use WebmanAotBuilder\Compatibility\UpstreamGeneratorBoundary;
use WebmanAotBuilder\Compatibility\UpstreamProjectGenerator;
use WebmanAotBuilder\Compatibility\UpstreamSourceRule;
use WebmanAotBuilder\Compatibility\WorkermanGeneratorRule;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function rejects(Closure $run, string $marker): void
{
    try { $run(); } catch (ConfigurationException $error) {
        ensure(str_contains($error->getMessage(), $marker), 'unexpected rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('unsafe shape was accepted: ' . $marker);
}
function copyTree(string $source, string $target): void
{
    ensure(!is_link($source), 'fixture source is a symlink');
    if (is_dir($source)) {
        if (!is_dir($target)) { mkdir($target, 0700, true); }
        foreach (new DirectoryIterator($source) as $entry) {
            if (!$entry->isDot()) { copyTree($entry->getPathname(), $target . '/' . $entry->getFilename()); }
        }
    } else { ensure(copy($source, $target), 'fixture copy failed'); }
}
function removeTree(string $root): void
{
    if (!is_dir($root)) { return; }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
function sourceDigests(string $directory): array
{
    $digests = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile()) { $digests[substr($file->getPathname(), strlen($directory) + 1)] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($digests);
    return $digests;
}
$archive = $argv[1] ?? '';
$project = $argv[2] ?? '';
$oldWorker = $argv[3] ?? '';
$sdkDirectory = $argv[4] ?? null;
ensure(is_file($archive) && is_file($project . '/composer.lock') && is_file($oldWorker . '/src/Worker.php'), 'Pass the locked generator archive, real project root and official Workerman 5.2.2 root');
$repository = dirname(__DIR__);
$lockFile = $repository . '/compatibility/locks/webman-workerman-2026-09-25.json';
$lock = json_decode(file_get_contents($lockFile), true, flags: JSON_THROW_ON_ERROR);
$preparedManifest = $argv[5] ?? '';
ensure(is_file($preparedManifest), 'Pass the selected prepared-toolchain manifest as the fifth argument');
$preparedData = json_decode(file_get_contents($preparedManifest), true, flags: JSON_THROW_ON_ERROR);
$tools = (new \WebmanAotBuilder\Toolchain\PreparedToolchain())->load($preparedManifest, dirname($preparedManifest), $repository . '/toolchain.lock.json', $preparedData['host']);
$sdkContext = $tools['sdkContext'];
ensure(realpath((string) $sdkDirectory) === $sdkContext['sdkDirectory'], 'SDK argument is not selected by the prepared toolchain');
$root = sys_get_temp_dir() . '/webman-aot-generator-contract-' . bin2hex(random_bytes(6));
mkdir($root . '/cache', 0700, true);
$started = microtime(true);
$originalVendor = sourceDigests($project . '/vendor');
try {
    foreach (['5.2.2' => $oldWorker, '5.2.3' => $project . '/vendor/workerman/workerman'] as $version => $worker) {
        echo "STAGE official Workerman {$version} full generate\n";
        $mirror = $root . '/case-' . $version . '/.webman-aot-builder/build/project';
        mkdir($mirror, 0700, true);
        foreach (['vendor', 'app', 'support', 'config', 'public'] as $directory) {
            if (is_dir($project . '/' . $directory)) { copyTree($project . '/' . $directory, $mirror . '/' . $directory); }
        }
        foreach (['composer.json', 'composer.lock', 'start.php'] as $file) { copyTree($project . '/' . $file, $mirror . '/' . $file); }
        if ($version === '5.2.2') {
            removeTree($mirror . '/vendor/workerman/workerman');
            copyTree($worker, $mirror . '/vendor/workerman/workerman');
            $composer = json_decode(file_get_contents($mirror . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
            foreach ($composer['packages'] as &$package) {
                if ($package['name'] === 'workerman/workerman') {
                    $package['version'] = 'v5.2.2';
                    $package['source']['reference'] = 'a0866601af066c0eab5a2f7b9aef8bdda8fa1911';
                }
            }
            unset($package);
            file_put_contents($mirror . '/composer.lock', json_encode($composer, JSON_THROW_ON_ERROR));
        }
        // Unrelated content in every core input must not become another version gate.
        foreach (array_keys($lock['mappings']) as $path) { file_put_contents($mirror . '/' . $path, "\n// compatible project source comment\n", FILE_APPEND); }
        $before = sourceDigests($mirror . '/vendor');
        $generated = (new UpstreamProjectGenerator())->generate($mirror, $archive, $root . '/cache', $lockFile, 'webman', 'webman-server', $sdkDirectory, $sdkContext);
        ensure($generated['mappings'] >= count($lock['mappings']), 'declared mappings are missing');
        $after = sourceDigests($mirror . '/vendor');
        if (isset($generated['adaptations']['monologWebProcessorSha256'])) {
            ensure($after['monolog/monolog/src/Monolog/Processor/WebProcessor.php'] === $generated['adaptations']['monologWebProcessorSha256'], 'Monolog adaptation evidence drift');
            unset($after['monolog/monolog/src/Monolog/Processor/WebProcessor.php'], $before['monolog/monolog/src/Monolog/Processor/WebProcessor.php']);
        }
        ensure($after === $before, 'generate changed vendor outside the declared Monolog mirror adaptation');
        foreach ($generated['coverageMappings'] as $mapping) {
            ensure(hash_file('sha256', $mirror . '/' . $mapping['path']) === $mapping['sourceSha256'], 'runtime source evidence drift');
            ensure(hash_file('sha256', $mirror . '/' . $mapping['shadow']) === $mapping['shadowSha256'], 'runtime shadow evidence drift');
        }
        $projectOutput = file_get_contents($generated['projectFile']);
        $sourceSection = substr($projectOutput, 0, strpos($projectOutput, "\nignore:\n"));
        foreach (['Request', 'Response'] as $name) {
            ensure(str_contains($sourceSection, "\n  - support/{$name}.php"), 'project support wrapper is not compiled');
            ensure(str_contains($sourceSection, "  - vendor/workerman/webman-framework/src\n"), 'support wrapper parent directory is not compiled');
        }
        ensure(!str_contains($sourceSection, "\n  - support/Setup.php"), 'Composer setup wizard was added to runtime sources');
        ensure(str_contains($sourceSection, "\n  - vendor/nesbot/carbon/src/Carbon"), 'active Carbon namespace source is missing');
        foreach (['database', 'conditionable', 'macroable'] as $name) {
            ensure(str_contains($sourceSection, "\n  - vendor/illuminate/{$name}"), 'installed strong dependency with shared namespace and empty root is missing');
        }

        foreach (['Lang', 'List', 'PHPStan'] as $name) {
            ensure(str_contains($projectOutput, "  - vendor/nesbot/carbon/src/Carbon/{$name}\n"), 'Carbon resource/tool ignore was lost');
        }
        $completion = new PluginSourceCompletion();
        ensure($completion->apply($mirror, 'webman', $lock['dynamicPhp'], $generated['coverageMappings']) === null, 'source completion is not idempotent');
        $ignoredSupport = str_replace("  - support/Request.php\n", '', $projectOutput);
        $ignoredSupport = str_replace("\nignore:\n", "\nignore:\n  - support/Request.php\n", $ignoredSupport);
        file_put_contents($generated['projectFile'], $ignoredSupport);
        rejects(fn () => $completion->apply($mirror, 'webman', $lock['dynamicPhp'], $generated['coverageMappings']), 'cannot be added to compiler sources');
        ensure(file_get_contents($generated['projectFile']) === $ignoredSupport, 'source completion overrode an ignore decision');
        file_put_contents($generated['projectFile'], $projectOutput);
        echo "PASS project support wrappers/parents compiled, source completion idempotent and ignore preserved\n";
        $withoutCarbon = str_replace("  - vendor/nesbot/carbon/src/Carbon\n", '', $projectOutput);
        $metadataPath = $mirror . '/vendor/composer/installed.json';
        $metadataOriginal = file_get_contents($metadataPath);
        $metadata = json_decode($metadataOriginal, true, flags: JSON_THROW_ON_ERROR);
        foreach ($metadata['packages'] as &$package) {
            if ($package['name'] === 'nesbot/carbon') { $package['autoload']['psr-4']['Carbon\\'] = '../'; }
        }
        unset($package);
        file_put_contents($metadataPath, json_encode($metadata, JSON_THROW_ON_ERROR));
        file_put_contents($generated['projectFile'], $withoutCarbon);
        rejects(fn () => $completion->apply($mirror, 'webman', $lock['dynamicPhp'], $generated['coverageMappings']), 'escapes its declared scope');
        ensure(file_get_contents($generated['projectFile']) === $withoutCarbon, 'unknown Carbon declaration changed sources');
        $ignoredCarbon = str_replace("\nignore:\n", "\nignore:\n  - vendor/nesbot/carbon/src/Carbon\n", $withoutCarbon);
        file_put_contents($metadataPath, $metadataOriginal);
        file_put_contents($generated['projectFile'], $ignoredCarbon);
        ensure($completion->apply($mirror, 'webman', $lock['dynamicPhp'], $generated['coverageMappings']) === null, 'ignored namespace was enabled by dependency completion');
        ensure(file_get_contents($generated['projectFile']) === $ignoredCarbon, 'namespace ignore decision was overwritten');
        file_put_contents($metadataPath, $metadataOriginal);
        file_put_contents($generated['projectFile'], $projectOutput);
        $replacementSources = new ReflectionMethod(PluginSourceCompletion::class, 'replacementSources');
        foreach ($generated['coverageMappings'] as $mapping) {
            if ($mapping['path'] === 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php') {
                ensure($replacementSources->invoke($completion, $mirror, [$mapping], [$mapping['shadow']], [$mapping['shadow']]) === [], 'ignored replacement activated its production dependency namespace');
            }
        }
        echo "PASS active namespace closure, ignored replacement seed and declaration boundaries\n";
        $workerOutput = file_get_contents($mirror . '/.typephp/build/workerman-worker.php');
        ensure(!str_contains($workerOutput, 'fclose(STDOUT)') && !str_contains($workerOutput, 'fclose(STDERR)') && !str_contains($workerOutput, 'fclose(static::$outputStream)'), 'resetStd retained standard-stream closes');
        if ($version === '5.2.3') {
            $tcp = file_get_contents($mirror . '/.typephp/build/workerman-tcp-connection.php');
            ensure(!preg_match('/\$reason\s*=\s*\'\'\s*;\s*set_error_handler/', $tcp), 'SSL reason was typed before reference capture');
            ensure(str_contains($tcp, 'if ($reason === null)'), 'SSL reason fallback is missing');
        }
        echo 'PASS ' . $version . ' generate ' . $generated['mappings'] . " verified mappings, vendor mirror adaptations checked, resetStd and SSL postconditions\n";
    }
    $rule = new WorkermanGeneratorRule();
    $worker = file_get_contents($project . '/vendor/workerman/workerman/src/Worker.php');
    rejects(fn () => $rule->transform('vendor/workerman/workerman/src/Worker.php', str_replace('@fclose(STDOUT)', '@fclose(STDIN)', $worker)), 'close structure drift');
    $tcp = file_get_contents($project . '/vendor/workerman/workerman/src/Connection/TcpConnection.php');
    rejects(fn () => $rule->transform('vendor/workerman/workerman/src/Connection/TcpConnection.php', str_replace('$reason = $msg;', '$reason = trim($msg);', $tcp)), 'expected 1');
    $staleReason = str_replace("        \$reason = '';", "        \$reason = 'stale';\n        \$reason = '';", $tcp);
    rejects(fn () => $rule->transform('vendor/workerman/workerman/src/Connection/TcpConnection.php', $staleReason), 'unsupported prior local state');
    $annotatedTcp = str_replace('use (&$reason)', 'use /* equivalent capture */ ( /* comment */ &$reason)', $tcp);
    $annotatedOutput = $rule->transform('vendor/workerman/workerman/src/Connection/TcpConnection.php', $annotatedTcp);
    ensure(str_contains($annotatedOutput, 'equivalent capture') && str_contains($annotatedOutput, 'if ($reason === null)'), 'SSL annotated capture was not adapted');
    $timer = file_get_contents($project . '/vendor/workerman/workerman/src/Timer.php');
    rejects(fn () => $rule->transform('vendor/workerman/workerman/src/Timer.php', str_replace('signalHandle(mixed ...$args)', 'signalHandle(int $required)', $timer)), 'signature structure drift');
    $sourceRule = new UpstreamSourceRule();
    foreach ([1, 5, 6, 10, 31, 32] as $count) {
        $source = '<?php function probe($value) { ' . str_repeat("\$data = compact('value');", $count) . 'return $data; }';
        $output = $sourceRule->compactCalls($source);
        ensure(substr_count($output, "['value' => \$value]") === $count, 'compact conversion was tied to a historical count');
        ensure($sourceRule->compactCalls($output) === $output, 'compact conversion is not idempotent');
    }
    rejects(fn () => $sourceRule->compactCalls('<?php $data = compact($unknown);'), 'unsupported arguments');
    ensure($sourceRule->compactCalls('<?php // compact($unknown)' . "\n" . '$text = "compact($unknown)";') === '<?php // compact($unknown)' . "\n" . '$text = "compact($unknown)";', 'compact decoy was rewritten');
    foreach ([
        'function missing() { return compact("missing"); }',
        'function conditional($flag) { if ($flag) { $missing = 1; } return compact("missing"); }',
        'function unsetBinding($value) { unset($value); return compact("value"); }',
        'function nested() { $fn = function () { $missing = 1; }; return compact("missing"); }',
        'function arrow($value) { return fn () => compact("value"); }',
    ] as $source) { rejects(fn () => $sourceRule->compactCalls('<?php ' . $source), 'not proven defined'); }
    $compactSource = '<?php function compactOriginal($value, $flag) { if ($flag) { $type = "first"; } else { $type = "second"; } return compact("value", "type"); }';
    $compactOutput = str_replace('compactOriginal', 'compactAdapted', $sourceRule->compactCalls($compactSource));
    eval(substr($compactSource, 5));
    eval(substr($compactOutput, 5));
    foreach ([null, 0, 'value'] as $value) {
        foreach ([true, false] as $flag) { ensure(compactOriginal($value, $flag) === compactAdapted($value, $flag), 'defined compact changed PHP values'); }
    }
    $headerSource = '<?php function headerOriginal($connection) { $responseHeader="sentinel"; $responseHeader1="other"; foreach ($connection->headers as $header) { $out[]=$header; } return [$responseHeader, $responseHeader1, $out]; }';
    $headerOutput = $sourceRule->replace('vendor/workerman/workerman/src/Protocols/Websocket.php', $headerSource, []);
    ensure(str_contains($headerOutput, '$responseHeader2'), 'header rename did not reserve a unique local');
    ensure($sourceRule->replace('vendor/workerman/workerman/src/Protocols/Websocket.php', $headerOutput, []) === $headerOutput, 'unique header adaptation is not idempotent');
    eval(substr($headerSource, 5));
    eval(substr(str_replace('headerOriginal', 'headerAdapted', $headerOutput), 5));
    $connection = (object) ['headers' => ['first', 'second']];
    ensure(headerOriginal($connection) === headerAdapted($connection), 'header rename overwrote prior local values');
    echo "PASS compact definite-binding values/rejections, header collision values and SSL prior-state rejection\n";
    echo "PASS unknown resetStd/SSL/timer/compact rejected; historical totals and decoys checked\n";
    foreach (['source-drift', 'unmapped-drift', 'input-add', 'input-delete', 'shadow-drift', 'unknown-shadow'] as $failure) {
        $mirror = $root . '/' . $failure . '/.webman-aot-builder/build/project';
        mkdir($mirror . '/vendor/workerman/webman-framework/src/support', 0700, true);
        $source = '<?php function fixture() { return 1; }';
        mkdir($mirror . '/app', 0700, true);
        file_put_contents($mirror . '/app/unmapped.php', '<?php function directInput() { return 1; }');
        file_put_contents($mirror . '/vendor/workerman/webman-framework/src/support/helpers.php', $source);
        file_put_contents($mirror . '/composer.lock', json_encode(['packages' => [['name' => 'test/package', 'version' => 'dev-new']]], JSON_THROW_ON_ERROR));
        $mapping = ['vendor/workerman/webman-framework/src/support/helpers.php' => ['shadow' => '.typephp/build/helpers.php', 'contractSha256' => hash('sha256', $source)]];
        $generate = static function () use ($mirror, $source, $failure): void {
            mkdir($mirror . '/.typephp/build', 0700, true);
            file_put_contents($mirror . '/.typephp/build/helpers.php', $failure === 'shadow-drift' ? str_replace('return 1', 'return 2', $source) : $source);
            file_put_contents($mirror . '/project.linux.yml', "sources:\n  - .typephp/build/helpers.php\nignore:\n  - vendor/workerman/webman-framework/src/support/helpers.php\n");
            if ($failure === 'source-drift') { file_put_contents($mirror . '/vendor/workerman/webman-framework/src/support/helpers.php', $source . '\n// changed'); }
            if ($failure === 'unknown-shadow') { file_put_contents($mirror . '/.typephp/build/unknown.php', $source); }
            if ($failure === 'unmapped-drift') { file_put_contents($mirror . '/app/unmapped.php', '<?php function directInput() { return 2; }'); }
            if ($failure === 'input-add') { file_put_contents($mirror . '/app/added.php', '<?php function addedInput() {}'); }
            if ($failure === 'input-delete') { unlink($mirror . '/app/unmapped.php'); }
        };
        $marker = ['source-drift' => 'changed source', 'unmapped-drift' => 'changed source input', 'input-add' => 'changed source input', 'input-delete' => 'changed source input', 'shadow-drift' => 'violates its source conversion contract', 'unknown-shadow' => 'undeclared'][ $failure ];
        rejects(fn () => (new UpstreamGeneratorBoundary())->run($mirror, __FILE__, hash_file('sha256', __FILE__), ['test/package' => []], $mapping, $generate), $marker);
        echo "PASS {$failure} safely rejected\n";
    }
    ensure(sourceDigests($project . '/vendor') === $originalVendor, 'original project vendor changed');
} finally { removeTree($root); }
echo sprintf("PASS generator contracts completed in %.3fs; temporary fixtures cleaned\n", microtime(true) - $started);
