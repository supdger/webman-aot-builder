<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\GeneratedProjectCoveragePlanner;
use WebmanAotBuilder\Project\ProjectProfile;
use WebmanAotBuilder\Project\ProjectDiscovery;
use WebmanAotBuilder\Project\WebmanViewAdapterPolicy;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function ensure(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reject(Closure $run, string $marker, string $name): void
{
    try {
        $run();
    } catch (ConfigurationException $exception) {
        ensure(str_contains($exception->getMessage(), $marker), $name . ': ' . $exception->getMessage());
        echo 'PASS reject ' . $name . "\n";
        return;
    }
    throw new RuntimeException('accepted ' . $name);
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-generated-coverage-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/attempt-' . str_repeat('a', 24) . '/project';
mkdir($mirror, 0700, true);
$put = static function (string $path, string $bytes) use ($mirror): void {
    $directory = dirname($mirror . '/' . $path);
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    file_put_contents($mirror . '/' . $path, $bytes);
};
$source = 'vendor/workerman/webman-framework/src/support/helpers.php';
$bootstrap = 'vendor/workerman/webman-framework/src/support/bootstrap.php';
$shadow = '.typephp/build/webman-helpers.php';
$fontPrefix = 'vendor/webman/captcha/src/';
$builder = <<<'BUILDER'
<?php
namespace Webman\Captcha;
class CaptchaBuilder implements CaptchaBuilderInterface {
    public function build($width = 150, $height = 40, $font = null, $fingerprint = null) {
        if ($font === null) {
            $font = $this->getFontPath(__DIR__ . '/Font/captcha'.$this->rand(0, 4).'.ttf');
        }
    }
    protected function getFontPath($font) { return $font; }
}
BUILDER;
$lock = [
    'schema' => 'webman-aot-builder-upstream-generator-lock-v1',
    'mappings' => [$source => ['shadow' => $shadow]],
    'entrypointMapping' => ['source' => $bootstrap, 'replacement' => 'main.php', 'policy' => 'upstream.webman-bootstrap-entrypoint.v1'],
    'dynamicPhp' => [],
    'runtimeResources' => ['webman/captcha' => 'runtime.captcha-font-resources.v1'],
];
$lockFile = $root . '/compatibility.json';
file_put_contents($lockFile, json_encode($lock, JSON_THROW_ON_ERROR));
$profile = new ProjectProfile(ProjectProfile::WEBMAN, [], []);
$reset = static function () use ($put, $source, $bootstrap, $shadow, $fontPrefix, $builder, $mirror): array {
    $put('app/controller/Home.php', "<?php namespace app\\controller; class Home {}\n");
    $put($source, "<?php function helpers() { return 2; }\n");
    $put($shadow, "<?php function helpers() { return 2; }\n");
    $put($bootstrap, "<?php // current compatible bootstrap\n");
    $put('support/bootstrap.php', "<?php // current compatible bootstrap\n");
    $put('main.php', "<?php // current generated main\n");
    $put('project.linux.yml', "sources:\n  - app\n  - main.php\n  - $shadow\nignore:\n  - $source\n  - $bootstrap\n  - support/bootstrap.php\n");
    $put('composer.lock', json_encode(['packages' => [['name' => 'webman/captcha', 'version' => 'dev-future', 'source' => ['reference' => 'new']]]], JSON_THROW_ON_ERROR));
    $put($fontPrefix . 'CaptchaBuilder.php', $builder);
    for ($index = 0; $index <= 4; $index++) {
        $path = $fontPrefix . 'Font/captcha' . $index . '.ttf';
        if (is_link($mirror . '/' . $path)) {
            unlink($mirror . '/' . $path);
        }
        $put($path, "\x00\x01\x00\x00current-font-" . $index);
    }
    return [
        [['path' => $source, 'shadow' => $shadow, 'sourceSha256' => hash_file('sha256', $mirror . '/' . $source), 'shadowSha256' => hash_file('sha256', $mirror . '/' . $shadow)]],
        ['bootstrapSourceSha256' => hash_file('sha256', $mirror . '/' . $bootstrap), 'bootstrapReplacementSha256' => hash_file('sha256', $mirror . '/main.php')],
    ];
};
$planner = new GeneratedProjectCoveragePlanner();
$run = static fn(array $mappings, array $adaptations): array => $planner->plan($mirror, $profile, $lockFile, $mappings, $adaptations);
try {
    [$mappings, $adaptations] = $reset();
    $plan = $run($mappings, $adaptations);
    ensure($plan['compiled'] === ['direct' => 1, 'shadow' => 3], 'compiler coverage counts changed');
    $fonts = array_values(array_filter($plan['resources']->entries(), static fn(array $entry): bool => ($entry['role'] ?? null) === 'font'));
    ensure(count($fonts) === 5 && $fonts[0]['sourceSha256'] === hash_file('sha256', $mirror . '/' . $fonts[0]['path']), 'runtime fonts did not bind actual bytes');
    echo "PASS current generated evidence and dev captcha use declared resource policy\n";
    foreach ([[], ['webman/captcha' => null], ['webman/captcha' => 'unknown-policy'], ['webman/captcha' => str_repeat('0', 64)]] as $policies) {
        $invalidLock = $lock;
        $invalidLock['runtimeResources'] = $policies;
        file_put_contents($lockFile, json_encode($invalidLock, JSON_THROW_ON_ERROR));
        reject(static fn() => $run($mappings, $adaptations), 'font resource policy is missing or unsupported', 'unproven captcha resource policy');
    }
    $invalidLock = $lock;
    unset($invalidLock['runtimeResources']);
    file_put_contents($lockFile, json_encode($invalidLock, JSON_THROW_ON_ERROR));
    reject(static fn() => $run($mappings, $adaptations), 'compatibility lock shape drifted', 'missing runtime resource declaration');
    file_put_contents($lockFile, json_encode($lock, JSON_THROW_ON_ERROR));
    foreach (['v99.0.0', 'v2.0.0-rc.1+build', 'unknown'] as $version) {
        $put('composer.lock', json_encode(['packages' => [['name' => 'webman/captcha', 'version' => $version]]], JSON_THROW_ON_ERROR));
        $run($mappings, $adaptations);
        echo 'PASS captcha label ' . $version . "\n";
    }
    $put($fontPrefix . 'CaptchaBuilder.php', str_replace(' . ', " \n /* compatible */ . ", $builder));
    $put($fontPrefix . 'Font/captcha0.ttf', "\x00\x01\x00\x00new-font-version");
    $run($mappings, $adaptations);
    echo "PASS captcha equivalent formatting and changed actual font bytes\n";
    $put($fontPrefix . 'CaptchaBuilder.php', str_replace('rand(0, 4)', 'rand(0, 5)', $builder));
    $put($fontPrefix . 'Font/captcha5.ttf', "\x00\x01\x00\x00additional-font");
    ensure(count(array_filter($run($mappings, $adaptations)['resources']->entries(), static fn(array $entry): bool => ($entry['role'] ?? null) === 'font')) === 6, 'expanded source font range failed');
    unlink($mirror . '/' . $fontPrefix . 'Font/captcha5.ttf');
    echo "PASS actual expanded captcha font selection range\n";
    [$mappings, $adaptations] = $reset();
    $fallbackYml = file_get_contents($mirror . '/project.linux.yml');
    foreach (['Request', 'Response'] as $name) {
        $put('vendor/workerman/webman-framework/src/support/' . $name . '.php', '<?php namespace support; class ' . $name . ' extends \\Webman\\Http\\' . $name . ' {}');
    }
    $put('project.linux.yml', str_replace('ignore:', "  - vendor/workerman/webman-framework/src/support/Request.php\n  - vendor/workerman/webman-framework/src/support/Response.php\nignore:", $fallbackYml));
    ensure($run($mappings, $adaptations)['compiled'] === ['direct' => 3, 'shadow' => 3], 'framework support fallbacks without project overrides were not compiled directly');
    echo "PASS actual framework Request and Response compile directly without project overrides\n";
    $put('project.linux.yml', $fallbackYml);
    foreach (['Request', 'Response'] as $name) {
        $put('support/' . $name . '.php', '<?php namespace support; class ' . $name . ' extends \\Webman\\Http\\' . $name . ' {}');
        $put('vendor/workerman/webman-framework/src/support/' . $name . '.php', '<?php namespace support; class ' . $name . ' extends \\Webman\\Http\\' . $name . ' {}');
    }
    $supportYml = file_get_contents($mirror . '/project.linux.yml');
    $put('project.linux.yml', str_replace('ignore:', "  - support/Request.php\n  - support/Response.php\nignore:\n  - vendor/workerman/webman-framework/src/support/Request.php\n  - vendor/workerman/webman-framework/src/support/Response.php", $supportYml));
    ensure($run($mappings, $adaptations)['compiled'] === ['direct' => 3, 'shadow' => 5], 'Webman support overrides were not covered');
    echo "PASS Webman compiled project support overrides framework fallbacks\n";
    foreach (['use-alias', 'prefix-alias', 'group-use', 'comments-and-case', 'braced-namespace', 'unrelated-closure'] as $shape) {
        foreach (['Request', 'Response'] as $name) {
            $bytes = match ($shape) {
                'use-alias' => '<?php namespace support; use Webman\\Http\\' . $name . ' as Base; class ' . $name . ' extends Base {}',
                'prefix-alias' => '<?php namespace support; use Webman\\Http as Http; class ' . $name . ' extends Http\\' . $name . ' {}',
                'group-use' => '<?php namespace support; use Webman\\Http\\{' . $name . ' as Base}; class ' . $name . ' extends Base {}',
                'comments-and-case' => '<?php namespace SUPPORT; CLASS /* role */ ' . strtolower($name) . ' EXTENDS /* parent */ \\webman\\http\\' . strtolower($name) . ' {}',
                'braced-namespace' => '<?php namespace support { class ' . $name . ' extends \\Webman\\Http\\' . $name . ' {} }',
                'unrelated-closure' => '<?php namespace support; $x = 1; $closure = function () use ($x) { return $x; }; class ' . $name . ' extends \\Webman\\Http\\' . $name . ' {}',
            };
            $put('support/' . $name . '.php', $bytes);
        }
        ensure($run($mappings, $adaptations)['compiled'] === ['direct' => 3, 'shadow' => 5], 'support class token roles changed for ' . $shape);
        echo "PASS actual Request and Response token roles {$shape}\n";
    }
    foreach (['<?php namespace support; /* class Request extends \\Webman\\Http\\Request {} */ class Request {}',
        '<?php namespace support; $decoy = "class Request extends \\Webman\\Http\\Request {}"; class Request {}',
        '<?php namespace support; if (false) { class Request extends \\Webman\\Http\\Request {} }'] as $invalid) {
        $put('support/Request.php', $invalid);
        reject(static fn() => $run($mappings, $adaptations), 'support override is missing or structurally invalid', 'comment, string or conditional support decoy');
    }
    $put('support/Request.php', '<?php namespace support; class Request {}');
    reject(static fn() => $run($mappings, $adaptations), 'support override is missing or structurally invalid', 'invalid project support parent');
    foreach (['Request', 'Response'] as $name) {
        unlink($mirror . '/support/' . $name . '.php');
        unlink($mirror . '/vendor/workerman/webman-framework/src/support/' . $name . '.php');
    }
    $put('project.linux.yml', $supportYml);
    $setup = '<?php namespace support; class Setup { public static function run() {} }';
    $setupComposer = ['scripts' => ['post-create-project-cmd' => ['support\\Setup::run'], 'setup-webman' => ['support\\Setup::run']]];
    $put('support/Setup.php', $setup);
    $put('composer.json', json_encode($setupComposer, JSON_THROW_ON_ERROR));
    $setupCategory = static function () use ($mirror, $profile): string {
        foreach ((new ProjectDiscovery($mirror))->discover($profile)->files() as $entry) {
            if ($entry['path'] === 'support/Setup.php') { return $entry['category']; }
        }
        throw new RuntimeException('setup discovery is missing');
    };
    ensure($setupCategory() === ProjectDiscovery::INSTALL_ONLY, 'declared Composer setup was treated as runtime PHP');
    echo "PASS Composer setup script role with declaration-only source\n";
    foreach ([$setup . ' startup();', str_replace('namespace support', 'namespace unrelated', $setup), str_replace('function run()', "function other()", $setup), str_replace('public static function run() {}', "public const TEXT = 'publicstaticfunctionrun(';", $setup)] as $invalidSetup) {
        $put('support/Setup.php', $invalidSetup);
        ensure($setupCategory() === ProjectDiscovery::BUSINESS_PHP, 'unsafe setup source was omitted');
        echo "PASS unproven setup structure retains compiler coverage\n";
    }
    $put('support/Setup.php', $setup);
    $put('composer.json', '{}');
    ensure($setupCategory() === ProjectDiscovery::BUSINESS_PHP, 'unbound setup source was omitted');
    echo "PASS unbound setup source retains compiler coverage\n";
    $put('composer.json', json_encode($setupComposer + ['autoload' => ['files' => ['support/Setup.php']]], JSON_THROW_ON_ERROR));
    ensure($setupCategory() === ProjectDiscovery::BUSINESS_PHP, 'autoload setup source was omitted');
    echo "PASS Composer autoload setup retains compiler coverage\n";
    $put('composer.json', json_encode($setupComposer, JSON_THROW_ON_ERROR));
    foreach (['app/controller/Home.php', 'config/bootstrap.php', 'support/bootstrap.php'] as $runtimePath) {
        $originalRuntime = is_file($mirror . '/' . $runtimePath) ? file_get_contents($mirror . '/' . $runtimePath) : null;
        $put($runtimePath, '<?php \\support\\Setup::run();');
        ensure($setupCategory() === ProjectDiscovery::BUSINESS_PHP, 'runtime setup reference was omitted');
        echo "PASS setup runtime reference retains compiler coverage\n";
        if ($originalRuntime === null) { unlink($mirror . '/' . $runtimePath); } else { $put($runtimePath, $originalRuntime); }
    }
    unlink($mirror . '/support/Setup.php');
    unlink($mirror . '/composer.json');
    $wrapper = "<?php require_once __DIR__ . '/../vendor/workerman/webman-framework/src/support/bootstrap.php';";
    foreach ([$wrapper, str_replace('require_once ', "require_once ( /* stock */ ", str_replace(';', ');', $wrapper))] as $wrapperVariant) {
        $put('support/bootstrap.php', $wrapperVariant);
        $wrapperPlan = $run($mappings, $adaptations);
        $alias = array_values(array_filter($wrapperPlan['coverage']->files(), static fn(array $entry): bool => $entry['path'] === 'support/bootstrap.php'))[0];
        ensure($alias['sourceSha256'] === hash('sha256', $wrapperVariant) && file_get_contents($mirror . '/support/bootstrap.php') === $wrapperVariant, 'bootstrap wrapper evidence was replaced by vendor bytes');
        echo "PASS stock bootstrap wrapper retains actual source alias evidence\n";
    }
    $put('support/bootstrap.php', $wrapper . ' custom_startup();');
    reject(static fn() => $run($mappings, $adaptations), 'project bootstrap differs', 'bootstrap wrapper additional side effect');
    $put('support/bootstrap.php', str_replace('workerman/webman-framework', 'unknown/package', $wrapper));
    reject(static fn() => $run($mappings, $adaptations), 'project bootstrap differs', 'bootstrap wrapper unknown source');
    $put('support/bootstrap.php', str_replace('__DIR__', '$unknownPath', $wrapper));
    reject(static fn() => $run($mappings, $adaptations), 'project bootstrap differs', 'bootstrap wrapper dynamic source');
    [$mappings, $adaptations] = $reset();
    $projectSource = 'app/functions.php';
    $projectShadow = '.typephp/build/app-functions.php';
    $put($projectSource, "<?php function project_function() { return 1; }\n");
    $put($projectShadow, "<?php function project_function() { return 1; }\n");
    $projectMapping = [
        'path' => $projectSource,
        'shadow' => $projectShadow,
        'sourceSha256' => hash_file('sha256', $mirror . '/' . $projectSource),
        'shadowSha256' => hash_file('sha256', $mirror . '/' . $projectShadow),
    ];
    $projectYml = file_get_contents($mirror . '/project.linux.yml');
    $put('project.linux.yml', str_replace('ignore:', "  - $projectShadow\nignore:\n  - $projectSource", $projectYml));
    $runtimeMappings = [...$mappings, $projectMapping];
    ensure($run($runtimeMappings, $adaptations)['compiled'] === ['direct' => 1, 'shadow' => 4], 'Webman project mapping was not compiled as a shadow');
    echo "PASS Webman current-run project mapping beyond baseline identities\n";
    $put($projectSource, "<?php function project_function() { return 2; }\n");
    reject(static fn() => $run($runtimeMappings, $adaptations), 'evidence drifted', 'runtime project source changed');
    $put($projectSource, "<?php function project_function() { return 1; }\n");
    $put($projectShadow, "<?php function project_function() { return 2; }\n");
    reject(static fn() => $run($runtimeMappings, $adaptations), 'evidence drifted', 'runtime project shadow changed');
    $put($projectShadow, "<?php function project_function() { return 1; }\n");
    $put('project.linux.yml', str_replace("  - $projectShadow\n", '', file_get_contents($mirror . '/project.linux.yml')));
    reject(static fn() => $run($runtimeMappings, $adaptations), 'replacement is not compiled', 'runtime project shadow compiler omission');
    unlink($mirror . '/' . $projectSource);
    unlink($mirror . '/' . $projectShadow);
    $put('project.linux.yml', $projectYml);
    reject(static fn() => $run([], $adaptations), 'evidence is missing', 'missing mapping proof');
    reject(static fn() => $run($mappings, []), 'entrypoint mapping drifted', 'missing bootstrap proof');
    reject(static fn() => $run([$mappings[0], $mappings[0]], $adaptations), 'evidence drifted', 'duplicate mapping proof');
    $put($source, "<?php function helpers() { return 99; }\n");
    reject(static fn() => $run($mappings, $adaptations), 'evidence drifted', 'source changed after generation');
    [$mappings, $adaptations] = $reset();
    $put($shadow, "<?php function helpers() { return 99; }\n");
    reject(static fn() => $run($mappings, $adaptations), 'evidence drifted', 'shadow changed after adaptation');
    [$mappings, $adaptations] = $reset();
    $put('main.php', "<?php // tampered entrypoint\n");
    reject(static fn() => $run($mappings, $adaptations), 'entrypoint mapping drifted', 'generated entrypoint changed');
    [$mappings, $adaptations] = $reset();
    $put('project.linux.yml', "sources:\n  - app\n  - main.php\nignore:\n  - $source\n  - $bootstrap\n  - support/bootstrap.php\n");
    reject(static fn() => $run($mappings, $adaptations), 'replacement is not compiled', 'shadow compiler omission');
    [$mappings, $adaptations] = $reset();
    $put($fontPrefix . 'CaptchaBuilder.php', str_replace('rand(0, 4)', 'rand(0, $count)', $builder));
    reject(static fn() => $run($mappings, $adaptations), 'selection structure is unsupported', 'unknown captcha selection');
    [$mappings, $adaptations] = $reset();
    $put($fontPrefix . 'CaptchaBuilder.php', str_replace('function build(', 'function unrelated(', $builder));
    reject(static fn() => $run($mappings, $adaptations), 'selection structure is unsupported', 'captcha selector outside build');
    foreach ([
        'builder in an unrelated namespace' => str_replace('namespace Webman\\Captcha;', 'namespace Other;', $builder),
        'later unknown getFontPath source' => str_replace("        }\n    }", '        }' . "\n" . '        $font = $this->getFontPath($unknownFont);' . "\n    }", $builder),
        'later direct font overwrite' => str_replace("        }\n    }", '        }' . "\n" . '        $font = $unknownFont;' . "\n    }", $builder),
        'hidden inactive default selector' => str_replace('if ($font === null)', 'if (false) { if ($font === null)', str_replace("        }\n    }", "        } }\n    }", $builder)),
        'unknown getFontPath helper output' => str_replace('return $font;', 'return $unknownFont;', $builder),
        'selector in a decoy class' => str_replace('function build(', 'function unrelated(', $builder) . <<<'DECOY'

class Decoy { public function build($width = 150, $height = 40, $font = null, $fingerprint = null) { if ($font === null) { $font = $this->getFontPath(__DIR__ . '/Font/captcha'.$this->rand(0, 4).'.ttf'); } } }
DECOY,
    ] as $name => $bytes) {
        [$mappings, $adaptations] = $reset();
        $put($fontPrefix . 'CaptchaBuilder.php', $bytes);
        reject(static fn() => $run($mappings, $adaptations), 'runtime font', $name);
    }
    [$mappings, $adaptations] = $reset();
    $direct = str_replace(['$font = $this->getFontPath(', "'.ttf');"], ['$font = ', "'.ttf';"], $builder);
    $put($fontPrefix . 'CaptchaBuilder.php', $direct);
    $run($mappings, $adaptations);
    echo "PASS direct default font source without path remapping\n";
    $unrelated = substr($builder, 0, -1) . 'public function unrelatedMethod(): string { return "future feature"; } }';
    $put($fontPrefix . 'CaptchaBuilder.php', $unrelated);
    $run($mappings, $adaptations);
    echo "PASS unrelated captcha method stays compatible\n";
    $afterSelection = static fn(string $statement): string => str_replace("        }\n    }", '        }' . "\n" . '        ' . $statement . "\n    }", $builder);
    foreach ([
        'extract injects local font' => $afterSelection("extract(['font' => \$unknownFont]);"),
        'qualified extract injects local font' => $afterSelection("\\extract(['font' => \$unknownFont]);"),
        'variable variable injects local font' => $afterSelection("\$name = 'font'; \$\$name = \$unknownFont;"),
        'eval injects local font' => $afterSelection("eval('font source');"),
        'include injects local font' => $afterSelection("include 'font-source.php';"),
        'include_once injects local font' => $afterSelection("include_once 'font-source.php';"),
        'require injects local font' => $afterSelection("require 'font-source.php';"),
        'require_once injects local font' => $afterSelection("require_once 'font-source.php';"),
        'extract function alias injects local font' => str_replace('namespace Webman\Captcha;', 'namespace Webman\Captcha; use function extract as unpackFont;', $afterSelection("unpackFont(['font' => \$unknownFont]);")),
        'font parameter is in the wrong slot' => str_replace('$width = 150, $height = 40, $font = null', '$font = null, $height = 40, $width = 150', $builder),
        'font parameter is passed by reference' => str_replace('$font = null', '&$font = null', $builder),
        'font parameter is variadic' => str_replace('$font = null, $fingerprint = null', '...$font', $builder),
        'font parameter has an unknown default' => str_replace('$font = null', '$font = false', $builder),
        'parse_str writes the font argument' => $afterSelection("parse_str('font=unknown', \$font);"),
    ] as $name => $bytes) {
        [$mappings, $adaptations] = $reset();
        $put($fontPrefix . 'CaptchaBuilder.php', $bytes);
        reject(static fn() => $run($mappings, $adaptations), 'runtime font', $name);
    }
    [$mappings, $adaptations] = $reset();
    $put($fontPrefix . 'CaptchaBuilder.php', $afterSelection("parse_str('x=1', \$data);"));
    $run($mappings, $adaptations);
    echo "PASS parse_str explicit unrelated output stays compatible\n";
    $unrelatedSymbols = substr($builder, 0, -1) . 'public function unrelatedMethod(): void { extract(["message" => "future"]); } }';
    $put($fontPrefix . 'CaptchaBuilder.php', $unrelatedSymbols);
    $run($mappings, $adaptations);
    echo "PASS unrelated method avoids font symbol restrictions\n";
    [$mappings, $adaptations] = $reset();
    unlink($mirror . '/' . $fontPrefix . 'Font/captcha1.ttf');
    reject(static fn() => $run($mappings, $adaptations), 'font is missing', 'missing selected captcha font');
    [$mappings, $adaptations] = $reset();
    unlink($mirror . '/' . $fontPrefix . 'Font/captcha1.ttf');
    symlink($mirror . '/' . $fontPrefix . 'Font/captcha0.ttf', $mirror . '/' . $fontPrefix . 'Font/captcha1.ttf');
    reject(static fn() => $run($mappings, $adaptations), 'unsafe resource', 'symlinked captcha font');
    [$mappings, $adaptations] = $reset();
    $put($fontPrefix . 'Font/payload.php', '<?php echo 1;');
    reject(static fn() => $run($mappings, $adaptations), 'unsafe resource', 'PHP hidden in font directory');
    unlink($mirror . '/' . $fontPrefix . 'Font/payload.php');

    if (isset($argv[2])) {
        $captchaRoot = rtrim($argv[2], '/\\');
        $put($fontPrefix . 'CaptchaBuilder.php', (string) file_get_contents($captchaRoot . '/src/CaptchaBuilder.php'));
        foreach (new DirectoryIterator($captchaRoot . '/src/Font') as $font) {
            if ($font->isFile() && !$font->isLink()) {
                $put($fontPrefix . 'Font/' . $font->getFilename(), (string) file_get_contents($font->getPathname()));
            }
        }
        $officialPlan = $run($mappings, $adaptations);
        ensure(count(array_filter($officialPlan['resources']->entries(), static fn(array $entry): bool => ($entry['role'] ?? null) === 'font')) === 5, 'official captcha resource set failed');
        echo "PASS official captcha builder and actual bundled font resources\n";
        $officialBuilder = (string) file_get_contents($captchaRoot . '/src/CaptchaBuilder.php');
        ensure(file_get_contents($mirror . '/' . $fontPrefix . 'CaptchaBuilder.php') === $officialBuilder, 'official custom font API source was modified');
        $phraseHeader = <<<'PHP_HEADER'
protected function writePhrase($image, $phrase, $font, $width, $height)
    {
PHP_HEADER;
        foreach ([
            'official helper redirects native font' => str_replace('return $font;', 'return $unknownFont;', $officialBuilder),
            'official drawing reads unknown font' => str_replace('\imagettfbbox($size, 0, $font, $phrase)', '\imagettfbbox($size, 0, $unknownFont, $phrase)', $officialBuilder),
            'official writePhrase overwrites font' => str_replace($phraseHeader, $phraseHeader . "\n" . '        $font = $unknownFont;', $officialBuilder),
        ] as $name => $bytes) {
            ensure($bytes !== $officialBuilder, 'negative font case did not change official source: ' . $name);
            $put($fontPrefix . 'CaptchaBuilder.php', $bytes);
            reject(static fn() => $run($mappings, $adaptations), 'runtime font', $name);
        }
        $buildFont = 'public function build($width = 150, $height = 40, $font = null, $fingerprint = null)';
        $ocrFont = 'public function buildAgainstOCR($width = 150, $height = 40, $font = null, $fingerprint = null)';
        foreach ([
            'official writePhrase font slot swap' => str_replace('writePhrase($image, $phrase, $font, $width, $height)', 'writePhrase($image, $font, $phrase, $width, $height)', $officialBuilder),
            'official build font slot swap' => str_replace($buildFont, 'public function build($font = null, $height = 40, $width = 150, $fingerprint = null)', $officialBuilder),
            'official OCR font slot swap' => str_replace($ocrFont, 'public function buildAgainstOCR($font = null, $height = 40, $width = 150, $fingerprint = null)', $officialBuilder),
            'official writePhrase extract injects font' => str_replace($phraseHeader, $phraseHeader . "\n" . "        extract(['font' => \$unknownFont]);", $officialBuilder),
        ] as $name => $bytes) {
            ensure($bytes !== $officialBuilder, 'negative font contract did not change official source: ' . $name);
            $put($fontPrefix . 'CaptchaBuilder.php', $bytes);
            reject(static fn() => $run($mappings, $adaptations), 'runtime font', $name);
        }
        $safeUnusedImport = str_replace('namespace Webman\Captcha;', 'namespace Webman\Captcha; use function Custom\mutateFont as imagettftext;', $officialBuilder);
        $put($fontPrefix . 'CaptchaBuilder.php', $safeUnusedImport);
        $run($mappings, $adaptations);
        echo "PASS fully qualified native drawing ignores unrelated function import\n";
        $safeNativeAlias = str_replace('namespace Webman\Captcha;', 'namespace Webman\Captcha; use function imagettftext as drawKnownFont;', $officialBuilder);
        $safeNativeAlias = str_replace('\imagettftext(', 'drawKnownFont(', $safeNativeAlias, $aliasHits);
        ensure($aliasHits > 0, 'native alias positive case did not change drawing source');
        $put($fontPrefix . 'CaptchaBuilder.php', $safeNativeAlias);
        $run($mappings, $adaptations);
        echo "PASS imported genuine native drawing alias retains safe font slot\n";
        $unknownNativeAlias = str_replace('\imagettftext(', 'imagettftext(', $safeUnusedImport, $aliasHits);
        ensure($aliasHits > 0, 'unknown alias negative case did not change drawing source');
        $put($fontPrefix . 'CaptchaBuilder.php', $unknownNativeAlias);
        reject(static fn() => $run($mappings, $adaptations), 'runtime font', 'unqualified imported custom drawing delegate');
        $qualifiedExtract = str_replace('namespace Webman\Captcha;', 'namespace Webman\Captcha; use function Custom\safe as extract;', $officialBuilder);
        $qualifiedExtract = str_replace($phraseHeader, $phraseHeader . "\n" . "        \\extract(['font' => \$unknownFont]);", $qualifiedExtract);
        $put($fontPrefix . 'CaptchaBuilder.php', $qualifiedExtract);
        reject(static fn() => $run($mappings, $adaptations), 'symbol table injection', 'fully qualified extract ignores harmless imported name');
    }

    $view = <<<'VIEW'
<?php
namespace support\view;
use Webman\View;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
class Twig implements View {
    public static function assign(string|array $name, mixed $value = null): void {}
    public static function render(string $template, array $vars, ?string $app = null, ?string $plugin = null): string {
        $views = new Environment(new FilesystemLoader('/view'));
        return $views->render($template, $vars);
    }
}
VIEW;
    $viewPolicy = new WebmanViewAdapterPolicy();
    $viewPolicy->validate($view, 'Twig');
    $extended = substr($view, 0, -1) . "public static function extension(): string { return 'future'; } }";
    $viewPolicy->validate($extended, 'Twig');
    $viewPath = 'vendor/workerman/webman-framework/src/support/view/Twig.php';
    $put($viewPath, $extended);
    $discovery = (new ProjectDiscovery($mirror, [$viewPath => 'runtime.third-party-dynamic.v1']))->discover($profile);
    $record = array_values(array_filter($discovery->files(), static fn(array $file): bool => $file['path'] === $viewPath));
    ensure(count($record) === 1 && $record[0]['category'] === ProjectDiscovery::THIRD_PARTY_DYNAMIC_PHP, 'dynamic view failed project discovery');
    echo "PASS registered view discovery permits compatible new method without historical hash\n";
    foreach ([null, 'unknown-policy', str_repeat('0', 64)] as $policy) {
        reject(static fn() => (new ProjectDiscovery($mirror, [$viewPath => $policy]))->discover($profile), 'dynamic PHP drifted', 'unproven runtime view policy');
    }
    reject(static fn() => $viewPolicy->validate($view . "\nif (", 'Twig'), 'invalid PHP', 'invalid view source');
    reject(static fn() => $viewPolicy->validate($view . "\nfile_put_contents('out', 'bad');", 'Twig'), 'top-level PHP', 'top-level executable view source');
    reject(static fn() => $viewPolicy->validate(str_replace('return $views->render($template, $vars);', 'include $template; return "";', $view), 'Twig'), 'dynamic execution', 'unexpected dynamic template execution');
    reject(static fn() => $viewPolicy->validate(str_replace("new Environment(new FilesystemLoader('/view'))", '"new Environment(new FilesystemLoader("', $view), 'Twig'), 'engine delegation drifted', 'quoted fake view engine');
    reject(static fn() => $viewPolicy->validate(str_replace('support\\view', 'app\\view', $view), 'Twig'), 'namespace drifted', 'business PHP masquerading as runtime view');
    if (isset($argv[1])) {
        foreach (['Blade', 'Raw', 'ThinkPHP', 'Twig'] as $name) {
            $bytes = file_get_contents(rtrim($argv[1], '/\\') . '/src/support/view/' . $name . '.php');
            ensure(is_string($bytes), 'official view source missing');
            $viewPolicy->validate($bytes, $name);
            $viewPolicy->validate(str_replace('class ' . $name, '/* new version comment */ class ' . $name, $bytes), $name);
            echo 'PASS official framework view ' . $name . "\n";
        }
    }
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
echo sprintf("PASS generated coverage compatibility completed in %.3fs\n", microtime(true) - $started);
