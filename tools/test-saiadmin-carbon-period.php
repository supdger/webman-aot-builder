#!/usr/bin/env php
<?php

declare(strict_types=1);

use WebmanAotBuilder\Compatibility\SaiAdminCarbonPeriodRule;
use WebmanAotBuilder\Cli\ConfigurationException;

$repo = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($repo): void {
    if (str_starts_with($class, 'WebmanAotBuilder\\')) {
        require $repo . '/src/' . str_replace('\\', '/', substr($class, 17)) . '.php';
    }
});
function checkCarbon(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$carbon = $argv[1] ?? '';
checkCarbon(is_file($carbon . '/CarbonPeriod.php') && is_file($carbon . '/CarbonInterval.php'), 'Pass Carbon src/Carbon directory');
spl_autoload_register(static function (string $class) use ($carbon): void {
    if (str_starts_with($class, 'Carbon\\')) {
        $path = $carbon . '/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (is_file($path)) require $path;
    }
});
$started = microtime(true);
$rule = new SaiAdminCarbonPeriodRule();
$interval = file_get_contents($carbon . '/CarbonInterval.php');
$period = file_get_contents($carbon . '/CarbonPeriod.php');
$changes = $rule->replacements($period, $interval);
$adapted = $rule->transform($period, $interval);
checkCarbon($rule->replacements($adapted, $interval) === [], 'CarbonPeriod lowering must be idempotent');
echo 'PASS installed CarbonPeriod magic units lowered; explicit static methods retained (' . count($changes) . " source forms)\n";

foreach (['year()', 'years(-2)', 'month()', 'day()', 'seconds($value)', 'microseconds($value)', 'days(max(1, 2))', 'seconds(...[$value])', 'seconds(($value < 0 ? -$value : $value))', 'days(\\Carbon\\CarbonInterval::seconds(1)->totalDays)'] as $call) {
    $expression = '\\Carbon\\CarbonInterval::' . $call;
    $source = '<?php namespace Carbon; class CarbonPeriod { function sample($value) { return ' . $expression . '; } }';
    $replacements = $rule->replacements($source, $interval);
    checkCarbon(count($replacements) >= 1, 'Expected token calls: ' . $call);
    $loweredSource = $rule->transform($source, $interval);
    token_get_all($loweredSource, TOKEN_PARSE);
    checkCarbon($rule->replacements($loweredSource, $interval) === [], 'Nested lowering must be idempotent');
    $lowered = substr($loweredSource, strpos($loweredSource, 'return ') + 7, -5);
    foreach ([-1.5, 0, 1, 1.5] as $value) {
        $original = eval('return ' . $expression . ';');
        $result = eval('return ' . $lowered . ';');
        checkCarbon(serialize($original) === serialize($result), 'Interval behavior differs: ' . $call . ' value ' . $value);
    }
}
echo "PASS native PHP interval behavior for negative, zero, positive, fractional, nested and spread arguments\n";
foreach (['SECONDS', 'SeCoNdS'] as $method) {
    \Carbon\CarbonInterval::macro($method, static fn(): \Carbon\CarbonInterval => new \Carbon\CarbonInterval(0, 0, 0, 0, 0, 0, 77));
    $source = '<?php namespace Carbon; class CarbonPeriod { function sample(){return \\Carbon\\CarbonInterval::' . $method . '(1);} }';
    $adaptedMacro = $rule->transform($source, $interval);
    checkCarbon(str_contains($adaptedMacro, "::__callStatic('" . $method . "', [1])"), 'Magic method macro key changed');
    $original = eval('return \\Carbon\\CarbonInterval::' . $method . '(1);');
    $result = eval('return \\Carbon\\CarbonInterval::__callStatic(' . var_export($method, true) . ', [1]);');
    checkCarbon($original->s === 77 && serialize($original) === serialize($result), 'Case-sensitive macro behavior changed');
    checkCarbon($rule->transform($adaptedMacro, $interval) === $adaptedMacro, 'Macro dispatch must be idempotent');
    \Carbon\CarbonInterval::macro($method, null);
}
\Carbon\CarbonInterval::macro('MAKE', static fn(): \Carbon\CarbonInterval => new \Carbon\CarbonInterval(0, 0, 0, 0, 0, 0, 77));
$source = '<?php namespace Carbon; class CarbonPeriod { function sample(){return \\Carbon\\CarbonInterval::MAKE(1, "seconds");} }';
checkCarbon($rule->transform($source, $interval) === $source, 'Declared static API must retain PHP case-insensitive dispatch');
checkCarbon(\Carbon\CarbonInterval::MAKE(1, 'seconds')->s === 1, 'Declared static API was confused with a macro');
\Carbon\CarbonInterval::macro('MAKE', null);
echo "PASS uppercase/mixed-case macro keys preserved; declared static method dispatch unchanged\n";
$source = <<<'SOURCE'
<?php namespace Carbon;
class CarbonPeriod {
    function sample() {
        // CarbonInterval::year() is documentation only.
        $text = 'CarbonInterval::year()';
        return CarbonInterval::year() ?? CarbonInterval /* comment */ :: YEAR ( );
    }
}
SOURCE;
$lowered = $rule->transform($source, $interval);
checkCarbon(str_contains($lowered, "'CarbonInterval::year()'") && str_contains($lowered, '// CarbonInterval::year()'), 'Strings/comments modified');
checkCarbon(str_contains($lowered, "__callStatic ('YEAR', [ ])"), 'Spacing/comment/case form not lowered');
checkCarbon(str_contains($lowered, '/* comment */'), 'Call-head comment removed');
$source = <<<'SOURCE'
<?php namespace Carbon;
class CarbonPeriod {
    function sample($value) {
        return CarbonInterval /* outer */ :: days /* method */ (
            CarbonInterval /* inner */ :: seconds(/* argument */ $value)->totalDays
        );
    }
    function unchanged($value) {
        return CarbonInterval /* native */ :: make($value) ?? CarbonInterval /* dispatch */ :: __callStatic('day', []);
    }
}
SOURCE;
$lowered = $rule->transform($source, $interval);
token_get_all($lowered, TOKEN_PARSE);
checkCarbon($rule->replacements($lowered, $interval) === [], 'Commented nested lowering must be idempotent');
foreach (['outer', 'method', 'inner', 'argument', 'native', 'dispatch'] as $comment) {
    checkCarbon(substr_count($lowered, '/* ' . $comment . ' */') === 1, 'Nested call comment was lost: ' . $comment);
}
checkCarbon(str_contains($lowered, 'CarbonInterval /* native */ :: make($value)'), 'Declared static call modified');
checkCarbon(str_contains($lowered, "CarbonInterval /* dispatch */ :: __callStatic('day', [])"), 'Already lowered call modified');
$futureInterval = str_replace('class CarbonInterval extends', 'class CarbonInterval extends', $interval);
$position = strpos($futureInterval, '{', strpos($futureInterval, 'class CarbonInterval'));
$futureInterval = substr_replace($futureInterval, "\npublic static function futureFactory(): static { return new static(); }\n", $position + 1, 0);
$source = '<?php namespace Carbon; class CarbonPeriod { function sample(){return CarbonInterval::futureFactory();} }';
checkCarbon($rule->replacements($source, $futureInterval) === [], 'New explicit static API rejected');
foreach (['unknownMagic()', '$method()', 'seconds(seconds: 1)', 'dayss(1)'] as $call) {
    $source = '<?php namespace Carbon; class CarbonPeriod { function sample(){return CarbonInterval::' . $call . ';} }';
    try {
        $rule->replacements($source, $interval);
        throw new RuntimeException('Unsafe magic call accepted: ' . $call);
    } catch (ConfigurationException $error) {
        checkCarbon(str_contains($error->getMessage(), 'CarbonPeriod'), 'Wrong rejection: ' . $error->getMessage());
    }
}
echo 'PASS token trivia, explicit future API and unsafe-shape checks; elapsed ' . number_format(microtime(true) - $started, 2) . "s\n";
