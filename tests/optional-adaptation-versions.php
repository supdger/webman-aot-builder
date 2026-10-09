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
    ensure(count($roots) === 1, 'Pass one extracted source package under ' . $name);
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

function runProbe(string $file, array $paths): string
{
    $process = proc_open([PHP_BINARY, $file, ...$paths], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
    ensure(is_resource($process), 'cannot start behavior probe');
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    ensure(proc_close($process) === 0 && is_string($output), 'behavior probe failed');
    return $output;
}

function compareMonolog(string $original, string $adapted): void
{
    $saved = $_SERVER;
    $results = [];
    try {
        foreach ([$original, $adapted] as $source) {
            $name = 'ProbeWebProcessor' . bin2hex(random_bytes(6));
            eval(substr(preg_replace('/\bclass\s+WebProcessor\b/', 'class ' . $name, $source, 1), strlen('<?php')));
            $class = 'Monolog\\Processor\\' . $name;
            $_SERVER = ['REQUEST_URI' => '/before', 'REQUEST_METHOD' => 'GET', 'REMOTE_ADDR' => '127.0.0.1'];
            $processor = new $class();
            $_SERVER['REQUEST_URI'] = '/after';
            $first = $processor(['extra' => []]);
            $explicit = new $class(['REQUEST_URI' => '/explicit', 'REQUEST_METHOD' => 'POST']);
            $_SERVER['REQUEST_URI'] = '/later';
            $results[] = [$first, $processor(['extra' => []]), $explicit(['extra' => []])];
        }
        ensure($results[0] === $results[1] && $results[0][0]['extra']['url'] === '/after'
            && $results[0][1]['extra']['url'] === '/later' && $results[0][2]['extra']['url'] === '/explicit',
            'Monolog live global reference or explicit input behavior differs');
    } finally {
        $_SERVER = $saved;
    }
}

$fixtures = $argv[1] ?? '';
$generatorSource = $argv[2] ?? '';
$sdk = $argv[3] ?? '';
$selection = $argv[5] ?? null;
ensure(in_array($selection, [null, '--intl-only', '--deepclone-target-only'], true), 'Unsupported fifth argument selection');
ensure(is_dir($sdk), 'Pass the verified actual static SDK directory as third argument');
$repository = dirname(__DIR__);
$intlCapabilities = ['intlEnabled' => true, 'nativeGraphemeLevenshtein' => false];
require packageRoot($fixtures, 'monolog') . '/src/Monolog/Processor/ProcessorInterface.php';
$lock = json_decode(file_get_contents($repository . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
ensure(is_file($generatorSource) && hash_file('sha256', $generatorSource) === $lock['generator']['sourceSha256'], 'Pass the fixed original ProjectGenerator.php');
require $generatorSource;
$policy = $lock['optionalAdaptations'];
$targetContext = [];
if ($selection !== '--intl-only') {
    $toolchainLock = json_decode(file_get_contents($repository . '/toolchain.lock.json'), true, flags: JSON_THROW_ON_ERROR);
    $approval = $toolchainLock['evidence']['patchedSdk'] ?? [];
    $capabilities = (new WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdk);
    $targetContext = ['sdkDirectory' => realpath($sdk), 'sdkSha256' => $approval['sdkSha256'] ?? null,
        'derivationSha256' => $approval['derivationSha256'] ?? null,
        'toolchainLockSha256' => hash_file('sha256', $repository . '/toolchain.lock.json'),
        'toolchainLockFile' => realpath($repository . '/toolchain.lock.json'),
        'phpVersionId' => $capabilities['phpVersionId'], 'deepcloneEnabled' => $capabilities['deepcloneEnabled']];
}

$monologPath = $policy['monolog/monolog']['path'];
$sources = [
    $monologPath => file_get_contents(packageRoot($fixtures, 'monolog') . '/src/Monolog/Processor/WebProcessor.php'),
];
token_get_all($sources[$monologPath], TOKEN_PARSE);
$deepcloneFiles = ['bootstrap.php', 'bootstrap81.php', 'Resources/stubs/ClassNotFoundException.php', 'Resources/stubs/NotInstantiableException.php', 'DeepClone.php'];
foreach ($deepcloneFiles as $name) {
    $path = 'vendor/symfony/polyfill-deepclone/' . $name;
    $sources[$path] = file_get_contents(packageRoot($fixtures, 'deepclone') . '/' . $name);
    token_get_all($sources[$path], TOKEN_PARSE);
}
$generator = new Tinywan\Typephp\Compiler\ProjectGenerator($fixtures);
$prepare = new ReflectionMethod($generator, 'prepareGuardedSource');
$flatten = new ReflectionMethod($generator, 'flattenGuardedSource');
$shadows = [];
foreach (['grapheme', 'idn', 'normalizer'] as $name) {
    $package = 'symfony/polyfill-intl-' . $name;
    $path = 'vendor/' . $package . '/bootstrap80.php';
    $sources[$path] = file_get_contents(packageRoot($fixtures, $name) . '/bootstrap80.php');
    token_get_all($sources[$path], TOKEN_PARSE);
    $content = $prepare->invoke($generator, $path, $sources[$path]);
    $shadow = $flatten->invoke($generator, $content);
    token_get_all($shadow, TOKEN_PARSE);
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
$deepCloneProbe = <<<'PROBE'
<?php
require $argv[1];
require $argv[2];
require $argv[3];
require $argv[4];
#[AllowDynamicProperties]
class FirstObject { public string $name = 'first'; private int $secret = 7; public function secret(): int { return $this->secret; } }
class SecondObject { public string $name = 'second'; }
class ScopeRoot { private int $same = 1; protected int $protected = 2; }
class ScopeMiddle extends ScopeRoot { private int $same = 3; }
class ScopeLeaf extends ScopeMiddle { private int $same = 4; }
$scopesMethod = new ReflectionMethod(Symfony\Polyfill\DeepClone\DeepClone::class, 'getPropertyScopes');
$scopes = [];
foreach ([ScopeRoot::class, ScopeMiddle::class, ScopeLeaf::class, SecondObject::class] as $scopeClass) {
    $scopes[$scopeClass] = $scopesMethod->invoke(null, new ReflectionClass($scopeClass));
}

$shared = (object) ['counter' => 1];
$value = (object) ['name' => 'roundtrip', 'nested' => [new FirstObject(), new SecondObject()],
    'left' => $shared, 'right' => $shared];
$encoded = deepclone_to_array($value);
$copy = deepclone_from_array($encoded);
$countEncoded = deepclone_to_array((object) ['single' => 'count branch']);
$hydrated = deepclone_hydrate(FirstObject::class, ["\0FirstObject\0secret" => 9]);
$scopeEvidence = null;
if (property_exists(Symfony\Polyfill\DeepClone\DeepClone::class, 'reviewCapture')) {
    deepclone_hydrate(FirstObject::class, ["\0FirstObject\0secret" => 5, 'unknown' => 10]);
    $scopeEvidence = Symfony\Polyfill\DeepClone\DeepClone::$reviewCapture;
    if ($scopeEvidence !== [null, null, null, null]) {
        throw new RuntimeException('scope locals or their live aliases retain a previous matched tuple');
    }
}
echo json_encode([is_array($encoded['objectMeta']), is_int($countEncoded['objectMeta']),
    $encoded, $countEncoded, $copy == $value, $copy !== $value, $copy->left === $copy->right,
    $copy->left !== $shared, DEEPCLONE_HYDRATE_CALL_HOOKS,
    DEEPCLONE_HYDRATE_NO_LAZY_INIT, DEEPCLONE_HYDRATE_PRESERVE_REFS,
    get_parent_class(DeepClone\ClassNotFoundException::class),
    get_parent_class(DeepClone\NotInstantiableException::class), $scopeEvidence, $scopes, $hydrated->secret()]);
PROBE;
$intlProbe = <<<'PROBE'
<?php
require $argv[1];
if (($argv[3] ?? null) === 'native-function-fixture') {
    function grapheme_levenshtein($s1, $s2, $insertion = 1, $replacement = 1, $deletion = 1) { return Symfony\Polyfill\Intl\Grapheme\Grapheme::grapheme_levenshtein($s1, $s2, $insertion, $replacement, $deletion); }
}
require $argv[2];
$results = [grapheme_levenshtein("a\u{301}b", "a\u{301}c"), grapheme_levenshtein('abc', 'ac', 2, 3, 4),
    grapheme_strrev("a\u{301}b"), grapheme_strrev(''), grapheme_levenshtein('bad' . chr(255), 'ok')];
try { grapheme_levenshtein('a', 'b', -1); } catch (ValueError $error) { $results[] = $error->getMessage(); }
echo json_encode($results, JSON_THROW_ON_ERROR);
PROBE;
$variants = [
    ['suite' => 'monolog', 'label' => 'monolog', 'sources' => $sources, 'version' => null],
    ['suite' => 'deepclone', 'label' => 'deepclone', 'sources' => $sources, 'version' => null],
    ['suite' => 'intl', 'label' => 'intl', 'sources' => $sources, 'version' => null],
];
if (isset($argv[4])) {
    $actualProject = realpath($argv[4]);
    ensure(is_string($actualProject), 'Pass the actual project containing the newer installed deepclone as fourth argument');
    $actualLock = json_decode(file_get_contents($actualProject . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    $packages = array_values(array_filter($actualLock['packages'] ?? [], static fn (array $package): bool => ($package['name'] ?? null) === 'symfony/polyfill-deepclone'));
    ensure(count($packages) === 1 && is_string($packages[0]['version'] ?? null) && is_string($packages[0]['source']['reference'] ?? null), 'new deepclone installed source identity is missing');
    $actualSources = $sources;
    foreach ($deepcloneFiles as $name) {
        $path = 'vendor/symfony/polyfill-deepclone/' . $name;
        $actualSources[$path] = file_get_contents($actualProject . '/' . $path);
        ensure(is_string($actualSources[$path]), 'new deepclone source is missing: ' . $name);
    }
    $variants[] = ['suite' => 'deepclone', 'label' => 'deepclone-upstream', 'sources' => $actualSources, 'version' => $packages[0]['version'], 'reference' => $packages[0]['source']['reference']];
    echo 'INPUT deepclone-upstream ' . $packages[0]['version'] . ' @' . $packages[0]['source']['reference'] . "\n";
}
$started = microtime(true);
foreach ($variants as $variant) {
    $suite = $variant['suite'];
    if ($selection === '--intl-only' && $suite !== 'intl'
        || $selection === '--deepclone-target-only' && $suite !== 'deepclone'
    ) { continue; }
    $sources = $variant['sources'];
    $newMetadata = $variant['label'] === 'deepclone-upstream';
    $previous = null;
    $cases = ['baseline', 'higher', 'higher-prerelease', 'unknown-label', 'lower-label', 'comments', 'equivalent-format', 'unrelated-change', 'historical-digest-change', 'already-adapted-source', 'guard-drift', 'source-drift', 'output-or-sdk-drift', 'duplicate'];
    if ($suite === 'deepclone') {
        $cases = [...$cases, ...($newMetadata ? ['metadata-boolean-drift'] : ['metadata-lifetime-drift']), 'parent-exit-drift', 'temporary-collision', 'parent-reference-drift', 'parent-reference-call', 'scope-locals'];
    }
    if ($suite === 'intl') { $cases = [...$cases, 'native-function-present', 'runtime-capability-missing', 'runtime-capability-unknown']; }
    if ($suite === 'deepclone') {
        $targetCases = ['historical-sdk-policy', 'selected-sdk-drift', 'selected-derivation-drift', 'selected-path-drift',
            'native-deepclone', 'unknown-deepclone', 'unknown-target', 'bootstrap-lower', 'bootstrap-higher',
            'bootstrap-inverse', 'bootstrap-inactive', 'bootstrap-unknown', 'bootstrap-path', 'bootstrap-trailing'];
        $cases = $selection === '--deepclone-target-only'
            ? ($newMetadata ? ['baseline', ...$targetCases] : ['baseline']) : [...$cases, ...$targetCases];
    }
    foreach ($cases as $case) {
        $caseTargetContext = $targetContext;
        if ($case === 'selected-sdk-drift' || $suite === 'deepclone' && $case === 'output-or-sdk-drift') { $caseTargetContext['sdkSha256'] = str_repeat('0', 64); }
        if ($case === 'selected-derivation-drift') { $caseTargetContext['derivationSha256'] = str_repeat('0', 64); }
        if ($case === 'selected-path-drift') { $caseTargetContext['sdkDirectory'] = $sdk . '/other'; }
        if ($case === 'native-deepclone') { $caseTargetContext['deepcloneEnabled'] = true; }
        if ($case === 'unknown-deepclone') { unset($caseTargetContext['deepcloneEnabled']); }
        if ($case === 'unknown-target') { unset($caseTargetContext['phpVersionId']); }

        $caseIntlCapabilities = $intlCapabilities;
        if ($case === 'native-function-present') { $caseIntlCapabilities['nativeGraphemeLevenshtein'] = true; }
        if ($case === 'runtime-capability-missing') { $caseIntlCapabilities = []; }
        if ($case === 'runtime-capability-unknown') { $caseIntlCapabilities['nativeGraphemeLevenshtein'] = 'unknown'; }
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
                    default => $variant['version'] ?? 'source-fixture',
                };
                $packages[] = ['name' => $name, 'version' => $version, 'source' => ['reference' => $case === 'baseline' ? ($variant['reference'] ?? 'fixture-reference') : str_repeat('b', 40)]];
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
            if ($case === 'comments' || $case === 'equivalent-format') {
                foreach ($sources + $shadows as $path => $contents) {
                    if ($case === 'comments') {
                        $contents .= "\n// unrelated release comment\n";
                    } else {
                        $formatted = '';
                        foreach (token_get_all($contents) as $token) {
                            $formatted .= is_array($token) && $token[0] === T_WHITESPACE ? " \n " : (is_array($token) ? $token[1] : $token);
                        }
                        $contents = $formatted;
                    }
                    writeSource($mirror . '/' . $path, $contents);
                }
            }
            if ($case === 'unrelated-change') {
                if ($suite !== 'intl') {
                    $contents = $sources[$target];
                    $contents = substr_replace($contents, "\n    public static function unrelatedFixture(): int { return 7; }\n}", strrpos($contents, '}'), 1);
                    writeSource($mirror . '/' . $target, $contents);
                } else {
                    // intl supplies grapheme_strlen, so its guarded PHP body is not executed.
                    foreach ([$target, '.typephp/build/symfony-grapheme-functions.php'] as $path) {
                        $contents = file_get_contents($mirror . '/' . $path);
                        $contents = str_replace('return p\\Grapheme::grapheme_strlen((string) $string);', 'return 1 + p\\Grapheme::grapheme_strlen((string) $string);', $contents);
                        writeSource($mirror . '/' . $path, $contents);
                    }
                }
            }
            if ($case === 'source-drift') {
                $contents = match ($suite) {
                    'monolog' => str_replace('$this->serverData = &$_SERVER;', '$this->serverData = $_SERVER;', $sources[$target]),
                    'deepclone' => $newMetadata
                        ? str_replace("'objectMeta' => \$metaIsCount ? \\count(\$objectMeta) : \$objectMeta,", "'objectMeta' => \$metaIsCount ? \\count(\$objectMeta) : \$objectMeta + 1,", $sources[$target])
                        : str_replace("'objectMeta' => \$n,", "'objectMeta' => \$n + 1,", $sources[$target]),
                    'intl' => $sources[$target] . "\nif (true) { throw new \\RuntimeException('unexpected top-level action'); }\n",
                };
                writeSource($mirror . '/' . $target, $contents);
            }
            if ($case === 'metadata-boolean-drift') {
                writeSource($mirror . '/' . $target, str_replace('$metaIsCount = true;', '$metaIsCount = 1;', $sources[$target]));
            }
            if (in_array($case, ['metadata-lifetime-drift', 'parent-exit-drift', 'temporary-collision', 'parent-reference-drift', 'parent-reference-call'], true)) {
                $contents = match ($case) {
                    'metadata-lifetime-drift' => str_replace('        if (null !== $preparedMask) {', '        $data[\'captured\'] = $n;' . "\n" . '        if (null !== $preparedMask) {', $sources[$target]),
                    'parent-exit-drift' => str_replace('        return $propertyScopes;', '        $propertyScopes[\'exit\'] = $class;' . "\n" . '        return $propertyScopes;', $sources[$target]),
                    'parent-reference-drift' => str_replace('        return $propertyScopes;', '        $propertyScopes[\'exit\'] = $probeAlias;' . "\n" . '        return $propertyScopes;', str_replace('        while ($class = $class->getParentClass()) {', '        $probeAlias = &$class;' . "\n" . '        while ($class = $class->getParentClass()) {', $sources[$target])),
                    'parent-reference-call' => str_replace('        while ($class = $class->getParentClass()) {', '        captureReflectionLocal($class);' . "\n" . '        while ($class = $class->getParentClass()) {', $sources[$target]),
                    'temporary-collision' => str_replace('        while ($class = $class->getParentClass()) {', '        $parentClass = \'existing local state\';' . "\n" . '        while ($class = $class->getParentClass()) {', $sources[$target]),
                };
                writeSource($mirror . '/' . $target, $contents);
            }
            if ($case === 'scope-locals') {
                $contents = str_replace("final class DeepClone\n{", "final class DeepClone\n{ public static array \$reviewCapture = [];", $sources[$target]);
                $contents = str_replace('        $scoped = [];', '        $scoped = [];' . "\n" . '        $scopeNameAlias = &$scopeName; $realNameAlias = &$realName;', $contents);
                $contents = str_replace('        unset($value);', '        unset($value);' . "\n" . '        self::$reviewCapture = [$scopeName, $realName, $scopeNameAlias, $realNameAlias];', $contents);
                writeSource($mirror . '/' . $target, $contents);
            }
            if ($case === 'guard-drift') {
                $path = match ($suite) {
                    'monolog' => $target,
                    'deepclone' => 'vendor/symfony/polyfill-deepclone/Resources/stubs/ClassNotFoundException.php',
                    'intl' => $target,
                };
                $contents = match ($suite) {
                    'monolog' => str_replace('$this->serverData = &$_SERVER;', '$fake = \'$this->serverData = &$_SERVER;\';', $sources[$path]),
                    'deepclone' => str_replace("!\\extension_loaded('deepclone')", "\\extension_loaded('deepclone')", $sources[$path]),
                    'intl' => str_replace("!function_exists('grapheme_levenshtein')", "!defined('grapheme_levenshtein')", $sources[$path]),
                };
                writeSource($mirror . '/' . $path, $contents);
            }
            if ($case === 'already-adapted-source') {
                match ($suite) {
                    'monolog' => (new MonologWebProcessorRule())->apply($mirror, $policy['monolog/monolog']),
                    'deepclone' => (new DeepClonePolyfillRule())->apply($mirror, $policy['symfony/polyfill-deepclone'], $sdk, $caseTargetContext),
                    'intl' => (new NativeIntlPolyfillRule())->apply($mirror, $policy['symfony/native-intl-polyfill'], $intlCapabilities),
                };
                if ($suite === 'deepclone') {
                    writeSource($mirror . '/' . $target, file_get_contents($mirror . '/.typephp/build/deepclone-DeepClone.php'));
                }
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
            if ($case === 'historical-digest-change') {
                $candidatePolicy['sourceSha256'] = str_repeat('0', 64);
                $candidatePolicy['adaptedSha256'] = str_repeat('0', 64);
                $candidatePolicy['adaptedGraphemeSha256'] = str_repeat('0', 64);
                if ($suite === 'deepclone') {
                    $candidatePolicy['files'] = array_fill_keys($deepcloneFiles, str_repeat('0', 64));
                }
                if ($suite === 'intl') {
                    foreach ($candidatePolicy['packages'] as &$packagePolicy) {
                        $packagePolicy['bootstrapSha256'] = str_repeat('0', 64);
                    }
                    unset($packagePolicy);
                    foreach ($candidatePolicy['shadows'] as &$shadowPolicy) {
                        $shadowPolicy['sourceSha256'] = str_repeat('0', 64);
                    }
                    unset($shadowPolicy);
                }
            }
            if ($case === 'output-or-sdk-drift') {
                if ($suite === 'deepclone') {
                    $candidatePolicy['sdkSha256'] = str_repeat('0', 64);
                } elseif ($suite === 'monolog') {
                    writeSource($mirror . '/' . $target, str_replace('$this->serverData = &$_SERVER;', '$this->serverData = &$_SERVER; $this->serverData = &$GLOBALS[\'_SERVER\'];', $sources[$target]));
                } else {
                    $path = '.typephp/build/symfony-grapheme-functions.php';
                    writeSource($mirror . '/' . $path, $shadows[$path] . "\nfunction unknown_intl_function(): int { return 0; }\n");
                }
                foreach (array_keys($before) as $path) {
                    $before[$path] = hash_file('sha256', $mirror . '/' . $path);
                }
            }
            if ($suite === 'deepclone' && str_starts_with($case, 'bootstrap-')) {
                $bootstrap = 'vendor/symfony/polyfill-deepclone/bootstrap.php';
                $condition = match ($case) {
                    'bootstrap-lower' => '\\PHP_VERSION_ID >= 80_000',
                    'bootstrap-higher' => 'PHP_VERSION_ID >= 80200',
                    'bootstrap-inverse' => '80425 <= PHP_VERSION_ID',
                    'bootstrap-inactive' => 'PHP_VERSION_ID >= 80500',
                    'bootstrap-unknown' => 'PHP_VERSION_ID >= 80100 && unknown_runtime_flag()',
                    default => '\\PHP_VERSION_ID >= 80100',
                };
                $contents = str_replace('\\PHP_VERSION_ID >= 80100', $condition, $sources[$bootstrap]);
                if ($case === 'bootstrap-path') { $contents = str_replace("'/bootstrap81.php'", "'/unknown.php'", $contents); }
                if ($case === 'bootstrap-trailing') { $contents .= "\nunknown_top_level_action();\n"; }
                ensure($contents !== $sources[$bootstrap], 'bootstrap fixture did not change');
                writeSource($mirror . '/' . $bootstrap, $contents);
                $before[$bootstrap] = hash_file('sha256', $mirror . '/' . $bootstrap);
                if (in_array($case, ['bootstrap-lower', 'bootstrap-higher', 'bootstrap-inverse', 'bootstrap-inactive'], true)) {
                    $ordinaryCondition = str_replace(['\\PHP_VERSION_ID', 'PHP_VERSION_ID'], (string) $targetContext['phpVersionId'], $condition);
                    ensure(eval('return ' . $ordinaryCondition . ';') === ($case !== 'bootstrap-inactive'), 'ordinary PHP target branch differs');
                }
            }
            if ($case === 'historical-sdk-policy') {
                $candidatePolicy['sdkSha256'] = str_repeat('0', 64);
                $candidatePolicy['derivationSha256'] = str_repeat('0', 64);
            }
            $beforeSource = file_get_contents($mirror . '/' . $target);
            $failure = in_array($case, ['source-drift', 'guard-drift', 'output-or-sdk-drift', 'duplicate', 'metadata-lifetime-drift', 'metadata-boolean-drift', 'parent-exit-drift', 'temporary-collision', 'parent-reference-drift', 'parent-reference-call', 'runtime-capability-missing', 'runtime-capability-unknown', 'selected-sdk-drift', 'selected-derivation-drift', 'selected-path-drift', 'native-deepclone', 'unknown-deepclone', 'unknown-target', 'bootstrap-inactive', 'bootstrap-unknown', 'bootstrap-path', 'bootstrap-trailing'], true);
            try {
                $result = match ($suite) {
                    'monolog' => (new MonologWebProcessorRule())->apply($mirror, $candidatePolicy),
                    'deepclone' => (new DeepClonePolyfillRule())->apply($mirror, $candidatePolicy, $sdk, $caseTargetContext),
                    'intl' => (new NativeIntlPolyfillRule())->apply($mirror, $candidatePolicy, $caseIntlCapabilities),
                };
                ensure(!$failure, 'unknown input accepted: ' . $suite . '/' . $case);
                $resultDigest = $suite === 'intl' ? $result['shadowSha256'] : $result;
                if (in_array($case, ['baseline', 'higher', 'higher-prerelease', 'unknown-label', 'lower-label'], true) && $previous !== null) {
                    ensure($resultDigest === $previous, 'same verified source produced a different result for version metadata');
                }
                if (in_array($case, ['baseline', 'higher', 'higher-prerelease', 'unknown-label', 'lower-label'], true)) {
                    $previous = $resultDigest;
                }
                $repeated = match ($suite) {
                    'monolog' => (new MonologWebProcessorRule())->apply($mirror, $candidatePolicy),
                    'deepclone' => (new DeepClonePolyfillRule())->apply($mirror, $candidatePolicy, $sdk, $caseTargetContext),
                    'intl' => (new NativeIntlPolyfillRule())->apply($mirror, $candidatePolicy, $caseIntlCapabilities),
                };
                ensure($repeated === $result, 'already-adapted input changed on repeat: ' . $suite . '/' . $case);
                if ($suite === 'deepclone') {
                    ensure(count($result) === 5, 'deepclone mapping coverage changed');
                    writeSource($root . '/deepclone-probe.php', $deepCloneProbe);
                    $probeResults = [];
                    foreach (['original', 'adapted'] as $mode) {
                        $prefix = $mirror . '/vendor/symfony/polyfill-deepclone/';
                        $paths = $mode === 'original'
                            ? [$prefix . 'bootstrap81.php', $prefix . 'Resources/stubs/ClassNotFoundException.php', $prefix . 'Resources/stubs/NotInstantiableException.php', $prefix . 'DeepClone.php']
                            : [$mirror . '/.typephp/build/deepclone-bootstrap81.php', $mirror . '/.typephp/build/deepclone-ClassNotFoundException.php', $mirror . '/.typephp/build/deepclone-NotInstantiableException.php', $mirror . '/.typephp/build/deepclone-DeepClone.php'];
                        $probeResults[] = runProbe($root . '/deepclone-probe.php', $paths);
                    }
                    ensure($probeResults[0] === $probeResults[1], 'deepclone original/adapted behavior differs');
                    $decoded = json_decode($probeResults[1], true, flags: JSON_THROW_ON_ERROR);
                    ensure(array_slice($decoded, 0, 2) === [true, true] && array_slice($decoded, 4, 4) === [true, true, true, true]
                        && end($decoded) === 9 && count($decoded[count($decoded) - 2]['ScopeLeaf']) === 6,
                        'deepclone metadata, identity, scopes or hydration changed');
                    foreach ($result as $mapping) {
                        ensure(hash_file('sha256', $mirror . '/' . $mapping['path']) === $mapping['sourceSha256'], 'deepclone changed vendor');
                        token_get_all(file_get_contents($mirror . '/' . $mapping['shadow']), TOKEN_PARSE);
                    }
                }
                if ($suite === 'monolog') {
                    ensure($result === hash_file('sha256', $mirror . '/' . $target), 'Monolog current output digest changed');
                    token_get_all(file_get_contents($mirror . '/' . $target), TOKEN_PARSE);
                    compareMonolog($beforeSource, file_get_contents($mirror . '/' . $target));
                }
                if ($suite === 'intl') {
                    writeSource($root . '/intl-probe.php', $intlProbe);
                    $classFile = packageRoot($fixtures, 'grapheme') . '/Grapheme.php';
                    $nativeFixture = $case === 'native-function-present' ? ['native-function-fixture'] : [];
                    $original = runProbe($root . '/intl-probe.php', [$classFile, $mirror . '/' . $target, ...$nativeFixture]);
                    $adapted = runProbe($root . '/intl-probe.php', [$classFile, $mirror . '/.typephp/build/symfony-grapheme-functions.php', ...$nativeFixture]);
                    ensure($original === $adapted, 'retained grapheme forwarding, Unicode or rejection behavior differs');
                    ensure($result['shadowSha256'] === hash_file('sha256', $mirror . '/.typephp/build/symfony-grapheme-functions.php')
                        && $result['projectSha256'] === hash_file('sha256', $mirror . '/project.linux.yml'), 'native intl current digests differ');
                    foreach ($sources as $path => $contents) {
                        ensure(hash_file('sha256', $mirror . '/' . $path) === $before[$path], 'native intl changed vendor');
                    }
                }
            } catch (ConfigurationException $error) {
                ensure($failure, 'verified source rejected: ' . $variant['label'] . '/' . $case . ': ' . $error->getMessage());
                if ($case === 'source-drift') {
                    ensure(str_contains($error->getMessage(), 'source'), 'unknown source mislabeled as version rejection');
                }
                foreach ($before as $path => $digest) {
                    ensure(hash_file('sha256', $mirror . '/' . $path) === $digest, 'failed adaptation wrote source or shadow');
                }
                ensure(file_get_contents($mirror . '/project.linux.yml') === $beforeProject, 'failed adaptation wrote project');
                ensure((glob($mirror . '/.typephp/build/deepclone-*') ?: []) === [], 'failed adaptation published a shadow');
            }
            echo 'PASS ' . $variant['label'] . ' ' . $case . "\n";
        } finally {
            removeFixture($root);
        }
    }
}
echo sprintf("PASS supplied source fixtures, fixed generator identity, actual generator shadows, source compatibility, repeated adaptation, current digests, PHP behavior equivalence and failure write guards (%.3fs)\n", microtime(true) - $started);
