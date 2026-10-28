<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

/** Select helper declarations and runtime branches for the approved static target. */
final class GuardedHelperSourceRule
{
    public function __construct(private ?int $phpVersionId = null, private ?string $redisVersion = null, private array $runtimeCapabilities = [], private ?string $mirror = null) {}

    public function prepare(string $path, string $source): ?string
    {
        return match (true) {
            $path === 'vendor/nikic/fast-route/src/functions.php' => $this->routeOptions($source),
            $path === 'vendor/cakephp/core/functions.php' => $this->cakeConstants($source),
            $path === 'vendor/illuminate/reflection/helpers.php' => $this->reflectionProxy($source),
            $path === 'vendor/illuminate/support/helpers.php' => $this->pregReplaceArray($source),
            $path === 'vendor/symfony/var-dumper/Resources/functions/dump.php' => $this->dumpReturn($source),
            in_array($path, ['vendor/zoujingli/ip2region/function.php', 'vendor/zoujingli/ip2region/src/common.php'], true) => $this->ipLoader($path, $source),
            preg_match('#^vendor/symfony/cache/Traits/Redis(?:Cluster)?[0-9]+ProxyTrait\.php$#D', $path) === 1 => $this->runtimeBranches($source, 'redis'),
            $path === 'vendor/symfony/polyfill-php85/bootstrap.php' => $this->phpBootstrap($source),
            preg_match('#^vendor/symfony/polyfill-php[0-9]+/bootstrap(?:[0-9]+)?\.php$#D', $path) === 1 => $this->phpBootstrapCutoff($path, $source),
            preg_match('#^vendor/symfony/polyfill-php[0-9]+/Resources/stubs/.+\.php$#D', $path) === 1 => $this->runtimeBranches($source, 'php', true),
            default => null,
        };
    }

