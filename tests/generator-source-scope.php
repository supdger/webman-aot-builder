<?php

declare(strict_types=1);

use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Compatibility\GeneratorSourceScope;

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Compatibility/GeneratorSourceScope.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamSourceRule.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(Closure $run): void
{
    try { $run(); } catch (ConfigurationException $error) {
        ensure(str_contains($error->getMessage(), 'method role is unknown'), $error->getMessage());
        return;
    }
    throw new RuntimeException('unclassified generator conversion was accepted');
}

$started = microtime(true);
$base = 'vendor/topthink/think-orm/src/db/BaseQuery.php';
$pdo = 'vendor/topthink/think-orm/src/db/PDOConnection.php';
$session = 'vendor/symfony/http-foundation/Session/Storage/Handler/PdoSessionHandler.php';
$request = 'vendor/symfony/http-foundation/Request.php';
$date = 'vendor/nesbot/carbon/src/Carbon/Traits/Date.php';
$timestamp = 'vendor/topthink/think-orm/src/model/concern/TimeStamp.php';
$mailer = 'vendor/phpmailer/phpmailer/src/PHPMailer.php';
$entry = 'vendor/vlucas/phpdotenv/src/Parser/EntryParser.php';
$cron = 'plugin/saiadmin/app/logic/tool/CrontabLogic.php';

$cases = [
    [$base, '$result = [];', '$resultState->value = [];', ['find']],
    [$base, 'return $result; }', 'return $resultState->value; }', ['find']],
    [$base, '$table = [];', '$tableState->value = [];', ['tableStr']],
    [$base, '$table[$item]', '$tableState->value[$item]', ['tableStr']],
    [$base, 'return $table; }', 'return $tableState->value; }', ['tableStr']],
    [$base, '$tableState->value[] = $val;', '$table[] = $val;', []],
    [$pdo, '$insertId = (int) $insertId;', '$insertIdState->value = (int) $insertId;', ['autoInsIDType']],
    [$pdo, 'return $insertId;', 'return $insertIdState->value;', ['autoInsIDType']],
    [$pdo, '$dbMaster = [];', '$dbMasterState->value = [];', ['multiConnect']],
    [$pdo, 'public function getFieldsType($tableName, ?string $field = null)', 'public function getFieldsType(mixed $tableName, ?string $field = null)', ['getFieldsType']],
    [$session, '$data = $sessionData;', '$dataHolder->value = $sessionData;', ['getInsertStatement', 'getUpdateStatement']],
    [$session, 'rewind($data);', 'rewind($dataHolder->value);', ['getInsertStatement', 'getUpdateStatement']],
    [$session, '$stmt->bindParam(\':data\', $data, \PDO::PARAM_LOB);', '$stmt->bindParam(\':data\', $dataHolder->value, \PDO::PARAM_LOB);', ['getInsertStatement', 'getUpdateStatement']],
    [$request, 'case \'PATCH\':', '$request = $parameters; $query = []; break; case \'PATCH\':', ['create']],
    [$entry, '} case self::', '} throw new \Error(\'Unreachable parser state.\'); case self::', ['processToken']],
    [$timestamp, '$value = $obj->__toString();', 'return $obj->__toString();', ['getTimeTypeValue']],
    [$timestamp, '} } }', '} } break; }', ['getTimeTypeValue']],
    [$mailer, '"\n"; }', '"\n"; break; }', ['edebug']],
    [$mailer, '$encoding = false;', '$encoding = \'\';', ['encodeHeader']],
    [$date, '$value = $operator === \'Of\'', '$unitValue = $operator === \'Of\'', ['get']],
    [$date, 'return (int) $value;', 'return (int) $unitValue;', ['get']],
    [$date, '$result = $result->subSecond();', '$result = $result->addUnit(\'second\', -1);', ['set']],
    [$cron, '} case 2:', '} break; case 2:', ['run']],
    [$cron, '} default:', '} break; default:', ['run']],
];
echo "STAGE finite conversion method ownership\n";
foreach ($cases as [$path, $before, $after, $expected]) {
    ensure(GeneratorSourceScope::methods($path, $before, $after) === $expected, 'incorrect method role: ' . $path . ' / ' . $before);
}
ensure(GeneratorSourceScope::methods($base, '$result /* unrelated trivia */ = [];', '$resultState /* output trivia */ -> value = [];') === ['find'], 'trivia changed the rule owner');
rejects(static fn () => GeneratorSourceScope::methods($base, '$result = $other;', '$resultState->value = $other;'));
rejects(static fn () => GeneratorSourceScope::methods($base, '$ result = [];', '$resultState->value = [];'));
rejects(static fn () => GeneratorSourceScope::methods($base, '$result = [];', '$unrelatedState->value = [];'));
ensure(GeneratorSourceScope::methods('vendor/unrelated/Class.php', '$result = [];', '$resultState->value = [];') === null, 'unrelated source acquired a local state scope');

