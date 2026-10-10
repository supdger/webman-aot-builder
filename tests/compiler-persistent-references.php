<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class PersistentReferenceCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root);
        $this->forTest = true;
        $this->setBuildDir($root . '/build');
        $this->setTargetName('persistent_references');
    }

    public function argument(string $function, int $index): TypePhp\Entity\ArgInfo
    {
        return $this->getFunction($function)->argInfoList[$index];
    }

    public function methodArgument(string $class, string $method, int $index): TypePhp\Entity\ArgInfo
    {
        return $this->hasInterface($class)
            ? $this->getInterface($class)->methods[strtolower($method)]->functionDef->argInfoList[$index]
            : $this->getClass($class)->getMethod($method)->functionDef->argInfoList[$index];
    }

    public function snapshotKey(string $file): string
    {
        $method = new ReflectionMethod(TypePhp\Translator::class, 'preparedProjectKey');
        $method->setAccessible(true);
        return $method->invoke($this, [$file]);
    }

    public function snapshot(string $cacheKey, bool $restore): bool
    {
        $method = new ReflectionMethod(TypePhp\Translator::class, $restore ? 'restorePreparedProject' : 'storePreparedProject');
        $method->setAccessible(true);
        return $restore ? $method->invoke($this, $cacheKey) : ($method->invoke($this, $cacheKey) === null);
    }
}

function requirePersistent(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-persistent-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
function keep(array &$value, bool &$active): Closure {
    return function () use (&$value, &$active): void { $value[]='later'; $active=false; };
}
function forward(array &$value, bool &$active): Closure {return keep($value,$active);}
function outer(array &$value, bool &$active): Closure {return forward($value,$active);}
function synchronous(array &$value): void {$value[]='synchronous';}
interface KeeperContract {public function keep(array &$value, bool &$active): Closure;}
class ParentKeeper implements KeeperContract {
    public function keep(array &$value, bool &$active): Closure {return keep($value,$active);}
}
class ChildKeeper extends ParentKeeper {
    public function keep(array &$value, bool &$active): Closure {
        return function () use (&$value, &$active): void {$value[]='child';$active=false;};
    }
}
function caller(): Closure { $value=[]; $active=true; $copy=$value; $callback=outer($value,$active); $value=['reset'];$active=false;return $callback; }
function duplicate(array &$first,array &$second): Closure { return function()use(&$first,&$second):void{$first[]='first';$second[]='second';}; }
function duplicateCaller(): Closure {$value=[];return duplicate($value,$value);}
function defaults(array &$value=[]): Closure {return function()use(&$value):void{$value[]='default';};}
function defaultCaller(): Closure {return defaults();}
function nestedClosure(): Closure {return function():Closure{$value=[];$active=true;return keep($value,$active);};}
function generatorCaller(): Iterator {$value=[];$active=true;$callback=keep($value,$active);yield $callback;}
class Holder {public array $value=[];public bool $active=true;}
function propertyCaller(Holder $holder): Closure {return keep($holder->value,$holder->active);}
class PrivateParent {private function same(array &$value): void {}}
class PrivateChild extends PrivateParent {private function same(array &$value): Closure {return function () use (&$value) {};}}
SOURCE);
    $compiler = new PersistentReferenceCompiler($root);
    $cacheKey = $compiler->snapshotKey($file);
    $compiler->prepareFile($file);
    $compiler->finalizePersistentReferences();
    foreach (['keep', 'forward', 'outer'] as $function) {
        foreach ([0, 1] as $index) {
            $argument = $compiler->argument($function, $index);
            requirePersistent($argument->persistentReference && TypePhp\Type::isTypedRefType($argument->type)
                && $argument->typeCheck !== null, $function . ' lost declaration contract');
        }
    }
    foreach (['KeeperContract', 'ParentKeeper', 'ChildKeeper'] as $class) {
        requirePersistent($compiler->methodArgument($class, 'keep', 0)->persistentReference, $class . ' override ABI differs');
    }
    requirePersistent(!$compiler->argument('synchronous', 0)->persistentReference
        && !$compiler->methodArgument('PrivateParent', 'same', 0)->persistentReference,
        'Synchronous or unrelated private ABI changed');
    echo "PASS direct capture, nested forward, related interface/public override and private boundary\n";
    $compiler->convertFile($file);
    $cpp = file_get_contents($compiler->getCppFile($file));
    requirePersistent(str_contains($cpp, 'php::Ref value, php::Ref active')
        && str_contains($cpp, 'php::Var value;') && str_contains($cpp, 'php::Var active;')
        && str_contains($cpp, 'php_keep(holder.attrRef("value"), holder.attrRef("active"))')
        && str_contains($cpp, 'php_duplicate(value.toReference(), value.toReference())')
        && str_contains($cpp, 'php::Array & value'), 'Actual conversion lost reference storage boundary');
    echo "PASS actual caller, duplicate alias, default, closure, generator local and property conversion\n";
    $compiler->snapshot($cacheKey, false);
    $warm = new PersistentReferenceCompiler($root);
    requirePersistent($warm->snapshot($cacheKey, true), 'Prepared-project warm cache did not restore');
    foreach (['keep', 'forward', 'outer'] as $function) {
        requirePersistent(serialize($warm->argument($function, 0)) === serialize($compiler->argument($function, 0)),
            $function . ' changed on warm restore');
    }
    $warm->convertFile($file);
    requirePersistent(file_get_contents($warm->getCppFile($file)) === $cpp, 'Warm conversion changed emitted ABI or body');
    echo "PASS cold/warm ArgInfo declaration, type check, persistent ABI and emitted C++\n";
    foreach ([
        'fixed-value-parameter' => ['function fixed(array $value):Closure{$active=true;return keep($value,$active);}', 'Persistent PHP reference requires owned Zend storage'],
        'generator-reference' => ['function delayed(array &$value):Iterator{yield $value;}', 'Generators with by-reference or variadic parameters are not supported yet'],
        'native-storage' => ['#[Native] class NativeHolder {public array $value=[]; public function run():Closure {$active=true;return keep($this->value,$active);}}', 'Native'],
    ] as $name => [$source, $message]) {
        $negativeRoot = $root . '/' . $name; mkdir($negativeRoot);
        $negative = $negativeRoot . '/negative.php';
        file_put_contents($negative, '<?php function keep(array &$value,bool &$active):Closure{return function()use(&$value,&$active){};} ' . $source);
        try {
            $negativeCompiler = new PersistentReferenceCompiler($negativeRoot);
            $negativeCompiler->prepareFile($negative); $negativeCompiler->finalizePersistentReferences(); $negativeCompiler->convertFile($negative);
            throw new RuntimeException($name . ' unexpectedly accepted');
        } catch (TypePhp\Exception\TestError $error) {
            requirePersistent(str_contains($error->getMessage(), $message), $name . ': ' . $error->getMessage());
            echo 'PASS strict diagnostic ' . $name . "\n";
        }
    }
    echo 'PASS persistent references elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
