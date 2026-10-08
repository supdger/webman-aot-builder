<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule;

spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});

if (($argv[1] ?? '') === '--behavior') {
    $carbon = $argv[2];
    $adapted = $argv[3] === 'adapted';
    spl_autoload_register(static function (string $class) use ($carbon, $adapted): void {
        if (!str_starts_with($class, 'Carbon\\')) { return; }
        $path = $carbon . '/src/Carbon/' . str_replace('\\', '/', substr($class, 7)) . '.php';
        if (!is_file($path)) { return; }
        if ($adapted && $class === 'Carbon\\CarbonInterval') {
            eval(substr((new SaiAdminCarbonIntervalRule())->transform(file_get_contents($path)), 5));
        } else { require $path; }
    });
    $state = static fn(Carbon\CarbonInterval $interval): array => [$interval->y, $interval->m, $interval->d,
        $interval->h, $interval->i, $interval->s, $interval->f, $interval->invert];
    $results = [];
    foreach ([false, true] as $enabled) {
        Carbon\CarbonInterval::enableFloatSetters($enabled);
        foreach (['microseconds', 'milliseconds', 'seconds', 'minutes', 'hours', 'days', 'weeks', 'months', 'years'] as $unit) {
            foreach ([0, 1, -1, 1.5, -1.5, 0.001, -0.001] as $value) {
                foreach (['magic', 'make'] as $kind) {
                    try {
                        $interval = $kind === 'magic' ? Carbon\CarbonInterval::__callStatic($unit, [$value])
                            : Carbon\CarbonInterval::make($value, $unit);
                        $results[] = [$enabled, $unit, $value, $kind, $state($interval)];
                    } catch (Throwable $error) { $results[] = [$enabled, $unit, $value, $kind, $error::class]; }
                }
            }
        }
    }
    foreach ([0, 1] as $inverted) {
        $interval = new Carbon\CarbonInterval(0);
        $interval->invert($inverted);
        $results[] = ['abs', $inverted, $state($interval->abs(true))];
        foreach ([null, true, false] as $value) {
            $interval->invert($inverted);
            $results[] = ['explicit', $inverted, $value, $state($interval->invert($value))];
        }
        $interval->invert($inverted);
        $results[] = ['public-toggle', $inverted, $state($interval->invert())];
    }
    foreach ([[-2, -3, -500000], [2, -90, 250000], [-2, 90, -250000]] as [$minutes, $seconds, $microseconds]) {
        $interval = new Carbon\CarbonInterval(0);
        $interval->i = $minutes;
        $interval->s = $seconds;
        $interval->f = $microseconds / 1000000;
        $results[] = ['cascade', $minutes, $seconds, $microseconds, $state($interval->cascade())];
    }
    echo json_encode($results, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function checkCarbonInterval(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "PASS {$message}\n";
}

$started = microtime(true);
$rule = new SaiAdminCarbonIntervalRule();
$source = <<<'PHP'
<?php
namespace Carbon;
class CarbonInterval {
    public int $invert = 0;
    public ?self $receiver = null;
    public function invert($inverted = null): static {
        $this->invert = (\func_num_args() === 0 ? !$this->invert : $inverted) ? 1 : 0;
        return $this;
    }
    public function abs(): static { $this->invert(); return $this; }
    public function toPeriod(): static { $this->invert(); return $this; }
    protected function solveNegativeInterval(): static { $this->invert(); return $this; }
    public function roundUnit(): static { $this->invert(); return $this; }
    public function set(array $values): static { return $this; }
    private function doCascade(bool $deep): static {
        $interval = $this->receiver ?? $this;
        $interval->invert = 1;
        return $interval;
    }
    private function invertCascade(array $values): static {
        return $this->set(array_map(function ($value) {
            return -$value;
        }, $values))->doCascade(true)->invert();
    }
}
PHP;
$output = $rule->transform($source);
token_get_all($output, TOKEN_PARSE);
checkCarbonInterval($rule->transform($output) === $output, 'adapted source is idempotent');
$marked = '';
foreach (token_get_all($source) as $token) {
    if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_WHITESPACE], true)) { $marked .= $token[1]; }
    else { $marked .= (is_array($token) ? $token[1] : $token) . ' /* retained */ '; }
}
$markedOutput = $rule->transform($marked);
checkCarbonInterval(substr_count($markedOutput, '/* retained */') === substr_count($marked, '/* retained */'), 'comments retained across receiver split');
checkCarbonInterval($rule->transform($markedOutput) === $markedOutput, 'commented source is idempotent');
$decoy = $source . "\n// \$this->invert()\n\$decoy = '\$this->invert()';\n";
checkCarbonInterval(str_ends_with($rule->transform($decoy), substr($decoy, strlen($source))), 'strings and comments are not calls');
$explicit = str_replace('$this->invert(); return $this;', '$this->invert(!$this->invert); return $this;', $source);
checkCarbonInterval($rule->transform($explicit) === $output, 'partially adapted standalone calls completed');
foreach (['Original' => $source, 'Adapted' => $output] as $kind => $code) {
    eval(substr(str_replace('namespace Carbon;', 'namespace Interval' . $kind . ';', $code), 5));
    $class = 'Interval' . $kind . '\\CarbonInterval';
    $interval = new $class();
    $receiver = new $class();
    $interval->receiver = $receiver;
    $result = (new ReflectionMethod($class, 'invertCascade'))->invoke($interval, [-1.5]);
    checkCarbonInterval($result === $receiver && $result->invert === 0 && $interval->invert === 0,
        "{$kind} receiver evaluated before sign read and returned object preserved");
    foreach (['abs', 'toPeriod', 'solveNegativeInterval', 'roundUnit'] as $method) {
        $interval->invert = 0;
        $result = (new ReflectionMethod($class, $method))->invoke($interval);
        checkCarbonInterval($result === $interval && $interval->invert === 1, "{$kind} {$method} toggles in place");
    }
    foreach ([null, true, false] as $value) {
        $interval->invert = 1;
        checkCarbonInterval($interval->invert($value) === $interval && $interval->invert === (int) (bool) $value,
            "{$kind} explicit " . var_export($value, true) . ' unchanged');
    }
}
foreach ([
    'changed default' => str_replace('invert($inverted = null)', 'invert($inverted = false)', $source),
    'changed arity semantics' => str_replace('func_num_args() === 0', 'func_num_args() === 1', $source),
    'changed sign assignment' => str_replace(') ? 1 : 0;', ') ? 0 : 1;', $source),
    'missing internal toggle' => str_replace('public function abs(): static { $this->invert();', 'public function abs(): static {', $source),
    'extra internal toggle' => str_replace('public function abs(): static {', 'public function abs(): static { $this->invert();', $source),
    'extra nullsafe toggle' => str_replace('public function abs(): static {', 'public function abs(): static { $this?->invert();', $source),
    'extra reference method' => str_replace('public function abs(): static {', 'public function &other(): static { $this->invert(); return $this; } public function abs(): static {', $source),
    'invalid PHP' => $source . '\nfunction broken( {',
    'unknown receiver' => str_replace('public function abs(): static { $this->invert();', 'public function abs(): static { $this->receiver->invert();', $source),
    'unknown method' => str_replace('public function abs()', 'public function other()', $source),
    'chain side effect drift' => str_replace('->doCascade(true)->invert()', '->doCascade(false)->invert()', $source),
    'chain variable collision' => str_replace('return $this->set(array_map(', '$interval = null; return $this->set(array_map(', $source),
    'unknown namespace' => str_replace('namespace Carbon;', 'namespace Other;', $source),
] as $name => $candidate) {
    try { $rule->transform($candidate); throw new RuntimeException("accepted {$name}"); }
    catch (ConfigurationException $error) { checkCarbonInterval(str_contains($error->getMessage(), 'CarbonInterval.php'), "{$name} rejected with source path"); }
}

