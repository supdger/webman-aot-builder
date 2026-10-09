<?php
declare(strict_types=1);
use WebmanAotBuilder\Compatibility\UpstreamProjectGenerator;
use WebmanAotBuilder\Cli\ConfigurationException;
spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});
$start = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-intl-providers-' . bin2hex(random_bytes(6));
mkdir($root . '/vendor/symfony/polyfill-php85', 0700, true);
mkdir($root . '/.typephp/build', 0700, true);
$sourcePath = 'vendor/symfony/polyfill-php85/bootstrap.php';
$shadowPath = '.typephp/build/symfony-php85-functions.php';
file_put_contents($root . '/' . $sourcePath, '<?php // upstream fixture');
$rule = new ReflectionMethod(UpstreamProjectGenerator::class, 'assertIntlFunctionProviders');
$generator = new UpstreamProjectGenerator();
$caps = ['intlEnabled' => true, 'nativeLocaleIsRightToLeft' => false, 'nativeGraphemeLevenshtein' => false];
$cases = 0;
$run = static function (string $name, string $php, array $capabilities, ?string $failure = null, string $ignore = '', bool $drift = false, string $mirrorSuffix = '') use ($root, $sourcePath, $shadowPath, $rule, $generator, &$cases): void {
    file_put_contents($root . '/' . $shadowPath, $php);
    file_put_contents($root . '/project.linux.yml', "name: probe\nsources:\n  - {$shadowPath}\nignore:\n{$ignore}\noutput:\n");
    $manifest = [['path' => $sourcePath, 'shadow' => $shadowPath, 'sourceSha256' => hash_file('sha256', $root . '/' . $sourcePath), 'shadowSha256' => $drift ? str_repeat('0', 64) : hash('sha256', $php)]];
    if ($cases === 0 && DIRECTORY_SEPARATOR === '\\') {
        echo 'Native Intl paths ' . json_encode(['root' => $root, 'canonicalRoot' => realpath($root), 'canonicalShadow' => realpath($root . '/' . $shadowPath)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
    try { $rule->invoke($generator, $root . $mirrorSuffix, $capabilities, $manifest); }
    catch (ConfigurationException $error) {
        if ($failure !== null && str_contains($error->getMessage(), $failure)) { ++$cases; echo "PASS {$name}\n"; return; }
        throw $error;
    }
    if ($failure !== null) { throw new RuntimeException("{$name} unsafe input accepted"); }
    ++$cases; echo "PASS {$name}\n";
};
$providers = '<?php function locale_is_right_to_left() {} function grapheme_levenshtein() {}';
try {
    $run('two missing native functions have one selected provider each', $providers, $caps);
    $run('canonical mirror accepts lexical dot alias', $providers, $caps, null, '', false, '/.');
    $run('canonical mirror accepts parent alias', $providers, $caps, null, '', false, '/../' . basename($root));
    $run('missing mirror rejected', $providers, $caps, 'mirror is missing or unsafe', '', false, '/missing');
    $run('missing RTL provider rejected', '<?php function grapheme_levenshtein() {}', $caps, 'locale_is_right_to_left expected 1');
    $run('missing Levenshtein provider rejected', '<?php function locale_is_right_to_left() {}', $caps, 'grapheme_levenshtein expected 1');
    $run('duplicate global provider rejected', $providers . ' function locale_is_right_to_left() {}', $caps, 'locale_is_right_to_left expected 1 global PHP declarations, got 2');
    $run('conditional false declaration cannot provide functions', '<?php if (false) { function locale_is_right_to_left() {} } function grapheme_levenshtein() {}', $caps, 'declaration is conditional');
    $run('alternative conditional declaration cannot provide functions', '<?php if (false): $x = 1; function locale_is_right_to_left() {} endif; function grapheme_levenshtein() {}', $caps, 'declaration is conditional');
    $run('ignored shadow does not provide functions', $providers, $caps, 'locale_is_right_to_left expected 1', "  - {$shadowPath}\n");
    $run('class methods and namespaced functions do not count', '<?php namespace Other; function locale_is_right_to_left() {} function grapheme_levenshtein() {} class Decoy { function locale_is_right_to_left() {} function grapheme_levenshtein() {} }', $caps, 'locale_is_right_to_left expected 1');
    $run('braced namespace and method scope preserves global providers', '<?php namespace Other { function locale_is_right_to_left() {} class Decoy { function grapheme_levenshtein() {} } } namespace { function locale_is_right_to_left() {} function grapheme_levenshtein() {} }', $caps);
    $run('native functions exclude PHP duplicates', $providers, array_replace($caps, ['nativeLocaleIsRightToLeft' => true]), 'locale_is_right_to_left expected 0');
    $run('both native functions need no PHP providers', '<?php class Decoy { function locale_is_right_to_left() {} }', array_replace($caps, ['nativeLocaleIsRightToLeft' => true, 'nativeGraphemeLevenshtein' => true]));
    $run('unknown selected SDK capability rejected', $providers, ['intlEnabled' => true], 'target capability is missing');
    $run('current mapping digest drift rejected', $providers, $caps, 'current mapping evidence drifted', '', true);
    echo sprintf("PASS %d Intl provider cases in %.3fs\n", $cases, microtime(true) - $start);
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
