<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

/** Every startup phase below is replaced by the fixed generated main entrypoint. */
final class BootstrapEntrypointRule
{
    private const IMPORTS = [
        'Dotenv' => 'Dotenv\\Dotenv',
        'Log' => 'support\\Log',
        'Bootstrap' => 'Webman\\Bootstrap',
        'Config' => 'Webman\\Config',
        'Middleware' => 'Webman\\Middleware',
        'Route' => 'Webman\\Route',
        'Util' => 'Webman\\Util',
        'Select' => 'Workerman\\Events\\Select',
        'Worker' => 'Workerman\\Worker',
    ];

    private const PHASES = [
        'worker-context' => <<<'PHP'
$worker = $worker ?? null;
PHP,
        'event-loop' => <<<'PHP'
if (empty(Worker::$eventLoopClass)) {
    Worker::$eventLoopClass = Select::class;
}
PHP,
        'error-handler' => <<<'PHP'
set_error_handler(function ($level, $message, $file = '', $line = 0) {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});
PHP,
        'shutdown-delay' => <<<'PHP'
if ($worker) {
    register_shutdown_function(function ($startTime) {
        if (time() - $startTime <= 0.1) {
            sleep(1);
        }
    }, time());
}
PHP,
        'environment' => <<<'PHP'
if (class_exists('Dotenv\Dotenv') && file_exists(base_path(false) . '/.env')) {
    if (method_exists('Dotenv\Dotenv', 'createUnsafeMutable')) {
        Dotenv::createUnsafeMutable(base_path(false))->load();
    } else {
        Dotenv::createMutable(base_path(false))->load();
    }
}
PHP,
        'configuration' => <<<'PHP'
Config::clear();
support\App::loadAllConfig(['route']);
if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set($timezone);
}
PHP,
        'autoload' => <<<'PHP'
foreach (config('autoload.files', []) as $file) {
    include_once $file;
}
PHP,
        'plugin-autoload' => <<<'PHP'
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['autoload']['files'] ?? [] as $file) {
            include_once $file;
        }
    }
    foreach ($projects['autoload']['files'] ?? [] as $file) {
        include_once $file;
    }
}
PHP,
        'middleware' => <<<'PHP'
Middleware::load(config('middleware', []));
PHP,
        'plugin-middleware' => <<<'PHP'
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project) || $name === 'static') {
            continue;
        }
        Middleware::load($project['middleware'] ?? []);
    }
    Middleware::load($projects['middleware'] ?? [], $firm);
    if ($staticMiddlewares = config("plugin.$firm.static.middleware")) {
        Middleware::load(['__static__' => $staticMiddlewares], $firm);
    }
}
PHP,
        'static-middleware' => <<<'PHP'
Middleware::load(['__static__' => config('static.middleware', [])]);
PHP,
        'bootstrap' => <<<'PHP'
foreach (config('bootstrap', []) as $className) {
    if (!class_exists($className)) {
        $log = "Warning: Class $className setting in config/bootstrap.php not found\r\n";
        echo $log;
        Log::error($log);
        continue;
    }
    /** @var Bootstrap $className */
    $className::start($worker);
}
PHP,
        'plugin-bootstrap' => <<<'PHP'
foreach (config('plugin', []) as $firm => $projects) {
    foreach ($projects as $name => $project) {
        if (!is_array($project)) {
            continue;
        }
        foreach ($project['bootstrap'] ?? [] as $className) {
            if (!class_exists($className)) {
                $log = "Warning: Class $className setting in config/plugin/$firm/$name/bootstrap.php not found\r\n";
                echo $log;
                Log::error($log);
                continue;
            }
            /** @var Bootstrap $className */
            $className::start($worker);
        }
    }
    foreach ($projects['bootstrap'] ?? [] as $className) {
        /** @var string $className */
        if (!class_exists($className)) {
            $log = "Warning: Class $className setting in plugin/$firm/config/bootstrap.php not found\r\n";
            echo $log;
            Log::error($log);
            continue;
        }
        /** @var Bootstrap $className */
        $className::start($worker);
    }
}
PHP,
        'routes' => <<<'PHP'
$directory = base_path() . '/plugin';
$paths = [config_path()];
foreach (Util::scanDir($directory) as $path) {
    if (is_dir($path = "$path/config")) {
        $paths[] = $path;
    }
}
Route::load($paths);
PHP,
    ];

    public function validate(string $source): void
    {
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new ConfigurationException('Webman bootstrap entrypoint is invalid PHP', previous: $exception);
        }
        $tokens = array_values(array_filter($tokens, static fn($token): bool => !is_array($token)
            || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
        $imports = [];
        $cursor = 0;
        while (($tokens[$cursor][0] ?? null) === T_USE) {
            $declaration = '';
            while (++$cursor < count($tokens) && $tokens[$cursor] !== ';') {
                $declaration .= is_array($tokens[$cursor]) && $tokens[$cursor][0] === T_AS
                    ? ' as ' : (is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor]);
            }
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_\\\\]*)(?: as ([A-Za-z_][A-Za-z0-9_]*))?$/D', $declaration, $match) !== 1) {
                throw new ConfigurationException('Webman bootstrap import declaration is unsupported');
            }
            $name = $match[2] ?? substr($match[1], (int) strrpos('\\' . $match[1], '\\'));
            if (isset($imports[strtolower($name)])) {
                throw new ConfigurationException('Webman bootstrap import declaration is duplicated');
            }
            $imports[strtolower($name)] = $match[1];
            $cursor++;
        }
        foreach (self::PHASES as $phase => $contract) {
            $expected = array_values(array_filter(token_get_all('<?php ' . $contract, TOKEN_PARSE), static fn($token): bool => !is_array($token)
                || !in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $actual = array_slice($tokens, $cursor, count($expected));
            if ($this->normalize($actual, $imports) !== $this->normalize($expected, array_change_key_case(self::IMPORTS))) {
                throw new ConfigurationException("Webman bootstrap entrypoint startup phase is unsupported: {$phase}");
            }
            $cursor += count($expected);
        }
        if ($cursor !== count($tokens)) {
            throw new ConfigurationException('Webman bootstrap entrypoint has unrepresented startup side effects');
        }
    }

    /** @param list<array|string> $tokens @param array<string,string> $imports @return list<string> */
    private function normalize(array $tokens, array $imports): array
    {
        $result = [];
        $variables = [];
        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                $result[] = $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $previous = $tokens[$index - 1] ?? null;
                $method = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true);
                $text = ltrim($text, '\\');
                $next = $tokens[$index + 1] ?? null;
                $class = (is_array($next) && $next[0] === T_DOUBLE_COLON)
                    || (is_array($previous) && $previous[0] === T_NEW);
                if (!$method && $class) {
                    $parts = explode('\\', $text, 2);
                    $text = ($imports[strtolower($parts[0])] ?? $parts[0])
                        . (isset($parts[1]) ? '\\' . $parts[1] : '');
                }
                $result[] = 'name:' . strtolower($text);
            } elseif ($id === T_VARIABLE) {
                $previous = $tokens[$index - 1] ?? null;
                if ($text === '$worker' || (is_array($previous) && $previous[0] === T_DOUBLE_COLON)) {
                    $result[] = $text;
                } else {
                    $variables[$text] ??= count($variables);
                    $result[] = 'variable:' . $variables[$text];
                }
            } elseif ($id === T_CONSTANT_ENCAPSED_STRING && !str_contains($text, '\\')) {
                $result[] = 'literal:' . substr($text, 1, -1);
            } else {
                $result[] = $id . ':' . $text;
            }
        }
        return $result;
    }
}
