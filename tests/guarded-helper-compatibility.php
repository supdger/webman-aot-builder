<?php

declare(strict_types=1);

use WebmanAotBuilder\Compatibility\GuardedHelperSourceRule;
use WebmanAotBuilder\Cli\ConfigurationException;

$repository = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repository): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) { require $repository . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});
$project = $argv[1] ?? '';
if (!is_dir($project . '/vendor')) { throw new RuntimeException('Pass the isolated actual installed project as first argument'); }
$targetCapabilities = ['intlEnabled' => true, 'nativeLocaleIsRightToLeft' => false, 'nativeGraphemeLevenshtein' => false, 'deferredIntlProviderValidation' => true];
$rule = new GuardedHelperSourceRule(80425, '6.2.0', $targetCapabilities);
$start = microtime(true);
$passed = 0;
function ensure(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function behavior(string $source, string $prelude, string $probe): string {
    $script = "<?php " . $prelude . "\n" . preg_replace('/\A<\?php/', '', $source, 1) . "\n" . $probe;
    $process = proc_open([PHP_BINARY, '-n'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    ensure(is_resource($process), 'Cannot start PHP behavior probe');
    fwrite($pipes[0], $script); fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    ensure(proc_close($process) === 0, 'Behavior child failed: ' . $error . $output);
    return $output;
}
function check(string $label, callable $probe): void {
    global $passed;
    $probe(); ++$passed; echo 'PASS ' . $label . "\n";
}
function reject(GuardedHelperSourceRule $rule, string $path, string $source): void {
    try { $rule->prepare($path, $source); } catch (ConfigurationException $error) { return; }
    throw new RuntimeException('Unsafe source accepted: ' . $path);
}
$actual = [
    'route' => 'vendor/nikic/fast-route/src/functions.php',
    'reflection' => 'vendor/illuminate/reflection/helpers.php',
    'replace' => 'vendor/illuminate/support/helpers.php',
    'dump' => 'vendor/symfony/var-dumper/Resources/functions/dump.php',
    'bootstrap' => 'vendor/symfony/polyfill-php85/bootstrap.php',
    'redis' => 'vendor/symfony/cache/Traits/Redis63ProxyTrait.php',
    'stub' => 'vendor/symfony/polyfill-php85/Resources/stubs/NoDiscard.php',
];
$sources = [];
foreach ($actual as $label => $path) {
    ensure(is_file($project . '/' . $path), 'Actual fixture missing: ' . $path);
    $sources[$label] = file_get_contents($project . '/' . $path);
}
$sources['cake'] = <<<'SOURCE'
<?php
namespace Cake\Core;
if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }
if (!defined('CAKE_DATE_RFC7231')) { define('CAKE_DATE_RFC7231', 'D, d M Y H:i:s \G\M\T'); }
if (!function_exists('cake_fixture')) { function cake_fixture() { return \DS . \CAKE_DATE_RFC7231; } }
SOURCE;
$actual['cake'] = 'vendor/cakephp/core/functions.php';
if (isset($argv[2])) { ensure(is_file($argv[2]), 'Pass the official Cake core functions source as second argument'); $sources['cake'] = file_get_contents($argv[2]); }
$sources['ip'] = "<?php if (!class_exists('Ip2Region')) { require_once __DIR__ . '/Ip2Region.php'; } function ip_fixture() { return Ip2Region::class; }";
$actual['ip'] = 'vendor/zoujingli/ip2region/function.php';
$sources['ip3'] = "<?php if (!class_exists('Ip2Region')) { require_once __DIR__ . '/src/Ip2Region.php'; } function ip_fixture() { return Ip2Region::class; }";
$actual['ip3'] = 'vendor/zoujingli/ip2region/src/common.php';
$adapted = [];
foreach ($actual as $label => $path) {
    $source = $sources[$label];
    check($label . ' actual or semantic fixture', function () use ($rule, $path, $source, &$adapted, $label): void {
        $adapted[$label] = $rule->prepare($path, $source);
        ensure(is_string($adapted[$label]), 'Rule did not handle fixture');
        token_get_all($adapted[$label], TOKEN_PARSE);
    });
    check($label . ' equivalent tokens', function () use ($rule, $path, $source, $adapted, $label): void {
        $changed = '';
        foreach (token_get_all($source) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $changed .= $text;
            if (is_array($token) && in_array($token[0], [T_VARIABLE, T_STRING, T_LNUMBER, T_CONSTANT_ENCAPSED_STRING], true)) { $changed .= ' /* same token */ '; }
        }
        $output = $rule->prepare($path, $changed);
        $texts = static fn (string $s): array => array_values(array_filter(token_get_all($s), static fn ($t): bool => !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $normalize = static fn (array $tokens): array => array_map(static fn ($t) => is_array($t) ? [$t[0], $t[1]] : $t, $tokens);
        ensure($normalize($texts($output)) === $normalize($texts($adapted[$label])), 'Equivalent source output changed');
    });
    check($label . ' repeated adaptation', function () use ($rule, $path, $adapted, $label): void { ensure($rule->prepare($path, $adapted[$label]) === $adapted[$label], 'Adaptation not idempotent'); });
    check($label . ' unrelated source addition', function () use ($rule, $path, $source): void {
        $addition = ' function unrelated_fixture_' . bin2hex(random_bytes(5)) . '() { $remaining = 12; $proxyInitializer = 4; return $remaining + $proxyInitializer; }';
        ensure(is_string($rule->prepare($path, $source . $addition)), 'Unrelated method rejected');
    });
}
check('FastRoute actual PHP options behavior', function () use ($sources, $adapted): void {
    $prelude = 'namespace { class HelperRouteParser {} class HelperRouteData {} class HelperRouteCollector { public array $data = []; public function __construct($parser, $generator) { $this->data = [get_class($parser), get_class($generator)]; } public function getData() { return $this->data; } } class HelperDispatcher { public function __construct(public array $data) {} } }';
    $probe = 'namespace { $options = ["routeParser" => "HelperRouteParser", "dataGenerator" => "HelperRouteData", "routeCollector" => "HelperRouteCollector", "dispatcher" => "HelperDispatcher", "cacheDisabled" => true, "cacheFile" => "ignored"]; $callback = function ($collector) { $collector->data[] = "route-added"; }; echo json_encode([\FastRoute\simpleDispatcher($callback, $options)->data, \FastRoute\cachedDispatcher($callback, $options)->data, $options]); }';
    $wrap = static fn (string $s): string => str_replace('namespace FastRoute;', 'namespace FastRoute {', $s) . "\n}";
    ensure(behavior($wrap($sources['route']), $prelude, $probe) === behavior($wrap($adapted['route']), $prelude, $probe), 'FastRoute options precedence changed');
});
foreach (glob($project . '/vendor/symfony/cache/Traits/Redis*ProxyTrait.php') as $file) {
    $name = basename($file, '.php');
    if (preg_match('/Redis(?:Cluster)?[0-9]+ProxyTrait/D', $name) !== 1) { continue; }
    check('actual Redis trait ' . $name, function () use ($rule, $file, $name): void {
        $source = file_get_contents($file);
        $output = $rule->prepare('vendor/symfony/cache/Traits/' . basename($file), $source);
        $prelude = 'namespace Symfony\Component\Cache\Traits; function phpversion($name) { return "6.2.0"; }';
        $probe = '$reflection = new \ReflectionClass("Symfony\\Component\\Cache\\Traits\\' . $name . '"); echo json_encode(array_map(fn ($m) => [$m->getName(), (string) $m->getReturnType()], $reflection->getMethods()));';
        ensure(behavior($source, $prelude, $probe) === behavior($output, $prelude, $probe), 'Actual Redis signature branch changed');
    });
}
$stubs = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($project . '/vendor/symfony/polyfill-php85/Resources/stubs', FilesystemIterator::SKIP_DOTS));
foreach ($stubs as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') { continue; }
    $relative = substr($file->getPathname(), strlen($project) + 1);
    check('actual PHP stub ' . basename($relative), function () use ($rule, $file, $relative): void {
        $source = file_get_contents($file->getPathname());
        $output = $rule->prepare($relative, $source);
        token_get_all($output, TOKEN_PARSE);
        ensure($rule->prepare($relative, $output) === $output, 'Actual stub repeat drift');
    });
}
check('reflection actual PHP behavior', function () use ($sources, $adapted): void {
    $prelude = 'namespace Illuminate\Support\Traits { trait ReflectsClosures {} } namespace { class HelperProxyFixture { public int $value = 7; } }';
    $probe = <<<'PROBE'
$calls = 0;
$result = proxy(HelperProxyFixture::class, function ($proxy, $eager) use (&$calls) { ++$calls; if (!$proxy instanceof HelperProxyFixture) { throw new RuntimeException('Proxy reference lost'); } return new HelperProxyFixture(); });
echo json_encode([$calls, $result->value, $calls, $result->value]);
PROBE;
    foreach ([$sources['reflection'], $adapted['reflection']] as $source) {
        $source = "<?php namespace {\n" . substr($source, 5) . "\n}";
        $outputs[] = behavior($source, $prelude, 'namespace { ' . $probe . ' }');
    }
    ensure($outputs[0] === $outputs[1] && $outputs[0] === '[0,7,1,7]', 'Lazy proxy behavior changed');
});
check('preg replacement actual PHP behavior', function () use ($sources, $adapted): void {
    $probe = <<<'PROBE'
$input = ['one', 'two'];
echo json_encode([preg_replace_array('/\?/', $input, '?/?/?'), $input, preg_replace_array('/x/', ['a'], 'unchanged'), preg_replace_array('/x/', [], 'xx')]);
PROBE;
    $a = behavior($sources['replace'], '', $probe); $b = behavior($adapted['replace'], '', $probe);
    ensure($a === $b, 'Replacement behavior changed');
});
check('replacement reference parameter preserves caller effect through refusal', function () use ($sources, $rule, $actual): void {
    $source = str_replace('array $replacements', 'array &$replacements', $sources['replace']);
    $output = behavior($source, '', '$values = ["a", "b"]; $result = preg_replace_array("/x/", $values, "xx"); echo json_encode([$result, $values]);');
    ensure($output === '["ab",[]]', 'Reference input counterexample did not reproduce');
    reject($rule, $actual['replace'], $source);
});
check('helper conversion stays inside its declaration', function () use ($sources, $rule, $actual): void {
    $addition = <<<'SOURCE'
function unrelated_helper($pattern, $replacements, $subject) {
    $remaining = ['sentinel'];
    $GLOBALS['replacementAlias'] = &$replacements;
    return preg_replace_callback($pattern, function () use (&$replacements) { return array_shift($replacements); }, $subject);
}
SOURCE;
    $source = $sources['replace'] . $addition;
    $probe = 'echo json_encode([unrelated_helper("/x/", ["a", "b"], "xx"), $GLOBALS["replacementAlias"]]);';
    ensure(behavior($source, '', $probe) === behavior($rule->prepare($actual['replace'], $source), '', $probe), 'Unrelated helper binding was rewritten');
});
check('dump actual PHP positional and named behavior', function () use ($sources, $adapted): void {
    $prelude = 'namespace Symfony\Component\VarDumper { class VarDumper { public static array $calls = []; public static function dump($value, $key = null) { self::$calls[] = [$value, $key]; } } } namespace Symfony\Component\VarDumper\Caster { class ScalarStub { public function __construct(public $value) {} } }';
    $probe = 'namespace { echo json_encode([dump(), dump(7), dump(7, 8), dump(named: 9), \Symfony\Component\VarDumper\VarDumper::$calls]); }';
    $a = behavior("<?php namespace {\n" . substr($sources['dump'], 5) . "\n}", $prelude, $probe);
    $b = behavior("<?php namespace {\n" . substr($adapted['dump'], 5) . "\n}", $prelude, $probe);
    ensure($a === $b, 'Dump return or output behavior changed');
});
check('cake global constant and helper behavior', function () use ($sources, $adapted): void {
    $probe = isset($GLOBALS['argv'][2])
        ? 'echo json_encode([\DS, \CAKE_DATE_RFC7231, \Cake\Core\pathCombine(["one", "/two", "three"], true), \Cake\Core\pluginSplit("Plugin.Name"), \Cake\Core\namespaceSplit("App\\Example")]);'
        : 'echo json_encode([\DS, \CAKE_DATE_RFC7231, \Cake\Core\cake_fixture()]);';
    $a = behavior($sources['cake'], '', $probe);
    $b = behavior($adapted['cake'], '', 'namespace { ' . $probe . ' }');
    ensure($a === $b, 'Cake constant scope changed');
});
check('ip static loader behavior', function () use ($sources, $adapted): void {
    foreach (['ip', 'ip3'] as $name) { ensure(behavior($sources[$name], 'class Ip2Region {}', 'echo ip_fixture();') === behavior($adapted[$name], 'class Ip2Region {}', 'echo ip_fixture();'), 'IP class identity changed'); }
});
check('redis threshold selects actual target, including future trait labels', function () use ($rule): void {
    foreach (['5.0.0' => 'new', '6.2.0' => 'new', '6.3.0' => 'old', '7.0.0' => 'old'] as $version => $expected) {
        $source = "<?php if (version_compare(phpversion('redis'), '{$version}', '>=')) { trait Redis99ProxyTrait { public function branch() { return 'new'; } } } else { trait Redis99ProxyTrait { public function branch() { return 'old'; } } }";
        $output = $rule->prepare('vendor/symfony/cache/Traits/Redis99ProxyTrait.php', $source);
        ensure(behavior($output, '', 'class RedisProbe { use Redis99ProxyTrait; } echo (new RedisProbe())->branch();') === $expected, 'Redis wrong runtime branch');
    }
});
check('PHP stub branch follows target capability', function () use ($rule): void {
    foreach ([80000 => 'new', 80500 => 'old', 90000 => 'old'] as $version => $expected) {
        $source = "<?php if (\\PHP_VERSION_ID >= {$version}) { class StubFixture { const BRANCH = 'new'; } } else { class StubFixture { const BRANCH = 'old'; } }";
        $output = $rule->prepare('vendor/symfony/polyfill-php85/Resources/stubs/Fixture.php', $source);
        ensure(behavior($output, '', 'echo StubFixture::BRANCH;') === $expected, 'PHP wrong runtime branch');
    }
});
check('numeric PHP polyfill families use the selected target', function () use ($rule): void {
    $source = '<?php if (\PHP_VERSION_ID < 80200) { $GLOBALS["inactive"] = true; class PolyfillFixture {} }';
    $output = $rule->prepare('vendor/symfony/polyfill-php82/Resources/stubs/Fixture.php', $source);
    ensure(!str_contains($output, 'PolyfillFixture') && !str_contains($output, 'inactive'), 'Inactive target branch retained or flattened');
    $output = (new GuardedHelperSourceRule(80100))->prepare('vendor/symfony/polyfill-php82/Resources/stubs/Fixture.php', $source);
    ensure(str_contains($output, 'class PolyfillFixture') && !str_contains($output, 'PHP_VERSION_ID'), 'Active branch lost');
});
check('target version short-circuits unknown capability checks', function () use ($rule): void {
    $source = '<?php if (\PHP_VERSION_ID < 80000 && extension_loaded("tokenizer")) { class OldTokenFixture {} }';
    ensure(!str_contains($rule->prepare('vendor/symfony/polyfill-php80/Resources/stubs/Fixture.php', $source), 'OldTokenFixture'), 'False target conjunct did not short-circuit');
    ensure((new GuardedHelperSourceRule(70400))->prepare('vendor/symfony/polyfill-php80/Resources/stubs/Fixture.php', $source) === $source, 'Unknown active conjunct changed source');
});
check('version elseif chain selects only the target branch', function (): void {
    $source = '<?php if (\PHP_VERSION_ID < 80100) { class FirstFixture {} } elseif (\PHP_VERSION_ID < 80400) { class SecondFixture {} }';
    foreach ([80000 => 'FirstFixture', 80300 => 'SecondFixture', 80425 => null] as $version => $expected) {
        $output = (new GuardedHelperSourceRule($version))->prepare('vendor/symfony/polyfill-php84/Resources/stubs/Fixture.php', $source);
        ensure(!str_contains($output, 'PHP_VERSION_ID'), 'Version chain remains');
        ensure($expected === null ? !str_contains($output, 'class ') : str_contains($output, 'class ' . $expected), 'Incorrect target chain branch');
    }
});
check('bootstrap target cutoff preserves the executed prefix', function () use ($rule): void {
    $source = '<?php $GLOBALS["prefix"] = 1; if (\PHP_VERSION_ID >= 80200) { return; } $GLOBALS["inactive"] = 1;';
    $output = $rule->prepare('vendor/symfony/polyfill-php82/bootstrap.php', $source);
    ensure($output === '<?php $GLOBALS["prefix"] = 1; ', 'Bootstrap prefix changed or inactive effects retained');
    $probe = 'echo json_encode([$GLOBALS["prefix"] ?? null, $GLOBALS["inactive"] ?? null]);';
    ensure(behavior($output, '', $probe) === '[1,null]', 'Bootstrap prefix effect changed');
    ensure((new GuardedHelperSourceRule(80100))->prepare('vendor/symfony/polyfill-php82/bootstrap.php', $source) === $source, 'Active fallback was removed');
});
check('unknown PHP layouts remain compiler input', function () use ($rule): void {
    foreach (['<?php if (\PHP_VERSION_ID < 80100): class AlternativeFixture {} endif;', '<?php if (\PHP_VERSION_ID < 80100) { class FirstFixture {} } else if (unknown_runtime()) { class OtherFixture {} }'] as $source) {
        ensure($rule->prepare('vendor/symfony/polyfill-php82/Resources/stubs/Fixture.php', $source) === $source, 'Unknown layout became wrapper refusal');
    }
});
check('unknown PHP guard preserves the whole source', function () use ($rule): void {
    $source = '<?php if (\PHP_VERSION_ID < 80200) { class OldFixture {} } if (unknown_runtime()) { class UnknownFixture {} }';
    ensure($rule->prepare('vendor/symfony/polyfill-php82/Resources/stubs/Fixture.php', $source) === $source, 'Partial guard selection changed unknown source');
});
check('target capabilities are required only for runtime selectors', function () use ($sources, $actual): void {
    $unconfigured = new GuardedHelperSourceRule();
    ensure(is_string($unconfigured->prepare($actual['reflection'], $sources['reflection'])), 'Ordinary helper requires runtime selection');
    reject($unconfigured, $actual['redis'], $sources['redis']);
    reject($unconfigured, $actual['stub'], $sources['stub']);
});
check('Intl fallback follows SDK extension and function capabilities', function () use ($sources): void {
    foreach ([[true, false], [true, true], [false, false]] as [$enabled, $native]) {
        $caps = ['intlEnabled' => $enabled, 'nativeLocaleIsRightToLeft' => $native, 'nativeGraphemeLevenshtein' => $native, 'deferredIntlProviderValidation' => true];
        $output = (new GuardedHelperSourceRule(80425, '6.2.0', $caps))->prepare('vendor/symfony/polyfill-php85/bootstrap.php', $sources['bootstrap']);
        $prelude = $native ? 'function locale_is_right_to_left(string $locale): bool { return true; }' : '';
        $exists = behavior($output, $prelude, 'echo json_encode(function_exists("locale_is_right_to_left"));');
        ensure($exists === ($enabled ? 'true' : 'false'), 'Locale RTL fallback selection changed');
    }
});
check('bootstrap80 cutoff requires the final production provider contract', function () use ($sources, $actual): void {
    $caps = ['intlEnabled' => true, 'nativeLocaleIsRightToLeft' => false, 'nativeGraphemeLevenshtein' => false];
    reject(new GuardedHelperSourceRule(80425, '6.2.0', $caps), $actual['bootstrap'], $sources['bootstrap']);
    reject(new GuardedHelperSourceRule(80425, '6.2.0'), $actual['bootstrap'], $sources['bootstrap']);
    $caps['intlEnabled'] = 'unknown';
    reject(new GuardedHelperSourceRule(80425, '6.2.0', $caps), $actual['bootstrap'], $sources['bootstrap']);
});
check('target versions are selection data', function (): void {
    $source = "<?php if (version_compare(phpversion('redis'), '7.0.0', '>=')) { trait FutureProxyTrait {} } else { trait LegacyProxyTrait {} }";
    $output = (new GuardedHelperSourceRule(90500, '7.0.1'))->prepare('vendor/symfony/cache/Traits/Redis99ProxyTrait.php', $source);
    ensure(str_contains($output, 'trait FutureProxyTrait') && !str_contains($output, 'trait LegacyProxyTrait'), 'Target capability parameters ignored');
});
$unsafe = [
    ['route', '<?php function route_fixture($options) { $options += [1] ? [2] : [3]; return $options; }'],
    ['reflection', str_replace('$instance = $callback($proxy, $eager);', '$instance = other_callback($proxy, $eager);', $sources['reflection'])],
    ['reflection', str_replace('$reflectionClass = new ReflectionClass($class);', '$proxyInitializer = null; $reflectionClass = new ReflectionClass($class);', $sources['reflection'])],
    ['replace', str_replace('return array_shift($replacements);', 'return array_pop($replacements);', $sources['replace'])],
    ['replace', str_replace('return preg_replace_callback($pattern,', '$GLOBALS["escape"] = &$replacements; return preg_replace_callback($pattern,', $sources['replace'])],
    ['replace', str_replace('return preg_replace_callback($pattern,', '$remaining = ["collision"]; return preg_replace_callback($pattern,', $sources['replace'])],
    ['dump', str_replace('$k = 0;', '$k = 5;', $sources['dump'])],
    ['dump', str_replace('return $vars[$k];', '$GLOBALS["exitKey"] = 1 + $k; return $vars[$k];', $sources['dump'])],
    ['dump', str_replace('if (!$vars) {', '$GLOBALS["keyAlias"] = &$k; if (!$vars) {', $sources['dump'])],
    ['cake', str_replace('DIRECTORY_SEPARATOR);', '"wrong");', $sources['cake'])],
    ['ip', str_replace("'/Ip2Region.php'", "'/Other.php'", $sources['ip'])],
    ['bootstrap', str_replace("'/bootstrap80.php'", "'/unknown.php'", $sources['bootstrap'])],
    ['bootstrap', str_replace("function locale_is_right_to_left", "function unknown_intl_function", $sources['bootstrap'])],
    ['redis', "<?php if (version_compare(phpversion('redis'), unknown_version(), '>=')) { trait RedisUnknown {} }"],
];
foreach ($unsafe as $index => [$label, $source]) { check('unsafe ' . $label . ' ' . $index, static fn () => reject($rule, $actual[$label], $source)); }
check('unowned source returns null', static function () use ($rule): void { ensure($rule->prepare('app/helpers.php', '<?php function app_function() {}') === null, 'Unowned source handled'); });
echo 'PASS ' . $passed . ' cases; helper behavior, target selection, equivalent tokens, repeat and unsafe rejection (' . number_format(microtime(true) - $start, 3) . "s)\n";
