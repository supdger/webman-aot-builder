<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\GeneratedProjectAdapter;
use WebmanAotBuilder\Compatibility\WebmanWorkermanRules;

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

$webman = $argv[1] ?? '';
$workerman = $argv[2] ?? '';
ensure(is_file($webman . '/src/support/Plugin.php') && is_file($workerman . '/src/Worker.php'), 'Pass the fixed official Webman and Workerman source roots');
$repository = dirname(__DIR__);
$lock = json_decode(file_get_contents($repository . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
$installerPath = 'vendor/workerman/webman-framework/src/support/Plugin.php';
$workerPath = 'vendor/workerman/workerman/src/Worker.php';
$installer = file_get_contents($webman . '/src/support/Plugin.php');
$worker = file_get_contents($workerman . '/src/Worker.php');
token_get_all($installer, TOKEN_PARSE);
token_get_all($worker, TOKEN_PARSE);
$adaptedWorker = $worker;
foreach (WebmanWorkermanRules::knownRules() as $rule) {
    if (in_array($rule->id(), ['workerman-worker-pid-runtime-path', 'workerman-worker-target-loadavg-call'], true)) {
        $adaptedWorker = $rule->transform($adaptedWorker, 'v5.2.2');
    }
}
$lock['mappings'][$workerPath]['shadowSha256'] = hash('sha256', $worker);
$lock['mappings'][$workerPath]['adaptedShadowSha256'] = hash('sha256', $adaptedWorker);
$annotated = '';
foreach (token_get_all($installer) as $token) {
    if (is_array($token) && $token[0] === T_WHITESPACE) {
        $annotated .= "\n \t/* installer spacing */ ";
    } else {
        $annotated .= is_array($token) ? $token[1] : $token;
    }
}
token_get_all($annotated, TOKEN_PARSE);
$root = sys_get_temp_dir() . '/webman-aot-installer-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/attempt-' . str_repeat('a', 24) . '/project';
mkdir($mirror . '/.typephp/build', 0700, true);
mkdir(dirname($mirror . '/' . $installerPath), 0700, true);
$started = microtime(true);
try {
    foreach ([
        'original' => [$installer, hash('sha256', $installer), null],
        'verified-whitespace' => [$annotated, hash('sha256', $annotated), null],
        'equivalent-whitespace-with-baseline-lock' => [$annotated, hash('sha256', $installer), null],
        'wrong-helper-literal' => [str_replace("'/helpers.php'", "'/unknown.php'", $installer), null, 'source structure drifted'],
        'wrong-event-variable' => [str_replace('function install($event)', 'function install($other)', $installer), null, 'source structure drifted'],
    ] as $name => [$contents, $digest, $errorMarker]) {
        $candidateLock = $lock;
        $candidateLock['installOnly'][$installerPath]['sourceSha256'] = $digest ?? hash('sha256', $contents);
        file_put_contents($mirror . '/' . $installerPath, $contents);
        file_put_contents($mirror . '/.typephp/build/workerman-worker.php', $worker);
        file_put_contents($mirror . '/project.linux.yml', "sources:\n  - .typephp/build/workerman-worker.php\nignore:\n  - vendor/workerman/webman-framework/src/support/helpers.php\n");
        try {
            $result = (new GeneratedProjectAdapter())->apply($mirror, $candidateLock, [['path' => $workerPath, 'shadow' => '.typephp/build/workerman-worker.php', 'shadowSha256' => hash('sha256', $worker)]]);
            ensure($errorMarker === null, 'installer unknown shape accepted: ' . $name);
            ensure($result['installOnlySourceSha256'] === hash('sha256', $contents), 'installer evidence digest changed');
            ensure($result['workerShadowSha256'] === hash('sha256', $adaptedWorker), 'worker output digest changed');
            ensure(str_contains(file_get_contents($mirror . '/project.linux.yml'), '  - ' . $installerPath), 'installer exclusion missing');
        } catch (ConfigurationException $error) {
            ensure(is_string($errorMarker) && str_contains($error->getMessage(), $errorMarker), 'wrong installer failure: ' . $error->getMessage());
            ensure(file_get_contents($mirror . '/.typephp/build/workerman-worker.php') === $worker, 'failed installer adaptation wrote Worker shadow');
        }
        ensure(file_get_contents($mirror . '/' . $installerPath) === $contents, 'installer source changed');
        echo 'PASS actual GeneratedProjectAdapter installer ' . $name . "\n";
    }
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
echo sprintf("PASS completed in %.3fs; installer structures checked; baseline bytes are not a version gate\n", microtime(true) - $started);
