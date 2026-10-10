<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\PluginSourceCompletion;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) { require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
});
function ensure(bool $condition, string $message): void { if (!$condition) { throw new RuntimeException($message); } }
function put(string $file, string $contents): void {
    if (!is_dir(dirname($file))) { mkdir(dirname($file), 0700, true); }
    ensure(file_put_contents($file, $contents) !== false, 'Fixture write failed');
}
$root = sys_get_temp_dir() . '/webman-aot-composer-sources-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/project';
$record = ['name' => 'example/library', 'version' => '1.0.0', 'require' => [], 'install-path' => '../example/library',
    'autoload' => ['psr-4' => ['Unlisted\\Namespace\\' => 'src/'], 'psr-0' => ['Legacy_' => 'legacy/'],
        'classmap' => ['Single.php', 'mapped/*/'], 'exclude-from-classmap' => ['/mapped/Tests/'], 'files' => ['functions.php']]];
$dev = ['name' => 'example/development', 'version' => '1.0.0', 'require' => [], 'install-path' => '../example/development', 'autoload' => ['psr-4' => ['Dev\\' => 'src/']]];
$metadata = ['packages' => [$record, $dev], 'dev-package-names' => [$dev['name']]];
$lock = ['packages' => [$record], 'packages-dev' => [$dev]];
$method = new ReflectionMethod(PluginSourceCompletion::class, 'replacementSources');
$rule = new PluginSourceCompletion();
$started = microtime(true);
try {
    foreach (['src/Added.php' => '<?php namespace Unlisted\\Namespace; class Added {}', 'src/Ignored.php' => '<?php namespace Unlisted\\Namespace; class Ignored {}',
        'src/Wrong.php' => '<?php namespace Wrong; class Different {}', 'src/Template.php' => '<div>template</div>', 'src/Anonymous.php' => '<?php new class {};',
        'legacy/Legacy/Item.php' => '<?php class Legacy_Item {}', 'Single.php' => '<?php class Single {}', 'mapped/Real/Included.php' => '<?php class Included {}',
        'mapped/Tests/Excluded.php' => '<?php class Excluded {}', 'functions.php' => '<?php function fixture_function() {}'] as $path => $source) {
        put($mirror . '/vendor/example/library/' . $path, $source);
    }
    put($mirror . '/vendor/example/development/src/Dev.php', '<?php // development');
    put($mirror . '/vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    put($mirror . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    $canonicalMirror = realpath($mirror);
    ensure(is_string($canonicalMirror), 'Fixture mirror cannot be resolved');
    $mirror = str_replace(DIRECTORY_SEPARATOR, '/', $canonicalMirror);
    $ignore = ['vendor/example/library/src/Ignored.php'];
    $selected = $method->invoke($rule, $mirror, [], ['.typephp/build/already.php'], $ignore);
    sort($selected, SORT_STRING);
    $expected = array_map(static fn(string $path): string => 'vendor/example/library/' . $path, ['Single.php', 'functions.php', 'legacy/Legacy/Item.php', 'mapped/Real/Included.php', 'src/Added.php']);
    sort($expected, SORT_STRING);
    ensure($selected === $expected, 'Production autoload selection/PSR-0/classmap exclusions/files differed');
    echo "PASS unlisted production namespaces, PSR-0, file and directory classmap, autoload files, dev and explicit ignore\n";
    ensure($method->invoke($rule, $mirror, [], $expected, $ignore) === [], 'Existing compiler files selected twice');
    echo "PASS existing compiler inputs are not duplicated\n";
    $coverageFile = $mirror . '/project.linux.yml';
    $coverageReader = new ReflectionMethod(\WebmanAotBuilder\Project\CompilerCoverageAudit::class, 'readCompilerLists');
    put($coverageFile, "sources:\n  - " . implode("\n  - ", $selected) . "\nignore:\n");
    ensure($coverageReader->invoke(new \WebmanAotBuilder\Project\CompilerCoverageAudit(), $coverageFile)['sources'] === $expected, 'Filesystem provider paths are not portable compiler inputs');
    echo "PASS native filesystem paths produce the same canonical PSR-4/PSR-0/classmap inputs\n";
    foreach (['vendor/example/library/src\\Added.php', '../outside.php', '/absolute.php', 'C:/escaped.php', './support/file.php'] as $unsafe) {
        put($coverageFile, "sources:\n  - {$unsafe}\nignore:\n");
        try { $coverageReader->invoke(new \WebmanAotBuilder\Project\CompilerCoverageAudit(), $coverageFile); throw new RuntimeException('Unsafe compiler input accepted'); }
        catch (ConfigurationException $error) { ensure($error->getMessage() === 'compiler coverage has unsafe sources entry', 'Unsafe compiler input gate changed'); }
    }
    echo "PASS raw unsafe compiler paths remain rejected\n";
    if (PHP_OS_FAMILY !== 'Windows') {
        $directory = $mirror . '/vendor/example/library/src';
        rename($directory, $directory . '-kept');
        symlink($directory . '-kept', $directory);
        try { $method->invoke($rule, $mirror, [], [], $ignore); throw new RuntimeException('Cached ancestor link accepted'); }
        catch (ConfigurationException $error) { ensure(str_contains($error->getMessage(), 'unsafe') || str_contains($error->getMessage(), 'symbolic'), 'Incorrect cached ancestor diagnostic'); }
        finally { unlink($directory); rename($directory . '-kept', $directory); }
        echo "PASS cached source plans revalidate selected directory ancestors\n";
    }
    $sourcePath = $mirror . '/vendor/example/library/src/Added.php';
    $original = file_get_contents($sourcePath);
    put($sourcePath, $original . "\n// changed");
    try { $method->invoke($rule, $mirror, [], [], $ignore); throw new RuntimeException('Cached source SHA drift accepted'); }
    catch (ConfigurationException $error) { ensure($error->getMessage() === 'production dependency source plan evidence drifted: vendor/example/library/src/Added.php', 'Incorrect source-plan SHA diagnostic'); }
    finally { put($sourcePath, $original); }
    echo "PASS cached source plans retain original file identity\n";
    $metadata['packages'][0]['version'] = '2.0.0';
    put($mirror . '/vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    try { $method->invoke($rule, $mirror, [], [], $ignore); throw new RuntimeException('Installed lock mismatch accepted'); }
    catch (ConfigurationException $error) { ensure(str_contains($error->getMessage(), 'identity differs'), 'Incorrect lock mismatch diagnostic'); }
    echo "PASS installed package identity must match production lock\n";
    $metadata['packages'][0] = $record;
    $metadata['packages'][0]['autoload']['psr-4']['Unlisted\\Namespace\\'] = '../../../outside';
    $lock['packages'][0] = $metadata['packages'][0];
    put($root . '/outside/Sentinel.php', '<?php // outside');
    put($mirror . '/vendor/composer/installed.json', json_encode($metadata, JSON_THROW_ON_ERROR));
    put($mirror . '/composer.lock', json_encode($lock, JSON_THROW_ON_ERROR));
    try { $method->invoke($rule, $mirror, [], [], $ignore); throw new RuntimeException('Escaping autoload path accepted'); }
    catch (ConfigurationException $error) { ensure(str_contains($error->getMessage(), 'escapes'), 'Incorrect path escape diagnostic'); }
    echo "PASS locked autoload declarations cannot escape their package root\n";
    echo sprintf("PASS Composer source completion in %.3fs\n", microtime(true) - $started);
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
