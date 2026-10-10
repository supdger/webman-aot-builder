<?php

declare(strict_types=1);
$archive=$argv[1]??'';$sdk=$argv[2]??'';$toolchain=$argv[3]??'';$repository=$argv[4]??dirname(__DIR__);
spl_autoload_register(static function(string $class)use($repository):void{$prefix='WebmanAotBuilder\\';if(str_starts_with($class,$prefix))require $repository.'/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';});
require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
function toArrayContract(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
final class ToArrayContractCompiler extends TypePhp\CompilerTest{public function __construct(string $root){parent::__construct($root);$this->forTest=true;}}
$root=sys_get_temp_dir().'/webman-aot-generator-toarray-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try{
    $lock=json_decode(file_get_contents($repository.'/compatibility/locks/webman-workerman-2026-09-25.json'),true,flags:JSON_THROW_ON_ERROR);
    echo "STAGE verified upstream generator and selected SDK overlay\n";
    $generatorRoot=(new WebmanAotBuilder\Compatibility\UpstreamGeneratorArchive())->materialize($archive,$lock['generator'],$root);
    $sourceFile=$generatorRoot.'/'.$lock['generator']['sourcePath'];
    $original=file_get_contents($sourceFile);toArrayContract(hash('sha256',$original)===$lock['generator']['sourceSha256'],'upstream identity remains locked');
    require $generatorRoot.'/src/Compiler/Profile/SaiAdminProfile.php';
    $previous=str_replace('class ProjectGenerator','class PreviousToArrayGenerator',$original);file_put_contents($root.'/previous.php',$previous);require $root.'/previous.php';
    $capabilities=(new WebmanAotBuilder\Toolchain\StaticTargetLayout())->runtimeCapabilities($sdk);$capabilities['deferredIntlProviderValidation']=true;
    $overlay=(new WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay())->prepare($sourceFile,$lock['generator']['sourceSha256'],$lock['generator']['mainStubSha256'],$root,null,[],false,$capabilities);
    require $overlay['path'];$generator=new Tinywan\Typephp\Compiler\ProjectGenerator($root);
    $old=new Tinywan\Typephp\Compiler\PreviousToArrayGenerator($root);
    $transform=new ReflectionMethod($generator,'patchSwitchTerminals');$oldTransform=new ReflectionMethod($old,'patchSwitchTerminals');
    $path='vendor/illuminate/contracts/Support/Arrayable.php';
    $source=<<<'SOURCE'
<?php
/** @return array<TKey,TValue> */
interface GeneratedArrayable {
    public function toArray();
}
SOURCE;
    $oldPrepared=$oldTransform->invoke($old,$path,$source);toArrayContract(str_contains($oldPrepared,'toArray(): array'),'old generator falsely adds PHP array return declaration');
    $prepared=$transform->invoke($generator,$path,$source);toArrayContract($prepared===$source,'ordinary interface declaration and docblock remain byte-exact');
    $input=$root.'/'.$path;mkdir(dirname($input),0700,true);file_put_contents($input,$source);
    $generator->generateSwitchTerminalSources();$shadow=$root.'/.typephp/build/illuminate-contracts-arrayable.php';toArrayContract(file_get_contents($shadow)===$source,'actual generated interface preserves original declaration');
    foreach([
        'typed-array'=>str_replace('toArray();','toArray(): array;',$source),
        'typed-string'=>str_replace('toArray();','toArray(): string;',$source),
        'trivia-default'=>str_replace('public function toArray();','#[Deprecated] public /* method */ function toArray($options = []);',$source),
    ]as$name=>$variant)toArrayContract($transform->invoke($generator,$path,$variant)===$variant,'existing type/attribute/default preserved '.$name);
    $consumer=' class OrdinaryModelInfo implements GeneratedArrayable {public function toArray(){return 23;}}';
    foreach(['old'=>$oldPrepared,'current'=>$prepared]as$name=>$interface){$dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,$interface.$consumer);$c=new ToArrayContractCompiler($dir);$c->prepareFile($file);$rejected=false;try{$c->convertFile($file);}catch(TypePhp\Exception\TestError $e){$rejected=true;toArrayContract(str_contains($e->getMessage(),'must be compatible'),'original LSP diagnostic retained '.$name);}toArrayContract($rejected===($name==='old'),'compiler LSP sees actual PHP declaration '.$name);}
    $dir=$root.'/typed-negative';mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php interface TypedArrayable{function toArray():array;}class BadArrayable implements TypedArrayable{function toArray():string{return "bad";}}');$c=new ToArrayContractCompiler($dir);$c->prepareFile($file);$rejected=false;try{$c->convertFile($file);}catch(TypePhp\Exception\TestError $e){$rejected=str_contains($e->getMessage(),'must be compatible');}toArrayContract($rejected,'explicit PHP parent array versus child string still rejected');
    require $root.'/current/fixture.php';$object=new OrdinaryModelInfo;toArrayContract($object->toArray()===23&&!(new ReflectionMethod(GeneratedArrayable::class,'toArray'))->hasReturnType(),'PHP permits ordinary scalar result with undeclared interface return');
    $generated=file_get_contents($overlay['path']);
    $begin=strpos($generated,"\n            if (\$search === '    public function toArray()'");
    $end=strpos($generated,"\n            if (((str_contains(\$search",$begin===false?0:$begin);
    toArrayContract($begin!==false&&$end!==false&&$end>$begin,'overlay contains only the bounded legacy signature filter');
    $oldOverlay=substr_replace($generated,'',$begin,$end-$begin);$oldDigest=hash('sha256',$oldOverlay);
    $oldCache=$root.'/saiadmin-generator-overlays/'.$oldDigest.'/src/Compiler/ProjectGenerator.php';mkdir(dirname($oldCache),0700,true);file_put_contents($oldCache,$oldOverlay);
    $again=(new WebmanAotBuilder\Compatibility\SaiAdminGeneratorOverlay())->prepare($sourceFile,$lock['generator']['sourceSha256'],$lock['generator']['mainStubSha256'],$root,null,[],false,$capabilities);toArrayContract($again===$overlay&&hash_file('sha256',$again['path'])===$again['sha256'],'warm overlay uses exact prepared content identity');
    toArrayContract($oldDigest!==$again['sha256']&&$oldCache!==$again['path']&&hash_file('sha256',$oldCache)===$oldDigest,'previous overlay cache is not reused or rewritten');
    echo 'PASS generator toArray contracts elapsed='.round(microtime(true)-$start,3)."s\n";
}finally{$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as$entry)$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());rmdir($root);}
