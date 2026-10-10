<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class ObjectJoinCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root);
        $this->forTest = true;
        $this->setBuildDir($root . '/build');
        $this->setTargetName('object_join_fixture');
    }

    public function plan(string $code, bool $argument = false): array
    {
        $this->resetFunction();
        if ($argument) {
            $this->addArgument('value', TypePhp\Type::OBJECT);
            $this->addObject('value', 'JoinA');
        }
        $body = (new PhpParser\ParserFactory())->createForNewestSupportedVersion()->parse('<?php ' . $code);
        $this->prepareObjectAssignmentJoins($body);
        return [$this->context->varTypeDegradations, $this->context->objects,
            $this->context->declaredObjects, $this->context->stableObjects, $this->context->exactObjects];
    }
}

function checkJoin(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

$root = sys_get_temp_dir() . '/webman-aot-object-join-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
$started = microtime(true);
$file = $root . '/fixture.php';
$source = <<<'PHP'
<?php
class JoinA { public function value(): string { return 'A'; } }
class JoinB { public function value(): string { return 'B'; } }
class JoinChild extends JoinA {}
function join_branch(bool $flag): string {
    if ($flag) { $value = new JoinA(); } else { $value = new JoinB(); }
    return $value->value();
}
function join_serial(): array {
    $value = new JoinA(); $first = $value->value();
    $value = new JoinB(); return [$first, $value->value()];
}
function join_ternary(bool $flag): string {
    $value = $flag ? new JoinA() : new JoinB(); return $value->value();
}
function join_closure(): Closure {
    return function (bool $flag): string {
        if ($flag) { $value = new JoinA(); } else { $value = new JoinB(); }
        return $value->value();
    };
}
function join_generator(): Generator {
    $value = new JoinA(); yield $value->value();
    $value = new JoinB(); yield $value->value();
}
function join_generator_closure(): Closure {
    return function (): Generator {
        $value = new JoinA(); yield $value->value();
        $value = new JoinB(); yield $value->value();
    };
}
function join_accept($value): string { return $value->value(); }
function join_argument(bool $flag): string {
    if ($flag) { $value = new JoinA(); } else { $value = new JoinB(); }
    return join_accept($value);
}
function join_mutate(&$value): void {}
PHP;
file_put_contents($file, $source);
try {
    $compiler = new ObjectJoinCompiler($root);
    $compiler->prepareFile($file);
    $plan = $compiler->plan('$value = new JoinA(); $value->value(); $value = new JoinB(); $value->value();');
    checkJoin(($plan[0]['value'] ?? null) === TypePhp\Type::VAR
        && $plan[1] === [] && $plan[2] === [] && $plan[3] === [] && $plan[4] === [],
        'Polymorphic local retained concrete class metadata');
    echo "PASS complete prepass reserves Var before either sibling method call\n";
    checkJoin(($compiler->plan('$value = new JoinA(); $value = new JoinChild();')[0]['value'] ?? null) === TypePhp\Type::VAR,
        'Known parent and child did not reserve polymorphic storage');
    echo "PASS parent and child reserve dynamic PHP storage\n";
    foreach ([
        'same-class' => '$value = new JoinA(); $value = new JoinA();',
        'static' => 'static $value; $value = new JoinA(); $value = new JoinB();',
        'global' => 'global $value; $value = new JoinA(); $value = new JoinB();',
        'reference-right' => '$value = new JoinA(); $alias =& $value; $value = new JoinB();',
        'reference-left' => '$value =& $alias; $value = new JoinA(); $value = new JoinB();',
        'reference-array' => '$value = new JoinA(); $array = [&$value]; $value = new JoinB();',
        'reference-capture' => '$value = new JoinA(); $callback = function () use (&$value) {}; $value = new JoinB();',
        'unset' => '$value = new JoinA(); unset($value); $value = new JoinB();',
        'unknown-write' => '$value = new JoinA(); $value = unknown(); $value = new JoinB();',
        'dynamic-variable' => '$value = new JoinA(); $$name = 1; $value = new JoinB();',
        'destructure' => '$value = new JoinA(); [$value] = $array; $value = new JoinB();',
        'compound' => '$value = new JoinA(); $value += 1; $value = new JoinB();',
        'foreach-binding' => '$value = new JoinA(); foreach ($array as $value) {} $value = new JoinB();',
        'catch-binding' => '$value = new JoinA(); try {} catch (Exception $value) {} $value = new JoinB();',
        'known-reference-call' => '$value = new JoinA(); join_mutate($value); $value = new JoinB();',
        'unknown-call' => '$value = new JoinA(); unknown($value); $value = new JoinB();',
        'unknown-callable' => '$value = new JoinA(); $callback($value); $value = new JoinB();',
    ] as $name => $code) {
        checkJoin(!isset($compiler->plan($code)[0]['value']), "{$name} crossed the join boundary");
        echo "PASS {$name} retains existing storage constraints\n";
    }
    checkJoin(!isset($compiler->plan('$value = new JoinA(); $value = new JoinB();', true)[0]['value']),
        'Declared argument was widened');
    echo "PASS typed argument retains its declared class constraint\n";
    $compiler->convertFile($file);
    $cpp = '';
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
    foreach ($entries as $entry) {
        if ($entry->isFile() && $entry->getExtension() === 'cc') { $cpp .= file_get_contents($entry->getPathname()); }
    }
    checkJoin(substr_count($cpp, 'php::Var value;') >= 6
        && substr_count($cpp, 'typephp_call_method_cached') >= 9,
        'Actual conversion did not emit dynamic local storage and dispatch');
    echo "PASS real conversion covers branch, serial, ternary, Closure and Fiber Generator locals\n";
    require $file;
    checkJoin(join_branch(true) === 'A' && join_branch(false) === 'B', 'Branch PHP behavior changed');
    checkJoin(join_serial() === ['A', 'B'], 'Serial PHP behavior changed');
    checkJoin(join_ternary(true) === 'A' && join_ternary(false) === 'B', 'Ternary PHP behavior changed');
    checkJoin(join_closure()(true) === 'A' && join_closure()(false) === 'B', 'Closure PHP behavior changed');
    checkJoin(iterator_to_array(join_generator()) === ['A', 'B'], 'Generator PHP behavior changed');
    checkJoin(iterator_to_array(join_generator_closure()()) === ['A', 'B'], 'Generator Closure PHP behavior changed');
    checkJoin(join_argument(true) === 'A' && join_argument(false) === 'B', 'Known by-value argument behavior changed');
    echo "PASS PHP baseline retains exact sibling behavior in every covered entry point\n";
    foreach ([
        'declared-reference-argument' => '<?php function typed_join(JoinA &$value): void { $value = new JoinA(); $value = new JoinB(); }',
        'native-object' => '<?php #[Native] class NativeJoinA {} #[Native] class NativeJoinB {} function native_join(): void { $value = new NativeJoinA(); $value = new NativeJoinB(); }',
    ] as $name => $invalidSource) {
        $invalid = $root . '/' . $name . '.php';
        file_put_contents($invalid, $invalidSource);
        $rejected = false;
        $negativeCompiler = new ObjectJoinCompiler($root);
        $negativeCompiler->prepareFile($file);
        try {
            $negativeCompiler->prepareFile($invalid);
            $negativeCompiler->convertFile($invalid);
        } catch (TypePhp\Exception\TestError | TypePhp\Exception\SyntaxError $error) {
            $rejected = str_contains($error->getMessage(), 'Cannot re-assign typed object')
                || str_contains($error->getMessage(), 'Cannot assign native object')
                || ($name === 'declared-reference-argument' && str_contains($error->getMessage(), 'References are only supported for int, string, float, bool, array, mixed, or union types'));
        }
        checkJoin($rejected, "{$name} concrete object constraint was silently removed");
        echo "PASS actual {$name} conversion retains strict rejection\n";
    }
    echo 'PASS ordinary object assignment join contracts elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
