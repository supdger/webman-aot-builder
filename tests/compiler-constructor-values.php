<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class ConstructorValueCompiler extends TypePhp\CompilerTest
{
 public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
 public function constructor(string $class):TypePhp\Entity\FunctionDef{return $this->getClass($class)->getMethod('__construct')->functionDef;}
}
function constructorCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-constructor-values-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$started=microtime(true);
try{
 $file=$root.'/fixture.php';file_put_contents($file, <<<'SOURCE'
<?php
trait ConstructorTrait{function __construct(){return 'trait';}}
class ConstructorResult{function __construct(){return $this;}}
class ConstructorScalar{function __construct(){return 17;}}
class ConstructorNull{function __construct(){return;}}
class ConstructorImplicit{function __construct(){}}
class ConstructorTraitConsumer{use ConstructorTrait;}
#[Native] class NativeConstructorEmpty{function __construct(){}}
#[Native] class NativeConstructorTrait{use EmptyConstructorTrait;}
trait EmptyConstructorTrait{function __construct(){}}
SOURCE);
 $compiler=new ConstructorValueCompiler($root);$compiler->prepareFile($file);$compiler->composeTraitDeclarations([$file]);
 foreach(['ConstructorResult','ConstructorScalar','ConstructorNull','ConstructorImplicit','ConstructorTraitConsumer']as$class){$fn=$compiler->constructor($class);constructorCheck($fn->returnType===TypePhp\Type::VAR&&$fn->returnTypeUndeclared&&$fn->returnTypeStr==='','ordinary constructor keeps PHP undeclared return and Var result '.$class);}
 foreach(['NativeConstructorEmpty','NativeConstructorTrait']as$class)constructorCheck($compiler->constructor($class)->returnType===TypePhp\Type::VOID,'Native constructor retains void ABI '.$class);
 $compiler->convertFile($file);$cpp=file_get_contents($compiler->getCppFile($file));constructorCheck(str_contains($cpp,'php::move(retval, return_value);'),'Zend wrapper preserves explicit constructor result');$header=file_get_contents(glob($root.'/build/include/*arginfo.h')[0]);constructorCheck(str_contains($header,'ZEND_BEGIN_ARG_INFO_EX(arginfo_class_ConstructorResult___construct')&&!str_contains($header,'ZEND_BEGIN_ARG_WITH_RETURN_TYPE'),'constructor arginfo does not introduce a PHP return declaration');
 foreach(['native-value'=>'#[Native] class RejectedNative{function __construct(){return 17;}}','native-trait'=>'trait BadNativeCtor{function __construct(){return 17;}} #[Native] class RejectedNative{use BadNativeCtor;}','declared-void'=>'class RejectedCtor{function __construct():void{}}','declared-scalar'=>'class RejectedCtor{function __construct():int{return 17;}}','ordinary-void'=>'function rejected():void{return 17;}']as$name=>$source){$dir=$root.'/'.$name;mkdir($dir);$negative=$dir.'/fixture.php';file_put_contents($negative,'<?php '.$source);try{$c=new ConstructorValueCompiler($dir);$c->prepareFile($negative);$c->composeTraitDeclarations([$negative]);$c->convertFile($negative);throw new RuntimeException('boundary widened '.$name);}catch(TypePhp\Exception\TestError$e){constructorCheck($e->getMessage()!=='','original return boundary '.$name);}}
 echo 'PASS ordinary constructor values elapsed='.round(microtime(true)-$started,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
