<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class ProjectDiscovery
{
    public const BUSINESS_PHP = 'business-php';
    public const CONFIG = 'config';
    public const TEMPLATE = 'template';
    public const STATIC_ASSET = 'static';
    public const INSTALL_ONLY = 'install-only';
    public const THIRD_PARTY_DYNAMIC_PHP = 'third-party-dynamic-php';
    public const SOURCE_METADATA = 'source-metadata';
    public const UNCLASSIFIED = 'unclassified';

    /**
     * @param array<string,string> $dynamicPhp Registered third-party view adapter paths and their runtime policy identities.
     */
    public function __construct(
        private readonly string $projectDirectory,
        private readonly array $dynamicPhp = []
    ) {
    }

    public function discover(ProjectProfile $profile): DiscoveryResult
    {
        RuntimeDataPaths::assertNoExcludedPhp($this->projectDirectory);
        $roots = [
            ['path' => 'app', 'owner' => 'project', 'required' => true],
            ['path' => 'support', 'owner' => 'project', 'required' => false],
            ['path' => 'config', 'owner' => 'project', 'required' => false],
            ['path' => 'public', 'owner' => 'project', 'required' => false],
            [
                'path' => 'vendor/workerman/webman-framework/src',
                'owner' => 'webman-core',
                'required' => true,
            ],
        ];
        if ($profile->name() === ProjectProfile::SAIADMIN) {
            $this->assertSaiAdminInstallationSources();
            $roots[] = [
                'path' => 'vendor/saithink/saiadmin/src',
                'owner' => 'saiadmin-core',
                'required' => true,
            ];
        }

        $pluginRoot = $this->absolute('plugin');
        if (is_link($pluginRoot)) {
            throw new ConfigurationException('project discovery refuses symlink: plugin');
        }
        if (is_dir($pluginRoot)) {
            $plugins = [];
            foreach (new \DirectoryIterator($pluginRoot) as $entry) {
                if ($entry->isDot()) {
                    continue;
                }
                if ($entry->isLink()) {
                    throw new ConfigurationException(
                        "project discovery refuses symlink: plugin/{$entry->getFilename()}"
                    );
                }
                if (!$entry->isDir()) {
                    continue;
                }
                $name = $entry->getFilename();
                if (preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
                    throw new ConfigurationException("invalid Webman plugin directory name: {$name}");
                }
                $plugins[] = $name;
            }
            sort($plugins, SORT_STRING);
            foreach ($plugins as $plugin) {
                $roots[] = [
                    'path' => "plugin/{$plugin}",
                    'owner' => "plugin:{$plugin}",
                    'required' => true,
                ];
            }
        }

        $files = [];
        foreach ($roots as $root) {
            $absolute = $this->absolute($root['path']);
            if (is_link($absolute)) {
                throw new ConfigurationException(
                    "project discovery refuses symlink: {$root['path']}"
                );
            }
            if (!is_dir($absolute)) {
                if ($root['required']) {
                    throw new ConfigurationException(
                        "required project source directory is missing: {$root['path']}"
                    );
                }
                continue;
            }
            $this->scanRoot($root['path'], $root['owner'], $files);
        }
        foreach ($this->dynamicPhp as $path => $policy) {
            if (!isset($files[$path])
                || $files[$path]['category'] !== self::THIRD_PARTY_DYNAMIC_PHP
            ) {
                throw new ConfigurationException(
                    "registered third-party dynamic PHP is missing: {$path}"
                );
            }
        }
        ksort($files, SORT_STRING);

        return new DiscoveryResult(array_values($files));
    }

    /**
     * @param array<string, array{path:string,category:string,owner:string}> $files
     */
    private function scanRoot(string $relativeRoot, string $owner, array &$files): void
    {
        $absoluteRoot = $this->absolute($relativeRoot);
        $directory = new \RecursiveDirectoryIterator(
            $absoluteRoot,
            \FilesystemIterator::SKIP_DOTS
        );
        $filter = new \RecursiveCallbackFilterIterator(
            $directory,
            function (\SplFileInfo $entry): bool {
                $relative = $this->relative($entry->getPathname());
                return !RuntimeDataPaths::isSourceExcluded($relative);
            }
        );
        $iterator = new \RecursiveIteratorIterator($filter);
        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                $relative = $this->relative($entry->getPathname());
                throw new ConfigurationException("project discovery refuses symlink: {$relative}");
            }
            if (!$entry->isFile()) {
                continue;
            }
            $path = $this->relative($entry->getPathname());
            $category = $this->classify($path);
            if ($category === null) {
                continue;
            }
            $record = [
                'path' => $path,
                'category' => $category,
                'owner' => $owner,
            ];
            if (isset($files[$path]) && $files[$path] !== $record) {
                throw new ConfigurationException("project file has conflicting discovery owners: {$path}");
            }
            $files[$path] = $record;
        }
    }

    private function classify(string $path): string
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $path), '/'));
        $basename = basename($normalized);
        if ($basename === '.ds_store' || $basename === 'readme.md') {
            return self::SOURCE_METADATA;
        }
        if (array_key_exists($path, $this->dynamicPhp)) {
            if (!str_starts_with($path, 'vendor/')
                || $this->dynamicPhp[$path] !== 'runtime.third-party-dynamic.v1'
                || preg_match('~^vendor/workerman/webman-framework/src/support/view/(Blade|Raw|ThinkPHP|Twig)\.php$~D', $path, $view) !== 1
            ) {
                throw new ConfigurationException(
                    "registered third-party dynamic PHP drifted: {$path}"
                );
            }
            $source = file_get_contents($this->absolute($path));
            if (!is_string($source)) {
                throw new ConfigurationException("registered third-party view adapter cannot be read: {$path}");
            }
            (new WebmanViewAdapterPolicy())->validate($source, $view[1]);
            return self::THIRD_PARTY_DYNAMIC_PHP;
        }
        if ($path === 'support/Setup.php' && $this->isComposerSetup()) {
            return self::INSTALL_ONLY;
        }
        if (str_starts_with($normalized, 'vendor/saithink/saiadmin/src/orm/')) {
            return self::INSTALL_ONLY;
        }
        $installedPrefix = 'vendor/saithink/saiadmin/src/plugin/saiadmin/';
        if (str_starts_with($normalized, $installedPrefix)) {
            $installedPath = 'plugin/saiadmin/' . substr($path, strlen($installedPrefix));
            $source = $this->absolute($path);
            $installed = $this->absolute($installedPath);
            if (!is_file($installed)
                || is_link($installed)
                || hash_file('sha256', $source) !== hash_file('sha256', $installed)
            ) {
                throw new ConfigurationException(
                    "SaiAdmin installation copy differs from active plugin: {$path}"
                );
            }
            return self::INSTALL_ONLY;
        }
        if (in_array($normalized, [
            'vendor/workerman/webman-framework/src/install.php',
            'vendor/workerman/webman-framework/src/start.php',
            'vendor/workerman/webman-framework/src/windows.php',
            'vendor/workerman/webman-framework/src/support/plugin.php',
        ], true)
            || preg_match('#^plugin/[^/]+/(?:install\.php|db/)#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/(?:install\.php|db/)#D',
                $normalized
            ) === 1
        ) {
            return self::INSTALL_ONLY;
        }
        if (str_starts_with($normalized, 'config/')
            || preg_match('#^plugin/[^/]+/config/#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/config/#D',
                $normalized
            ) === 1
        ) {
            return self::CONFIG;
        }
        if (preg_match('#^app/(?:view|views|template|templates)/#D', $normalized) === 1
            || preg_match(
                '#^plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/app/(?:view|views|template|templates)/#D',
                $normalized
            ) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/utils/code/stub/#D',
                $normalized
            ) === 1
        ) {
            return self::TEMPLATE;
        }
        if (str_starts_with($normalized, 'public/')
            || preg_match('#^plugin/[^/]+/public/#D', $normalized) === 1
            || preg_match(
                '#^vendor/saithink/saiadmin/src/plugin/[^/]+/public/#D',
                $normalized
            ) === 1
        ) {
            return self::STATIC_ASSET;
        }
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'php') {
            return self::BUSINESS_PHP;
        }

        return self::UNCLASSIFIED;
    }

    private function isComposerSetup(): bool
    {
        $composerFile = $this->absolute('composer.json');
        $composer = is_file($composerFile) && !is_link($composerFile)
            ? json_decode((string) file_get_contents($composerFile), true)
            : null;
        if (!is_array($composer)) {
            return false;
        }
        foreach (['post-create-project-cmd', 'setup-webman'] as $event) {
            $commands = $composer['scripts'][$event] ?? [];
            if (!in_array('support\\Setup::run', (array) $commands, true)) {
                return false;
            }
        }
        foreach (array_merge($composer['autoload']['files'] ?? [], $composer['autoload-dev']['files'] ?? []) as $file) {
            if (is_string($file) && str_ends_with(str_replace('\\', '/', $file), 'support/Setup.php')) {
                return false;
            }
        }
        $source = file_get_contents($this->absolute('support/Setup.php'));
        if (!is_string($source)) {
            return false;
        }
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError) {
            return false;
        }
        $tokens = array_values(array_filter($tokens, static fn(array|string $token): bool =>
            !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));
        $text = static fn(array|string $token): string => is_array($token) ? $token[1] : $token;
        $namespace = false;
        $class = false;
        $hasRun = false;
        for ($index = 0, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_OPEN_TAG) {
                continue;
            }
            if (is_array($token) && in_array($token[0], [T_NAMESPACE, T_USE, T_DECLARE], true)) {
                $declaration = '';
                $kind = $token[0];
                while (++$index < $count && $tokens[$index] !== ';') {
                    $declaration .= $text($tokens[$index]);
                }
                if ($kind === T_NAMESPACE) {
                    if ($namespace || $declaration !== 'support') { return false; }
                    $namespace = true;
                } elseif ($kind === T_DECLARE && $declaration !== '(strict_types=1)') {
                    return false;
                }
                continue;
            }
            if (is_array($token) && $token[0] === T_FINAL) {
                continue;
            }
            if (is_array($token) && $token[0] === T_CLASS && !$class) {
                if (!$namespace || $text($tokens[++$index] ?? '') !== 'Setup'
                    || ($tokens[++$index] ?? '') !== '{'
                ) { return false; }
                $class = true;
                $depth = 1;
                while (++$index < $count && $depth > 0) {
                    $entry = $tokens[$index];
                    if ($entry === '{' || (is_array($entry) && in_array($entry[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                        $depth++;
                    } elseif ($entry === '}') {
                        $depth--;
                    }
                    if ($depth === 1 && is_array($entry) && $entry[0] === T_PUBLIC
                        && ($tokens[$index + 1][0] ?? null) === T_STATIC
                        && ($tokens[$index + 2][0] ?? null) === T_FUNCTION
                        && ($tokens[$index + 3][0] ?? null) === T_STRING
                        && strtolower($tokens[$index + 3][1]) === 'run'
                        && ($tokens[$index + 4] ?? null) === '('
                    ) { $hasRun = true; }
                }
                $index--;
                continue;
            }
            return false;
        }
        if (!$class || !$hasRun) {
            return false;
        }
        foreach (['app', 'support', 'config', 'plugin'] as $directory) {
            $root = $this->absolute($directory);
            if (!is_dir($root)) { continue; }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (!$file->isFile() || strtolower($file->getExtension()) !== 'php'
                    || $file->getPathname() === $this->absolute('support/Setup.php')
                ) { continue; }
                if ($file->isLink()) { return false; }
                foreach (token_get_all((string) file_get_contents($file->getPathname())) as $entry) {
                    if (!is_array($entry)) { continue; }
                    if (($entry[0] === T_STRING && strtolower($entry[1]) === 'setup')
                        || (in_array($entry[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                            && strtolower(ltrim($entry[1], '\\')) === 'support\\setup')
                        || ($entry[0] === T_CONSTANT_ENCAPSED_STRING
                            && preg_match('~(?:support[\\\\/]+Setup|Setup(?:\.php)?[\'\"])~i', $entry[1]) === 1)
                    ) { return false; }
                }
            }
        }
        return true;
    }

    private function assertSaiAdminInstallationSources(): void
    {
        $ormRoot = $this->absolute('vendor/saithink/saiadmin/src/orm');
        $templateRoot = $this->absolute('vendor/saithink/saiadmin/src/plugin/saiadmin');
        if (!is_dir($ormRoot) && !is_dir($templateRoot)) {
            return;
        }
        $installer = $this->absolute('vendor/saithink/saiadmin/src/Install.php');
        $ormCommand = $this->absolute(
            'vendor/saithink/saiadmin/src/plugin/saiadmin/command/SaiOrm.php'
        );
        $installSource = is_file($installer) ? file_get_contents($installer) : false;
        $ormSource = is_file($ormCommand) ? file_get_contents($ormCommand) : false;
        if (!is_string($installSource)
            || !str_contains($installSource, "'plugin/saiadmin' => 'plugin/saiadmin'")
            || !str_contains($installSource, 'copy_dir(')
            || !is_string($ormSource)
            || !str_contains($ormSource, "vendor/saithink/saiadmin/src/orm/")
            || !str_contains($ormSource, 'copyDirectory(')
        ) {
            throw new ConfigurationException(
                'SaiAdmin installation-source contract drifted'
            );
        }
    }

    private function relative(string $path): string
    {
        $root = rtrim(realpath($this->projectDirectory) ?: $this->projectDirectory, '/\\');
        $normalizedRoot = str_replace('\\', '/', $root);
        $normalizedPath = str_replace('\\', '/', $path);
        $prefix = $normalizedRoot . '/';
        if (!str_starts_with($normalizedPath, $prefix)) {
            throw new ConfigurationException("discovered path escaped project root: {$path}");
        }

        return substr($normalizedPath, strlen($prefix));
    }

    private function absolute(string $relativePath): string
    {
        return rtrim($this->projectDirectory, '/\\')
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    }
}
