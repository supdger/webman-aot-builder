<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\ProjectProfile;

final class GenericProjectGenerator
{
    /**
     * @return array{mappings:list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}>,mainFile:string,entrypointMapping:?array}
     */
    public function generate(
        string $mirror,
        string $stubFile,
        string $stubSha256,
        string $outputName,
        array $dynamicPhp = [],
        ?string $targetPhpVersion = null,
        array $targetCapabilities = []
    ): array {
        $resolved = realpath($mirror);
        if (!is_string($resolved) || $resolved !== $mirror || is_link($mirror)
            || !\WebmanAotBuilder\Project\ProjectMirror::isOwnedPath($mirror)
            || is_link($mirror . '/.typephp') || is_link($mirror . '/.typephp/build')
        ) {
            throw new ConfigurationException('PHP generation requires an isolated project mirror');
        }
        $build = $mirror . '/.typephp/build';
        if (!is_dir($build) && !mkdir($build, 0700, true) && !is_dir($build)) {
            throw new ConfigurationException('cannot create PHP generation directory');
        }
        $frameworkBootstrap = 'vendor/workerman/webman-framework/src/support/bootstrap.php';
        $webman = is_file($mirror . '/' . $frameworkBootstrap);
        if (!$webman && is_file($mirror . '/support/bootstrap.php')
            && is_file($mirror . '/vendor/workerman/webman-framework/src/support/App.php')
            && is_file($mirror . '/vendor/workerman/workerman/src/Worker.php')
        ) {
            $app = $this->read($mirror . '/vendor/workerman/webman-framework/src/support/App.php');
            $webman = preg_match('/\bnamespace\s+support\s*;/', $app) === 1
                && preg_match('/\bclass\s+App\b/', $app) === 1
                && preg_match('/\bstatic\s+function\s+run\s*\(/', $app) === 1;
            if ($webman) {
                $frameworkBootstrap = 'support/bootstrap.php';
            }
        }
        $profile = new ProjectProfile(ProjectProfile::PHP, [], []);
        $registeredDynamic = [];
        foreach ($dynamicPhp as $path => $digest) {
            if (is_string($path) && is_string($digest) && str_starts_with($path, 'vendor/')
                && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1
                && is_file($mirror . '/' . $path) && !is_link($mirror . '/' . $path)
                && hash_file('sha256', $mirror . '/' . $path) === $digest
            ) {
                $registeredDynamic[$path] = $digest;
            }
        }
        $discovery = (new ProjectDiscovery($mirror, $registeredDynamic))->discover($profile, false);
        $mainFile = $this->findMain($mirror, $discovery->files());
        if ($mainFile === null && $webman) {
            $stub = $this->read($stubFile);
            if (!hash_equals($stubSha256, hash('sha256', $stub))) {
                throw new ConfigurationException('locked PHP generator main stub drifted');
            }
            if (file_exists($mirror . '/main.php')) {
                throw new ConfigurationException('TypePHP entrypoint error: existing main.php does not declare global main()');
            }
            $this->write($mirror . '/main.php', $stub);
            $mainFile = 'main.php';
        }
        if ($mainFile === null) {
            throw new ConfigurationException('TypePHP entrypoint missing: binary compilation requires a global main(): void');
        }
        $upstream = new \Tinywan\Typephp\Compiler\ProjectGenerator($mirror);
        $variants = (new CarbonLazyVariants())->apply($mirror, $upstream, $targetPhpVersion);
        $mappings = array_merge($variants, $this->adaptSources($mirror, $upstream, array_column($variants, 'path')));
        $mappings = array_merge(
            $mappings,
            (new ConditionalDeclarationSelector())->apply($mirror, $discovery->files(), $mappings, $targetCapabilities)
        );
        $mappings = array_merge(
            $mappings,
            (new ConditionalFileLoader())->apply($mirror, $discovery->files(), $mappings)
        );
        if ($targetCapabilities !== []) {
            $this->write(
                $build . '/target-php-capabilities.json',
                json_encode($targetCapabilities, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
            );
        }
        $entrypoint = null;
        $entrypointShape = new WebmanEntrypointShape();
        if ($webman && $mainFile === 'main.php'
            && hash_file('sha256', $mirror . '/main.php') === $stubSha256
            && $entrypointShape->bootstrap($this->read($mirror . '/' . $frameworkBootstrap))
        ) {
            $extra = [];
            if ($frameworkBootstrap !== 'support/bootstrap.php' && is_file($mirror . '/support/bootstrap.php')) {
                $source = $this->read($mirror . '/support/bootstrap.php');
                if ($entrypointShape->bootstrap($source)) {
                    $extra['support/bootstrap.php'] = hash('sha256', $source);
                } else {
                    fwrite(STDERR, "[adapt] skipped support/bootstrap.php: unrecognized bootstrap statements remain compiler input\n");
                }
            }
            foreach (['start.php', 'windows.php'] as $path) {
                if (!is_file($mirror . '/' . $path)) {
                    continue;
                }
                $source = $this->read($mirror . '/' . $path);
                if ($entrypointShape->startup($source)) {
                    $extra[$path] = hash('sha256', $source);
                } else {
                    fwrite(STDERR, "[adapt] skipped {$path}: unrecognized startup statements remain compiler input\n");
                }
            }
            $entrypoint = [
                'source' => $frameworkBootstrap,
                'sourceSha256' => hash('sha256', $this->read($mirror . '/' . $frameworkBootstrap)),
                'replacement' => 'main.php',
                'replacementSha256' => $stubSha256,
                'policy' => 'upstream.webman-bootstrap-entrypoint.v1',
                'sources' => $extra,
            ];
        } elseif ($webman && $mainFile === 'main.php' && hash_file('sha256', $mirror . '/main.php') === $stubSha256) {
            fwrite(STDERR, "[adapt] skipped {$frameworkBootstrap}: unrecognized bootstrap statements remain compiler input\n");
        }
        $this->write(
            $build . '/entrypoint-mapping.json',
            json_encode($entrypoint, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        $this->manifest($mirror, $mainFile, $mappings, $entrypoint, $outputName, $registeredDynamic);
        $sourceFiles = (new ProjectDiscovery($mirror, $registeredDynamic))->discover($profile, false)->files();
        $this->write(
            $build . '/source-inputs.json',
            json_encode([
                'schema' => 'webman-aot-builder-source-inputs-v1',
                'paths' => array_column($sourceFiles, 'path'),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n"
        );
        return ['mappings' => $mappings, 'mainFile' => $mainFile, 'entrypointMapping' => $entrypoint];
    }

    /** @param list<array{path:string,category:string,owner:string}> $files */
    private function findMain(string $mirror, array $files): ?string
    {
        usort($files, static fn(array $a, array $b): int =>
            ($a['path'] === 'main.php' ? -1 : ($b['path'] === 'main.php' ? 1 : strcmp($a['path'], $b['path']))));
        foreach ($files as $file) {
            if ($file['category'] !== ProjectDiscovery::BUSINESS_PHP) {
                continue;
            }
            $tokens = token_get_all($this->read($mirror . '/' . $file['path']));
            $namespace = '';
            $namespaceDepth = 0;
            $depth = 0;
            foreach ($tokens as $index => $token) {
                if ($token === '{'
                    || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))
                ) {
                    ++$depth;
                } elseif ($token === '}') {
                    --$depth;
                    if ($depth < $namespaceDepth) {
                        $namespace = '';
                        $namespaceDepth = 0;
                    }
                } elseif (is_array($token) && $token[0] === T_NAMESPACE) {
                    $namespace = '';
                    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                        if ($tokens[$next] === ';' || $tokens[$next] === '{') {
                            $namespaceDepth = $tokens[$next] === '{' ? $depth + 1 : 0;
                            break;
                        }
                        if (is_array($tokens[$next]) && in_array($tokens[$next][0], [T_STRING, T_NAME_QUALIFIED], true)) {
                            $namespace .= $tokens[$next][1];
                        }
                    }
                } elseif (is_array($token) && $token[0] === T_FUNCTION
                    && $namespace === '' && $depth === $namespaceDepth
                ) {
                    for ($next = $index + 1; isset($tokens[$next]); ++$next) {
                        $name = $tokens[$next];
                        if (is_array($name) && in_array($name[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            continue;
                        }
                        if (is_array($name) && $name[0] === T_STRING && strtolower($name[1]) === 'main') {
                            return $file['path'];
                        }
                        break;
                    }
                }
            }
        }
        return null;
    }

    /** @return list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> */
    private function adaptSources(string $mirror, object $upstream, array $alreadyMapped): array
    {
        $rules = [];
        foreach ([
            'GUARDED_SOURCES', 'NULLABLE_STATIC_SOURCES', 'STRAY_BOOTSTRAP_SOURCES',
            'PRELOAD_HINT_SOURCES', 'VARIADIC_HANDLER_SOURCES', 'SWITCH_TERMINAL_SOURCES',
            'REF_CAPTURE_SOURCES',
        ] as $kind) {
            foreach (constant($upstream::class . '::' . $kind) as $source => $specification) {
                $shadow = is_array($specification) ? $specification[0] : $specification;
                $rules[$source]['shadow'] = $shadow;
                $rules[$source]['kinds'][] = $kind;
            }
        }
        foreach ($upstream->discoverProjectGuardedSources() as $source => $shadow) {
            $rules[$source] = ['shadow' => $shadow, 'kinds' => ['GUARDED_SOURCES']];
        }
        ksort($rules, SORT_STRING);
        $mappings = [];
        foreach ($rules as $path => $rule) {
            if (in_array($path, $alreadyMapped, true) || !is_file($mirror . '/' . $path)) {
                continue;
            }
            $source = $this->read($mirror . '/' . $path);
            $shadow = $source;
            set_error_handler(
                static function (int $severity, string $message): never {
                    throw new ConfigurationException("PHP compatibility transform failed: {$message}");
                },
                E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE
            );
            try {
                foreach ($rule['kinds'] as $kind) {
                    $shadow = $this->transform($upstream, $kind, $path, $shadow);
                }
                token_get_all($shadow, TOKEN_PARSE);
            } catch (\RuntimeException | \ParseError $exception) {
                if ($exception instanceof ConfigurationException
                    || str_starts_with($exception->getMessage(), 'Unable to ')
                ) {
                    throw $exception;
                }
                fwrite(STDERR, "[adapt] skipped {$path}: {$exception->getMessage()}\n");
                continue;
            } finally {
                restore_error_handler();
            }
            if ($source === $shadow) {
                continue;
            }
            $this->write($mirror . '/' . $rule['shadow'], $shadow);
            $mappings[] = [
                'path' => $path,
                'shadow' => $rule['shadow'],
                'sourceSha256' => hash('sha256', $source),
                'shadowSha256' => hash('sha256', $shadow),
                'contractSha256' => hash('sha256', $shadow),
            ];
        }
        return $mappings;
    }

    private function transform(object $generator, string $kind, string $path, string $content): string
    {
        $call = static fn(string $method, mixed ...$arguments): string =>
            (new \ReflectionMethod($generator, $method))->invoke($generator, ...$arguments);
        switch ($kind) {
            case 'GUARDED_SOURCES':
                return $call('flattenGuardedSource', $call('prepareGuardedSource', $path, $content));
            case 'NULLABLE_STATIC_SOURCES':
                return $call('stripStrayBootstrapCalls', $call('patchUninitializedScalarStatics', $content));
            case 'STRAY_BOOTSTRAP_SOURCES':
                $content = $call('stripStrayBootstrapCalls', $content);
                return $path === 'vendor/workerman/coroutine/src/Context/Fiber.php'
                    ? $call('patchCoroutineFiberContextWrites', $content) : $content;
            case 'PRELOAD_HINT_SOURCES':
                $content = (string) preg_replace('/^class_exists\\([^;\\r\\n]+\\);[ \\t]*\\R/m', '', $content);
                return match ($path) {
                    'vendor/symfony/http-kernel/HttpKernel.php' => $call('patchSymfonyHttpKernelEvents', $content),
                    'vendor/symfony/http-foundation/Session/Storage/NativeSessionStorage.php' => $call('patchSymfonyNativeSessionStorage', $content),
                    default => $content,
                };
            case 'VARIADIC_HANDLER_SOURCES':
                return $call('patchVariadicHandlers', $path, $content);
            case 'SWITCH_TERMINAL_SOURCES':
                return $call('patchSwitchTerminals', $path, $content);
            case 'REF_CAPTURE_SOURCES':
                return $call('patchRefCaptures', $path, $content);
        }
        throw new ConfigurationException("unknown PHP generator transform: {$kind}");
    }

    /** @param list<array{path:string,shadow:string,sourceSha256:string,shadowSha256:string}> $mappings */
    private function manifest(
        string $mirror,
        string $main,
        array $mappings,
        ?array $entrypoint,
        string $outputName,
        array $dynamicPhp
    ): void {
        $projectFile = $mirror . '/project.linux.yml';
        if (!is_file($projectFile) && is_file($mirror . '/project.yml')) {
            $this->write($projectFile, $this->read($mirror . '/project.yml'));
        }
        if (!is_file($projectFile)) {
            $this->write(
                $projectFile,
                "name: {$outputName}\n\nsources:\n  - {$main}\n\nignore:\n\n"
                    . "output: build/{$outputName}\nmode: bin\noptimize: 2\njob: 4\ndebug: false\n"
            );
        }
        $project = $this->read($projectFile);
        $project = (string) preg_replace('/^output:[^\r\n]*$/m', "output: build/{$outputName}", $project);
        $project = (string) preg_replace('/^mode:[^\r\n]*$/m', 'mode: bin', $project);
        if (preg_match('/^output:/m', $project) !== 1) {
            $project .= "\noutput: build/{$outputName}\n";
        }
        if (preg_match('/^mode:/m', $project) !== 1) {
            $project .= "\nmode: bin\n";
        }
        $this->write($projectFile, $project);
        (new PluginSourceCompletion())->apply($mirror, ProjectProfile::PHP, $dynamicPhp, $mappings, $entrypoint);
    }

    private function read(string $path): string
    {
        $contents = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            throw new ConfigurationException("PHP generator input missing or unsafe: {$path}");
        }
        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (is_link($path) || is_link($directory)
            || (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory))
            || file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)
        ) {
            throw new ConfigurationException("cannot write PHP generator output: {$path}");
        }
    }
}