    /** Prepare pure self-guarded declarations in Composer file-autoload order. */
    public function prepareAutoloadFunctions(string $source, array $providers): ?array
    {
        if (($this->runtimeCapabilities['sdkNamespacedFunctionExportsKnown'] ?? false) !== true) { return null; }
        try { $tokens = $this->tokens($source); $guards = $this->topLevelGuards($tokens); }
        catch (\ParseError) { return null; }
        catch (ConfigurationException $error) {
            if (in_array($error->getMessage(), ['Helper source delimiter structure drift', 'Helper source unbalanced declaration', 'Helper target guard has unsupported elseif'], true)) { return null; }
            throw $error;
        }
        if ($guards === []) { return null; }
        $namespace = '';
        $edits = [];
        $declared = [];
        $guardStarts = array_column($guards, null, 'start');
        for ($index = 0; $index < count($tokens); ++$index) {
            $token = $tokens[$index];
            if ($token['id'] === T_DECLARE) {
                $declaration = array_slice(array_column($tokens, 'text'), $index, 7);
                if (!in_array($declaration, [['declare', '(', 'strict_types', '=', '1', ')', ';'], ['declare', '(', 'strict_types', '=', '0', ')', ';']], true)) { return null; }
                $index += 6;
                continue;
            }
            if ($token['id'] === T_NAMESPACE) {
                if ($namespace !== '' || !isset($tokens[$index + 1], $tokens[$index + 2]) || $tokens[$index + 2]['text'] !== ';') { return null; }
                $namespace = trim($tokens[++$index]['text'], '\\');
                ++$index;
                continue;
            }
            if ($token['id'] === T_USE) {
                while (isset($tokens[++$index]) && $tokens[$index]['text'] !== ';') {
                    if ($tokens[$index]['id'] === T_FUNCTION) { return null; }
                }
                continue;
            }
            if (!isset($guardStarts[$index])) { return null; }
            $guard = $guardStarts[$index];
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            if ($namespace === '' || count($condition) !== 5 || $condition[0] !== '!' || !in_array($condition[1], ['function_exists', '\\function_exists'], true)
                || $condition[2] !== '(' || $condition[4] !== ')' || $guard['elseStart'] !== null
                || ($tokens[$guard['bodyStart']]['id'] ?? null) !== T_FUNCTION
                || ($tokens[$guard['bodyStart'] + 1]['id'] ?? null) !== T_STRING
            ) { return null; }
            $name = strtolower($namespace . '\\' . $tokens[$guard['bodyStart'] + 1]['text']);
            if (strtolower(ltrim($this->literal($condition[3]) ?? '', '\\')) !== $name
                || (in_array($name, $this->runtimeCapabilities['sdkNamespacedFunctionExports'] ?? [], true) && !in_array($name, $this->runtimeCapabilities['nativeNamespacedFunctions'] ?? [], true))
                || $name === strtolower($namespace . '\\function_exists')
            ) { return null; }
            if ($condition[1] === 'function_exists' && array_key_exists(strtolower($namespace . '\\function_exists'), $providers)) { return null; }
            $parameterEnd = $this->closing($tokens, $guard['bodyStart'] + 2, '(', ')');
            $body = $parameterEnd + 1;
            while (isset($tokens[$body]) && $tokens[$body]['text'] !== '{') { ++$body; }
            if ($body >= $guard['bodyEnd'] || $this->closing($tokens, $body, '{', '}') !== $guard['bodyEnd'] - 1) { return null; }
            if (array_key_exists($name, $providers) && $providers[$name] === null) { return null; }
            $replacement = isset($providers[$name]) || isset($declared[$name]) || in_array($name, $this->runtimeCapabilities['nativeNamespacedFunctions'] ?? [], true) ? '' : $this->body($source, $tokens, $guard);
            $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], $replacement];
            $declared[$name] = true;
            $index = $guard['end'];
        }
        $prepared = $this->edits($source, $edits);
        try { $this->tokens($prepared); }
        catch (\ParseError $error) { throw new ConfigurationException('autoload helper preparation produced invalid source', previous: $error); }
        return ['source' => $prepared, 'functions' => array_keys($declared)];
    }

    private function routeOptions(string $source): string
    {
        if ($this->matches(array_column($this->tokens($source), 'text'), '$options +=') === []) { return $source; }
        $tokens = $this->tokens($source);
        foreach ($this->matches(array_column($tokens, 'text'), '$options += [') as $index) {
            if (in_array($tokens[$index - 1]['id'] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) { throw new ConfigurationException('FastRoute options union receiver structure drift'); }
            $end = $this->closing($tokens, $index + 2, '[', ']');
            if (($tokens[$end + 1]['text'] ?? null) !== ';') { throw new ConfigurationException('FastRoute options union expression structure drift'); }
        }
        $source = $this->replace($source, '$options += [', '$options = $options + [', 'FastRoute options union');
        if ($this->matches(array_column($this->tokens($source), 'text'), '$options +=') !== []) {
            throw new ConfigurationException('FastRoute unsupported options union assignment');
        }
        return $source;
    }

    private function reflectionProxy(string $source): string
    {
        $before = '$proxy = $reflectionClass->newLazyProxy(function () use ($callback, $eager, &$proxy) { $instance = $callback($proxy, $eager); return $instance; }, $options);';
        $after = '$proxyInitializer = function () use ($callback, $eager, &$proxy) { $instance = $callback($proxy, $eager); return $instance; }; $proxy = $reflectionClass->newLazyProxy($proxyInitializer, $options);';
        $texts = array_column($this->functionTokens($source, 'proxy'), 'text');
        if ($this->matches($texts, $before) !== [] && in_array('$proxyInitializer', $texts, true)) {
            throw new ConfigurationException('Illuminate proxy initializer local already exists');
        }
        if (!in_array('newLazyProxy', $texts, true)) { return $source; }
        return $this->replaceFunction($source, 'proxy', $before, $after, 'Illuminate reflection proxy');
    }

    private function pregReplaceArray(string $source): string
    {
        $before = 'return preg_replace_callback($pattern, function () use (&$replacements) { return array_shift($replacements); }, $subject);';
        $after = 'return preg_replace_callback($pattern, function () use ($replacements, &$remaining) { if ($remaining === null) { $remaining = $replacements; } return array_shift($remaining); }, $subject);';
        $tokens = $this->functionTokens($source, 'preg_replace_array');
        $texts = array_column($tokens, 'text');
        $parameterEnd = $this->closing($tokens, 2, '(', ')');
        $bindings = array_keys(array_slice($texts, 3, $parameterEnd - 3), '$replacements', true);
        if (count($bindings) !== 1 || ($texts[$bindings[0] + 2] ?? null) === '&') {
            throw new ConfigurationException('Illuminate replacements must be a parameter passed by value');
        }
        if ($this->matches($texts, $before) !== [] && (in_array('$remaining', $texts, true) || count(array_keys($texts, '$replacements', true)) !== 3)) {
            throw new ConfigurationException('Illuminate replacement remaining local already exists');
        }
        return $this->replaceFunction($source, 'preg_replace_array', $before, $after, 'Illuminate replacement array');
    }

    private function dumpReturn(string $source): string
    {
        $texts = array_column($this->functionTokens($source, 'dump'), 'text');
        $tokens = $this->functionTokens($source, 'dump');
        $condition = 'if (array_key_exists(0, $vars) && 1 === count($vars))';
        $branches = $this->matches($texts, $condition);
        if (count($branches) !== 1) { throw new ConfigurationException('Symfony dump single-value branch structure drift'); }
        $bodyStart = $branches[0] + count($this->tokens('<?php ' . $condition, false));
        $bodyEnd = $this->closing($tokens, $bodyStart, '{', '}');
        if (($texts[$bodyEnd + 1] ?? null) !== 'else') { throw new ConfigurationException('Symfony dump multi-value branch missing'); }
        $elseStart = $bodyEnd + 2;
        $elseEnd = $this->closing($tokens, $elseStart, '{', '}');
        foreach (array_keys($texts, '$k', true) as $index) {
            $unchangedBranch = $index > $elseStart && $index < $elseEnd;
            $returnKey = array_slice($texts, $index - 3, 3) === ['return', '$vars', '['] && array_slice($texts, $index + 1, 2) === [']', ';'];
            $initialKey = $index > $bodyStart && $index < $bodyEnd && array_slice($texts, $index + 1, 3) === ['=', '0', ';'];
            if (!$unchangedBranch && !$returnKey && !$initialKey) {
                throw new ConfigurationException('Symfony dump key local escapes conversion');
            }
        }
        $original = $this->matches($texts, 'return $vars[$k];') !== [];
        $assignments = $this->matches($texts, '$k =');
        if (($original && ($this->matches($texts, 'VarDumper::dump($vars[0]); $k = 0;') === [] || count($assignments) !== 1))
            || (!$original && $assignments !== [])
        ) { throw new ConfigurationException('Symfony dump key local structure drift'); }
        $source = $this->replaceFunction($source, 'dump', 'VarDumper::dump($vars[0]); $k = 0;', 'VarDumper::dump($vars[0]);', 'Symfony dump key assignment');
        return $this->replaceFunction($source, 'dump', 'return $vars[$k];', 'return array_values($vars)[0];', 'Symfony dump return key');
    }

    private function ipLoader(string $path, string $source): string
    {
        $expected = $path === 'vendor/zoujingli/ip2region/function.php' ? '/Ip2Region.php' : '/src/Ip2Region.php';
        $tokens = $this->tokens($source);
        $edits = [];
        foreach ($this->topLevelGuards($tokens) as $guard) {
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            if (count($condition) !== 5 || $condition[0] !== '!' || ltrim($condition[1], '\\') !== 'class_exists'
                || $condition[2] !== '(' || $this->literal($condition[3]) !== 'Ip2Region' || $condition[4] !== ')'
            ) { continue; }
            $body = $this->texts($tokens, $guard['bodyStart'], $guard['bodyEnd']);
            if (count($body) !== 5 || $body[0] !== 'require_once' || $body[1] !== '__DIR__' || $body[2] !== '.'
                || $this->literal($body[3]) !== $expected || $body[4] !== ';' || $guard['elseStart'] !== null
            ) { throw new ConfigurationException('IP2Region loader source structure drift'); }
            $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], ''];
        }
        $source = $this->edits($source, $edits);
        if (in_array('require_once', array_column($this->tokens($source), 'text'), true)) {
            throw new ConfigurationException('IP2Region unsupported runtime loader');
        }
        return $source;
    }

    private function cakeConstants(string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        $constants = [];
        foreach ($this->topLevelGuards($tokens) as $guard) {
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            if (count($condition) === 5 && $condition[0] === '!' && ltrim($condition[1], '\\') === 'defined'
                && $condition[2] === '(' && $condition[4] === ')'
            ) {
                $name = $this->literal($condition[3]);
                $body = $this->texts($tokens, $guard['bodyStart'], $guard['bodyEnd']);
                $expected = match ($name) { 'DS' => 'DIRECTORY_SEPARATOR', 'CAKE_DATE_RFC7231' => 'D, d M Y H:i:s \\G\\M\\T', default => null };
                $value = $body[4] ?? '';
                if ($expected === null || count($body) !== 7 || ltrim($body[0], '\\') !== 'define' || $body[1] !== '('
                    || $this->literal($body[2]) !== $name || $body[3] !== ',' || $body[5] !== ')' || $body[6] !== ';'
                    || ($name === 'DS' ? $value !== $expected : $this->literal($value) !== $expected) || $guard['elseStart'] !== null
                    || isset($constants[$name])
                ) { throw new ConfigurationException('Cake global constant declaration structure drift'); }
                $constants[$name] = $value;
                $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], ''];
            } elseif (count($condition) === 5 && $condition[0] === '!' && ltrim($condition[1], '\\') === 'function_exists'
                && $condition[2] === '(' && $condition[4] === ')'
            ) {
                $body = $this->texts($tokens, $guard['bodyStart'], $guard['bodyEnd']);
                $name = $this->literal($condition[3]) ?? '';
                if (str_starts_with($name, 'Cake\\Core\\')) { $name = substr($name, strlen('Cake\\Core\\')); }
                if (!$this->isFunction($body, $name) || $guard['elseStart'] !== null) {
                    throw new ConfigurationException('Cake helper declaration guard structure drift');
                }
                $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], $this->body($source, $tokens, $guard)];
            } else { throw new ConfigurationException('Cake helper unsupported runtime guard'); }
        }
        if ($constants === [] && $this->matches(array_column($tokens, 'text'), 'namespace Cake\\Core {') !== []) { return $source; }
        if (!isset($constants['DS'], $constants['CAKE_DATE_RFC7231'])) { throw new ConfigurationException('Cake required global constants missing'); }
        $source = $this->edits($source, $edits);
        $namespace = 'namespace { const DS = ' . $constants['DS'] . '; const CAKE_DATE_RFC7231 = ' . $constants['CAKE_DATE_RFC7231'] . '; } namespace Cake\\Core {';
        $source = $this->replace($source, 'namespace Cake\\Core;', $namespace, 'Cake helper namespace');
        return $source . "\n}\n";
    }

    private function runtimeBranches(string $source, string $runtime, bool $preserveUnknown = false): string
    {
        $original = $source;
        $tokens = $this->tokens($source);
        $edits = [];
        $guards = $preserveUnknown ? $this->optionalPhpGuards($tokens) : $this->topLevelGuards($tokens);
        if ($guards === null) { return $original; }
        foreach ($guards as $guard) {
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            $selected = $this->runtimeCondition($condition, $runtime);
            if ($selected === null) {
                if ($preserveUnknown) { return $original; }
                throw new ConfigurationException('Unsupported ' . $runtime . ' target declaration guard');
            }
            $body = $selected ? $this->body($source, $tokens, $guard) : '';
            if (!$selected) {
                foreach ($guard['elseifs'] ?? [] as $branch) {
                    $selected = $this->runtimeCondition($this->texts($tokens, $branch['conditionStart'], $branch['conditionEnd']), $runtime);
                    if ($selected === null) { return $original; }
                    if ($selected) { $body = $this->body($source, $tokens, $branch); break; }
                }
                if (!$selected && $guard['elseStart'] !== null) { $body = $this->body($source, $tokens, $guard, true); }
            }
            $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], $body];
        }
        $source = $this->edits($source, $edits);
        foreach ($this->tokens($source) as $token) {
            if (($runtime === 'php' && ltrim($token['text'], '\\') === 'PHP_VERSION_ID')
                || ($runtime === 'redis' && ltrim($token['text'], '\\') === 'phpversion')
            ) {
                if ($preserveUnknown) { return $original; }
                throw new ConfigurationException('Unselected target runtime branch remains');
            }
        }
        return $source;
    }

    private function phpBootstrapCutoff(string $path, string $source): string
    {
        $tokens = $this->tokens($source);
        $guards = $this->optionalPhpGuards($tokens);
        if ($guards === null) { return $source; }
        $edits = [];
        foreach ($guards as $guard) {
            if ($guard['elseStart'] !== null || $guard['elseifs'] !== []) { return $source; }
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            $body = $this->texts($tokens, $guard['bodyStart'], $guard['bodyEnd']);
            $selected = $this->runtimeCondition($condition, 'php');
            $replacement = null;
            if ($selected === false) { $replacement = ''; }
            elseif ($selected === true && $body === ['return', ';']) {
                $edits[] = [$tokens[$guard['start']]['offset'], strlen($source), ''];
                break;
            } elseif ($selected === true && count($body) === 6 && array_slice($body, 0, 4) === ['return', 'require', '__DIR__', '.'] && $body[5] === ';') {
                $relative = $this->literal($body[4]);
                if ($relative === null || preg_match('#^/bootstrap[0-9]+\.php$#D', $relative) !== 1 || $this->mirror === null) { return $source; }
                $companionPath = dirname($path) . $relative;
                $file = $this->mirror . '/' . $companionPath;
                $root = realpath($this->mirror);
                $resolved = realpath($file);
                if (!is_string($root) || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($root) || !is_string($resolved)
                    || !str_starts_with($resolved, $root . '/') || is_link($file) || !is_file($file)
                ) { throw new ConfigurationException('PHP polyfill bootstrap companion is missing or unsafe'); }
                for ($parent = dirname($file); $parent !== $root; $parent = dirname($parent)) {
                    if (is_link($parent) || $parent === dirname($parent) || !str_starts_with($parent, $root . '/')) { throw new ConfigurationException('PHP polyfill bootstrap companion ancestor is unsafe'); }
                }
                $companion = file_get_contents($file);
                if (!is_string($companion)) { throw new ConfigurationException('Unable to read PHP polyfill bootstrap companion'); }
                $prepared = $this->phpBootstrapCutoff($companionPath, $companion);
                if ($prepared === $companion) { return $source; }
                $edits[] = [$tokens[$guard['start']]['offset'], strlen($source), ''];
                break;
            } elseif ($condition === ['!', 'defined', '(', "'ARRAY_FILTER_USE_VALUE'", ')'] && $body === ['define', '(', "'ARRAY_FILTER_USE_VALUE'", ',', '0', ')', ';']) {
                $replacement = $this->intlCapability('nativeArrayFilterUseValue') ? '' : 'const ARRAY_FILTER_USE_VALUE = 0;';
            } elseif (count($condition) === 5 && $condition[0] === '!' && ltrim($condition[1], '\\') === 'function_exists'
                && $condition[2] === '(' && $condition[4] === ')' && $this->literal($condition[3]) === 'clamp' && $this->isFunction($body, 'clamp')
            ) { $replacement = $this->intlCapability('nativeClamp') ? '' : $this->body($source, $tokens, $guard); }
            elseif (count($condition) === 10 && ltrim($condition[0], '\\') === 'extension_loaded' && $condition[1] === '('
                && $this->literal($condition[2]) === 'intl' && $condition[3] === ')' && $condition[4] === '&&' && $condition[5] === '!'
                && ltrim($condition[6], '\\') === 'function_exists' && $condition[7] === '(' && $condition[9] === ')'
                && $this->literal($condition[8]) === 'grapheme_strrev' && $this->isFunction($body, 'grapheme_strrev')
            ) { $replacement = $this->intlCapability('intlEnabled') && !$this->intlCapability('nativeGraphemeStrrev') ? $this->body($source, $tokens, $guard) : ''; }
            else { return $source; }
            $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], $replacement];
        }
        return $this->edits($source, $edits);
    }

    private function optionalPhpGuards(array $tokens): ?array
    {
        try { return $this->topLevelGuards($tokens, true); }
        catch (ConfigurationException $error) {
            if (in_array($error->getMessage(), ['Helper source delimiter structure drift', 'Helper source unbalanced declaration', 'Helper target guard has unsupported elseif'], true)) { return null; }
            throw $error;
        }
    }

    private function phpBootstrap(string $source): string
    {
        $tokens = $this->tokens($source);
        $edits = [];
        foreach ($this->topLevelGuards($tokens) as $guard) {
            $condition = $this->texts($tokens, $guard['conditionStart'], $guard['conditionEnd']);
            $body = $this->texts($tokens, $guard['bodyStart'], $guard['bodyEnd']);
            $selected = $this->runtimeCondition($condition, 'php');
            $replacement = null;
            if ($selected !== null) {
                if ($body === ['return', ';']) {
                    if ($selected) {
                        if ($this->intlCapability('intlEnabled') && !$this->intlCapability('nativeGraphemeLevenshtein')
                            && ($this->runtimeCapabilities['deferredIntlProviderValidation'] ?? null) !== true
                        ) { throw new ConfigurationException('PHP polyfill bootstrap80 fallback requires final active provider validation'); }
                        $edits[] = [$tokens[$guard['start']]['offset'], strlen($source), ''];
                        break;
                    }
                    $replacement = '';
                } elseif (count($body) === 7 && $body[0] === 'require' && $body[1] === '__DIR__' && $body[2] === '.'
                    && $this->literal($body[3]) === '/bootstrap80.php' && array_slice($body, 4) === [';', 'return', ';']
                ) {
                    if ($selected) {
                        if ($this->intlCapability('intlEnabled') && !$this->intlCapability('nativeGraphemeLevenshtein')
                            && ($this->runtimeCapabilities['deferredIntlProviderValidation'] ?? null) !== true
                        ) { throw new ConfigurationException('PHP polyfill bootstrap80 fallback requires final active provider validation'); }
                        $edits[] = [$tokens[$guard['start']]['offset'], strlen($source), ''];
                        break;
                    }
                    $replacement = '';
                } else { throw new ConfigurationException('PHP polyfill target branch has unsupported executable statements'); }
            } elseif (count($condition) === 10 && ltrim($condition[0], '\\') === 'extension_loaded' && $condition[1] === '('
                && $this->literal($condition[2]) === 'intl' && $condition[3] === ')' && $condition[4] === '&&' && $condition[5] === '!'
                && ltrim($condition[6], '\\') === 'function_exists' && $condition[7] === '(' && $condition[9] === ')'
            ) {
                $name = $this->literal($condition[8]);
                if (!in_array($name, ['locale_is_right_to_left', 'grapheme_levenshtein'], true) || !$this->isFunction($body, $name ?? '')) {
                    throw new ConfigurationException('PHP polyfill unsupported intl fallback declaration');
                }
                $native = $name === 'locale_is_right_to_left' ? 'nativeLocaleIsRightToLeft' : 'nativeGraphemeLevenshtein';
                $replacement = $this->intlCapability('intlEnabled') && !$this->intlCapability($native)
                    ? $this->body($source, $tokens, $guard) : '';
            } elseif (count($condition) === 5 && $condition[0] === '!' && ltrim($condition[1], '\\') === 'function_exists'
                && $condition[2] === '(' && $condition[4] === ')' && $this->isFunction($body, $this->literal($condition[3]) ?? '')
            ) { continue; }
            else { throw new ConfigurationException('PHP polyfill unsupported bootstrap guard'); }
            if ($guard['elseStart'] !== null) { throw new ConfigurationException('PHP polyfill target guard has unsupported alternative'); }
            $edits[] = [$tokens[$guard['start']]['offset'], $tokens[$guard['end']]['end'], $replacement];
        }
        return $this->edits($source, $edits);
    }

    private function intlCapability(string $name): bool
    {
        if (!is_bool($this->runtimeCapabilities[$name] ?? null)) {
            throw new ConfigurationException('Selected SDK Intl capability is missing or unknown: ' . $name);
        }
        return $this->runtimeCapabilities[$name];
    }

    private function runtimeCondition(array $condition, string $runtime): ?bool
    {
        if ($runtime === 'php' && count($condition) > 4 && $condition[3] === '&&'
            && array_intersect($condition, ['||', 'or', 'xor', '?', '=']) === []
        ) {
            $left = $this->runtimeCondition(array_slice($condition, 0, 3), $runtime);
            if ($left === false) { return false; }
            if ($left === true) { return $this->runtimeCondition(array_slice($condition, 4), $runtime); }
            return null;
        }
        if ($runtime === 'php' && count($condition) === 3 && ltrim($condition[0], '\\') === 'PHP_VERSION_ID'
            && preg_match('/\A[0-9]+\z/D', $condition[2]) === 1
        ) {
            if ($this->phpVersionId === null) { throw new ConfigurationException('PHP target capability is required for guarded declarations'); }
            $actual = $this->phpVersionId;
            $expected = (int) $condition[2];
            return match ($condition[1]) { '<' => $actual < $expected, '<=' => $actual <= $expected, '>' => $actual > $expected, '>=' => $actual >= $expected, '===' => $actual === $expected, '==' => $actual == $expected, '!=' => $actual != $expected, '!==' => $actual !== $expected, default => null };
        }
        if ($runtime === 'redis' && count($condition) === 11 && ltrim($condition[0], '\\') === 'version_compare'
            && $condition[1] === '(' && ltrim($condition[2], '\\') === 'phpversion' && $condition[3] === '('
            && $this->literal($condition[4]) === 'redis' && $condition[5] === ')' && $condition[6] === ','
            && $condition[8] === ',' && $condition[10] === ')'
        ) {
            $version = $this->literal($condition[7]);
            $operator = $this->literal($condition[9]);
            if (!is_string($version) || preg_match('/\A[0-9]+(?:\.[0-9]+)*(?:[-+][A-Za-z0-9.-]+)?\z/D', $version) !== 1
                || !in_array($operator, ['<', '<=', '>', '>=', '=', '==', '===', '!=', '<>', '!=='], true)
            ) { return null; }
            if ($this->redisVersion === null) { throw new ConfigurationException('Redis target capability is required for proxy declarations'); }
            return version_compare($this->redisVersion, $version, $operator);
        }
        return null;
    }

    private function functionTokens(string $source, string $name): array
    {
        $tokens = $this->tokens($source);
        $functions = [];
        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_FUNCTION || ($tokens[$index + 1]['text'] ?? null) !== $name) { continue; }
            $parameters = $this->closing($tokens, $index + 2, '(', ')');
            $body = $parameters + 1;
            while ($body < count($tokens) && $tokens[$body]['text'] !== '{') { ++$body; }
            $end = $this->closing($tokens, $body, '{', '}');
            $functions[] = array_slice($tokens, $index, $end - $index + 1);
        }
        if (count($functions) !== 1) { throw new ConfigurationException('Helper function declaration structure drift: ' . $name); }
        return $functions[0];
    }

    private function isFunction(array $body, string $name): bool
    {
        if (($body[0] ?? null) !== 'function' || ($body[1] ?? null) !== $name || $name === '') { return false; }
        $tokens = array_map(static fn (string $text): array => ['text' => $text], $body);
        $parameters = $this->closing($tokens, 2, '(', ')');
        $start = $parameters + 1;
        while ($start < count($tokens) && $tokens[$start]['text'] !== '{') { ++$start; }
        return $this->closing($tokens, $start, '{', '}') === count($tokens) - 1;
    }

    private function replaceFunction(string $source, string $name, string $before, string $after, string $label): string
    {
        $tokens = $this->functionTokens($source, $name);
        $start = $tokens[0]['offset'];
        $end = $tokens[count($tokens) - 1]['end'];
        $body = '<?php ' . substr($source, $start, $end - $start);
        $adapted = $this->replace($body, $before, $after, $label);
        return substr_replace($source, substr($adapted, strlen('<?php ')), $start, $end - $start);
    }

    private function replace(string $source, string $before, string $after, string $label): string
    {
        $texts = array_column($this->tokens($source), 'text');
        $original = $this->matches($texts, $before);
        $adapted = $after === '' ? [] : $this->matches($texts, $after);
        if ($original === [] && $adapted !== []) { return $source; }
        if ($original === []) { throw new ConfigurationException($label . ' source structure drift'); }
        $width = count($this->tokens('<?php ' . $before, false));
        $adapted = array_filter($adapted, static function (int $index) use ($original, $width): bool {
            foreach ($original as $start) { if ($index >= $start && $index < $start + $width) { return false; } }
            return true;
        });
        return (new BoundedTextRule($label, 'source', 'helper', new MinimumVersion('0.0.0'), [], $before, $after, count($original) + count($adapted), $after === '' ? [] : [$after]))->transform($source, 'source-validated');
    }

    private function matches(array $texts, string $source): array
    {
        $needle = array_column($this->tokens('<?php ' . $source, false), 'text');
        $hits = [];
        for ($index = 0; $index <= count($texts) - count($needle); ++$index) {
            if (array_slice($texts, $index, count($needle)) === $needle) { $hits[] = $index; }
        }
        return $hits;
    }

    private function tokens(string $source, bool $parse = true): array
    {
        $tokens = [];
        $offset = 0;
        foreach (token_get_all($source, $parse ? TOKEN_PARSE : 0) as $token) {
            $id = is_array($token) ? $token[0] : null;
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $tokens[] = ['text' => $text, 'id' => $id, 'offset' => $offset, 'end' => $end]; }
            $offset = $end;
        }
        return $tokens;
    }

    private function texts(array $tokens, int $start, int $end): array
    {
        return array_column(array_slice($tokens, $start, $end - $start), 'text');
    }

    private function literal(string $value): ?string
    {
        if (strlen($value) < 2 || !in_array($value[0], ["'", '"'], true) || $value[-1] !== $value[0]) { return null; }
        $value = substr($value, 1, -1);
        return str_replace(["\\'", '\\\\'], ["'", '\\'], $value);
    }

    private function closing(array $tokens, int $start, string $open, string $close): int
    {
        if (($tokens[$start]['text'] ?? null) !== $open) { throw new ConfigurationException('Helper source delimiter structure drift'); }
        $depth = 1;
        for ($index = $start + 1; $index < count($tokens); ++$index) {
            if ($tokens[$index]['text'] === $open) { ++$depth; }
            if ($tokens[$index]['text'] === $close && --$depth === 0) { return $index; }
        }
        throw new ConfigurationException('Helper source unbalanced declaration');
    }

    private function topLevelGuards(array $tokens, bool $allowElseif = false): array
    {
        $guards = [];
        $depth = 0;
        for ($index = 0; $index < count($tokens); ++$index) {
            if ($depth === 0 && $tokens[$index]['id'] === T_IF) {
                $conditionEnd = $this->closing($tokens, $index + 1, '(', ')');
                $bodyEnd = $this->closing($tokens, $conditionEnd + 1, '{', '}');
                $elseStart = null;
                $elseEnd = null;
                $end = $bodyEnd;
                $elseifs = [];
                while ($allowElseif && ($tokens[$end + 1]['id'] ?? null) === T_ELSEIF) {
                    $branchStart = $end + 1;
                    $branchConditionEnd = $this->closing($tokens, $branchStart + 1, '(', ')');
                    $end = $this->closing($tokens, $branchConditionEnd + 1, '{', '}');
                    $elseifs[] = ['conditionStart' => $branchStart + 2, 'conditionEnd' => $branchConditionEnd, 'bodyStart' => $branchConditionEnd + 2, 'bodyEnd' => $end];
                }
                if (($tokens[$end + 1]['id'] ?? null) === T_ELSE) {
                    $elseStart = $end + 3;
                    $elseEnd = $this->closing($tokens, $end + 2, '{', '}');
                    $end = $elseEnd;
                } elseif (($tokens[$end + 1]['id'] ?? null) === T_ELSEIF) {
                    throw new ConfigurationException('Helper target guard has unsupported elseif');
                }
                $guards[] = ['start' => $index, 'conditionStart' => $index + 2, 'conditionEnd' => $conditionEnd, 'bodyStart' => $conditionEnd + 2, 'bodyEnd' => $bodyEnd, 'elseStart' => $elseStart, 'elseEnd' => $elseEnd, 'end' => $end, 'elseifs' => $elseifs];
                $index = $end;
            } elseif ($tokens[$index]['text'] === '{') { ++$depth; }
            elseif ($tokens[$index]['text'] === '}') { --$depth; }
        }
        return $guards;
    }

    private function body(string $source, array $tokens, array $guard, bool $alternative = false): string
    {
        $start = $alternative ? $guard['elseStart'] : $guard['bodyStart'];
        $end = $alternative ? $guard['elseEnd'] : $guard['bodyEnd'];
        return substr($source, $tokens[$start - 1]['end'], $tokens[$end]['offset'] - $tokens[$start - 1]['end']);
    }

    private function edits(string $source, array $edits): string
    {
        foreach (array_reverse($edits) as [$start, $end, $replacement]) { $source = substr_replace($source, $replacement, $start, $end - $start); }
        return $source;
    }
}
