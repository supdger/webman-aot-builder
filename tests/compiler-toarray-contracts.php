<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
if (!is_file($toolchain . '/vendor/autoload.php')) {
    throw new RuntimeException('Pass the patched TypePHP source directory');
}
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

function ensure(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

final class ToArrayCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root);
        $this->forTest = true;
    }

    public function method(string $class): TypePhp\Entity\FunctionDef
    {
        return $this->hasInterface($class)
            ? $this->getInterface($class)->methods['toarray']->functionDef
            : $this->getClass($class)->getMethod('toArray')->functionDef;
    }

    public function call(string $class, array $arguments = []): array
    {
        $this->resetFunction();
        $this->enterCompilerPhase(self::PHASE_CONVERT);
        $this->addLocalVar('value', TypePhp\Type::OBJECT);
        $this->addObject('value', $class);
        $expression = new PhpParser\Node\Expr\MethodCall(
            new PhpParser\Node\Expr\Variable('value'), 'toArray', $arguments,
        );
        return [$this->detectTypeOfExpr($expression), $this->detectClassOfExpr($expression),
            $this->parseMethodCall($expression)];
    }

    public function propertyCall(bool $static, string $class = 'ObjectHolder', array $arguments = []): array
    {
        $this->resetFunction();
        $this->enterCompilerPhase(self::PHASE_CONVERT);
        $this->addLocalVar('holder', TypePhp\Type::OBJECT);
        $this->addObject('holder', $class);
        $receiver = $static
            ? new PhpParser\Node\Expr\StaticPropertyFetch(new PhpParser\Node\Name('ObjectHolder'), 'staticObject')
            : new PhpParser\Node\Expr\PropertyFetch(new PhpParser\Node\Expr\Variable('holder'), 'object');
        $call = new PhpParser\Node\Expr\MethodCall($receiver, 'toArray', $arguments);
        return [$this->detectTypeOfExpr($call), $this->detectClassOfExpr($call), $this->parseMethodCall($call)];
    }

    public function cast(string $class): string
    {
        $this->resetFunction();
        $this->enterCompilerPhase(self::PHASE_CONVERT);
        $this->addLocalVar('value', TypePhp\Type::OBJECT);
        $this->addObject('value', $class);
        return $this->parseCastArray(new PhpParser\Node\Expr\Cast\Array_(new PhpParser\Node\Expr\Variable('value')));
    }
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-toarray-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $source = <<<'SOURCE'
<?php
interface ArrayContract { public function toArray($value); }
class DynamicArray implements ArrayContract { public function toArray($value) { return $value; } }
class BaseArray { public function toArray() { return ['parent']; } }
class ChildArray extends BaseArray { public function toArray() { return 'child'; } }
class InheritedArray extends BaseArray {}
class YieldArray { public function toArray() { yield 'value'; } }
class ResultValue {}
class ObjectArray { public function toArray(): ResultValue { return new ResultValue(); } }
class ObjectHolder { public ObjectArray $object; public static ObjectArray $staticObject; }
class PrivateArray { private function toArray($value): ResultValue { return new ResultValue(); } public function __call($name, $arguments) { return 'private magic'; } }
class ProtectedArray { protected function toArray($value): ResultValue { return new ResultValue(); } public function __call($name, $arguments) { return 'protected magic'; } public function read(ProtectedHolder $holder): ResultValue { return $holder->object->toArray('value'); } }
class LexicalArray { private function toArray($value): ResultValue { return new ResultValue(); } public function read(LexicalHolder $holder): ResultValue { return $holder->object->toArray('value'); } }
class LexicalChild extends LexicalArray { public function toArray($value): string { return 'child'; } }
class LexicalHolder { public LexicalChild $object; }
class PrivateHolder { public PrivateArray $object; }
class ProtectedHolder { public ProtectedArray $object; }
class ScalarArray { public function toArray(): string { return 'scalar'; } }
class ScalarHolder { public ScalarArray $object; }
class ExactArray { public function toArray(): array { return ['exact']; } }
#[Native] class NativeArray { public function toArray(): array { return ['native']; } }
#[Arrayable] class GeneratedArray { public int $value = 3; }
function array_contract(ArrayContract $object, $value) { return $object->toArray($value); }
function child_array(ChildArray $object) { return $object->toArray(); }
function yield_array(YieldArray $object) { return $object->toArray(); }
function object_array(ObjectArray $object): ResultValue { return $object->toArray(); }
function property_array(ObjectHolder $holder): ResultValue { return $holder->object->toArray(); }
function private_property_array(PrivateHolder $holder, $value) { $result = $holder->object->toArray($value); return $result; }
function protected_property_array(ProtectedHolder $holder, $value) { $result = $holder->object->toArray($value); return $result; }
function scalar_callback(ScalarHolder $holder): Closure { $callback = $holder->object->toArray(...); return $callback; }
function object_callback(ObjectHolder $holder): Closure { $callback = $holder->object->toArray(...); return $callback; }
function static_property_array(): ResultValue { return ObjectHolder::$staticObject->toArray(); }
class ArgumentHolder { public DynamicArray $object; }
function property_argument(ArgumentHolder $holder, $value) { return $holder->object->toArray($value); }
SOURCE;
    $file = $root . '/input.php';
    file_put_contents($file, $source);
    $compiler = new ToArrayCompiler($root);
    $compiler->prepareFile($file);
    foreach (['DynamicArray', 'ArrayContract', 'ChildArray', 'BaseArray', 'YieldArray'] as $class) {
        ensure($compiler->method($class)->returnType === TypePhp\Type::VAR, "{$class} implicit PHP return type changed");
    }
    echo "PASS ordinary class/interface/override/generator declaration metadata\n";
    $argument = [new PhpParser\Node\Arg(new PhpParser\Node\Scalar\String_('argument'))];
    foreach (['DynamicArray', 'ArrayContract'] as $class) {
        [$type, $resultClass, $call] = $compiler->call($class, $argument);
        ensure($type === TypePhp\Type::VAR && $resultClass === '', "{$class} ordinary dynamic return inferred as array");
        ensure(!str_contains($call, 'php::toArray('), "{$class} declared call diverted through conversion bridge");
    }
    foreach (['BaseArray', 'ChildArray', 'InheritedArray', 'YieldArray'] as $class) {
        [$type, , $call] = $compiler->call($class);
        ensure($type === TypePhp\Type::VAR && !str_contains($call, 'php::toArray('), "{$class} call bypassed PHP method metadata");
    }
    [$type, $resultClass] = $compiler->call('ObjectArray');
    ensure($type === TypePhp\Type::OBJECT && $resultClass === 'ResultValue', 'ordinary object return class erased by keyword inference');
    ensure($compiler->call('ScalarArray')[0] === TypePhp\Type::STR, 'ordinary scalar return type erased by keyword inference');
    ensure($compiler->call('ExactArray')[0] === TypePhp\Type::ARRAY, 'ordinary exact array metadata changed');
    foreach ([false, true] as $static) {
        [$propertyType, $propertyClass, $propertyCall] = $compiler->propertyCall($static);
        ensure($propertyType === TypePhp\Type::OBJECT && $propertyClass === 'ResultValue', 'typed property return metadata erased');
        ensure(!str_contains($propertyCall, 'php::toArray('), 'typed property ordinary call diverted through conversion bridge');
    }
    foreach (['PrivateHolder', 'ProtectedHolder'] as $holder) {
        [$propertyType, $propertyClass, $propertyCall] = $compiler->propertyCall(false, $holder, $argument);
        ensure($propertyType === TypePhp\Type::VAR && $propertyClass === '', 'inaccessible property method metadata bypassed __call');
        ensure(!str_contains($propertyCall, 'php::toArray('), 'inaccessible ordinary method diverted through conversion bridge');
    }
    foreach (['ScalarHolder', 'ObjectHolder'] as $holder) {
        [$callbackType, $callbackClass] = $compiler->propertyCall(false, $holder, [new PhpParser\Node\VariadicPlaceholder()]);
        ensure($callbackType === TypePhp\Type::VAR && $callbackClass === '', 'first-class callable inferred as method return value');
    }
    echo "PASS first-class callable string/object return metadata\n";
    echo "PASS ordinary argument calls, inherited dispatch, dynamic/scalar/object returns\n";
    foreach (['DynamicArray', 'ScalarArray', 'YieldArray'] as $class) {
        ensure(str_contains($compiler->cast($class), 'php::toArray('), "{$class} explicit cast lost runtime conversion bridge");
    }
    ensure($compiler->call('NativeArray')[0] === TypePhp\Type::ARRAY, 'valid Native hook return changed');
    ensure(!str_contains($compiler->cast('NativeArray'), 'php::toArray('), 'Native cast crossed the Zend bridge');
    ensure($compiler->method('GeneratedArray')->returnType === TypePhp\Type::ARRAY, 'Arrayable generated hook changed');
    $compiler->convertFile($file, true);
    echo "PASS actual prepare/convert with interface calls, inheritance, Generator and Arrayable\n";
    require $file;
    ensure((new DynamicArray())->toArray('argument') === 'argument', 'PHP argument semantics changed');
    ensure((new ChildArray())->toArray() === 'child' && (new InheritedArray())->toArray() === ['parent'], 'PHP inheritance semantics changed');
    ensure(iterator_to_array((new YieldArray())->toArray()) === ['value'], 'PHP Generator semantics changed');
    ensure((new ObjectArray())->toArray() instanceof ResultValue, 'PHP object return changed');
    $scalarHolder = new ScalarHolder(); $scalarHolder->object = new ScalarArray();
    $objectHolder = new ObjectHolder(); $objectHolder->object = new ObjectArray();
    ensure(scalar_callback($scalarHolder)() === 'scalar', 'string first-class callable assignment or invocation changed');
    ensure(object_callback($objectHolder)() instanceof ResultValue, 'object first-class callable assignment or invocation changed');
    $privateHolder = new PrivateHolder(); $privateHolder->object = new PrivateArray();
    $protectedHolder = new ProtectedHolder(); $protectedHolder->object = new ProtectedArray();
    ensure(private_property_array($privateHolder, 'argument') === 'private magic', 'private __call behavior changed');
    ensure(protected_property_array($protectedHolder, 'argument') === 'protected magic', 'protected __call behavior changed');
    ensure($protectedHolder->object->read($protectedHolder) instanceof ResultValue, 'accessible protected call changed');
    $lexicalHolder = new LexicalHolder(); $lexicalHolder->object = new LexicalChild();
    ensure((new LexicalArray())->read($lexicalHolder) instanceof ResultValue, 'lexical private call replaced by unrelated child declaration');
    echo "PASS ordinary PHP argument/inheritance/Generator/object-return/private/protected __call behavior\n";
    foreach ([
        'public function toArray() { return []; }',
        'public function toArray(): ?array { return []; }',
        'public function &toArray(): array { $value = []; return $value; }',
        'public function toArray($argument): array { return []; }',
        'public function toArray(): string { return "wrong"; }',
    ] as $index => $method) {
        $invalid = $root . '/invalid-' . $index . '.php';
        file_put_contents($invalid, '<?php #[Native] class InvalidNative' . $index . ' { ' . $method . ' }');
        $rejected = false;
        try { (new ToArrayCompiler($root))->prepareFile($invalid); }
        catch (TypePhp\Exception\TestError | TypePhp\Exception\SyntaxError $error) {
            $rejected = str_contains($error->getMessage(), 'Native conversion method');
        }
        ensure($rejected, 'invalid Native conversion signature accepted: ' . $method);
        echo "PASS invalid Native hook {$index} rejected\n";
    }
    echo sprintf("PASS toArray compiler contracts, %.3fs\n", microtime(true) - $started);
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
