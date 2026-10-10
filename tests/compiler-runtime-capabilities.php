<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});

function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$repository = dirname(__DIR__);
$baseline = $argv[1] ?? '';
ensure(is_file($baseline . '/src/CompilerBase.php') && is_file($baseline . '/vendor/autoload.php'), 'Pass TypePHP source before the runtime-capability patch');
$root = sys_get_temp_dir() . '/webman-aot-runtime-capabilities-' . bin2hex(random_bytes(6));
$started = microtime(true);
if (!is_dir($root . '/src')) { mkdir($root . '/src', 0700, true); }
copy($baseline . '/src/CompilerBase.php', $root . '/src/CompilerBase.php');
copy($baseline . '/composer.json', $root . '/composer.json');
$manifestFile = $repository . '/toolchain/patches/typephp/0.9.2/manifest.json';
$manifest = json_decode(file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['rules'] as $rule) {
    $target = $root . '/' . $rule['path'];
    if (!is_dir(dirname($target))) { mkdir(dirname($target), 0700, true); }
    if (($rule['added'] ?? false) === true) { continue; }
    $original = in_array($rule['path'], ['src/CompilerBase.php', 'composer.json'], true) && isset($argv[2])
        ? $argv[2] . '/' . basename($rule['path']) : $baseline . '/' . $rule['path'];
    copy($original, $target);
}
$approvedFile = $argv[3] ?? '';
ensure(is_file($approvedFile), 'Pass approved minimal-component.json as argument 3 (argument 2 is baseline-file directory)');
$approvedJson = file_get_contents($approvedFile);
$componentLock = json_decode(file_get_contents($repository . '/toolchain/minimal-components.lock.json'), true, flags: JSON_THROW_ON_ERROR);
$approved = json_decode($approvedJson, true, flags: JSON_THROW_ON_ERROR);
ensure(($componentLock['components'][$approved['host']]['manifestSha256'] ?? null) === hash('sha256', $approvedJson), 'baseline component manifest is not approved by component lock');
foreach ($manifest['rules'] as $rule) {
    if (!in_array($rule['path'], ['src/CompilerBase.php', 'composer.json'], true)) { continue; }
    $entry = $approved['entries']['prepared/typephp-source/typephp-0.9.2/' . $rule['path']];
    $expected = $rule['path'] === 'composer.json' ? $rule['afterSha256'] : $rule['preparedBeforeSha256'];
    ensure($entry['sha256'] === $expected && hash_file('sha256', $baseline . '/' . $rule['path']) === $expected
        && filesize($baseline . '/' . $rule['path']) === $entry['size'], 'approved prepared baseline chain differs');
}
$before = file_get_contents($root . '/src/CompilerBase.php');
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0027-compiler-runtime-capabilities.patch', $root);
foreach (['src/CompilerBase.php', 'composer.json'] as $path) {
    ensure(hash_file('sha256', $root . '/' . $path) === $approved['entries']['prepared/typephp-source/typephp-0.9.2/' . $path]['sha256'], 'runtime patch does not reproduce approved prepared baseline');
}
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0028-ordinary-toarray-method-contracts.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0029-unavailable-composer-traits.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0030-polymorphic-php-local-storage.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0031-persistent-php-reference-storage.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0032-closure-exception-cleanup.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0033-mutable-php-value-parameters.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0034-native-boundary-reference-overrides.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0035-target-function-value-storage.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0036-request-namespace-function-fallback.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0037-php-string-bitwise-not.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0038-known-php-local-value-joins.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0039-target-internal-class-declarations.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0040-pure-php-call-return-prediction.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0041-lazy-missing-composer-interfaces.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0042-ordinary-constructor-return-values.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0043-ordinary-destructor-return-values.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0044-related-php-object-local-joins.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0045-switch-goto-termination.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0046-final-switch-case-exit.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0047-lexical-finally-goto-exits.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0048-php-catch-local-value-storage.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0049-goto-safe-expression-temporaries.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0050-foreach-list-local-value-storage.patch', $root);
(new WebmanAotBuilder\Toolchain\UnifiedPatchApplier())->apply($repository . '/toolchain/patches/typephp/0.9.2/0051-switch-selector-value-lifetime.patch', $root);
$after = file_get_contents($root . '/src/CompilerBase.php');
$manifest = json_decode(file_get_contents($repository . '/toolchain/patches/typephp/0.9.2/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($manifest['rules'] as $rule) {
    if (in_array($rule['path'], ['src/CompilerBase.php', 'composer.json'], true)) {
        ensure(hash_file('sha256', $root . '/' . $rule['path']) === $rule['afterSha256'], 'after material SHA differs');
    }
}
$start = strpos($after, '    protected static function assertRuntimeCapabilities(): void');
$end = strpos($after, '    public function __construct', $start);
$method = substr($after, $start, $end - $start);
$oldStart = strpos($before, "        if (version_compare(PHP_VERSION, '8.4.0'");
$oldEnd = strpos($before, '        $this->rootPath', $oldStart);
$oldGate = substr($before, $oldStart, $oldEnd - $oldStart);
function probe(string $code, array $arguments = []): array {
    $process = proc_open([PHP_BINARY, '-n', ...$arguments, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    ensure(is_resource($process), 'probe failed to start'); fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    return [proc_close($process), $out, $err];
}
$autoload = 'require ' . var_export($baseline . '/vendor/autoload.php', true) . ';';
$imports = 'use PhpParser\ParserFactory; use PhpParser\PhpVersion;';
$newClass = 'class RuntimeProbe { ' . $method . ' public static function run(): void {self::assertRuntimeCapabilities();}} RuntimeProbe::run(); echo "accepted";';
$tests = 0;
foreach (['8.4.25', '8.6.0', '99.0.0', '8.3.0'] as $label) {
    $prefix = 'namespace TypePhp; define("TypePhp\\PHP_VERSION", ' . var_export($label, true) . '); ';
    $old = probe($prefix . 'class OriginalProbe { public function error(string $m): never {throw new \RuntimeException($m);} public function run(): void {' . $oldGate . '}} (new OriginalProbe)->run();');
    ensure(($old[0] === 0) === ($label === '8.4.25'), 'old guard counterexample differs');
    $new = probe($prefix . $autoload . $imports . $newClass);
    ensure($new[0] === 0 && $new[1] === 'accepted', 'equivalent capabilities rejected for version label ' . $label . ': ' . $new[2]);
    echo "PASS original numeric gate / capability-equivalent label {$label}\n"; $tests++;
}
foreach (['proc_open', 'proc_close', 'token_get_all', 'json_decode'] as $missing) {
    $r = probe('namespace TypePhp;' . $autoload . $imports . $newClass, ['-d', 'disable_functions=' . $missing]);
    ensure($r[0] !== 0 && str_contains($r[1] . $r[2], 'requires the ' . $missing . ' function'), 'missing actual function accepted');
    echo "PASS disabled {$missing} capability rejected\n"; $tests++;
}
foreach (['extension', 'reflection', 'parser', 'syntax', 'integer'] as $missing) {
    $mock = match ($missing) {
        'extension' => 'function extension_loaded($s) {return $s !== "tokenizer" && \extension_loaded($s);}',
        'reflection' => 'function method_exists($c,$m) {return $m !== "isPrivateSet" && \method_exists($c,$m);}',
        'parser' => 'function method_exists($c,$m) {return $m !== "createForVersion" && \method_exists($c,$m);}',
        'syntax' => 'function token_get_all($s,$f) {throw new \ParseError("unsupported property hooks");}',
        'integer' => 'define("TypePhp\\PHP_INT_SIZE",4);',
    };
    $r = probe('namespace TypePhp;' . $autoload . $imports . $mock . $newClass);
    ensure($r[0] !== 0, 'missing actual capability accepted: ' . $missing);
    echo "PASS absent {$missing} capability rejected\n"; $tests++;
}
$constructor = probe($autoload . 'require ' . var_export($root . '/src/CompilerBase.php', true) . '; new TypePhp\\CompilerBase(' . var_export($root, true) . '); echo "accepted";');
ensure($constructor[0] === 0 && $constructor[1] === 'accepted', 'actual patched constructor failed: ' . $constructor[1] . $constructor[2]);
$tests++; echo "PASS actual patched CompilerBase constructor\n";
$verifier = new WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier();
$fingerprint = new WebmanAotBuilder\Toolchain\TypePhpPatchManifestFingerprint();
$verifier->verify($root, $manifestFile);
ensure(strlen($fingerprint->digest($manifestFile)) === 64, 'production fingerprint rejected composer.json');
$changed = $manifest;
$compilerRuleIndex = array_search('src/CompilerBase.php', array_column($manifest['rules'], 'path'), true);
$changed['rules'][$compilerRuleIndex]['preparedBeforeSha256'] = str_repeat('1', 64);
file_put_contents($root . '/changed-chain.json', json_encode($changed, JSON_THROW_ON_ERROR));
ensure($fingerprint->digest($root . '/changed-chain.json') !== $fingerprint->digest($manifestFile), 'fingerprint ignores prepared baseline identity');
$tests++; echo "PASS approved intermediate baseline and fingerprint commitment\n";
foreach ([null, 'invalid', $manifest['rules'][$compilerRuleIndex]['afterSha256']] as $invalid) {
    $changed['rules'][$compilerRuleIndex]['preparedBeforeSha256'] = $invalid;
    file_put_contents($root . '/changed-chain.json', json_encode($changed, JSON_THROW_ON_ERROR));
    foreach ([fn() => $verifier->verify($root, $root . '/changed-chain.json'), fn() => $fingerprint->digest($root . '/changed-chain.json')] as $action) {
        $rejected = false;
        try { $action(); } catch (WebmanAotBuilder\Cli\ConfigurationException $error) { $rejected = true; }
        ensure($rejected, 'invalid or self-derived prepared baseline accepted');
    }
    $tests++; echo "PASS invalid or self-derived prepared baseline rejected\n";
}

$tests++; echo "PASS production source verifier and fingerprint\n";
foreach (['../composer.json', 'arbitrary.json', 'src/../composer.json'] as $unsafe) {
    $bad = $manifest;
    $bad['rules'][count($bad['rules']) - 1]['path'] = $unsafe;
    file_put_contents($root . '/bad-manifest.json', json_encode($bad, JSON_THROW_ON_ERROR));
    foreach ([fn() => $verifier->verify($root, $root . '/bad-manifest.json'), fn() => $fingerprint->digest($root . '/bad-manifest.json')] as $action) {
        $rejected = false;
        try { $action(); } catch (WebmanAotBuilder\Cli\ConfigurationException $error) { $rejected = true; }
        ensure($rejected, 'unsafe root or traversal path accepted: ' . $unsafe);
    }
    $tests++; echo "PASS unsafe manifest path {$unsafe} rejected\n";
}
rename($root . '/composer.json', $root . '/composer-original.json');
symlink($root . '/composer-original.json', $root . '/composer.json');
$rejected = false;
try { $verifier->verify($root, $manifestFile); } catch (WebmanAotBuilder\Cli\ConfigurationException $error) { $rejected = true; }
ensure($rejected, 'symlinked composer.json accepted');
unlink($root . '/composer.json'); rename($root . '/composer-original.json', $root . '/composer.json');
$tests++; echo "PASS symlinked material rejected\n";
ensure(!str_contains($after, "version_compare(PHP_VERSION"), 'numeric runtime gate remains');
ensure(json_decode(file_get_contents($root . '/composer.json'), true)['require']['php'] === '>=8.4', 'PHP syntax requirement or upper incorrect');
echo sprintf("PASS %d runtime capability cases, material SHA verified, %.3fs\n", $tests, microtime(true) - $started);
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
    $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($root);
