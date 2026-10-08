<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\IlluminateIntervalRule;
use WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

function checkInterval(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS {$message}\n";
}

$started = microtime(true);
$rule = new IlluminateIntervalRule();
$fixtures = __DIR__ . '/fixtures/illuminate-support/';
$old = file_get_contents($fixtures . 'functions-12.php');
$new = file_get_contents($fixtures . 'functions-13.php');
foreach ([12 => $old, 13 => $new] as $version => $source) {
    $output = $rule->transform($source);
    token_get_all($output, TOKEN_PARSE);
    checkInterval(substr_count($output, '::__callStatic(') === ($version === 12 ? 9 : 5), "Illuminate {$version} all nine units accepted");
    checkInterval($rule->transform($output) === $output, "Illuminate {$version} adaptation idempotent");
    if ($version === 13) {
        foreach (['seconds', 'minutes', 'hours', 'days'] as $unit) {
            checkInterval(str_contains($output, "CarbonInterval::make(\${$unit}, '{$unit}')"), "{$unit} make preserves fractional semantics");
        }
    }
}
checkInterval(str_contains($rule->transform(str_replace('seconds($seconds)', 'seconds(($seconds),)', $old)), "::__callStatic('seconds', [\$seconds])"), 'parenthesized parameter and trailing comma accepted');
$named = str_replace("make(\$seconds, 'seconds')", "make(unit: 'seconds', interval: \$seconds,)", $new);
checkInterval(str_contains($rule->transform($named), "make(unit: 'seconds', interval: \$seconds,)"), 'equivalent named make arguments retained');
checkInterval(str_contains($rule->transform(str_replace('CarbonInterval::seconds(', 'CarbonInterval::SECONDS(', $old)), "::__callStatic('SECONDS', [\$seconds])"), 'magic method spelling retained');
$upper = $rule->transform(str_replace('CarbonInterval::seconds(', 'CarbonInterval::SECONDS(', $old));
checkInterval($rule->transform($upper) === $upper, 'uppercase dispatch adaptation idempotent');
$constructorSource = str_replace('CarbonInterval::seconds($seconds)', 'new CarbonInterval(0, 0, 0, 0, 0, 0, $seconds)', $old);
$constructorOutput = $rule->transform($constructorSource);
checkInterval(str_contains($constructorOutput, 'new CarbonInterval(0, 0, 0, 0, 0, 0, $seconds)'), 'explicit constructor expression preserved');
checkInterval($rule->transform($constructorOutput) === $constructorOutput, 'explicit constructor adaptation idempotent');
$variant = str_replace('CarbonInterval::', '\\Carbon\\CarbonInterval /* static call */ :: ', $old);
$variant = str_replace('$seconds', '$duration', $variant);
$output = $rule->transform($variant);
checkInterval(substr_count($output, '\\Carbon\\CarbonInterval::__callStatic(') === 9, 'qualified class and renamed parameter accepted');
checkInterval(substr_count($output, '/* static call */') === 9, 'call comments retained');
checkInterval($rule->transform($output) === $output, 'qualified adaptation idempotent');
$alias = str_replace('use Carbon\\CarbonInterval;', 'use Carbon\\CarbonInterval as Interval;', $old);
$alias = str_replace([': CarbonInterval', 'CarbonInterval::'], [': Interval', 'Interval::'], $alias);
checkInterval(substr_count($rule->transform($alias), 'Interval::__callStatic(') === 9, 'import alias accepted');
$string = "\n// CarbonInterval::seconds(\$seconds)\n\$unrelated = 'CarbonInterval::seconds(\$seconds)';\n";
checkInterval(str_ends_with($rule->transform($old . $string), $string), 'unrelated strings and comments unchanged');
foreach ([
    'invalid PHP' => $old . '\nfunction broken( {',
    'wrong unit' => str_replace("make(\$seconds, 'seconds')", "make(\$seconds, 'minutes')", $new),
    'wrong parameter' => str_replace('seconds($seconds)', 'seconds($seconds + 1)', $old),
    'unknown body' => str_replace('return CarbonInterval::years($years);', '$years++; return CarbonInterval::years($years);', $old),
    'missing helper' => str_replace('function years(', 'function otherYears(', $old),
    'duplicate helper' => $old . "\nfunction years(int \$years): CarbonInterval { return CarbonInterval::years(\$years); }\n",
    'wrong namespace' => str_replace('namespace Illuminate\\Support;', 'namespace Other;', $old),
    'wrong import' => str_replace('use Carbon\\CarbonInterval;', 'use Other\\CarbonInterval;', $old),
] as $name => $source) {
    try { $rule->transform($source); throw new RuntimeException("accepted {$name}"); }
    catch (ConfigurationException $error) { checkInterval(str_contains($error->getMessage(), 'functions.php'), "{$name} rejected with source path"); }
}
$generatorRoot = $argv[1] ?? null;
if ($generatorRoot !== null) {
    $lock = json_decode(file_get_contents(dirname(__DIR__) . '/compatibility/locks/webman-workerman-2026-09-25.json'), true, flags: JSON_THROW_ON_ERROR);
    $sourceFile = $generatorRoot . '/src/Compiler/ProjectGenerator.php';
    $cache = sys_get_temp_dir() . '/webman-aot-interval-test-' . bin2hex(random_bytes(6));
    $overlay = (new SaiAdminGeneratorOverlay())->prepare($sourceFile, $lock['generator']['sourceSha256'],
        $lock['generator']['mainStubSha256'], $cache, null, [], false);
    require $overlay['path'];
    $reflection = new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class);
    $generator = $reflection->newInstanceWithoutConstructor();
    $prepare = $reflection->getMethod('prepareGuardedSource');
    foreach ([12 => $old, 13 => $new] as $version => $source) {
        checkInterval($prepare->invoke($generator, 'vendor/illuminate/support/functions.php', $source) === $rule->transform($source), "locked generator invokes Illuminate {$version} rule");
    }
    $fixture = $cache . '/project';
    mkdir($fixture . '/vendor/illuminate/support', 0700, true);
    $vendor = $fixture . '/vendor/illuminate/support/functions.php';
    foreach ([12 => $old, 13 => $new] as $version => $source) {
        file_put_contents($vendor, $source);
        $generated = (new Tinywan\Typephp\Compiler\ProjectGenerator($fixture))->generateFlattenedSources();
        $flattened = $fixture . '/.typephp/build/illuminate-support-functions.php';
        checkInterval(is_file($flattened) && $generated !== [], "Illuminate {$version} actual flattened source generated");
        checkInterval(file_get_contents($vendor) === $source, "Illuminate {$version} vendor unchanged");
    }
    $before = file_get_contents($flattened);
    file_put_contents($vendor, str_replace("make(\$seconds, 'seconds')", "make(\$seconds, 'minutes')", $new));
    try { (new Tinywan\Typephp\Compiler\ProjectGenerator($fixture))->generateFlattenedSources(); throw new RuntimeException('mixed unknown input written'); }
    catch (ConfigurationException) { checkInterval(file_get_contents($flattened) === $before, 'mixed unknown structure leaves previous shadow unchanged'); }
    checkInterval(hash_file('sha256', $sourceFile) === $lock['generator']['sourceSha256'], 'locked upstream generator unchanged');
    echo 'Generator overlay: ' . $overlay['path'] . "\n";
} else {
    echo "SKIP locked generator integration: pass locked generator root\n";
}
$carbonRoot = $argv[2] ?? null;
if ($carbonRoot !== null) {
    spl_autoload_register(static function (string $class) use ($carbonRoot): void {
        if (str_starts_with($class, 'Carbon\\')) {
            $path = $carbonRoot . '/src/Carbon/' . str_replace('\\', '/', substr($class, 7)) . '.php';
            if (is_file($path)) { require $path; }
        }
    });
    foreach ([12 => $old, 13 => $new] as $version => $source) {
        foreach (['Original' => $source, 'Adapted' => $rule->transform($source)] as $kind => $code) {
            eval(substr(str_replace('namespace Illuminate\\Support;', 'namespace Interval' . $kind . $version . ';', $code), 5));
        }
    }
    $comparisons = 0;
    foreach ([false, true] as $floatSetters) {
        Carbon\CarbonInterval::enableFloatSetters($floatSetters);
        foreach ([12, 13] as $version) {
            foreach (['microseconds', 'milliseconds', 'seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'] as $unit) {
                $values = in_array($unit, ['weeks', 'months', 'years'], true) ? [0, 1, 2, -1] : [0, 1, 2, -1, 1.5, -1.5, 0.001];
                foreach ($values as $value) {
                    $results = [];
                    foreach (['Original', 'Adapted'] as $kind) {
                        try {
                            $interval = ('Interval' . $kind . $version . '\\' . $unit)($value);
                            $results[$kind] = [$interval->y, $interval->m, $interval->d, $interval->h, $interval->i, $interval->s, $interval->f, $interval->invert];
                        } catch (Throwable $error) { $results[$kind] = $error::class; }
                    }
                    if ($results['Original'] !== $results['Adapted']) { throw new RuntimeException("semantic mismatch {$version} {$unit} {$value}"); }
                    ++$comparisons;
                }
            }
        }
    }
    Carbon\CarbonInterval::enableFloatSetters(false);
    Carbon\CarbonInterval::macro('seconds', static fn($value) => new Carbon\CarbonInterval(0, 0, 0, 0, 0, 0, 42));
    checkInterval(IntervalOriginal12\seconds(1)->s === IntervalAdapted12\seconds(1)->s, 'old dispatch macro behavior preserved');
    checkInterval(IntervalOriginal13\seconds(1.5)->f === IntervalAdapted13\seconds(1.5)->f, 'new make independent of unit macro preserved');
    foreach (['Original' => $constructorSource, 'Adapted' => $constructorOutput] as $kind => $code) {
        eval(substr(str_replace('namespace Illuminate\\Support;', 'namespace IntervalConstructor' . $kind . ';', $code), 5));
    }
    $originalConstructor = IntervalConstructorOriginal\seconds(1.5);
    $adaptedConstructor = IntervalConstructorAdapted\seconds(1.5);
    checkInterval($originalConstructor->s === 1 && $originalConstructor->f === 0.5
        && $adaptedConstructor->s === $originalConstructor->s && $adaptedConstructor->f === $originalConstructor->f,
        'explicit fractional constructor and macro bypass semantics preserved');
    Carbon\CarbonInterval::macro('seconds', null);
    Carbon\CarbonInterval::macro('SECONDS', static fn($value) => new Carbon\CarbonInterval(0, 0, 0, 0, 0, 0, 77));
    $caseSource = str_replace('CarbonInterval::seconds(', 'CarbonInterval::SECONDS(', $old);
    foreach (['Original' => $caseSource, 'Adapted' => $rule->transform($caseSource)] as $kind => $code) {
        eval(substr(str_replace('namespace Illuminate\\Support;', 'namespace IntervalCase' . $kind . ';', $code), 5));
    }
    checkInterval(IntervalCaseOriginal\seconds(1)->s === 77 && IntervalCaseAdapted\seconds(1)->s === 77, 'case sensitive uppercase macro key preserved');
    Carbon\CarbonInterval::macro('SECONDS', null);
    echo "PASS {$comparisons} real Carbon interval behavior comparisons with float setters on and off\n";
} else { echo "SKIP real Carbon behavior: pass installed Carbon source root as second argument\n"; }
echo 'Interval compatibility checks completed in ' . number_format(microtime(true) - $started, 2) . "s\n";
