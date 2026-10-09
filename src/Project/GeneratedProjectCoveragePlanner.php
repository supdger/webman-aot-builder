<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class GeneratedProjectCoveragePlanner
{
    /**
     * @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $generatedMappings
     * @param array<string,string> $generatedAdaptations
     * @return array{
     *   discovery:DiscoveryResult,
     *   coverage:CoverageLedger,
     *   resources:RuntimeResourceManifest,
     *   compiled:array{direct:int,shadow:int}
     * }
     */
    public function plan(
        string $mirrorDirectory,
        ProjectProfile $profile,
        string $compatibilityLockFile,
        array $generatedMappings = [],
        array $generatedAdaptations = []
    ): array {
        $mirror = realpath($mirrorDirectory);
        if (!is_string($mirror)
            || is_link($mirrorDirectory)
            || !ProjectMirror::isOwnedPath($mirror)
        ) {
            throw new ConfigurationException(
                'generated coverage requires an isolated project mirror'
            );
        }
        $lockSource = is_file($compatibilityLockFile)
            && !is_link($compatibilityLockFile)
            ? file_get_contents($compatibilityLockFile)
            : false;
        if (!is_string($lockSource)) {
            throw new ConfigurationException('generated coverage compatibility lock is missing');
        }
        try {
            $lock = json_decode($lockSource, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                'generated coverage compatibility lock is invalid',
                previous: $exception
            );
        }
        if (!is_array($lock)
            || ($lock['schema'] ?? null) !== 'webman-aot-builder-upstream-generator-lock-v1'
            || !is_array($lock['mappings'] ?? null)
            || !is_array($lock['entrypointMapping'] ?? null)
            || !is_array($lock['dynamicPhp'] ?? null)
            || !is_array($lock['runtimeResources'] ?? null)
        ) {
            throw new ConfigurationException(
                'generated coverage compatibility lock shape drifted'
            );
        }

        $discovery = (new ProjectDiscovery($mirror, $lock['dynamicPhp']))
            ->discover($profile);
        $businessPaths = [];
        foreach ($discovery->files() as $file) {
            if ($file['category'] === ProjectDiscovery::BUSINESS_PHP) {
                $businessPaths[$file['path']] = true;
            }
        }

        $mappings = [];
        $shadows = [];
        foreach ($generatedMappings as $generated) {
            if (!is_array($generated)
                || !is_string($generated['path'] ?? null)
                || !is_string($generated['shadow'] ?? null)
                || !is_string($generated['sourceSha256'] ?? null)
                || !is_string($generated['shadowSha256'] ?? null)
            ) {
                throw new ConfigurationException('generated coverage mapping evidence is invalid');
            }
            $source = $generated['path'];
            $shadow = $generated['shadow'];
            if (isset($mappings[$source]) || isset($shadows[$shadow])
                || !str_starts_with($shadow, '.typephp/build/')
                || !str_ends_with($source, '.php') || !str_ends_with($shadow, '.php')
                || $this->digest($mirror, $source) !== $generated['sourceSha256']
                || $this->digest($mirror, $shadow) !== $generated['shadowSha256']
                || (isset($lock['mappings'][$source])
                    && ($lock['mappings'][$source]['shadow'] ?? null) !== $shadow)
            ) {
                throw new ConfigurationException("generated coverage mapping evidence drifted: {$source}");
            }
            $mappings[$source] = $generated;
            $shadows[$shadow] = true;
        }
        foreach ($lock['mappings'] as $source => $mapping) {
            if (!isset($mappings[$source])) {
                throw new ConfigurationException("generated coverage mapping evidence is missing: {$source}");
            }
        }

        $decisions = [];
        foreach ($mappings as $source => $mapping) {
            if (!isset($businessPaths[$source])) {
                continue;
            }
            if (!is_array($mapping)
                || !is_string($mapping['shadow'] ?? null)
                || !is_string($mapping['sourceSha256'] ?? null)
                || !is_string($mapping['shadowSha256'] ?? null)
                || $this->digest($mirror, $source) !== $mapping['sourceSha256']
            ) {
                throw new ConfigurationException(
                    "generated coverage source mapping drifted: {$source}"
                );
            }
            $expectedShadowSha256 = $mapping['shadowSha256'];
            if (!is_string($expectedShadowSha256)
                || preg_match('/^[a-f0-9]{64}$/D', $expectedShadowSha256) !== 1
                || $this->digest($mirror, $mapping['shadow'])
                    !== $expectedShadowSha256
            ) {
                throw new ConfigurationException(
                    "generated coverage shadow mapping drifted: {$source}"
                );
            }
            $decisions[$source] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => 'upstream.typephp-generated-shadow.v1',
                'reason' => 'verified upstream AOT replacement is compiled instead of the original',
                'replacement' => $mapping['shadow'],
            ];
        }

        $entrypoint = $lock['entrypointMapping'];
        $entrypoint['sourceSha256'] = $generatedAdaptations['bootstrapSourceSha256'] ?? null;
        $entrypoint['replacementSha256'] = $generatedAdaptations['bootstrapReplacementSha256'] ?? null;
        if (($entrypoint['source'] ?? null)
                !== 'vendor/workerman/webman-framework/src/support/bootstrap.php'
            || ($entrypoint['replacement'] ?? null) !== 'main.php'
            || ($entrypoint['policy'] ?? null)
                !== 'upstream.webman-bootstrap-entrypoint.v1'
            || !is_string($entrypoint['sourceSha256'] ?? null)
            || !is_string($entrypoint['replacementSha256'] ?? null)
            || !isset($businessPaths[$entrypoint['source']])
            || $this->digest($mirror, $entrypoint['source'])
                !== $entrypoint['sourceSha256']
            || $this->digest($mirror, $entrypoint['replacement'])
                !== $entrypoint['replacementSha256']
            || isset($decisions[$entrypoint['source']])
        ) {
            throw new ConfigurationException(
                'generated coverage entrypoint mapping drifted'
            );
        }
        $decisions[$entrypoint['source']] = [
            'status' => CoverageLedger::COMPILED_SHADOW,
            'policy' => $entrypoint['policy'],
            'reason' => 'verified Webman bootstrap is replaced by the compiled entrypoint',
            'replacement' => 'main.php',
        ];
        $projectBootstrap = 'support/bootstrap.php';
        if (isset($businessPaths[$projectBootstrap])) {
            $projectBootstrapDigest = $this->digest($mirror, $projectBootstrap);
            $projectBytes = $projectBootstrapDigest !== null
                ? file_get_contents($mirror . '/' . $projectBootstrap)
                : false;
            $vendorBytes = file_get_contents($mirror . '/' . $entrypoint['source']);
            if (!is_string($projectBytes)
                || !is_string($vendorBytes)
                || hash('sha256', $vendorBytes) !== $entrypoint['sourceSha256']
                || (str_replace("\r\n", "\n", $projectBytes) !== $vendorBytes
                    && !$this->isBootstrapWrapper($projectBytes))
                || $this->digest($mirror, $projectBootstrap) !== $projectBootstrapDigest
            ) {
                throw new ConfigurationException(
                    'project bootstrap differs from the verified Webman startup source'
                );
            }
            $entrypoint['projectSourceSha256'] = $projectBootstrapDigest;
            $decisions[$projectBootstrap] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => $entrypoint['policy'],
                'reason' => 'the stock project bootstrap is replaced by the compiled entrypoint',
                'replacement' => 'main.php',
            ];
        }
        foreach (['Request', 'Response'] as $name) {
            $source = "vendor/workerman/webman-framework/src/support/{$name}.php";
            $replacement = "support/{$name}.php";
            if (!isset($businessPaths[$source])) {
                continue;
            }
            if (!file_exists($mirror . '/' . $replacement) && !is_link($mirror . '/' . $replacement)) {
                continue;
            }
            if (!isset($businessPaths[$replacement])
                || !$this->isSupportClass($mirror, $source, $name)
                || !$this->isSupportClass($mirror, $replacement, $name)
            ) {
                throw new ConfigurationException(
                    "Webman project support override is missing or structurally invalid: {$name}"
                );
            }
            $decisions[$source] = [
                'status' => CoverageLedger::COMPILED_SHADOW,
                'policy' => 'webman.project-support-override.v1',
                'reason' => 'compiled project support class supersedes the inactive framework fallback',
                'replacement' => $replacement,
            ];
        }

        $coverage = (new CoveragePlanner($mirror))->plan($discovery, $decisions);
        $compiled = (new CompilerCoverageAudit($entrypoint))->audit(
            $mirror,
            $coverage
        );
        $resources = (new RuntimeResourcePlanner($mirror))->plan(
            $discovery,
            $coverage,
            $this->requiredRuntimeFiles($mirror, $lock['runtimeResources'])
        );
        return [
            'discovery' => $discovery,
            'coverage' => $coverage,
            'resources' => $resources,
            'compiled' => $compiled,
        ];
    }

    /**
     * @param array<string,string> $policies
     * @return list<array{path:string,role:string}>
     */
    private function requiredRuntimeFiles(string $mirror, array $policies): array
    {
        $composerFile = $mirror . '/composer.lock';
        $composer = is_file($composerFile) && !is_link($composerFile)
            ? json_decode((string) file_get_contents($composerFile), true)
            : null;
        if (!is_array($composer)) {
            throw new ConfigurationException('runtime resource Composer lock is missing or invalid');
        }
        $captcha = null;
        foreach (array_merge($composer['packages'] ?? [], $composer['packages-dev'] ?? []) as $package) {
            if (is_array($package) && ($package['name'] ?? null) === 'webman/captcha') {
                if ($captcha !== null) {
                    throw new ConfigurationException('webman/captcha package declaration is duplicated');
                }
                $captcha = $package;
            }
        }
        if ($captcha === null) {
            return [];
        }
        if (($policies['webman/captcha'] ?? null) !== 'runtime.captcha-font-resources.v1') {
            throw new ConfigurationException('webman/captcha runtime font resource policy is missing or unsupported');
        }
        $prefix = 'vendor/webman/captcha/src/';
        $builderPath = $prefix . 'CaptchaBuilder.php';
        $builderDigest = $this->digest($mirror, $builderPath);
        $builder = $builderDigest !== null ? file_get_contents($mirror . '/' . $builderPath) : false;
        if (!is_string($builder)) {
            throw new ConfigurationException('webman/captcha runtime font builder is missing or unsafe');
        }
        $selection = (new CaptchaFontResourcePolicy())->validate($builder);
        $minimum = $selection['minimum'];
        $maximum = $selection['maximum'];
        $fontDirectory = $mirror . '/' . $prefix . 'Font';
        if (!is_dir($fontDirectory) || is_link($fontDirectory)) {
            throw new ConfigurationException('webman/captcha runtime font directory is missing or unsafe');
        }
        $actual = [];
        foreach (new \DirectoryIterator($fontDirectory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isLink() || !$entry->isFile()
                || preg_match('/^captcha(?:0|[1-9][0-9]*)\.ttf$/D', $entry->getFilename()) !== 1
                || $this->digest($mirror, $prefix . 'Font/' . $entry->getFilename()) === null
            ) {
                throw new ConfigurationException('webman/captcha runtime font directory contains an unsafe resource');
            }
            $actual[$entry->getFilename()] = true;
        }
        $required = [];
        for ($index = $minimum; $index <= $maximum; $index++) {
            $name = 'captcha' . $index . '.ttf';
            if (!isset($actual[$name])) {
                throw new ConfigurationException("webman/captcha runtime font is missing: {$name}");
            }
        }
        ksort($actual, SORT_STRING);
        foreach (array_keys($actual) as $name) {
            $required[] = ['path' => $prefix . 'Font/' . $name, 'role' => 'font'];
        }
        if ($this->digest($mirror, $builderPath) !== $builderDigest) {
            throw new ConfigurationException('webman/captcha runtime font builder changed during planning');
        }
        return $required;
    }

    private function digest(string $mirror, string $relative): ?string
    {
        if ($relative === ''
            || str_starts_with($relative, '/')
            || str_contains($relative, '\\')
            || preg_match('/^[A-Za-z]:/', $relative) === 1
            || in_array('..', explode('/', $relative), true)
            || in_array('.', explode('/', $relative), true)
            || in_array('', explode('/', $relative), true)
        ) {
            return null;
        }
        $path = $mirror;
        foreach (explode('/', $relative) as $component) {
            $path .= '/' . $component;
            if (is_link($path)) {
                return null;
            }
        }
        $digest = is_file($path) && !is_link($path)
            ? hash_file('sha256', $path)
            : false;
        return is_string($digest) ? $digest : null;
    }

    private function isBootstrapWrapper(string $source): bool
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }
        $tokens = array_values(array_filter($tokens, static fn(array|string $token): bool =>
            !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        if (count($tokens) === 7 && $tokens[1] === '(' && $tokens[5] === ')') {
            array_splice($tokens, 5, 1);
            array_splice($tokens, 1, 1);
        }
        return count($tokens) === 5
            && is_array($tokens[0]) && $tokens[0][0] === T_REQUIRE_ONCE
            && is_array($tokens[1]) && $tokens[1][0] === T_DIR
            && $tokens[2] === '.'
            && is_array($tokens[3]) && $tokens[3][0] === T_CONSTANT_ENCAPSED_STRING
            && in_array($tokens[3][1], [
                "'/../vendor/workerman/webman-framework/src/support/bootstrap.php'",
                '"/../vendor/workerman/webman-framework/src/support/bootstrap.php"',
            ], true)
            && $tokens[4] === ';';
    }

    private function isSupportClass(string $mirror, string $relative, string $name): bool
    {
        if ($this->digest($mirror, $relative) === null) { return false; }
        $source = file_get_contents($mirror . '/' . $relative);
        if (!is_string($source)) { return false; }
        try { $tokens = token_get_all($source, TOKEN_PARSE); } catch (\ParseError) { return false; }
        $tokens = array_values(array_filter($tokens, static fn(array|string $token): bool =>
            !is_array($token) || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $text = static fn(array|string $token): string => is_array($token) ? $token[1] : $token;
        $namespace = null;
        $namespaceDepth = 0;
        $depth = 0;
        $aliases = [];
        $found = 0;
        foreach ($tokens as $index => $token) {
            $id = is_array($token) ? $token[0] : null;
            if ($id === T_NAMESPACE) {
                if ($namespace !== null || $depth !== 0) { return false; }
                $namespace = strtolower($text($tokens[$index + 1] ?? ''));
                if ($namespace !== 'support' || !in_array($tokens[$index + 2] ?? null, [';', '{'], true)) { return false; }
                $namespaceDepth = ($tokens[$index + 2] ?? null) === '{' ? 1 : 0;
            }
            if ($id === T_USE && $depth === $namespaceDepth && ($tokens[$index + 1] ?? null) !== '(') {
                $declaration = '';
                for ($cursor = $index + 1; isset($tokens[$cursor]) && $tokens[$cursor] !== ';'; ++$cursor) {
                    $part = $tokens[$cursor];
                    $declaration .= is_array($part) && $part[0] === T_AS ? ' as ' : $text($part);
                }
                if (!isset($tokens[$cursor])) { return false; }
                if (in_array($tokens[$index + 1][0] ?? null, [T_FUNCTION, T_CONST], true)) { continue; }
                $prefix = '';
                if (str_contains($declaration, '{')) {
                    if (preg_match('/^(.+\\\\)\{([^{}]+)\}$/D', $declaration, $group) !== 1) { return false; }
                    $prefix = $group[1]; $declaration = $group[2];
                }
                foreach (explode(',', $declaration) as $import) {
                    $parts = preg_split('/\s+as\s+/i', $import);
                    if (!is_array($parts) || count($parts) > 2) { return false; }
                    $target = ltrim($prefix . $parts[0], '\\');
                    $alias = strtolower($parts[1] ?? substr($target, (int) strrpos('\\' . $target, '\\')));
                    if ($alias === '' || isset($aliases[$alias])) { return false; }
                    $aliases[$alias] = $target;
                }
            }
            if ($id === T_CLASS && ($tokens[$index - 1][0] ?? null) !== T_DOUBLE_COLON
                && is_array($tokens[$index + 1] ?? null) && $tokens[$index + 1][0] === T_STRING
                && strcasecmp($tokens[$index + 1][1], $name) === 0
            ) {
                if ($namespace !== 'support' || $depth !== $namespaceDepth
                    || ($tokens[$index + 2][0] ?? null) !== T_EXTENDS
                ) { return false; }
                $parent = $text($tokens[$index + 3] ?? '');
                if (!str_starts_with($parent, '\\')) {
                    $parts = explode('\\', $parent, 2);
                    $parent = isset($aliases[strtolower($parts[0])])
                        ? $aliases[strtolower($parts[0])] . (isset($parts[1]) ? '\\' . $parts[1] : '')
                        : $namespace . '\\' . $parent;
                }
                if (strcasecmp(ltrim($parent, '\\'), 'Webman\\Http\\' . $name) !== 0 || ++$found !== 1) { return false; }
            }
            if ($token === '{') { ++$depth; }
            if ($token === '}') { --$depth; }
        }
        return $found === 1;
    }
}
