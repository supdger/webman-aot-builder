<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Toolchain\SdkArchiveGuard;
use WebmanAotBuilder\Toolchain\StaticSdkFingerprint;

final class DeepClonePolyfillRule
{
    /**
     * Select the PHP fallback from the verified selected SDK identity and
     * capabilities, never the host's extensions or a dependency policy digest.
     *
     * @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>
     */
    public function apply(string $directory, array $policy, ?string $sdkDirectory, array $targetContext): array
    {
        $mirror = realpath($directory);
        if (!is_string($mirror) || is_link($directory)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException('deepclone adaptation requires an isolated project mirror');
        }
        $composer = json_decode($this->read($mirror . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
        $packages = array_values(array_filter(
            array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []),
            static fn (array $package): bool => ($package['name'] ?? null) === 'symfony/polyfill-deepclone'
        ));
        $prefix = 'vendor/symfony/polyfill-deepclone/';
        if ($packages === [] && !file_exists($mirror . '/' . $prefix)) {
            return [];
        }
        if (count($packages) !== 1
            || ($policy['rule'] ?? null) !== 'symfony.deepclone-php84-fallback.v1'
            || ($policy['sdkPolicy'] ?? null) !== 'selected.prepared-toolchain-sdk.v1'
            || !is_string($packages[0]['version'] ?? null)
            || $packages[0]['version'] === ''
        ) {
            throw new ConfigurationException('deepclone package policy drifted');
        }
        $sdk = is_string($sdkDirectory) && !is_link($sdkDirectory) ? realpath($sdkDirectory) : false;
        $selectedSdk = is_string($targetContext['sdkDirectory'] ?? null) && !is_link($targetContext['sdkDirectory'])
            ? realpath($targetContext['sdkDirectory']) : false;
        if (!is_string($sdk) || !is_dir($sdk)
            || $selectedSdk !== $sdk
            || !is_int($targetContext['phpVersionId'] ?? null) || $targetContext['phpVersionId'] <= 0
            || !is_bool($targetContext['deepcloneEnabled'] ?? null)
        ) {
            throw new ConfigurationException('deepclone fallback requires verified selected SDK runtime capabilities');
        }
        foreach (['sdkSha256', 'derivationSha256', 'toolchainLockSha256'] as $identity) {
            if (!is_string($targetContext[$identity] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $targetContext[$identity]) !== 1
            ) {
                throw new ConfigurationException('deepclone selected SDK identity is missing or unapproved');
            }
        }
        try {
            $lockFile = $targetContext['toolchainLockFile'] ?? null;
            if (!is_string($lockFile) || !is_file($lockFile) || is_link($lockFile)
                || hash_file('sha256', $lockFile) !== $targetContext['toolchainLockSha256']
            ) {
                throw new \RuntimeException('selected toolchain approval identity drifted');
            }
            $lock = json_decode($this->read($lockFile), true, flags: JSON_THROW_ON_ERROR);
            $approval = $lock['evidence']['patchedSdk'] ?? null;
            if (!is_array($approval)
                || ($approval['sdkSha256'] ?? null) !== $targetContext['sdkSha256']
                || ($approval['derivationSha256'] ?? null) !== $targetContext['derivationSha256']
            ) {
                throw new \RuntimeException('selected SDK identity differs from its toolchain approval');
            }
            if ((new StaticSdkFingerprint())->digest($sdk) !== $targetContext['sdkSha256']) {
                throw new \RuntimeException('static contents differ from the approved selected SDK');
            }
            (new SdkArchiveGuard())->assertDerivation($sdk, $targetContext);
            $capabilities = (new \WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdk);
            if ($capabilities['phpVersionId'] !== $targetContext['phpVersionId']
                || $capabilities['deepcloneEnabled'] !== $targetContext['deepcloneEnabled']
            ) {
                throw new \RuntimeException('selected SDK runtime capabilities drifted');
            }
            if ($targetContext['deepcloneEnabled']) {
                throw new \RuntimeException('native deepclone function, constant and exception ABI is not verified');
            }
        } catch (\RuntimeException|\JsonException $exception) {
            throw new ConfigurationException('deepclone selected target SDK drifted: ' . $exception->getMessage(), previous: $exception);
        }
        $names = ['bootstrap.php', 'bootstrap81.php', 'Resources/stubs/ClassNotFoundException.php',
            'Resources/stubs/NotInstantiableException.php', 'DeepClone.php'];
        $sources = [];
        foreach ($names as $name) {
            $sources[$name] = $this->read($mirror . '/' . $prefix . $name);
        }
        $shadows = [];
        // The selected target must take the verified bootstrap81 branch.
        $shadows['bootstrap.php'] = "<?php\n// Selected target fallback declarations are compiled from deepclone-bootstrap81.php.\n";
        $this->assertBootstrap($sources['bootstrap.php'], $targetContext['phpVersionId']);
        $shadows['bootstrap81.php'] = $this->unguard($sources['bootstrap81.php'], null);
        foreach (['ClassNotFoundException', 'NotInstantiableException'] as $class) {
            $name = "Resources/stubs/{$class}.php";
            $shadows[$name] = $this->unguard($sources[$name], $class);
        }
        // Keep the count-or-metadata value in the mixed array slot. TypePHP
        // cannot change the inferred type of a local from int to array.
        $originalMeta = <<<'PHP'
        $n = \count($metaOut);
        foreach ($metaOut as $v) {
            if (0 !== $v) {
                $n = $metaOut;
                break;
            }
        }

        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : ($classes ?: ''),
            'objectMeta' => $n,
            'prepared' => $prepared,
        ];
PHP;
        $adaptedMeta = <<<'PHP'
        $data = [
            'classes' => 1 === \count($classes) ? $classes[0] : ($classes ?: ''),
            'objectMeta' => \count($metaOut),
            'prepared' => $prepared,
        ];
        foreach ($metaOut as $v) {
            if (0 !== $v) {
                $data['objectMeta'] = $metaOut;
                break;
            }
        }
PHP;
        $shadows['DeepClone.php'] = $this->hasStableMetadata($sources['DeepClone.php'])
            ? $sources['DeepClone.php']
            : $this->transform($sources['DeepClone.php'], $originalMeta, $adaptedMeta, 'metadata');
        // The cached entry is either a two-string scope/name tuple or absent.
        // Keep assignment outside the condition; TypePHP rejects a list there.
        $originalScope = '            if ([$scopeName, $realName] = $propertyScopes[$name] ?? null) {';
        $adaptedScope = <<<'PHP'
            $propertyScope = $propertyScopes[$name] ?? null;
            $scopeName = $propertyScope[0] ?? null;
            $realName = $propertyScope[1] ?? null;
            if ($propertyScope) {
PHP;
        $shadows['DeepClone.php'] = $this->transform($shadows['DeepClone.php'], $originalScope, $adaptedScope, 'property scope');

        // The typed reflector is not used after the parent traversal. Test the
        // ReflectionClass|false result before assigning the next real parent.
        $originalParent = '        while ($class = $class->getParentClass()) {';
        $adaptedParent = "        while (\$parentClass = \$class->getParentClass()) {\n            \$class = \$parentClass;";
        $shadows['DeepClone.php'] = $this->transform($shadows['DeepClone.php'], $originalParent, $adaptedParent, 'parent reflection');
        foreach ($shadows as $name => $source) {
            $this->tokens($source);
        }

        $projectFile = $mirror . '/project.linux.yml';
        $project = $this->read($projectFile);
        if (substr_count($project, "\nignore:\n") !== 1) {
            throw new ConfigurationException('deepclone project source sections drifted');
        }
        $mappings = [];
        $writes = [];
        foreach ($shadows as $name => $source) {
            $relative = $prefix . $name;
            $shadow = '.typephp/build/deepclone-' . basename($name);
            $shadowPath = $mirror . '/' . $shadow;
            [$sourceSection, $ignoreSection] = explode("\nignore:\n", $project);
            $alreadyAdapted = is_file($shadowPath) && !is_link($shadowPath)
                && $this->read($shadowPath) === $source
                && substr_count($sourceSection . "\n", "  - {$shadow}\n") === 1
                && substr_count($ignoreSection, "  - {$relative}\n") === 1
                && !str_contains($sourceSection, "  - {$relative}\n")
                && !str_contains($ignoreSection, "  - {$shadow}\n");
            if (!is_string($source) || is_link($shadowPath)
                || (!$alreadyAdapted && (file_exists($shadowPath)
                    || str_contains($project, "  - {$relative}\n")
                    || str_contains($project, "  - {$shadow}\n")))
            ) {
                throw new ConfigurationException("deepclone generated source entry drifted: {$name}");
            }
            if (!$alreadyAdapted) {
                $project = str_replace("\nignore:\n", "\n  - {$shadow}\n\nignore:\n  - {$relative}\n", $project);
            }
            $writes[$mirror . '/' . $shadow] = $source;
            $mappings[] = ['path' => $relative, 'shadow' => $shadow,
                'sourceSha256' => hash('sha256', $sources[$name]), 'shadowSha256' => hash('sha256', $source)];
        }
        foreach ($writes + [$projectFile => $project] as $path => $source) {
            if (file_put_contents($path, $source, LOCK_EX) === false) {
                throw new ConfigurationException('unable to write deepclone fallback adaptation');
            }
        }
        return $mappings;
    }

    private function transform(string $source, string $before, string $after, string $label): string
    {
        $this->assertTemporaries($source, $label);
        return (new BoundedTextRule(
            'deepclone.' . str_replace(' ', '-', $label),
            'symfony/polyfill-deepclone',
            'vendor/symfony/polyfill-deepclone/DeepClone.php',
            new MinimumVersion('1.0.0'),
            ['namespace Symfony\\Polyfill\\DeepClone;', 'class DeepClone'],
            $before,
            $after,
            1,
            [$after]
        ))->transform($source, 'source-verified');
    }

    private function hasStableMetadata(string $source): bool
    {
        $texts = array_column($this->methodTokens($source, 'deepclone_to_array'), 'text');
        if (in_array('$n', $texts, true) || in_array('$metaOut', $texts, true)
            || count(array_keys($texts, '$metaIsCount', true)) !== 5
        ) {
            return false;
        }
        foreach ([
            ['$objectMeta', '=', '[', ']', ';', '$metaIsCount', '=', 'true', ';'],
            ['$objectMeta', '[', '$id', ']', '=', '$classId', ';', '$metaIsCount', '=', '$metaIsCount', '&&', '0', '===', '$classId', ';'],
            ['$objectMeta', '[', '$id', ']', '=', '[', '$classId', ',', '$wakeup', ']', ';', '$metaIsCount', '=', 'false', ';'],
            ["'objectMeta'", '=>', '$metaIsCount', '?', '\\count', '(', '$objectMeta', ')', ':', '$objectMeta', ','],
        ] as $needle) {
            $hits = 0;
            for ($index = 0; $index <= count($texts) - count($needle); ++$index) {
                if (array_slice($texts, $index, count($needle)) === $needle) {
                    ++$hits;
                }
            }
            if ($hits !== 1) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array{text:string,offset:int,end:int}> */
    private function methodTokens(string $source, string $method): array
    {
        $tokens = $this->tokens($source);
        $bodies = [];
        foreach ($tokens as $index => $token) {
            if ($token['text'] !== 'function' || ($tokens[$index + 1]['text'] ?? null) !== $method) {
                continue;
            }
            $bodyStart = $this->closing($tokens, $index + 2, '(', ')') + 1;
            while (($tokens[$bodyStart]['text'] ?? null) !== '{' && $bodyStart < count($tokens)) {
                ++$bodyStart;
            }
            $bodyEnd = $this->closing($tokens, $bodyStart, '{', '}');
            $bodies[] = array_slice($tokens, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
        }
        if (count($bodies) !== 1) {
            throw new ConfigurationException("deepclone {$method} method source structure drifted");
        }
        return $bodies[0];
    }

    private function assertTemporaries(string $source, string $label): void
    {
        [$method, $needle, $temporary, $beforeCount, $afterCount] = match ($label) {
            'metadata' => ['deepclone_to_array', ['$n', '=', '\\count', '(', '$metaOut', ')', ';'], '$n', 3, 0],
            'property scope' => ['deepclone_hydrate', ['[', '$scopeName', ',', '$realName', ']', '='], '$propertyScope', 0, 4],
            'parent reflection' => ['getPropertyScopes', ['while', '(', '$class', '='], '$parentClass', 0, 2],
        };
        $body = $this->methodTokens($source, $method);
        $texts = array_column($body, 'text');
        $original = false;
        for ($index = 0; $index <= count($texts) - count($needle); ++$index) {
            if (array_slice($texts, $index, count($needle)) === $needle) {
                $original = true;
                break;
            }
        }
        if (count(array_filter($texts, static fn (string $text): bool => $text === $temporary)) !== ($original ? $beforeCount : $afterCount)) {
            throw new ConfigurationException("deepclone {$label} temporary variable source structure drifted");
        }
        if ($label === 'parent reflection') {
            foreach ($texts as $index => $text) {
                if ($text !== '$class') {
                    continue;
                }
                $receiver = ($texts[$index + 1] ?? null) === '->';
                $assignment = ($texts[$index + 1] ?? null) === '='
                    && (($original && array_slice($texts, $index - 2, 2) === ['while', '('])
                        || (!$original && ($texts[$index + 2] ?? null) === '$parentClass'));
                if (!$receiver && !$assignment || in_array($texts[$index - 1] ?? null, ['&', '...'], true)) {
                    throw new ConfigurationException('deepclone parent reflection reference or escaping local source structure drifted');
                }
            }
            $parentCalls = array_keys($texts, 'getParentClass', true);
            if (count($parentCalls) !== 1) {
                throw new ConfigurationException('deepclone parent reflection source structure drifted');
            }
            $loopBody = $parentCalls[0] + 1;
            while (($body[$loopBody]['text'] ?? null) !== '{' && $loopBody < count($body)) {
                ++$loopBody;
            }
            $loopEnd = $this->closing($body, $loopBody, '{', '}');
            if (in_array('$class', array_slice($texts, $loopEnd + 1), true)) {
                throw new ConfigurationException('deepclone parent reflection exit value source structure drifted');
            }
        }
    }

    private function assertBootstrap(string $source, int $phpVersionId): void
    {
        $tokens = $this->tokens($source);
        $texts = array_column($tokens, 'text');
        $prefix = ['use', 'Symfony\\Polyfill\\DeepClone', 'as', 'p', ';'];
        if (array_slice($texts, 0, count($prefix)) !== $prefix) {
            throw new ConfigurationException('deepclone bootstrap source structure drifted');
        }
        $index = count($prefix);
        foreach (['extension', 'runtime'] as $guard) {
            if (($texts[$index] ?? null) !== 'if' || ($texts[$index + 1] ?? null) !== '(') {
                throw new ConfigurationException('deepclone bootstrap guard source structure drifted');
            }
            $conditionEnd = $this->closing($tokens, $index + 1, '(', ')');
            $condition = array_slice($texts, $index + 2, $conditionEnd - $index - 2);
            $bodyStart = $conditionEnd + 1;
            $bodyEnd = $this->closing($tokens, $bodyStart, '{', '}');
            $body = array_slice($texts, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            if ($guard === 'extension') {
                if ($condition !== ['extension_loaded', '(', "'deepclone'", ')'] || $body !== ['return', ';']) {
                    throw new ConfigurationException('deepclone bootstrap extension guard source structure drifted');
                }
            } else {
                if ($body !== ['require', '__DIR__', '.', "'/bootstrap81.php'", ';']
                    || count($condition) !== 3
                    || !in_array($condition[1], ['>=', '>', '<=', '<', '==', '===', '!=', '!=='], true)
                ) {
                    throw new ConfigurationException('deepclone bootstrap runtime guard source structure drifted');
                }
                $left = ltrim($condition[0], '\\');
                $right = ltrim($condition[2], '\\');
                if (($left === 'PHP_VERSION_ID') === ($right === 'PHP_VERSION_ID')) {
                    throw new ConfigurationException('deepclone bootstrap runtime capability is unknown');
                }
                $literal = $left === 'PHP_VERSION_ID' ? $right : $left;
                if (preg_match('/^[0-9]+(?:_[0-9]+)*$/D', $literal) !== 1
                    || strlen(str_replace('_', '', $literal)) > 9
                ) {
                    throw new ConfigurationException('deepclone bootstrap runtime threshold is unknown');
                }
                $threshold = (int) str_replace('_', '', $literal);
                $a = $left === 'PHP_VERSION_ID' ? $phpVersionId : $threshold;
                $b = $right === 'PHP_VERSION_ID' ? $phpVersionId : $threshold;
                $selected = match ($condition[1]) {
                    '>=' => $a >= $b, '>' => $a > $b, '<=' => $a <= $b, '<' => $a < $b,
                    '==', '===' => $a === $b, '!=', '!==' => $a !== $b,
                };
                if (!$selected) {
                    throw new ConfigurationException('deepclone fallback is not selected by the target PHP runtime');
                }
            }
            $index = $bodyEnd + 1;
        }
        if ($index !== count($tokens)) {
            throw new ConfigurationException('deepclone bootstrap unsupported trailing source structure');
        }
    }

    private function unguard(string $source, ?string $class): string
    {
        $tokens = $this->tokens($source);
        $output = [];
        $seen = [];
        $header = $class === null ? 'use Symfony\\Polyfill\\DeepClone as p;' : 'namespace DeepClone;';
        $prefix = array_column($this->tokens('<?php ' . $header), 'text');
        if (array_slice(array_column($tokens, 'text'), 0, count($prefix)) !== $prefix) {
            throw new ConfigurationException('deepclone declaration namespace source structure drifted');
        }
        $output[] = '<?php' . "\n\n" . $header;
        for ($index = count($prefix); $index < count($tokens);) {
            if ($tokens[$index]['text'] !== 'if' || ($tokens[$index + 1]['text'] ?? null) !== '(') {
                throw new ConfigurationException('deepclone fallback guard source structure drifted');
            }
            $conditionEnd = $this->closing($tokens, $index + 1, '(', ')');
            $condition = array_column(array_slice($tokens, $index + 2, $conditionEnd - $index - 2), 'text');
            $bodyStart = $conditionEnd + 1;
            $bodyEnd = $this->closing($tokens, $bodyStart, '{', '}');
            $bodyTokens = array_slice($tokens, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
            $body = array_column($bodyTokens, 'text');
            if ($condition === ['extension_loaded', '(', "'deepclone'", ')'] && $class === null) {
                if ($body !== ['return', ';'] || isset($seen['extension'])) {
                    throw new ConfigurationException('deepclone extension guard source structure drifted');
                }
                $seen['extension'] = true;
            } elseif ($class !== null && $condition === ['!', 'extension_loaded', '(', "'deepclone'", ')']) {
                if (array_slice($body, 0, 4) !== ['class', $class, 'extends', '\\InvalidArgumentException']
                    || isset($seen[$class])
                    || $this->closing($bodyTokens, 4, '{', '}') !== count($bodyTokens) - 1
                ) {
                    throw new ConfigurationException('deepclone exception source structure drifted');
                }
                $seen[$class] = true;
                $output[] = substr($source, $tokens[$bodyStart]['end'], $tokens[$bodyEnd]['offset'] - $tokens[$bodyStart]['end']);
            } elseif ($class === null && count($condition) === 5 && $condition[0] === '!'
                && in_array($condition[1], ['function_exists', 'defined'], true)
                && $condition[2] === '(' && $condition[4] === ')'
            ) {
                $name = trim($condition[3], "'");
                if (isset($seen[$name])) {
                    throw new ConfigurationException('deepclone duplicate fallback declaration source structure drifted');
                }
                $seen[$name] = true;
                if ($condition[1] === 'function_exists') {
                    if (!in_array($name, ['deepclone_to_array', 'deepclone_from_array', 'deepclone_hydrate'], true)
                        || array_slice($body, 0, 3) !== ['function', $name, '(']
                    ) {
                        throw new ConfigurationException('deepclone function source structure drifted');
                    }
                    $parametersEnd = $this->closing($bodyTokens, 2, '(', ')');
                    $functionBody = $parametersEnd + 1;
                    while (($bodyTokens[$functionBody]['text'] ?? null) !== '{' && $functionBody < count($bodyTokens)) {
                        ++$functionBody;
                    }
                    if ($this->closing($bodyTokens, $functionBody, '{', '}') !== count($bodyTokens) - 1) {
                        throw new ConfigurationException('deepclone function guard source structure drifted');
                    }
                    $output[] = substr($source, $tokens[$bodyStart]['end'], $tokens[$bodyEnd]['offset'] - $tokens[$bodyStart]['end']);
                } else {
                    $constants = ['DEEPCLONE_HYDRATE_CALL_HOOKS' => '0', 'DEEPCLONE_HYDRATE_NO_LAZY_INIT' => '1', 'DEEPCLONE_HYDRATE_PRESERVE_REFS' => '2'];
                    if (!isset($constants[$name]) || $body !== ['define', '(', "'{$name}'", ',', '1', '<<', $constants[$name], ')', ';']) {
                        throw new ConfigurationException('deepclone constant source structure drifted');
                    }
                    $output[] = 'const ' . $name . ' = 1 << ' . $constants[$name] . ';';
                }
            } else {
                throw new ConfigurationException('deepclone unsupported fallback source structure drifted');
            }
            $index = $bodyEnd + 1;
        }
        $required = $class === null
            ? ['extension', 'deepclone_to_array', 'deepclone_from_array', 'deepclone_hydrate', 'DEEPCLONE_HYDRATE_CALL_HOOKS', 'DEEPCLONE_HYDRATE_NO_LAZY_INIT', 'DEEPCLONE_HYDRATE_PRESERVE_REFS']
            : [$class];
        if (array_diff($required, array_keys($seen)) !== []) {
            throw new ConfigurationException('deepclone missing fallback source declaration');
        }
        return implode("\n\n", $output) . "\n";
    }

    /** @return list<array{text:string,offset:int,end:int}> */
    private function tokens(string $source): array
    {
        try {
            $raw = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('deepclone source structure drifted: invalid PHP', previous: $exception);
        }
        $tokens = [];
        $offset = 0;
        foreach ($raw as $token) {
            $id = is_array($token) ? $token[0] : $token;
            $text = is_array($token) ? $token[1] : $token;
            $end = $offset + strlen($text);
            if (!in_array($id, [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $normalized = $id === T_NAME_FULLY_QUALIFIED && in_array($text, ['\\extension_loaded', '\\function_exists', '\\defined', '\\define'], true) ? substr($text, 1) : $text;
                if ($id === T_CONSTANT_ENCAPSED_STRING && preg_match('/^[\'"]([A-Za-z0-9_\/.]+)[\'"]$/D', $text, $match) === 1) {
                    $normalized = "'" . $match[1] . "'";
                }
                $tokens[] = ['text' => $normalized, 'offset' => $offset, 'end' => $end];
            }
            $offset = $end;
        }
        return $tokens;
    }

    /** @param list<array{text:string,offset:int,end:int}> $tokens */
    private function closing(array $tokens, int $start, string $open, string $close): int
    {
        if (($tokens[$start]['text'] ?? null) === $open) {
            $depth = 0;
            for ($index = $start; $index < count($tokens); ++$index) {
                if ($tokens[$index]['text'] === $open) {
                    ++$depth;
                } elseif ($tokens[$index]['text'] === $close && --$depth === 0) {
                    return $index;
                }
            }
        }
        throw new ConfigurationException('deepclone source structure drifted: unbalanced declaration');
    }

    private function read(string $path): string
    {
        if (!is_file($path) || is_link($path)) {
            throw new ConfigurationException('deepclone adaptation source is missing or unsafe');
        }
        $source = file_get_contents($path);
        if (!is_string($source)) {
            throw new ConfigurationException('deepclone adaptation source is unreadable');
        }
        return $source;
    }
}
