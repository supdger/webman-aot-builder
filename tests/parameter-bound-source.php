<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Compatibility/GeneratorSourceScope.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamSourceRule.php';
require $argv[1] . '/vendor/autoload.php';
require $argv[2];
function ensure(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
$started = microtime(true);
$table = (new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class))->getConstant('SWITCH_TERMINAL_REPLACEMENTS');
$rule = new WebmanAotBuilder\Compatibility\UpstreamSourceRule();
$creator = 'vendor/nesbot/carbon/src/Carbon/Traits/Creator.php';
$source = file_get_contents($argv[1] . '/' . $creator);
$output = $rule->replace($creator, $source, $table[$creator]);
$span = new ReflectionMethod($rule, 'methodSpan');
[$start, $end] = $span->invoke($rule, $output, 'createSafe', true);
[$helperStart, $helperEnd] = $span->invoke($rule, $source, 'monthToInt', true);
eval(substr($source, 5, strpos($source, 'trait Creator') - 5)
    . 'class ScopeCarbon extends \Carbon\Carbon {'
    . substr($output, $start, $end - $start)
    . substr($source, $helperStart, $helperEnd - $helperStart)
    . '}');
foreach ([[2024, 2, 29, 12, 30, 0, 'UTC'], [2023, 2, 29, 12, 30, 0, 'UTC'], [2024, 13, 1, 0, 0, 0, 'UTC']] as $parameters) {
    $results = [];
    foreach ([Carbon\Carbon::class, Carbon\Traits\ScopeCarbon::class] as $class) {
        try { $result = $class::createSafe(...$parameters); $results[] = $result?->format('Y-m-d H:i:s P'); }
        catch (Throwable $error) { $results[] = [$error::class, $error->getMessage()]; }
    }
    ensure($results[0] === $results[1], 'ordinary PHP safe date creation changed');
}
$unrelated = 'public function auditField($field, $year) { return $$field; }';
$callback = '$auditUnused = static function ($field, $year) { return $$field; };';
$variant = substr_replace($source, $unrelated, strrpos($source, '}'), 0);
$variant = str_replace('$fields = static::getRangesByUnit();', $callback . '$fields = static::getRangesByUnit();', $variant);
$converted = $rule->replace($creator, $variant, $table[$creator]);
ensure(str_contains($converted, $unrelated) && str_contains($converted, $callback), 'creator changed an unrelated method or callback');
token_get_all($converted, TOKEN_PARSE);
echo "PASS safe date ordinary PHP success/rejection and unrelated dynamic field scopes\n";
$blueprint = 'vendor/illuminate/database/Schema/Blueprint.php';
$source = file_get_contents($argv[1] . '/' . $blueprint);
$unrelated = 'public function auditCompact() { return compact(\'autoIncrement\', \'unsigned\'); }';
$newApi = 'public function compatibleInteger($column, $autoIncrement = false, $unsigned = false) { return compact(\'autoIncrement\', \'unsigned\'); }';
$variant = substr_replace($source, $unrelated . $newApi, strrpos($source, '}'), 0);
$converted = $rule->replace($blueprint, $variant, $table[$blueprint]);
token_get_all($converted, TOKEN_PARSE);
ensure(str_contains($converted, $unrelated), 'blueprint changed an unrelated unbound method');
ensure(str_contains($converted, 'compatibleInteger($column, $autoIncrement = false, $unsigned = false) { return [\'autoIncrement\' => $autoIncrement, \'unsigned\' => $unsigned]; }'), 'new parameter bound integer API was rejected');
echo "PASS integer column parameter binding, additional compatible API and unrelated scopes\n";
printf("PASS parameter-bound sources in %.3fs\n", microtime(true) - $started);
