<?php
declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\BoundedTextRule;
use WebmanAotBuilder\Compatibility\MinimumVersion;
use WebmanAotBuilder\Compatibility\WebmanWorkermanRules;
use WebmanAotBuilder\Compatibility\RuleEngine;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(Closure $call, string $marker): void
{
    try {
        $call();
    } catch (ConfigurationException $error) {
        check(str_contains($error->getMessage(), $marker), 'unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('unknown source accepted');
}

function annotated(string $source): string
{
    $output = '';
    $quoted = false;
    foreach (token_get_all($source) as $token) {
        if ($token === '"' || (is_array($token) && in_array($token[0], [T_START_HEREDOC, T_END_HEREDOC], true))) {
            $quoted = !$quoted;
            $output .= is_array($token) ? $token[1] : $token;
        } elseif ($quoted) {
            $output .= is_array($token) ? $token[1] : $token;
        } elseif (is_array($token) && $token[0] === T_WHITESPACE) {
            $output .= "\n \t";
        } elseif (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_ENCAPSED_AND_WHITESPACE], true)) {
            $output .= $token[1];
        } else {
            $output .= (is_array($token) ? $token[1] : $token) . ' /* preserved */';
        }
    }
    return $output;
}

$start = microtime(true);
$minimum = new MinimumVersion('1.0.0');
foreach (['1.0.0', 'v99.0.0', '2.0.0-beta.1', '1.0.0+build.9'] as $version) {
    $minimum->assertSupported($version, 'minimum', 'test/package', 'vendor/test.php', 1);
}
rejects(fn () => $minimum->assertSupported('0.9.9', 'minimum', 'test/package', 'vendor/test.php', 1), 'minimum 1.0.0');
rejects(fn () => $minimum->assertSupported('1.0.0-beta.1', 'minimum', 'test/package', 'vendor/test.php', 1), 'minimum 1.0.0');
rejects(fn () => $minimum->assertSupported('dev-main', 'minimum', 'test/package', 'vendor/test.php', 1), 'cannot establish');
(new MinimumVersion('1.0.0-beta.1'))->assertSupported('1.0.0', 'minimum', 'test/package', 'vendor/test.php', 1);
echo "PASS minimum-only stable/prerelease/build labels and explicit unknown diagnosis\n";
$rule = new BoundedTextRule('test-shape', 'test/package', 'vendor/test.php', new MinimumVersion('1.0.0'), ['class Probe'], 'function ()', 'function (...$args)', 2, ['function (...$args)']);
$original = '<?php class Probe { function a() { $x = function () {}; $y = function () {}; } }';
$expected = str_replace('function ()', 'function (...$args)', $original);
foreach (['1.0.0', '9.4.0', 'dev-next'] as $version) {
    check($rule->transform($original, $version) === $expected, 'same source rejected for ' . $version);
}
check($rule->transform($expected, '9.4.0') === $expected, 'adapted source is not idempotent');
$mixed = preg_replace('/function \(\.\.\.\$args\)/', 'function ()', $expected, 1);
check($rule->transform($mixed, '9.4.0') === $expected, 'mixed source did not complete adaptation');
$spaced = annotated($original);
$adapted = $rule->transform($spaced, '9.4.0');
check(substr_count($adapted, '/* preserved */') === substr_count($spaced, '/* preserved */'), 'token edits lost comments');
check($rule->transform($adapted, '9.4.0') === $adapted, 'annotated result not idempotent');
$decoys = $original . "\n// function ()\n" . '$literal = "function ()";';
check(str_ends_with($rule->transform($decoys, '9.4.0'), "// function ()\n" . '$literal = "function ()";'), 'comment or string rewritten');
rejects(fn () => $rule->transform(str_replace('function ()', 'function ($value)', $original), '9.4.0'), 'no supported');
$additional = str_replace('$y = function () {};', '$y = function () {}; $z = function () {};', $original);
check($rule->transform($additional, '9.4.0') === str_replace('function ()', 'function (...$args)', $additional), 'additional safe operation was rejected');
rejects(fn () => $rule->transform(str_replace('class Probe', 'class Unknown', $original), '9.4.0'), 'source structure drift');
echo "PASS versions, idempotence, mixed forms, comments, strings, drift and duplicates\n";

