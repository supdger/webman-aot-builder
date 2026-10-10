<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';
require $toolchain.'/vendor/autoload.php';
require $toolchain.'/src/gen_stub.php';
final class RelatedObjectCompiler extends TypePhp\CompilerTest
{
    public array $plans=[];
    public function __construct(string $root){parent::__construct($root);$this->forTest=true;}
    protected function prepareObjectAssignmentJoins(PhpParser\NodeAbstract|array|null $body):void
    {
        parent::prepareObjectAssignmentJoins($body);
        $this->plans[strtolower($this->functionDef->name)]=$this->context->varTypeDegradations;
    }
}
function relatedObjectCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-related-object-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
$classes='class JoinBase{function value():int{return 1;}}class JoinChild extends JoinBase{function value():int{return 2;}}class JoinGrandchild extends JoinChild{function value():int{return 3;}}class JoinSibling extends JoinBase{function value():int{return 4;}}';
try{
    $file=$root.'/fixture.php';file_put_contents($file,'<?php '.$classes.<<<'SOURCE'
function childFirst(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=new JoinBase;}return $result->value();}
function parentFirst(bool $flag):int{if($flag){$result=new JoinBase;}else{$result=new JoinChild;}return $result->value();}
function threeLevels(int $level):int{if($level===0){$result=new JoinBase;}elseif($level===1){$result=new JoinChild;}else{$result=new JoinGrandchild;}return $result->value();}
function siblings(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=new JoinSibling;}return $result->value();}
function sameClass(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=new JoinChild;}return $result->value();}
SOURCE);
    $compiler=new RelatedObjectCompiler($root);$compiler->prepareFile($file);$compiler->convertFile($file);
    foreach(['childfirst','parentfirst','threelevels','siblings']as$name)relatedObjectCheck(($compiler->plans[$name]['result']??null)===TypePhp\Type::VAR,'different known classes reserve PHP Var '.$name);
    relatedObjectCheck(!isset($compiler->plans['sameclass']['result']),'single class preserves existing object storage');
    foreach([
        'reference'=>'function rejected(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=new JoinBase;}$alias=&$result;return $result->value();}',
        'byref-call'=>'function mutate(&$x){}function rejected(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=new JoinBase;}mutate($result);return $result->value();}',
        'unknown-argument'=>'function rejected(bool $flag,$call):int{if($flag){$result=new JoinChild;}else{$result=new JoinBase;}$call($result);return $result->value();}',
        'unknown-rhs'=>'function rejected(bool $flag):int{if($flag){$result=new JoinChild;}else{$result=unknownResult();}return $result->value();}',
        'native-value'=>'#[Native] class JoinNative{}function rejected(bool $flag){if($flag){$result=new JoinNative;}else{$result=new JoinBase;}return $result;}',
    ]as$name=>$body){
        $dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php '.$classes.$body);$c=new RelatedObjectCompiler($dir);$c->prepareFile($file);try{$c->convertFile($file);}catch(TypePhp\Exception\TestError $e){}relatedObjectCheck(!isset($c->plans['rejected']['result']),'existing exclusion retains storage '.$name);
    }
    echo 'PASS related object joins elapsed='.round(microtime(true)-$start,3)."s\n";
}finally{
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);
}
