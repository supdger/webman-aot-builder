<?php
declare(strict_types=1);
use WebmanAotBuilder\Compatibility\UpstreamProjectGenerator;
use WebmanAotBuilder\Toolchain\PreparedToolchain;
use WebmanAotBuilder\Cli\ConfigurationException;
spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});
$started = microtime(true);
$manifest = $argv[1] ?? '';
if (!is_file($manifest)) { throw new RuntimeException('Pass the selected prepared-toolchain manifest'); }
$data = json_decode(file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
$tools = (new PreparedToolchain())->load($manifest, dirname($manifest), dirname(__DIR__) . '/toolchain.lock.json', $data['host']);
$context = $tools['sdkContext'];
$method = new ReflectionMethod(UpstreamProjectGenerator::class, 'assertSelectedSdk');
$generator = new UpstreamProjectGenerator();
$cases = 0;
$probe = static function (string $name, string $directory, array $candidate, ?string $failure = null) use ($method, $generator, &$cases): void {
    try { $method->invoke($generator, $directory, $candidate); }
    catch (ConfigurationException $error) {
        if ($failure !== null && str_contains($error->getMessage(), $failure)) { ++$cases; echo "PASS {$name}\n"; return; }
        throw $error;
    }
    if ($failure !== null) { throw new RuntimeException("{$name} unsafe SDK accepted"); }
    ++$cases; echo "PASS {$name}\n";
};
$probe('current selected SDK and approved derivation accepted', $context['sdkDirectory'], $context);
$probe('missing selected context rejected', $context['sdkDirectory'], [], 'not the selected');
$probe('unselected SDK directory rejected', dirname($context['sdkDirectory']), $context, 'not the selected');
$probe('self reported static fingerprint rejected', $context['sdkDirectory'], array_replace($context, ['sdkSha256' => str_repeat('0', 64)]), 'differs from its authority');
$probe('self reported derivation identity rejected', $context['sdkDirectory'], array_replace($context, ['derivationSha256' => str_repeat('0', 64)]), 'differs from its authority');
$probe('selected authority SHA drift rejected', $context['sdkDirectory'], array_replace($context, ['toolchainLockSha256' => str_repeat('0', 64)]), 'authority lock drifted');
$probe('missing selected authority file rejected', $context['sdkDirectory'], array_replace($context, ['toolchainLockFile' => $manifest . '.absent']), 'authority lock drifted');
$probe('unknown SDK capability rejected', $context['sdkDirectory'], array_replace($context, ['deepcloneEnabled' => null]), 'not the selected');
echo sprintf("PASS %d selected SDK consumer cases in %.3fs\n", $cases, microtime(true) - $started);
