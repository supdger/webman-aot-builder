<?php

declare(strict_types=1);

require ($argv[1] ?? '') . '/vendor/autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'WebmanAotBuilder\\';
    if (str_starts_with($class, $prefix)) {
        require dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    }
});
function ensure(bool $value, string $message): void { if (!$value) { throw new RuntimeException($message); } }
$root = sys_get_temp_dir() . '/webman-aot-array-return-' . bin2hex(random_bytes(6));
$mirror = $root . '/.webman-aot-builder/build/project';
mkdir($mirror, 0700, true);
$input = <<<'SOURCE'
<?php
namespace Fixture;
interface ArrayContract { public function toArray(); }
interface DocumentedArray { /** @return array<string, mixed> */ public function toArray(); }
interface DerivedArray extends DocumentedArray {}
interface ConflictingArray { /** @return string */ public function toArray(); }
class ContractArray implements DerivedArray { public function toArray($value = []) { return $value; } }
class ContractViolation implements DocumentedArray { public function toArray() { return null; } }
class ConflictingContracts implements DocumentedArray, ConflictingArray { public function toArray($value = []) { return $value; } }
class GeneratorContract implements DocumentedArray { public function toArray() { yield 1; } }
trait ArrayTrait { public function toArray() { return []; } }
abstract class AbstractArray { public function toArray() { return []; } }
class LiteralArray implements ArrayContract { public function toArray() { return ['value' => 4]; } }
class CastArray { public function toArray($value) { return (array) $value; } }
class DefaultArray { protected $left = []; protected $right = []; public function toArray() { return $this->all(); } public function all($keys = null) { $input = array_merge($this->left, $this->right); if (!$keys) { return $input; } foreach ($keys as $key) { return null; } } }
class CallableArray { public function toArray() { return array_merge(...); } }
class RequiredArray { public function toArray() { return $this->all(); } public function all($required) { return []; } }
class RecursiveArray { public function toArray() { return $this->all(); } public function all() { return $this->all(); } }
class DocOnly { /** @return array */ public function toArray($value) { return $value; } }
class NonArrayBranch { public function toArray($yes) { if ($yes) { return null; } return []; } }
class FallThrough { public function toArray($yes) { if ($yes) { return []; } } }
class NestedScope { public function toArray() { $fn = function () { yield 1; return 2; }; $object = new class { public function toArray() { return 2; } }; return []; } }
class GeneratorArray { public function toArray() { yield 1; return []; } }
class TypedArray { public function toArray(): array { return []; } }
class TypedUnion { public function toArray(): array|string { return []; } }
class ReferenceArray { public function &toArray() { return []; } }
class ParentArray { public function toArray() { return []; } }
use Fixture\ParentArray as ParentAlias;
class ChildArray extends ParentAlias { public function toArray() { return []; } }
class ParameterArray { public function toArray($value = [')', [1, 2]] /* ) */) { return []; } }
class FinallyScalar { public function toArray() { try { return []; } finally { return 'scalar'; } return []; } }
function toArray() { return []; }
SOURCE;
try {
    file_put_contents($mirror . '/input.php', $input);
    file_put_contents($mirror . '/ignored.php', '<?php class IgnoredInput { function toArray() { return []; } }');
    $ignoredBefore = hash_file('sha256', $mirror . '/ignored.php');
    $rule = new WebmanAotBuilder\Compatibility\ArrayReturnTypeRule();
    echo "STAGE inferred array declarations and scope safety\n";
    $receipt = $rule->apply($mirror, ['ignored.php']);
    $output = file_get_contents($mirror . '/input.php');
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
    $finder = new PhpParser\NodeFinder();
    $classes = $finder->findInstanceOf($parser->parse($output), PhpParser\Node\Stmt\Class_::class);
    $adapted = [];
    foreach ($classes as $class) {
        if ($class->name === null) { continue; }
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === 'toArray' && $method->returnType !== null) { $adapted[] = $class->name->toString(); }
        }
    }
    sort($adapted);
    ensure($adapted === ['CastArray', 'ContractArray', 'ContractViolation', 'DefaultArray', 'LiteralArray', 'NestedScope', 'ParameterArray', 'TypedArray', 'TypedUnion'], 'unknown or inherited method was annotated');
    ensure(hash_file('sha256', $mirror . '/ignored.php') === $ignoredBefore, 'ignored source was modified');
    ensure($receipt['files']['input.php']['beforeSha256'] === hash('sha256', $input), 'receipt input digest differs');
    ensure($receipt['files']['input.php']['afterSha256'] === hash('sha256', $output), 'receipt output digest differs');
    $again = $rule->apply($mirror, ['ignored.php']);
    ensure($again['files'] === [] && file_get_contents($mirror . '/input.php') === $output, 'adaptation is not idempotent');
    require $mirror . '/input.php';
    ensure((new Fixture\ContractArray())->toArray(['value' => 4]) === ['value' => 4], 'contract array value changed');
    try { (new Fixture\ContractViolation())->toArray(); throw new RuntimeException('Contract violation did not fail'); } catch (TypeError) {}
    ensure((new Fixture\DefaultArray())->toArray() === [], 'default-call branch runtime differs');
    ensure((new Fixture\LiteralArray())->toArray() === ['value' => 4], 'adapted literal method runtime differs');
    ensure((new Fixture\CastArray())->toArray((object) ['value' => 4]) === ['value' => 4], 'adapted cast method runtime differs');
    ensure((new Fixture\NestedScope())->toArray() === [], 'nested return scope runtime differs');
    try { $rule->apply($root); throw new RuntimeException('unowned source was accepted'); }
    catch (WebmanAotBuilder\Cli\ConfigurationException $error) { ensure(str_contains($error->getMessage(), 'isolated'), 'wrong ownership failure'); }
    echo "PASS literal/cast runtime, nested scopes, yield/unknown/finally, inheritance alias, declarations, ignored source, receipt, idempotence and ownership\n";
} finally {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($root);
}