$switches = [
    [$session, 'buildDsnFromUrl', '$driver'],
    [$request, 'create', 'strtoupper($method)'],
    [$entry, 'processToken', '$state'],
    [$timestamp, 'getTimeTypeValue', '$type'],
    [$mailer, 'edebug', '$this->Debugoutput'],
    [$date, 'set', '$name'],
    [$date, 'get', 'true'],
    [$cron, 'run', '$info->type'],
];
echo "STAGE exact switch binding roles\n";
foreach ($switches as [$path, $method, $expected]) {
    ensure(GeneratorSourceScope::switchExpression($path, $method) === $expected, 'incorrect switch expression: ' . $path . '#' . $method);
    ensure(GeneratorSourceScope::switchExpression($path, 'unrelatedMethod') === null, 'switch expression leaked into an unrelated method');
}
foreach ([$base => ['find', 'tableStr'], $pdo => ['autoInsIDType', 'multiConnect'], $session => ['getInsertStatement', 'getUpdateStatement']] as $path => $methods) {
    foreach ($methods as $method) {
        ensure(GeneratorSourceScope::switchExpression($path, $method) === null, 'ordinary state scope became a switch scope');
    }
}
ensure(GeneratorSourceScope::switchExpression($date, 'get', 'return (int) $value;') === null, 'ordinary get state acquired a switch scope');
ensure(GeneratorSourceScope::switchExpression($mailer, 'encodeHeader', '$encoding = false;') === null, 'header local state acquired a switch scope');

if (isset($argv[1])) {
    echo "STAGE actual fixed generator table has no unclassified rule\n";
    require $argv[1];
    $class = new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class);
    $table = $class->getConstant('SWITCH_TERMINAL_REPLACEMENTS');
    $count = 0;
    foreach ([$base, $pdo, $session, $request, $entry, $timestamp, $mailer, $date, $cron] as $path) {
        foreach ($table[$path] as $before => $after) {
            GeneratorSourceScope::methods($path, $before, $after);
            ++$count;
        }
    }
    echo "PASS {$count} actual generator rule roles classified\n";
    if (isset($argv[2])) {
        $source = file_get_contents($argv[2] . '/' . $date);
        ensure(is_string($source), 'actual Carbon date fixture is missing');
        $unrelated = 'public function auditPropertyWrite($result, $name, $value) { $result->$name = $value; return $result; }';
        $variant = substr_replace($source, $unrelated, strrpos($source, '}'), 0);
        $loop = 'case \'audit\': while ($value < 0) { $result->$name = $value; $value++; } break;';
        $variant = preg_replace('/switch \(\$name\) \{/', 'switch ($name) { ' . $loop, $variant, 1);
        token_get_all($variant, TOKEN_PARSE);
        $rule = new \WebmanAotBuilder\Compatibility\UpstreamSourceRule();
        $output = $rule->replace($date, $variant, $table[$date]);
        token_get_all($output, TOKEN_PARSE);
        ensure(str_contains($output, $loop), 'setter conversion changed an unrelated switch case loop');
        ensure(str_contains($output, $unrelated), 'setter conversion changed an unrelated method');
        echo "PASS actual setter default terminal, unrelated method and nested case loop preservation\n";
    }
}
printf("PASS %d method cases, switch ownership, trivia and unknown-role rejection in %.3fs\n", count($cases), microtime(true) - $started);