foreach (array_slice($argv, 1) as $carbon) {
    $path = $carbon . '/src/Carbon/CarbonInterval.php';
    $original = file_get_contents($path);
    $adapted = $rule->transform($original);
    token_get_all($adapted, TOKEN_PARSE);
    checkCarbonInterval($rule->transform($adapted) === $adapted, basename($carbon) . ' actual source shape and idempotence');
    $results = [];
    foreach (['original', 'adapted'] as $kind) {
        $process = proc_open([PHP_BINARY, '-d', 'error_reporting=22527', __FILE__, '--behavior', $carbon, $kind],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { throw new RuntimeException('cannot start real Carbon comparison'); }
        $results[$kind] = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) { throw new RuntimeException($kind . ' behavior failed: ' . $errors); }
        json_decode($results[$kind], true, flags: JSON_THROW_ON_ERROR);
    }
    checkCarbonInterval($results['original'] === $results['adapted'], basename($carbon) . ' real PHP signed/fractional/cascade/explicit behavior unchanged');
    checkCarbonInterval(file_get_contents($path) === $original, basename($carbon) . ' vendor source unchanged');
    echo 'PASS ' . count(json_decode($results['original'], true, flags: JSON_THROW_ON_ERROR)) . " real Carbon behavior cases\n";
}
echo 'Carbon interval compatibility checks completed in ' . number_format(microtime(true) - $started, 2) . "s\n";
