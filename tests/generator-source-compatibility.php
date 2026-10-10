<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\GeneratorRuntimeRule;
use WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay;
use WebmanAotBuilder\Compatibility\UpstreamGeneratorArchive;
use WebmanAotBuilder\Compatibility\UpstreamSourceRule;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
function tokens(string $source): array
{
    return array_map(static fn ($token) => is_array($token) ? array_slice($token, 0, 2) : $token, array_values(array_filter(token_get_all($source), static fn ($token): bool => !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true))));
}
function commented(string $source): string
{
    $offset = 0; $edits = [];
    foreach (token_get_all($source) as $token) {
        $id = is_array($token) ? $token[0] : $token;
        $text = is_array($token) ? $token[1] : $token;
        $offset += strlen($text);
        if ($id === T_WHITESPACE) { $edits[] = $offset; }
    }
    foreach (array_reverse($edits) as $at) { $source = substr_replace($source, ' /* source contract trivia */ ', $at, 0); }
    return $source;
}
function rejects(Closure $run, string $marker): void
{
    try { $run(); } catch (ConfigurationException $error) { ensure(str_contains($error->getMessage(), $marker), $error->getMessage()); return; }
    throw new RuntimeException('unsafe source was accepted: ' . $marker);
}
$archive = $argv[1] ?? '';
$project = $argv[2] ?? '';
$sdk = $argv[3] ?? '';
$targetCapabilities = (new \WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdk);
$lock = json_decode(file_get_contents(dirname(__DIR__) . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
$root = sys_get_temp_dir() . '/webman-aot-source-rules-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
try {
    echo "STAGE materialize verified generator and source-contract overlay\n";
    $generatorRoot = (new UpstreamGeneratorArchive())->materialize($archive, $lock['generator'], $root);
    $sourceFile = $generatorRoot . '/' . $lock['generator']['sourcePath'];
    require $generatorRoot . '/src/Compiler/Profile/SaiAdminProfile.php';
    $overlay = (new SaiAdminGeneratorOverlay())->prepare($sourceFile, $lock['generator']['sourceSha256'], $lock['generator']['mainStubSha256'], $root, $project, [], false, $targetCapabilities);
    require $overlay['path'];
    $generator = new Tinywan\Typephp\Compiler\ProjectGenerator($project);
    $class = new ReflectionClass($generator);
    echo "STAGE compiler-supported closure binding passes through unchanged\n";
    $bindingTransform = new ReflectionMethod($generator, 'patchSwitchTerminals');
    $bindingRules = 0;
    foreach ($class->getConstant('SWITCH_TERMINAL_REPLACEMENTS') as $path => $replacements) {
        foreach ($replacements as $search => $replacement) {
            if (!(str_contains($search, '->bindTo(') || str_contains($search, 'Closure::bind('))
                || !str_contains($replacement, 'binding is not supported.')
            ) { continue; }
            $base = str_contains($path, '/Carbon/') ? file_get_contents($project . '/' . $path) : '<?php ';
            $input = $base . ' class BindingSource { public function unchanged($callback, $macro, $driver, $name) {' . $search
                . '} public function additional($callback) { return $callback->bindTo($this, static::class); } }';
            $output = $bindingTransform->invoke($generator, $path, $input);
            ensure(str_contains($output, $search) && str_contains($output, 'public function additional($callback) { return $callback->bindTo($this, static::class); }'), 'compiler-supported binding was downgraded: ' . $path);
            ++$bindingRules;
        }
    }
    ensure($bindingRules === 4, 'locked obsolete binding family changed');
    $carbonCall = '<?php return \\call_user_func_array($boundMacro ?: $macro, $parameters);';
    foreach ($class->getConstant('SWITCH_TERMINAL_REPLACEMENTS') as $path => $replacements) {
        if (!isset($replacements['return \\call_user_func_array($boundMacro ?: $macro, $parameters);'])) { continue; }
        $input = file_get_contents($project . '/' . $path) . ' function boundCall($boundMacro, $macro, $parameters) {' . substr($carbonCall, 6) . '}';
        ensure(str_contains($bindingTransform->invoke($generator, $path, $input), substr($carbonCall, 6)), 'bound Carbon macro was discarded');
    }
    echo "PASS four binding downgrades and bound macro invocation preserve input\n";
    echo "STAGE optional project conversion preserves whole input on partial matches\n";
    $optional = new UpstreamSourceRule();
    $partial = '<?php $before = 1; $newerSource = 2;';
    $pair = ['$before = 1;' => '$after = 1;', '$olderSource = 2;' => '$prepared = 2;'];
    ensure($optional->applyIfPresent('project.php', $partial, $pair, $applicable) === $partial && !$applicable, 'partial conversion escaped original-input rollback');
    rejects(fn () => $optional->replace('project.php', $partial, $pair), 'structure drift');
    $known = '<?php $before = 1; $olderSource = 2;';
    $prepared = $optional->replace('project.php', $known, $pair);
    ensure($optional->applyIfPresent('project.php', $known, $pair) === $prepared, 'known optional conversion differs from strict conversion');
    ensure($optional->applyIfPresent('project.php', $prepared, $pair) === $prepared, 'optional conversion is not idempotent');
    rejects(fn () => $optional->applyIfPresent('project.php', $known, ['' => '$invalid = 1;']), 'no executable source contract');
    $strPath = 'vendor/illuminate/support/Str.php';
    $strSource = file_get_contents($project . '/' . $strPath);
    ensure($bindingTransform->invoke($generator, $strPath, $strSource) === $strSource, 'new Str source was partially renamed');
    $intervalPath = 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php';
    $intervalSource = str_replace('CarbonInterface::ONE_DAY_WORDS', 'CarbonInterface::NEW_DAY_WORDS', file_get_contents($project . '/' . $intervalPath));
    $intervalPrepared = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule())->transform($intervalSource);
    ensure($intervalPrepared !== $intervalSource, 'fixture did not exercise the Carbon pre-step');
    ensure($bindingTransform->invoke($generator, $intervalPath, $intervalSource) === $intervalSource, 'table fallback retained a partial Carbon pre-step');
    echo "PASS partial / known / adapted / strict-error / original Str contracts\n";
    $files = 0; $rules = 0;
    foreach (['patchSwitchTerminals' => 'SWITCH_TERMINAL_REPLACEMENTS', 'patchRefCaptures' => 'REF_CAPTURE_REPLACEMENTS'] as $method => $table) {
        echo "STAGE {$table} complete installed source matrix\n";
        $transform = new ReflectionMethod($generator, $method);
        foreach ($class->getConstant($table) as $path => $replacements) {
            if (!is_file($project . '/' . $path)) { continue; }
            $source = file_get_contents($project . '/' . $path);
            $variant = commented($source);
            ensure(tokens($source) === tokens($variant), 'fixture changed effective input tokens: ' . $path);
            $actual = $transform->invoke($generator, $path, $source);
            $equivalent = $transform->invoke($generator, $path, $variant);
            ensure(tokens($actual) === tokens($equivalent), 'equivalent input conversion differs: ' . $path);
            token_get_all($actual, TOKEN_PARSE);
            token_get_all($equivalent, TOKEN_PARSE);
            ++$files; $rules += count($replacements);
            echo "PASS {$path} (" . count($replacements) . " declared transforms)\n";
        }
    }
    echo "STAGE preload and target-runtime structural contracts\n";
    $runtime = new GeneratorRuntimeRule();
    foreach ($class->getConstant('PRELOAD_HINT_SOURCES') as $path => $target) {
        if (!is_file($project . '/' . $path)) { continue; }
        $source = file_get_contents($project . '/' . $path);
        ensure(tokens($runtime->stripPreload($source)) === tokens($runtime->stripPreload(commented($source))), 'preload trivia differs: ' . $path);
    }
    $preloads = '<?php namespace Example; class_exists(A::class); class_exists(B::class); class Consumer { public function test(){ class_exists(C::class); } }';
    $output = $runtime->stripPreload($preloads);
    ensure(!str_contains($output, 'A::class') && !str_contains($output, 'B::class') && str_contains($output, 'C::class'), 'preload scope or discovery cardinality drifted');
    rejects(fn () => $runtime->stripPreload('<?php class_exists($computed);'), 'standalone');
    rejects(fn () => $runtime->stripPreload('<?php $used = class_exists(A::class);'), 'standalone');
    rejects(fn () => $runtime->stripBootstrap('<?php namespace Workerman\Coroutine\Context; class Fiber { static function initDriver() {} } Fiber::initDriver();'), 'no equivalent main');
    rejects(fn () => $runtime->stripBootstrap('<?php namespace Workerman; class Coroutine { static function init() {} } Coroutine::init(); Coroutine::init();'), 'duplicate');
    $tags = '<?php class CacheInput { function clear($ids, $cache) { $aotCacheTag_ = "saved"; $aotCacheTag = "saved"; if(is_array($ids)) { $tags=[]; foreach($ids as $id) { $tags[]=$cache["key"].$id; } } else { $tags=$cache["key"].$ids; } return Cache::tag($tags)->clear(); } }';
    $adaptedTags = $runtime->cacheTags($tags);
    ensure(str_contains($adaptedTags, '$aotCacheTag__ ='), 'cache tag target collided with an existing local');
    foreach (['vendor/symfony/console/Helper/QuestionHelper.php' => 'extraRead', 'vendor/symfony/http-kernel/Controller/ControllerResolver.php' => 'extraController'] as $path => $method) {
        if (!is_file($project . '/' . $path)) { continue; }
        $source = file_get_contents($project . '/' . $path);
        $extra = $method === 'extraRead' ? 'public function extraRead($inputStream, $question) { $ret = $this->readInput($inputStream, $question); return $ret; }' : 'public function extraController($controller) { $controller = $this->instantiateController($controller); return $controller; }';
        $source = substr_replace($source, $extra, strrpos($source, '}'), 0);
        $transformed = (new ReflectionMethod($generator, 'patchSwitchTerminals'))->invoke($generator, $path, $source);
        ensure(str_contains($transformed, $extra), 'new unrelated method was changed: ' . $method);
    }
    echo "PASS complete installed {$files} source domains / {$rules} declared transforms, trivia variants, preload scope and unsafe source rejection in " . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
