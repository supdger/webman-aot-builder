<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminGeneratorOverlay
{
    /**
     * @return array{path:string,sha256:string}
     */
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $expectedStubSha256,
        string $privateCache,
        ?string $mirror = null,
        array $carbonPolicy = [],
        bool $saiAdmin = true,
        array $targetCapabilities = []
    ): array {
        $source = is_file($sourceFile) && !is_link($sourceFile)
            ? file_get_contents($sourceFile)
            : false;
        if (!is_string($source)
            || !hash_equals($expectedSourceSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator source drifted');
        }
        $overlay = $source;
        if ($saiAdmin) {
            $before = "                \$sourceRel === 'plugin/saiadmin/exception/SystemException.php',\n";
            $groupEnd = <<<'PHP'
                    $sourceRel === 'vendor/webman/console/src/Application.php',
                        => 1,
    PHP;
            $after = <<<'PHP'
                    $sourceRel === 'vendor/webman/console/src/Application.php',
                        => 1,
                    $sourceRel === 'plugin/saiadmin/exception/SystemException.php'
                        => match (true) {
                            substr_count($content, ', Throwable $previous = null)') === 1
                                && substr_count($content, ', ?Throwable $previous = null)') === 0 => 1,
                            substr_count($content, ', Throwable $previous = null)') === 0
                                && substr_count($content, ', ?Throwable $previous = null)') === 1 => 0,
                            default => -1,
                        },
    PHP;
            if (substr_count($source, $before) !== 1 || substr_count($source, $groupEnd) !== 1) {
                throw new ConfigurationException('locked SaiAdmin exception rule structure drifted');
            }
            $overlay = str_replace($groupEnd, $after, str_replace($before, '', $source));
            if ($mirror !== null) {
                $overlay = $this->applyCarbonSourceRules($overlay, $mirror);
            }
        }
        $patchEntry = <<<'PHP'
    protected function patchSwitchTerminals(string $sourceRel, string $content): string
    {
PHP;
        $intervalPatch = <<<'PHP'
        if ($sourceRel === 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php') {
            $content = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule())->transform($content);
        }
PHP;
        if (substr_count($overlay, $patchEntry) !== 1) {
            throw new ConfigurationException('locked Carbon interval patch entry structure drifted');
        }
        $overlay = str_replace($patchEntry, $patchEntry . "\n" . $intervalPatch, $overlay);
        $start = strpos($overlay, "        if (\$sourceRel === 'vendor/illuminate/support/functions.php') {");
        $end = strpos($overlay, "        if (\$sourceRel === 'vendor/cakephp/core/functions.php') {");
        if ($start === false || $end === false || $end <= $start) {
            throw new ConfigurationException('locked Illuminate interval rule structure drifted');
        }
        $intervalRule = <<<'PHP'
        if ($sourceRel === 'vendor/illuminate/support/functions.php') {
            return (new \WebmanAotBuilder\Compatibility\IlluminateIntervalRule())->transform($content);
        }
PHP;
        $overlay = substr_replace($overlay, $intervalRule . "\n", $start, $end - $start);
        $variadicStart = strpos($overlay, '    protected function patchVariadicHandlers(string $sourceRel, string $content): string');
        $variadicEnd = strpos($overlay, "    /**\n     * 为含类型化引用捕获", $variadicStart ?: 0);
        if ($variadicStart === false || $variadicEnd === false) {
            throw new ConfigurationException('locked Workerman handler patch entry structure drifted');
        }
        $variadicMethod = <<<'PHP'
    protected function patchVariadicHandlers(string $sourceRel, string $content): string
    {
        return (new \WebmanAotBuilder\Compatibility\WorkermanGeneratorRule())->transform($sourceRel, $content);
    }

PHP;
        $overlay = substr_replace($overlay, $variadicMethod, $variadicStart, $variadicEnd - $variadicStart);
        foreach ([
            'patchRefCaptures' => ['REF_CAPTURE_REPLACEMENTS', ['vendor/workerman/webman-framework/src/Route/Route.php', 'vendor/workerman/coroutine/src/Barrier/Fiber.php', 'vendor/workerman/coroutine/src/Channel/Fiber.php']],
            'patchSwitchTerminals' => ['SWITCH_TERMINAL_REPLACEMENTS', ['vendor/workerman/webman-framework/src/App.php', 'vendor/workerman/webman-framework/src/Config.php', 'vendor/workerman/workerman/src/Protocols/Websocket.php', 'vendor/topthink/think-orm/src/model/Collection.php', 'vendor/vlucas/phpdotenv/src/Parser/EntryParser.php']],
        ] as $method => [$rules, $paths]) {
            $entry = "    protected function {$method}(string \$sourceRel, string \$content): string\n    {";
            if (substr_count($overlay, $entry) !== 1) {
                throw new ConfigurationException('locked upstream conversion entry structure drifted: ' . $method);
            }
            $pathList = var_export($paths, true);
            $contract = "\n        if (in_array(\$sourceRel, {$pathList}, true)) {\n"
                . "            return (new \\WebmanAotBuilder\\Compatibility\\UpstreamSourceRule())->applyIfPresent(\$sourceRel, \$content, self::{$rules}[\$sourceRel]);\n        }\n";
            $overlay = str_replace($entry, $entry . $contract, $overlay);
        }
        $compactStart = strpos($overlay, '    protected function expandIlluminateQueryBuilderCompactCalls(string $content): string');
        $compactEnd = strpos($overlay, '    private function stripSymfonyRequestPreloadHints', $compactStart ?: 0);
        if ($compactStart === false || $compactEnd === false) {
            throw new ConfigurationException('locked Illuminate compact conversion entry structure drifted');
        }
        $compactMethod = <<<'PHP'
    protected function expandIlluminateQueryBuilderCompactCalls(string $content): string
    {
        return (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->compactCalls($content);
    }

PHP;
        $overlay = substr_replace($overlay, $compactMethod, $compactStart, $compactEnd - $compactStart);
        $blueprint = "                \$sourceRel === 'vendor/illuminate/database/Schema/Blueprint.php'\n"
            . "                    && \$search === \"compact('autoIncrement', 'unsigned')\"\n"
            . "                    && str_contains(\$content, 'public function integer(')\n"
            . "                    => 5,";
        if (substr_count($overlay, $blueprint) !== 1) {
            throw new ConfigurationException('locked Blueprint compact conversion contract drifted');
        }
        $overlay = str_replace($blueprint, '', $overlay);
        $blueprintEntry = "        if (\$sourceRel === 'vendor/illuminate/database/Query/Builder.php') {";
        $blueprintRule = <<<'PHP'
        if ($sourceRel === 'vendor/illuminate/database/Schema/Blueprint.php') {
            $content = (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->compactCalls($content);
        }
PHP;
        if (substr_count($overlay, $blueprintEntry) !== 1) {
            throw new ConfigurationException('locked Blueprint conversion entry drifted');
        }
        $overlay = str_replace($blueprintEntry, $blueprintRule . "\n" . $blueprintEntry, $overlay);
        $guardedEntry = "    protected function prepareGuardedSource(string \$sourceRel, string \$content): string\n    {";
        $intlRule = <<<'PHP'
        if (in_array($sourceRel, ['vendor/symfony/polyfill-intl-grapheme/bootstrap80.php', 'vendor/symfony/polyfill-intl-idn/bootstrap80.php'], true)) {
            return (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->prepareIntl($sourceRel, $content);
        }
PHP;
        if (substr_count($overlay, $guardedEntry) !== 1) {
            throw new ConfigurationException('locked Intl guarded conversion entry structure drifted');
        }
        $overlay = str_replace($guardedEntry, $guardedEntry . "\n" . $intlRule, $overlay);
        $overlay = $this->applySourceContracts($overlay);
        if ($mirror !== null) { $overlay = $this->discoverGuardedFamilies($overlay, $mirror, $targetCapabilities); }
        $guardedEntry = "    protected function prepareGuardedSource(string \$sourceRel, string \$content): string\n    {";
        $phpTarget = var_export($targetCapabilities['phpVersionId'] ?? null, true);
        $redisTarget = var_export($targetCapabilities['redisVersion'] ?? null, true);
        $runtimeTarget = var_export($targetCapabilities, true);
        $helperContract = "        if ((\$prepared = (new \\WebmanAotBuilder\\Compatibility\\GuardedHelperSourceRule({$phpTarget}, {$redisTarget}, {$runtimeTarget}, \$this->basePath))->prepare(\$sourceRel, \$content)) !== null) {\n            return \$prepared;\n        }";
        if (substr_count($overlay, $guardedEntry) !== 1) { throw new ConfigurationException('locked helper contract entry is missing'); }
        $overlay = str_replace($guardedEntry, $guardedEntry . "\n" . $helperContract, $overlay);
        $digest = hash('sha256', $overlay);
        $stubSource = dirname($sourceFile, 2) . '/Stubs/main.php.stub';
        $stub = is_file($stubSource) && !is_link($stubSource)
            ? file_get_contents($stubSource)
            : false;
        if (!is_string($stub)
            || !hash_equals($expectedStubSha256, hash('sha256', $stub))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator stub drifted');
        }
        $directory = $privateCache . '/saiadmin-generator-overlays/' . $digest . '/src';
        $compilerDirectory = $directory . '/Compiler';
        $stubDirectory = $directory . '/Stubs';
        if ((!is_dir($compilerDirectory) && !mkdir($compilerDirectory, 0700, true)
                && !is_dir($compilerDirectory))
            || (!is_dir($stubDirectory) && !mkdir($stubDirectory, 0700, true)
                && !is_dir($stubDirectory))
        ) {
            throw new ConfigurationException('cannot create private SaiAdmin generator overlay directory');
        }
        $stubTarget = $stubDirectory . '/main.php.stub';
        if (is_file($stubTarget)) {
            if (is_link($stubTarget) || hash_file('sha256', $stubTarget) !== $expectedStubSha256) {
                throw new ConfigurationException('private SaiAdmin generator stub drifted');
            }
        } elseif (file_put_contents($stubTarget, $stub, LOCK_EX) !== strlen($stub)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator stub');
        }
        $target = $compilerDirectory . '/ProjectGenerator.php';
        if (is_file($target)) {
            if (is_link($target) || hash_file('sha256', $target) !== $digest) {
                throw new ConfigurationException('private SaiAdmin generator overlay drifted');
            }
        } elseif (file_put_contents($target, $overlay, LOCK_EX) !== strlen($overlay)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator overlay');
        }
        return ['path' => $target, 'sha256' => $digest];
    }
    private function discoverGuardedFamilies(string $source, string $mirror, array $targetCapabilities): string
    {
        [$source, $autoloadSelected] = $this->discoverAutoloadHelpers($source, $mirror, $targetCapabilities);
        $selected = $autoloadSelected;
        $families = ['vendor/symfony/cache/Traits' => '#^Redis(?:Cluster)?[0-9]+ProxyTrait\\.php$#D'];
        foreach (glob($mirror . '/vendor/symfony/polyfill-php*', GLOB_ONLYDIR) ?: [] as $polyfill) {
            if (preg_match('#^polyfill-php[0-9]+$#D', basename($polyfill)) === 1) {
                if (is_link($polyfill)) { throw new ConfigurationException('guarded source family directory is unsafe'); }
                $families['vendor/symfony/' . basename($polyfill) . '/Resources/stubs'] = '#\\.php$#D';
            }
        }
        foreach (array_keys($families) as $root) {
            if (!str_starts_with($root, 'vendor/symfony/polyfill-php')) { continue; }
            $bootstrap = substr($root, 0, -strlen('/Resources/stubs')) . '/bootstrap.php';
            $file = $mirror . '/' . $bootstrap;
            if (!is_file($file) || str_contains($source, var_export($bootstrap, true) . ' =>')) { continue; }
            $manifestFile = dirname($file) . '/composer.json';
            $autoloadFile = $mirror . '/vendor/composer/autoload_files.php';
            if (!is_file($manifestFile) || is_link($manifestFile) || !is_file($autoloadFile) || is_link($autoloadFile)) { continue; }
            $manifest = json_decode((string) file_get_contents($manifestFile), true);
            $autoload = file_get_contents($autoloadFile);
            if (!in_array('bootstrap.php', $manifest['autoload']['files'] ?? [], true)
                || !is_string($autoload) || !str_contains($autoload, var_export('/' . substr($bootstrap, strlen('vendor/')), true))
            ) { continue; }
            if (is_link($file)) { throw new ConfigurationException('guarded source family contains a link'); }
            $contents = file_get_contents($file);
            if (!is_string($contents)) { throw new ConfigurationException('Unable to read guarded source family'); }
            $prepared = (new GuardedHelperSourceRule($targetCapabilities['phpVersionId'] ?? null, $targetCapabilities['redisVersion'] ?? null, $targetCapabilities, $mirror))->prepare($bootstrap, $contents);
            if ($prepared === $contents || str_contains($source, var_export($bootstrap, true) . ' =>')) { continue; }
            $target = '.typephp/build/symfony-family-' . basename(dirname($file)) . '-bootstrap.php';
            $selected[$target] = '        ' . var_export($bootstrap, true) . ' => ' . var_export($target, true) . ",\n";
            if (preg_match("#return\\s+require\\s+__DIR__\\s*\\.\\s*['\"](/bootstrap[0-9]+\\.php)['\"]#", $contents, $delegation) === 1) {
                $companion = dirname($bootstrap) . $delegation[1];
                $companionFile = $mirror . '/' . $companion;
                if (is_file($companionFile) && !is_link($companionFile)) {
                    $companionSource = file_get_contents($companionFile);
                    if (!is_string($companionSource)) { throw new ConfigurationException('Unable to read PHP polyfill bootstrap companion'); }
                    $companionPrepared = (new GuardedHelperSourceRule($targetCapabilities['phpVersionId'] ?? null, null, $targetCapabilities, $mirror))->prepare($companion, $companionSource);
                    if ($companionPrepared !== $companionSource) {
                        $companionTarget = '.typephp/build/symfony-family-' . basename(dirname($companionFile)) . '-' . basename($companionFile);
                        $selected[$companionTarget] = '        ' . var_export($companion, true) . ' => ' . var_export($companionTarget, true) . ",\n";
                    }
                }
            }
        }
        foreach ($families as $root => $pattern) {
            $directory = $mirror . '/' . $root;
            if (!file_exists($directory)) { continue; }
            if (!is_dir($directory) || is_link($directory)) { throw new ConfigurationException('guarded source family directory is unsafe'); }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isLink()) { throw new ConfigurationException('guarded source family contains a link'); }
                if (!$file->isFile() || preg_match($pattern, $file->getFilename()) !== 1) { continue; }
                $path = $root . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1));
                if (str_contains($source, var_export($path, true) . ' =>')) { continue; }
                if (str_starts_with($root, 'vendor/symfony/polyfill-php')) {
                    $contents = file_get_contents($file->getPathname());
                    if (!is_string($contents)) { throw new ConfigurationException('Unable to read guarded source family'); }
                    $prepared = (new GuardedHelperSourceRule($targetCapabilities['phpVersionId'] ?? null, $targetCapabilities['redisVersion'] ?? null, $targetCapabilities, $mirror))->prepare($path, $contents);
                    if ($prepared === $contents) { continue; }
                }
                $relative = substr($path, strlen('vendor/symfony/'));
                $target = '.typephp/build/symfony-family-' . preg_replace('/[^A-Za-z0-9-]/', '-', substr($relative, 0, -4)) . '.php';
                if (isset($selected[$target])) { throw new ConfigurationException('guarded source family targets collide'); }
                $selected[$target] = '        ' . var_export($path, true) . ' => ' . var_export($target, true) . ",\n";
            }
        }
        if ($selected === []) { return $source; }
        ksort($selected, SORT_STRING);
        $anchor = "    public const GUARDED_SOURCES = [\n";
        if (substr_count($source, $anchor) !== 1) { throw new ConfigurationException('locked guarded family declaration is missing'); }
        return str_replace($anchor, $anchor . implode('', $selected), $source);
    }

    private function composerAutoloadEntries(string $source): array
    {
        $tokens = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            $tokens[] = is_array($token) ? $token[1] : $token;
        }
        if (implode('', array_slice($tokens, 0, 17)) !== '$vendorDir=dirname(__DIR__);$baseDir=dirname($vendorDir);returnarray(') { return []; }
        $entries = [];
        $index = 17;
        while (isset($tokens[$index]) && $tokens[$index] !== ')') {
            if (preg_match("/^'[a-f0-9]{32}'$/D", $tokens[$index]) !== 1 || ($tokens[$index + 1] ?? null) !== '=>'
                || !in_array($tokens[$index + 2] ?? null, ['$vendorDir', '$baseDir'], true)
                || ($tokens[$index + 3] ?? null) !== '.'
                || preg_match("~^'(/[A-Za-z0-9_./-]+)'$~D", $tokens[$index + 4] ?? '', $path) !== 1
                || ($tokens[$index + 5] ?? null) !== ','
            ) { return []; }
            $entries[] = [null, substr($tokens[$index + 2], 1), $path[1]];
            $index += 6;
        }
        return array_slice($tokens, $index) === [')', ';'] ? $entries : [];
    }

    private function discoverAutoloadHelpers(string $source, string $mirror, array $capabilities): array
    {
        $autoloadFile = $mirror . '/vendor/composer/autoload_files.php';
        if (!is_file($autoloadFile) || is_link($autoloadFile)) { return [$source, []]; }
        $autoload = file_get_contents($autoloadFile);
        if (!is_string($autoload)) { throw new ConfigurationException('cannot read Composer helper autoload order'); }
        $entries = $this->composerAutoloadEntries($autoload);
        $providers = [];
        $selected = [];
        $helper = new GuardedHelperSourceRule($capabilities['phpVersionId'] ?? null, $capabilities['redisVersion'] ?? null, $capabilities, $mirror);
        foreach ($entries as $entry) {
            $path = ($entry[1] === 'vendorDir' ? 'vendor/' : '') . ltrim($entry[2], '/');
            if (str_contains('/' . $path . '/', '/../') || str_contains('/' . $path . '/', '/./')) { continue; }
            $file = $mirror . '/' . $path;
            $resolved = realpath($file);
            if (!is_string($resolved) || !is_file($resolved) || !str_starts_with($resolved, $mirror . '/')) { continue; }
            $cursor = $mirror;
            foreach (explode('/', $path) as $part) { $cursor .= '/' . $part; if (is_link($cursor)) { throw new ConfigurationException('Composer helper autoload source is unsafe'); } }
            $contents = file_get_contents($file);
            if (!is_string($contents)) { throw new ConfigurationException('cannot read Composer helper autoload source'); }
            $prepared = $helper->prepareAutoloadFunctions($contents, $providers);
            if ($prepared !== null && !str_contains($source, var_export($path, true) . ' =>')) {
                $target = '.typephp/build/composer-helper-' . hash('sha256', $path) . '.php';
                $selected[$target] = '        ' . var_export($path, true) . ' => ' . var_export($target, true) . ",\n";
                $entryPoint = "    protected function prepareGuardedSource(string \$sourceRel, string \$content): string\n    {";
                $contract = "\n        if (\$sourceRel === " . var_export($path, true) . ") {\n"
                    . "            if (hash('sha256', \$content) !== '" . hash('sha256', $contents) . "') { throw new \\WebmanAotBuilder\\Cli\\ConfigurationException('Composer helper source evidence drifted'); }\n"
                    . '            return ' . var_export($prepared['source'], true) . ";\n        }";
                if (substr_count($source, $entryPoint) !== 1) { throw new ConfigurationException('locked helper preparation entry is missing'); }
                $source = str_replace($entryPoint, $entryPoint . $contract, $source);
                foreach ($prepared['functions'] as $name) { $providers[$name] = true; }
            } else {
                $tokens = token_get_all($contents);
                $namespace = '';
                $depth = 0;
                foreach ($tokens as $index => $token) {
                    if ($token === '{') { ++$depth; continue; }
                    if ($token === '}') { --$depth; continue; }
                    if (!is_array($token)) { continue; }
                    if ($token[0] === T_NAMESPACE) {
                        $namespace = '';
                        for (++$index; isset($tokens[$index]); ++$index) {
                            $next = $tokens[$index];
                            if ($next === ';' || $next === '{') { break; }
                            if (is_array($next) && in_array($next[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) { $namespace .= $next[1]; }
                        }
                    } elseif ($token[0] === T_FUNCTION) {
                        for (++$index; isset($tokens[$index]); ++$index) {
                            $next = $tokens[$index];
                            if (is_array($next) && in_array($next[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                            if (is_array($next) && $next[0] === T_STRING) {
                                $name = strtolower(($namespace === '' ? '' : $namespace . '\\') . $next[1]);
                                if (!array_key_exists($name, $providers)) { $providers[$name] = $depth === 0 ? true : null; }
                            }
                            break;
                        }
                    }
                }
            }
        }
        return [$source, $selected];
    }

    private function applySourceContracts(string $overlay): string
    {
        foreach ([
            'patchRefCaptures' => <<<'BODY'
        return (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->applyIfPresent($sourceRel, $content, self::REF_CAPTURE_REPLACEMENTS[$sourceRel] ?? []);
BODY,
            'patchSwitchTerminals' => <<<'BODY'
        $originalContent = $content;
        if ($sourceRel === 'plugin/saiadmin/app/cache/UserInfoCache.php') {
            return (new \WebmanAotBuilder\Compatibility\GeneratorRuntimeRule())->cacheTags($content);
        }
        if ($sourceRel === 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php') {
            $content = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule())->transform($content);
        }
        if ($sourceRel === 'vendor/illuminate/database/Query/Builder.php' || $sourceRel === 'vendor/illuminate/database/Schema/Blueprint.php') {
            $content = (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->compactCalls($content);
        }
        if ($sourceRel === 'vendor/symfony/http-foundation/Request.php') {
            $content = (new \WebmanAotBuilder\Compatibility\GeneratorRuntimeRule())->stripPreload($content);
        }
        if ($sourceRel === 'plugin/saiadmin/utils/code/CodeEngine.php') {
            $content = (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->applyIfPresent($sourceRel, $content, ["defined('DS') or define('DS', DIRECTORY_SEPARATOR);" => 'const DS = DIRECTORY_SEPARATOR;']);
        }
        $rules = self::SWITCH_TERMINAL_REPLACEMENTS[$sourceRel] ?? [];
        foreach ($rules as $search => &$replacement) {
            if ($search === '    public function toArray()'
                && $replacement === '    public function toArray(): array') {
                unset($rules[$search]);
                continue;
            }
            if (((str_contains($search, '->bindTo(') || str_contains($search, 'Closure::bind('))
                    && str_contains($replacement, 'closure')
                    && str_contains($replacement, 'binding is not supported.'))
                || $search === 'return \call_user_func_array($boundMacro ?: $macro, $parameters);'
            ) {
                unset($rules[$search]);
                continue;
            }
            if ($sourceRel === 'vendor/illuminate/database/Schema/Blueprint.php' && str_starts_with(trim($search), 'compact(')) { unset($rules[$search]); }
            if ($sourceRel === 'plugin/saiadmin/app/cache/ReflectionCache.php') {
                $replacement = str_replace(['__SAIADMIN_LOGIN_NO_NEED_LOGIN__', '__SAIADMIN_INSTALL_NO_NEED_LOGIN__'], [$this->readSaiAdminNoNeedLogin('LoginController.php'), $this->readSaiAdminNoNeedLogin('InstallController.php')], $replacement);
            }
        }
        unset($replacement);
        $prepared = (new \WebmanAotBuilder\Compatibility\UpstreamSourceRule())->applyIfPresent($sourceRel, $content, $rules, $applicable);
        return $applicable ? $prepared : $originalContent;
BODY,
            'stripStrayBootstrapCalls' => '        return (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->stripBootstrap($content);',
            'patchCoroutineFiberContextWrites' => '        return (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->contextWrites($content);',
            'patchUninitializedScalarStatics' => '        return (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->nullableStatics($content);',
            'stripSymfonyRequestPreloadHints' => '        return (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->stripPreload($content);',
            'patchSaiAdminUserInfoCache' => '        return (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->cacheTags($content);',
        ] as $method => $body) {
            $overlay = $this->replaceMethodBody($overlay, $method, $body);
        }
        foreach (['patchSymfonyNativeSessionStorage', 'patchSymfonyHttpKernelEvents'] as $method) {
            [$start, $end] = $this->methodBody($overlay, $method);
            $body = substr($overlay, $start, $end - $start);
            $loop = strpos($body, '        foreach ($replacements as $search => $replacement) {');
            if ($loop === false) { throw new ConfigurationException('locked Symfony local conversion table drifted'); }
            $body = substr($body, 0, $loop) . '        return (new \\WebmanAotBuilder\\Compatibility\\UpstreamSourceRule())->applyIfPresent(' . var_export($method, true) . ', $content, $replacements);' . "\n    ";
            $overlay = substr_replace($overlay, $body, $start, $end - $start);
        }
        $start = strpos($overlay, "            \$content = (string) preg_replace('/^class_exists");
        $end = strpos($overlay, "            if (\$sourceRel === 'vendor/symfony/http-kernel/HttpKernel.php') {", $start === false ? 0 : $start);
        if ($start === false || $end === false) { throw new ConfigurationException('locked preload generation entry drifted'); }
        $overlay = substr_replace($overlay, "            \$content = (new \\WebmanAotBuilder\\Compatibility\\GeneratorRuntimeRule())->stripPreload(\$content);\n", $start, $end - $start);
        $start = strpos($overlay, "            if (\$sourceRel === 'vendor/workerman/coroutine/src/Barrier/Swoole.php') {");
        $end = strpos($overlay, "            \$targetFile = \$this->basePath . '/' . \$targetRel;", $start === false ? 0 : $start);
        if ($start === false || $end === false) { throw new ConfigurationException('locked Swoole source generation entry drifted'); }
        $overlay = substr_replace($overlay, '', $start, $end - $start);
        return $overlay;
    }

    private function replaceMethodBody(string $source, string $method, string $replacement): string
    {
        [$start, $end] = $this->methodBody($source, $method);
        return substr_replace($source, "\n" . $replacement . "\n    ", $start, $end - $start);
    }

    /** @return array{int,int} Verified generator methods, not user dependency identity. */
    private function methodBody(string $source, string $method): array
    {
        $tokens = token_get_all($source);
        $offset = 0;
        $found = false;
        $body = null;
        $depth = 0;
        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_FUNCTION) {
                for ($next = $index + 1; isset($tokens[$next]) && is_array($tokens[$next]) && $tokens[$next][0] === T_WHITESPACE; ++$next) {}
                $found = is_array($tokens[$next] ?? null) && $tokens[$next][0] === T_STRING && $tokens[$next][1] === $method;
            }
            if ($found && $token === '{') {
                $body = $offset + 1;
                $depth = 1;
                $found = false;
            } elseif ($body !== null) {
                if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { ++$depth; }
                elseif ($token === '}' && --$depth === 0) { return [$body, $offset]; }
            }
            $offset += strlen($text);
        }
        throw new ConfigurationException('locked generator method is missing or unbalanced: ' . $method);
    }

    private function applyCarbonSourceRules(string $generator, string $mirror): string
    {
        $sourcePath = 'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php';
        $period = $this->readCarbonSource($mirror . '/' . $sourcePath);
        $interval = $this->readCarbonSource($mirror . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php');
        (new SaiAdminCarbonPeriodRule())->replacements($period, $interval);
        (new SaiAdminCarbonIntervalRule())->transform($interval);
        $anchor = "        '{$sourcePath}' => [\n";
        $start = strpos($generator, $anchor);
        $end = strpos($generator, "        'vendor/illuminate/database/Eloquent/Casts/ArrayObject.php' => [", $start ?: 0);
        if ($start === false || $end === false || $end <= $start) {
            throw new ConfigurationException('Carbon generator mapping structure drifted');
        }
        $mapping = substr($generator, $start, $end - $start);
        $oldIntervalRules = <<<'PHP'
            '\Carbon\CarbonInterval::day()' => 'new \Carbon\CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::day()' => 'new CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::month()' => 'new CarbonInterval(0, 1)',
PHP;
        if (substr_count($mapping, $oldIntervalRules) !== 1) {
            throw new ConfigurationException('locked Carbon interval mapping structure drifted');
        }
        $generator = substr_replace($generator, str_replace($oldIntervalRules . "\n", '', $mapping), $start, $end - $start);
        $method = <<<'PHP'
    protected function patchSwitchTerminals(string $sourceRel, string $content): string
    {
PHP;
        $rule = <<<'PHP'
        if ($sourceRel === 'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php') {
            $intervalPath = $this->basePath . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php';
            $interval = is_file($intervalPath) && !is_link($intervalPath) ? file_get_contents($intervalPath) : false;
            if (!is_string($interval)) {
                throw new \WebmanAotBuilder\Cli\ConfigurationException('CarbonInterval compatibility source is missing');
            }
            $content = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonPeriodRule())->transform($content, $interval);
        }
PHP;
        if (substr_count($generator, $method) !== 1) {
            throw new ConfigurationException('locked Carbon patch entry structure drifted');
        }
        return str_replace($method, $method . "\n" . $rule, $generator);
    }

    private function readCarbonSource(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException('Carbon compatibility source is missing: ' . $path);
        }
        return $source;
    }
}
