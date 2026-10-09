<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Compatibility/GeneratorSourceScope.php';
require dirname(__DIR__) . '/src/Compatibility/UpstreamSourceRule.php';
require $argv[1] . '/vendor/autoload.php';
require $argv[2];

#[Attribute(Attribute::TARGET_CLASS)]
class ListenerScopeAttribute
{
    public function __construct(public string $name) {}
}
#[ListenerScopeAttribute('parent')]
class ListenerScopeParent {}
class ListenerScopeChild extends ListenerScopeParent {}
#[ListenerScopeAttribute('interface')]
interface ListenerScopeInterface {}
class ListenerScopeImplementation implements ListenerScopeInterface {}
#[ListenerScopeAttribute('own')]
class ListenerScopeOwn extends ListenerScopeParent implements ListenerScopeInterface {}
class ListenerScopeNone {}

function ensure(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
$started = microtime(true);
$path = 'vendor/symfony/http-kernel/EventListener/ErrorListener.php';
$source = file_get_contents($argv[1] . '/' . $path);
$table = (new ReflectionClass(Tinywan\Typephp\Compiler\ProjectGenerator::class))->getConstant('SWITCH_TERMINAL_REPLACEMENTS');
$rule = new WebmanAotBuilder\Compatibility\UpstreamSourceRule();
$output = $rule->replace($path, $source, $table[$path]);
token_get_all($output, TOKEN_PARSE);
$renamed = str_replace('class ErrorListener implements', 'class ScopedErrorListener implements', $output);
eval(substr($renamed, 5));
$originalClass = new ReflectionClass(Symfony\Component\HttpKernel\EventListener\ErrorListener::class);
$adaptedClass = new ReflectionClass(Symfony\Component\HttpKernel\EventListener\ScopedErrorListener::class);
$original = $originalClass->newInstanceWithoutConstructor();
$adapted = $adaptedClass->newInstanceWithoutConstructor();
foreach ([ListenerScopeParent::class, ListenerScopeChild::class, ListenerScopeImplementation::class, ListenerScopeOwn::class, ListenerScopeNone::class] as $class) {
    $before = $originalClass->getMethod('getInheritedAttribute')->invoke($original, $class, ListenerScopeAttribute::class);
    $after = $adaptedClass->getMethod('getInheritedAttribute')->invoke($adapted, $class, ListenerScopeAttribute::class);
    ensure($before == $after, 'attribute lineage result changed: ' . $class);
    echo "PASS ordinary PHP attribute lineage {$class}\n";
}
$unrelated = 'public function scopeUntouched($class, $attribute) { return $class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF); }';
$variant = substr_replace($source, $unrelated, strrpos($source, '}'), 0);
$callback = '$scopeUnused = static function ($class, $attribute) { return $class->getAttributes($attribute, \ReflectionAttribute::IS_INSTANCEOF); };';
$variant = str_replace('$class = new \ReflectionClass($class);', $callback . '$class = new \ReflectionClass($class);', $variant);
$converted = $rule->replace($path, $variant, $table[$path]);
token_get_all($converted, TOKEN_PARSE);
ensure(str_contains($converted, $unrelated) && str_contains($converted, $callback), 'unrelated method or callback was changed');
ensure(!str_contains(substr($output, strpos($output, 'do {'), strpos($output, 'while ($interfaces)') - strpos($output, 'do {')), '$interfaceReflection'), 'interface reflection leaked into class lineage');
printf("PASS class/interface reflection binding and unrelated scopes in %.3fs\n", microtime(true) - $started);
