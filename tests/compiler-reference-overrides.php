<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class ReferenceOverrideCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root) { parent::__construct($root); $this->forTest = true; }
    public function argument(string $class): TypePhp\Entity\ArgInfo
    {
        return ($this->hasInterface($class) ? $this->getInterface($class)->methods['dispatch']
            : $this->getClass($class)->getMethod('dispatch'))->functionDef->argInfoList[0];
    }
    public function hasDeclaration(string $class): bool { return $this->hasClass($class) || $this->hasInterface($class); }
    public function stubAbi(string $class): void { $this->getClass($class)->getMethod('dispatch')->functionDef->persistentReferenceEligible = false; }
}
function checkOverride(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-reference-overrides-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
class RedisBoundaryConnection extends Redis {
    public function dispatch(array &$value): Closure { return function()use(&$value):void{$value[]='redis';}; }
}
class MissingBoundaryChild extends MissingBoundaryParent {
    public function dispatch(array &$value): Closure { return function()use(&$value):void{$value[]='missing';}; }
}
class UnrelatedOwner {public function dispatch(array &$value): Closure {return function():void{};}}
interface OverrideTop {public function dispatch(array &$value): Closure;}
interface OverrideMiddle extends OverrideTop {public function dispatch(array &$value): Closure;}
class OverrideParent implements OverrideMiddle {public function dispatch(array &$value): Closure {return function():void{};}}
class OverrideChild extends OverrideParent {public function dispatch(array &$value): Closure {return function()use(&$value):void{$value[]='child';};}}
class OverrideGrandchild extends OverrideChild {public function dispatch(array &$value): Closure {return function():void{};}}
SOURCE);
    $compiler = new ReferenceOverrideCompiler($root);
    $compiler->prepareFile($file);
    checkOverride(!$compiler->hasDeclaration('Redis') && !$compiler->hasDeclaration('MissingBoundaryParent'), 'Fixture unexpectedly registered ancestor PHP declarations');
    $compiler->finalizePersistentReferences();
    foreach (['RedisBoundaryConnection', 'MissingBoundaryChild'] as $class) {
        checkOverride($compiler->argument($class)->persistentReference && TypePhp\Type::isTypedRefType($compiler->argument($class)->type), $class . ' lost its own typed reference ABI');
    }
    checkOverride(!$compiler->argument('UnrelatedOwner')->persistentReference, 'Unrelated same-name method acquired persistent ABI');
    echo "PASS target Redis and missing ancestors stop at source boundary; unrelated owner ABI remains unchanged\n";
    foreach (['OverrideTop', 'OverrideMiddle', 'OverrideParent', 'OverrideChild', 'OverrideGrandchild'] as $class) {
        checkOverride($compiler->argument($class)->persistentReference && $compiler->argument($class)->typeCheck !== null, $class . ' lost known override propagation or entry check');
    }
    echo "PASS transitive PHP interface and class overrides keep typed persistent references\n";
    $compiler->finalizePersistentReferences();
    checkOverride(!$compiler->argument('UnrelatedOwner')->persistentReference, 'Repeated fixed point changed unrelated ABI');
    echo "PASS repeated finalization preserves unrelated and related contracts\n";
    $negative = new ReferenceOverrideCompiler($root);
    $negative->prepareFile($file);
    $negative->stubAbi('OverrideParent');
    try {
        $negative->finalizePersistentReferences();
        throw new RuntimeException('Known stub override ABI was changed');
    } catch (TypePhp\Exception\TestError $error) {
        checkOverride(str_contains($error->getMessage(), 'Persistent PHP references cannot change a Native or stub override ABI'), 'Wrong stub boundary error: ' . $error->getMessage());
    }
    echo 'PASS known stub override retains exact ABI rejection; elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
