<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class DestructorValueCompiler extends TypePhp\CompilerTest
{
 public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
 public function cloneDeclaration(string $class):TypePhp\Entity\FunctionDef{return $this->getClass($class)->getMethod('__clone')->functionDef;}
 public function destructor(string $class):TypePhp\Entity\FunctionDef{return $this->getClass($class)->getMethod('__destruct')->functionDef;}
}
function destructorCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-destructor-values-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$started=microtime(true);
try{
 $file=$root.'/fixture.php';file_put_contents($file, <<<'SOURCE'
<?php
trait DestructorTrait{function __destruct(){return 'trait';}}
class DestructorResult{function __destruct(){return $this;}}
class DestructorScalar{function __destruct(){return 17;}}
class DestructorNull{function __destruct(){return;}}
class DestructorImplicit{function __destruct(){}}
class DestructorTraitConsumer{use DestructorTrait;}
#[Native] class NativeDestructorEmpty{function __destruct(){}}
#[Native] class NativeDestructorTrait{use EmptyDestructorTrait;}
trait EmptyDestructorTrait{function __destruct(){}}
class OrdinaryCloneBoundary{function __clone(){}}
SOURCE);
 $compiler=new DestructorValueCompiler($root);$compiler->prepareFile($file);$compiler->composeTraitDeclarations([$file]);
 foreach(['DestructorResult','DestructorScalar','DestructorNull','DestructorImplicit','DestructorTraitConsumer']as$class){$fn=$compiler->destructor($class);destructorCheck($fn->returnType===TypePhp\Type::VAR&&$fn->returnTypeUndeclared&&$fn->returnTypeStr==='','ordinary destructor keeps PHP undeclared return and Var result '.$class);}
 foreach(['NativeDestructorEmpty','NativeDestructorTrait']as$class)destructorCheck($compiler->destructor($class)->returnType===TypePhp\Type::VOID,'Native destructor retains void ABI '.$class);
 destructorCheck($compiler->cloneDeclaration('OrdinaryCloneBoundary')->returnType===TypePhp\Type::VOID,'clone retains existing void result');
 $compiler->convertFile($file);$cpp=file_get_contents($compiler->getCppFile($file));destructorCheck(str_contains($cpp,'php::move(retval, return_value);'),'Zend wrapper preserves explicit destructor result');$header=file_get_contents(glob($root.'/build/include/*arginfo.h')[0]);destructorCheck(str_contains($header,'ZEND_BEGIN_ARG_INFO_EX(arginfo_class_DestructorResult___destruct'),'destructor arginfo does not introduce a PHP return declaration');
 foreach(['native-value'=>'#[Native] class RejectedNative{function __destruct(){return 17;}}','native-trait'=>'trait BadNativeCtor{function __destruct(){return 17;}} #[Native] class RejectedNative{use BadNativeCtor;}','declared-void'=>'class RejectedCtor{function __destruct():void{}}','declared-scalar'=>'class RejectedCtor{function __destruct():int{return 17;}}','ordinary-void'=>'function rejected():void{return 17;}','clone-value'=>'class CloneBoundary{function __clone(){return 17;}}']as$name=>$source){$dir=$root.'/'.$name;mkdir($dir);$negative=$dir.'/fixture.php';file_put_contents($negative,'<?php '.$source);try{$c=new DestructorValueCompiler($dir);$c->prepareFile($negative);$c->composeTraitDeclarations([$negative]);$c->convertFile($negative);throw new RuntimeException('boundary widened '.$name);}catch(TypePhp\Exception\TestError$e){destructorCheck($e->getMessage()!=='','original return boundary '.$name);}}
 echo 'PASS ordinary destructor values elapsed='.round(microtime(true)-$started,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
