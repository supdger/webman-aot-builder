<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

final class WebmanEntrypointShape
{
    // Webman's MIT-licensed worker bootstrap; compare complete tokens, not a project hash.
    private const WORKER_BOOTSTRAP = <<<'BOOTSTRAP'
<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

use Dotenv\Dotenv;
use support\Log;
use Webman\Bootstrap;
use Webman\Config;
use Webman\Middleware;
use Webman\Route;
use Webman\Util;
use Workerman\Events\Select;
use Workerman\Worker;

$worker = $worker ?? null;

if (empty(Worker::$eventLoopClass)) {
    Worker::$eventLoopClass = Select::class;
}

set_error_handler(function ($level, $message, $file = '', $line = 0) {
    if (error_reporting() & $level) {
        throw new ErrorException($message, 0, $level, $file, $line);
    }
});

if ($worker) {
    register_shutdown_function(function ($startTime) {
        if (time() - $startTime <= 0.1) {
            sleep(1);
        }
    }, time());
}

if (class_exists('Dotenv\Dotenv') && file_exists(base_path(false) . '/.env')) {
    if (method_exists('Dotenv\Dotenv', 'createUnsafeMutable')) {
        Dotenv::createUnsafeMutable(base_path(false))->load();
    } else {
        Dotenv::createMutable(base_path(false))->load();
    }
}

Config::clear();
support\App::loadAllConfig(['route']);
if ($timezone = config('app.default_timezone')) {
    date_default_timezone_set($timezone);
}

foreach (config('autoload.files', []) as $file) {
    include_once $file;
}
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

Middleware::load(config('middleware', []));
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
Middleware::load(['__static__' => config('static.middleware', [])]);

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

$directory = base_path() . '/plugin';
$paths = [config_path()];
foreach (Util::scanDir($directory) as $path) {
    if (is_dir($path = "$path/config")) {
        $paths[] = $path;
    }
}
Route::load($paths);

BOOTSTRAP;

    public function bootstrap(string $source): bool
    {
        $tokens = $this->normalize($source);
        return $tokens === '' || $tokens === $this->normalize(self::WORKER_BOOTSTRAP);
    }

    public function startup(string $source): bool
    {
        $tokens = $this->normalize($source);
        return preg_match('~^(?:useWorkerman\\\\Worker;)?(?:chdir\(__DIR__\);)?require(?:_once)?__DIR__\."/support/bootstrap\.php";(?:\\\\Workerman\\\\Worker|Worker)::runAll\(\);$~D', $tokens) === 1
            || preg_match('~^(?:chdir\(__DIR__\);)?require(?:_once)?__DIR__\."/vendor/autoload\.php";\\\\?support\\\\App::run\(\);$~D', $tokens) === 1;
    }

    private function normalize(string $source): string
    {
        $result = '';
        foreach (token_get_all($source) as $token) {
            if (!is_array($token)) {
                $result .= $token;
                continue;
            }
            if (in_array($token[0], [T_OPEN_TAG, T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $value = substr($token[1], 1, -1);
                $value = $token[1][0] === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
                $result .= json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            } else {
                $result .= $token[1];
            }
        }
        return $result;
    }
}
