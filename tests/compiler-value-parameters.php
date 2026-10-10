<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class ValueParameterCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root); $this->forTest = true;
        $this->setBuildDir($root . '/build'); $this->setTargetName('value_parameters');
    }
    public function strings(): array { return array_flip($this->literalStrings); }
    public function declaration(string $name): string { return serialize($this->getFunction($name)); }
    public function snapshotKey(string $file): string
    {
        $method = new ReflectionMethod(TypePhp\Translator::class, 'preparedProjectKey');
        $method->setAccessible(true); return $method->invoke($this, [$file]);
    }
    public function snapshot(string $key, bool $restore): bool
    {
        $method = new ReflectionMethod(TypePhp\Translator::class, $restore ? 'restorePreparedProject' : 'storePreparedProject');
        $method->setAccessible(true); return $restore ? $method->invoke($this, $key) : ($method->invoke($this, $key) === null);
    }
}
function checkParameter(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
$root = sys_get_temp_dir() . '/webman-aot-value-parameters-' . bin2hex(random_bytes(6));
mkdir($root, 0700, true); $started = microtime(true);
try {
    $file = $root . '/fixture.php';
    file_put_contents($file, <<<'SOURCE'
<?php
class ParameterResult {public function value():string{return 'result';}}
class OtherResult {public function value():string{return 'other';}}
function parameter_result(string $value):ParameterResult{return new ParameterResult();}
function parameter_replace(string $value):ParameterResult{$before=$value;$value=parameter_result($value);return $value;}
function parameter_cycle(string $value='default'):string{$value=1;$value=[];$value=new ParameterResult();$value='done';return $value;}
function parameter_branch(string $value,bool $object):mixed{$value=$object?new ParameterResult():[];return $value;}
function parameter_object(ParameterResult $value):string{$first=$value->value();$value=new OtherResult();return $value->value();}
function parameter_closure():Closure{return function(string $value):array{$copy=$value;$value=[];return [$copy,$value];};}
function parameter_generator(string $value):Generator{yield $value;$value=[];yield $value;}
function parameter_object_generator(ParameterResult $value):Generator{yield $value->value();$value=new OtherResult();yield $value->value();}
class ParameterParent {public function convert(string $value):mixed{$value=[];return $value;}}
class ParameterChild extends ParameterParent {public function convert(string $value):mixed{$value=new ParameterResult();return $value;}}
SOURCE);
    $compiler = new ValueParameterCompiler($root); $key = $compiler->snapshotKey($file);
    $compiler->prepareFile($file); $original = $compiler->declaration('parameter_replace');
    $compiler->convertFile($file); $cpp = file_get_contents($compiler->getCppFile($file));
    checkParameter($compiler->declaration('parameter_replace') === $original, 'Public declaration changed during local storage planning');
    checkParameter(str_contains($cpp, 'php::Str __typephp_captured_arg_value')
        && str_contains($cpp, 'php::Var value = __typephp_captured_arg_value;')
        && str_contains($cpp, 'php_parameter_result(php::toStringArgExact(value'), 'Original entry ABI or early typed call changed');
    checkParameter(str_contains($cpp, 'php::toObject(value') && str_contains($cpp, 'typephp_call_method_cached'), 'Object return or dynamic receiver dispatch lost');
    echo "PASS actual default, mixed cycles, branch, sibling object, inheritance, closure and generator conversion\n";
    $compiler->snapshot($key, false); $warm = new ValueParameterCompiler($root);
    checkParameter($warm->snapshot($key, true), 'Warm prepared cache did not restore');
    $warm->convertFile($file);
    $normalize = static fn(string $code, array $strings): string => preg_replace_callback('/get_str\((\d+)\)/', static fn(array $match): string => 'get_str(' . json_encode($strings[(int) $match[1]]) . ')', $code);
    checkParameter($normalize(file_get_contents($warm->getCppFile($file)), $warm->strings()) === $normalize($cpp, $compiler->strings())
        && $warm->declaration('parameter_replace') === $original, 'Warm declaration or storage plan differs');
    echo "PASS cold/warm declaration and emitted body retain original parameter ABI\n";
    foreach ([
        'by-reference' => 'function rejected(string &$value):mixed{$value=[];return $value;}',
        'unknown-assignment' => 'function rejected(string $value,$unknown):mixed{$value=$unknown;return $value;}',
        'reference-alias' => 'function rejected(string $value):mixed{$alias=&$value;$value=[];return $value;}',
        'unknown-call' => 'function rejected(string $value):mixed{unknown_call($value);$value=[];return $value;}',
        'native-parameter' => '#[Native] class ParameterNative {} function rejected(ParameterNative $value):mixed{$value=[];return $value;}',
        'typed-array' => 'function rejected(#[StdList(Type::String)] array $value):mixed{$value="changed";return $value;}',
        'std-container' => 'function rejected(#[StdVector(Type::String)] $value):mixed{$value="changed";return $value;}',
    ] as $name => $source) {
        $negativeRoot = $root . '/' . $name; mkdir($negativeRoot); $negative = $negativeRoot . '/negative.php';
        file_put_contents($negative, '<?php ' . $source);
        try {
            $negativeCompiler = new ValueParameterCompiler($negativeRoot);
            $negativeCompiler->prepareFile($negative); $negativeCompiler->convertFile($negative);
            $negativeCpp = file_get_contents($negativeCompiler->getCppFile($negative));
            if ($name === 'unknown-assignment') {
                checkParameter(!str_contains($negativeCpp, '__typephp_captured_arg_value'), 'Unknown RHS widened fixed storage');
                echo "PASS excluded unknown assignment preserves existing conversion/storage\n";
                continue;
            }
            throw new RuntimeException($name . ' unexpectedly accepted');
        } catch (TypePhp\Exception\TestError $error) {
            checkParameter($error->getMessage() !== '', $name . ' lost strict diagnostic');
            echo 'PASS excluded ' . $name . ': ' . $error->getMessage() . "\n";
        }
    }
    echo 'PASS mutable value parameters elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) { $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname()); }
    rmdir($root);
}
