#!/usr/bin/env php
<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay;
use WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule;
use WebmanAotBuilder\Compatibility\SaiAdminCarbonPeriodRule;

$repo = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repo): void {
    if (str_starts_with($class, 'WebmanAotBuilder\\')) {
        require $repo . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
    }
});
function checkCarbonGenerator(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS {$message}\n";
}
$generatorRoot = $argv[1] ?? '';
$project = $argv[2] ?? '';
$source = $generatorRoot . '/src/Compiler/ProjectGenerator.php';
$carbon = $project . '/vendor/nesbot/carbon/src/Carbon/';
checkCarbonGenerator(is_file($source) && is_file($carbon . 'CarbonPeriod.php') && is_file($carbon . 'CarbonInterval.php'), 'locked generator and real Carbon sources present');
$lock = json_decode(file_get_contents($repo . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
$root = sys_get_temp_dir() . '/webman-aot-carbon-generator-test-' . bin2hex(random_bytes(6));
mkdir($root . '/mirror/vendor/nesbot/carbon/src/Carbon', 0700, true);
$period = file_get_contents($carbon . 'CarbonPeriod.php');
$interval = file_get_contents($carbon . 'CarbonInterval.php');
$periodFile = $root . '/mirror/vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php';
file_put_contents($periodFile, $period);
$intervalFile = $root . '/mirror/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php';
file_put_contents($intervalFile, $interval);
$apply = static fn() => (new SaiAdminGeneratorOverlay())->prepare($source,
    $lock['generator']['sourceSha256'], $lock['generator']['mainStubSha256'], $root . '/cache', $root . '/mirror');
$started = microtime(true);
$result = $apply();
require $result['path'];
$reflection = new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class);
$generator = new Tinywan\Typephp\Compiler\ProjectGenerator($root . '/mirror');
$patched = $reflection->getMethod('patchSwitchTerminals')->invoke($generator, 'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php', $period);
token_get_all($patched, TOKEN_PARSE);
foreach ((new SaiAdminCarbonPeriodRule())->replacements($period, $interval) as $replacement) {
    checkCarbonGenerator(str_contains($patched, $replacement), 'real Carbon period preserves explicit dispatch');
}
$patchedInterval = $reflection->getMethod('patchSwitchTerminals')->invoke($generator, 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php', $interval);
checkCarbonGenerator(str_contains($patchedInterval, '$this->invert(!$this->invert)')
    && str_contains($patchedInterval, '$interval->invert(!$interval->invert)'), 'real Carbon interval uses explicit internal toggles');
checkCarbonGenerator(file_get_contents($intervalFile) === $interval, 'original Carbon interval vendor unchanged');
$generated = $generator->generateSwitchTerminalSources();
$intervalShadow = $root . '/mirror/.typephp/build/carbon-interval.php';
$periodShadow = $root . '/mirror/.typephp/build/carbon-period.php';
checkCarbonGenerator($generated !== [] && is_file($intervalShadow) && is_file($periodShadow), 'actual final Carbon shadows generated');
$shadow = file_get_contents($intervalShadow);
checkCarbonGenerator(str_contains($shadow, '$interval->invert(!$interval->invert)')
    && !str_contains($shadow, '->doCascade(true)->invert()'), 'final interval shadow preserves cascade receiver order');
checkCarbonGenerator(file_get_contents($intervalFile) === $interval && file_get_contents($periodFile) === $period, 'final shadow generation leaves Carbon vendor unchanged');
checkCarbonGenerator(file_get_contents($periodFile) === $period, 'original Carbon vendor unchanged');
foreach (['3.13.2', '3.14.0', '3.14.1', '99.0.0'] as $version) {
    file_put_contents($root . '/mirror/composer.lock', json_encode(['packages' => [['name' => 'nesbot/carbon', 'version' => $version, 'source' => ['reference' => str_repeat('0', 40)]]]]));
    checkCarbonGenerator($apply()['sha256'] === $result['sha256'], "{$version} equivalent source accepted without version or reference gate");
}
file_put_contents($periodFile, $period . "\n// preserved unrelated comment\n");
checkCarbonGenerator($apply()['sha256'] === $result['sha256'], 'unrelated source comments do not require exact source digest');
$replacements = (new SaiAdminCarbonPeriodRule())->replacements($period, $interval);
$call = array_key_first($replacements);
checkCarbonGenerator(is_string($call), 'actual source has a magic call for unknown-shape regression');
$unknown = str_replace($call, 'CarbonInterval::unknownIntervalUnit()', $period);
file_put_contents($periodFile, $unknown);
try { $apply(); throw new RuntimeException('unknown unit must fail'); }
catch (ConfigurationException $error) { checkCarbonGenerator(str_contains($error->getMessage(), 'unsupported'), 'unknown Carbon magic call rejected'); }
$plain = (new SaiAdminGeneratorOverlay())->prepare($source, $lock['generator']['sourceSha256'],
    $lock['generator']['mainStubSha256'], $root . '/plain-cache', null, [], false);
$probe = $root . '/plain-probe.php';
file_put_contents($probe, <<<'PHP'
<?php
$repo = $argv[1];
spl_autoload_register(static function (string $class) use ($repo): void {
    if (str_starts_with($class, 'WebmanAotBuilder\\')) {
        require $repo . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
    }
});
require $argv[2];
$generator = new Tinywan\Typephp\Compiler\ProjectGenerator($argv[3]);
$generator->generateSwitchTerminalSources();
$shadow = file_get_contents($argv[3] . '/.typephp/build/carbon-interval.php');
exit(str_contains($shadow, '$interval->invert(!$interval->invert)') ? 0 : 1);
PHP);
$plainMirror = $root . '/plain-mirror';
mkdir($plainMirror . '/vendor/nesbot/carbon/src/Carbon', 0700, true);
copy($intervalFile, $plainMirror . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php');
$process = proc_open([PHP_BINARY, $probe, $repo, $plain['path'], $plainMirror],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
checkCarbonGenerator(is_resource($process), 'plain generator probe started');
$stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
checkCarbonGenerator(proc_close($process) === 0, 'plain build final interval shadow adapted: ' . trim($stdout . $stderr));
checkCarbonGenerator(hash_file('sha256', $source) === $lock['generator']['sourceSha256'], 'locked generator unchanged');
echo 'Carbon generator checks completed in ' . number_format(microtime(true) - $started, 2) . "s\n";