$webman = $argv[1] ?? '';
$workerman = $argv[2] ?? '';
if ($webman === '' || $workerman === '') {
    throw new RuntimeException('Pass fixed official webman-framework and workerman archive roots');
}
$lock = json_decode(file_get_contents(dirname(__DIR__) . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
$originals = [];
$adaptedSources = [];
foreach (WebmanWorkermanRules::knownRules() as $candidate) {
    $path = $candidate->sourcePath();
    if (!isset($originals[$path])) {
        $packageRoot = $candidate->dependency() === 'workerman/workerman' ? $workerman : $webman;
        $relative = substr($path, strlen('vendor/' . $candidate->dependency() . '/'));
        $source = file_get_contents($packageRoot . '/' . $relative);
        check(is_string($source), 'official source missing: ' . $path);
        $originals[$path] = $source;
        $adaptedSources[$path] = $source;
    }
    if ($candidate instanceof BoundedTextRule) {
        $reflection = new ReflectionClass($candidate);
        $needle = $reflection->getProperty('needle')->getValue($candidate);
        $replacement = $reflection->getProperty('replacement')->getValue($candidate);
    } elseif ($candidate->id() === 'workerman-tcp-error-handler-variadic') {
        $needle = 'set_error_handler(static function (int $code, string $msg): bool {';
        $replacement = 'set_error_handler(static function (int $code, string $msg, ...$__err): bool {';
    } else {
        $needle = 'set_error_handler(fn() => false);';
        $replacement = 'set_error_handler(fn(...$__err) => false);';
    }
    $source = $adaptedSources[$path];
    $expected = str_replace($needle, $replacement, $source, $hits);
    check($hits === $candidate->expectedHits(), 'locked source shape mismatch: ' . $candidate->id());
    $output = $candidate->transform($source, 'fixture-source-validated');
    check($output === $expected, 'canonical output changed: ' . $candidate->id());
    check($candidate->transform($source, 'v99.0.0') === $expected, 'equivalent later version rejected: ' . $candidate->id());
    check($candidate->transform($output, 'v99.0.0') === $output, 'official adapted source rejected: ' . $candidate->id());
    $marked = annotated($source);
    $markedOutput = $candidate->transform($marked, 'v99.0.0');
    token_get_all($markedOutput, TOKEN_PARSE);
    token_get_all($output, TOKEN_PARSE);
    check(substr_count($markedOutput, '/* preserved */') === substr_count($marked, '/* preserved */'), 'official comments lost: ' . $candidate->id());
    check($candidate->transform($markedOutput, 'v99.0.0') === $markedOutput, 'official annotated adaptation not idempotent: ' . $candidate->id());
    $adaptedSources[$path] = $output;
    echo 'PASS official ' . $candidate->id() . " canonical, later label, adapted and annotated forms\n";
}
foreach ($originals as $path => $source) {
    token_get_all($source, TOKEN_PARSE);
    // The upstream generator may include additional changes beyond these bounded rules.
}
echo sprintf("PASS 19 official source contracts (%.3fs)\n", microtime(true) - $start);

if (isset($argv[3], $argv[4])) {
    foreach (WebmanWorkermanRules::knownRules() as $candidate) {
        $worker = $candidate->dependency() === 'workerman/workerman';
        $packageRoot = $worker ? $argv[4] : $argv[3];
        $relative = substr($candidate->sourcePath(), strlen('vendor/' . $candidate->dependency() . '/'));
        $source = file_get_contents($packageRoot . '/' . $relative);
        check(is_string($source), 'alternate official source missing');
        $output = $candidate->transform($source, $worker ? 'v5.2.1' : 'v2.2.3');
        token_get_all($output, TOKEN_PARSE);
        check($candidate->transform($output, $worker ? 'v5.2.1' : 'v2.2.3') === $output, 'alternate official output is not idempotent');
    }
    echo "PASS actual official Webman 2.2.3 and Workerman 5.2.1 source shapes for all 19 rules\n";
}

$root = sys_get_temp_dir() . '/webman-aot-bounded-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/attempt-' . str_repeat('a', 24) . '/project';
mkdir($mirror, 0700, true);
try {
    foreach ($originals as $path => $source) {
        if (!is_dir(dirname($mirror . '/' . $path))) {
            mkdir(dirname($mirror . '/' . $path), 0700, true);
        }
        file_put_contents($mirror . '/' . $path, $source);
    }
    $rulesDirectory = $mirror . '/.webman-aot-builder/build/rules';
    mkdir($rulesDirectory, 0700, true);
    $versions = ['workerman/webman-framework' => 'v99.0.0', 'workerman/workerman' => 'v99.0.0'];
    $manifest = (new RuleEngine())->apply($mirror, $rulesDirectory, WebmanWorkermanRules::knownRules(), $versions);
    foreach ($manifest as $entry) {
        check(file_get_contents($entry['shadowPath']) === $adaptedSources[$entry['path']], 'RuleEngine output differs');
        check(file_get_contents($mirror . '/' . $entry['path']) === $originals[$entry['path']], 'RuleEngine changed vendor');
    }
    file_put_contents($mirror . '/vendor/workerman/webman-framework/src/App.php', str_replace('function getFallback(', 'function unknownFallback(', $originals['vendor/workerman/webman-framework/src/App.php']));
    rejects(fn () => (new RuleEngine())->apply($mirror, $rulesDirectory, WebmanWorkermanRules::knownRules(), $versions), 'source structure drift');
    file_put_contents($mirror . '/vendor/workerman/webman-framework/src/App.php', $originals['vendor/workerman/webman-framework/src/App.php']);

    $packages = [];
    foreach ($lock['packages'] as $name => $package) {
        $packages[] = ['name' => $name, 'version' => 'v99.0.0', 'source' => ['reference' => str_repeat('b', 40)]];
    }
    file_put_contents($mirror . '/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    $mappings = [];
    foreach ($originals as $path => $source) {
        $mappings[$path] = ['shadow' => $lock['mappings'][$path]['shadow'], 'sourceSha256' => hash('sha256', $source), 'shadowSha256' => hash('sha256', $adaptedSources[$path])];
    }
    $generate = static function () use ($mirror, $mappings, $adaptedSources): void {
        mkdir($mirror . '/.typephp/build', 0700, true);
        $sources = [];
        $ignored = [];
        foreach ($mappings as $path => $mapping) {
            file_put_contents($mirror . '/' . $mapping['shadow'], $adaptedSources[$path]);
            $sources[] = '  - ' . $mapping['shadow'];
            $ignored[] = '  - ' . $path;
        }
        file_put_contents($mirror . '/project.linux.yml', "sources:\n" . implode("\n", $sources) . "\nignore:\n" . implode("\n", $ignored) . "\n");
    };
    $boundary = new WebmanAotBuilder\Compatibility\UpstreamGeneratorBoundary();
    $actual = $boundary->run($mirror, __FILE__, hash_file('sha256', __FILE__), $lock['packages'], $mappings, $generate);
    check(count($actual) === count($mappings), 'boundary mapping count changed');
    foreach ($originals as $path => $source) {
        check(file_get_contents($mirror . '/' . $path) === $source, 'boundary changed vendor');
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mirror . '/.typephp', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($mirror . '/.typephp');
    $unknownPath = $mirror . '/vendor/workerman/webman-framework/src/App.php';
    file_put_contents($unknownPath, file_get_contents($unknownPath) . "\n// unknown input\n");
        $changedDuringGeneration = static function () use ($generate, $unknownPath): void {
        $generate();
        file_put_contents($unknownPath, file_get_contents($unknownPath) . "\n// generation drift\n");
    };
    rejects(fn () => $boundary->run($mirror, __FILE__, hash_file('sha256', __FILE__), $lock['packages'], $mappings, $changedDuringGeneration), 'upstream generator changed source');
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mirror . '/.typephp', FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($mirror . '/.typephp');
    file_put_contents($unknownPath, $originals['vendor/workerman/webman-framework/src/App.php']);
    $packages[] = $packages[0];
    file_put_contents($mirror . '/composer.lock', json_encode(['packages' => $packages], JSON_THROW_ON_ERROR));
    rejects(fn () => $boundary->run($mirror, __FILE__, hash_file('sha256', __FILE__), $lock['packages'], $mappings, $generate), 'duplicate package');
    echo "PASS RuleEngine source preservation/unknown rejection; full boundary alternate package labels, complete mappings/output/syntax guards and duplicate rejection\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
echo sprintf("PASS completed in %.3fs\n", microtime(true) - $start);
