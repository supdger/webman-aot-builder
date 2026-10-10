<?php

declare(strict_types=1);
require dirname(__DIR__).'/src/Cli/ConfigurationException.php';
require dirname(__DIR__).'/src/Toolchain/UnifiedPatchApplier.php';
require dirname(__DIR__).'/src/Toolchain/TypePhpPatchManifestFingerprint.php';
require dirname(__DIR__).'/src/Toolchain/TypePhpPatchSourceVerifier.php';
use WebmanAotBuilder\Toolchain\UnifiedPatchApplier;
use WebmanAotBuilder\Toolchain\TypePhpPatchManifestFingerprint;
use WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier;
function coverageReject(Closure $operation,string $message):void{try{$operation();}catch(RuntimeException $error){if(!str_contains($error->getMessage(),$message))throw $error;return;}throw new RuntimeException('Expected coverage refusal: '.$message);}
$root=sys_get_temp_dir().'/webman-aot-patch-coverage-'.bin2hex(random_bytes(6));mkdir($root.'/src',0700,true);$start=microtime(true);
try {
    file_put_contents($root.'/src/Existing.php',"before\n");file_put_contents($root.'/src/Missing.php',"untouched\n");
    $modify="--- a/src/Existing.php\n+++ b/src/Existing.php\n@@ -1 +1 @@\n-before\n+after\n";
    $create="--- /dev/null\n+++ b/src/Added.php\n@@ -0,0 +1 @@\n+created\n";
    $later="--- a/src/Added.php\n+++ b/src/Added.php\n@@ -1 +1 @@\n-created\n+final\n";
    file_put_contents($root.'/0001-existing.patch',$modify);file_put_contents($root.'/0002-added.patch',$create);file_put_contents($root.'/0003-later.patch',$later);
    $rules=[['path'=>'src/Existing.php','beforeSha256'=>hash('sha256',"before\n"),'afterSha256'=>hash('sha256',"after\n")],['path'=>'src/Added.php','beforeSha256'=>hash('sha256',''),'added'=>true,'afterSha256'=>hash('sha256',"final\n")]];
    $manifest=['component'=>'typephp-source','version'=>'fixture','rules'=>$rules,'reason'=>'Complete source target coverage'];$manifestFile=$root.'/manifest.json';file_put_contents($manifestFile,json_encode($manifest));$applier=new UnifiedPatchApplier();
    $applier->verifyManifestCoverage($manifestFile,$rules);echo "PASS first creation followed by later modifications retains added identity\n";
    $missing=$manifest;$missing['rules']=[$rules[0]];file_put_contents($manifestFile,json_encode($missing));
    foreach([fn()=>$applier->verifyManifestCoverage($manifestFile,$missing['rules']),fn()=>(new TypePhpPatchManifestFingerprint())->digest($manifestFile),fn()=>(new TypePhpPatchSourceVerifier())->verify($root,$manifestFile)] as $operation){coverageReject($operation,'missing from manifest');}
    if(file_get_contents($root.'/src/Existing.php')!=="before\n"||file_get_contents($root.'/src/Missing.php')!=="untouched\n"||file_exists($root.'/src/Added.php'))throw new RuntimeException('Coverage refusal changed source bytes');
    echo "PASS missing target refuses before source writes, fingerprints, or prepared-source acceptance\n";
    $bad=$rules;$bad[1]['added']=false;coverageReject(fn()=>$applier->verifyManifestCoverage($manifestFile,$bad),'creation identity');
    rename($root.'/0002-added.patch',$root.'/0004-added.patch');coverageReject(fn()=>$applier->verifyManifestCoverage($manifestFile,$rules),'preceding creation');rename($root.'/0004-added.patch',$root.'/0002-added.patch');
    file_put_contents($root.'/0004-unsafe.patch',str_replace('src/Existing.php','src/../Existing.php',$modify));coverageReject(fn()=>$applier->verifyManifestCoverage($manifestFile,$rules),'unsafe patch');unlink($root.'/0004-unsafe.patch');
    file_put_contents($root.'/0001-conflict.patch',$modify);coverageReject(fn()=>$applier->verifyManifestCoverage($manifestFile,$rules),'duplicate TypePHP patch number');unlink($root.'/0001-conflict.patch');
    $official=$root.'/toolchain/patches/typephp/fixture';mkdir($official,0700,true);coverageReject(fn()=>$applier->verifyManifestCoverage($official.'/manifest.json',$rules),'chain is missing');
    file_put_contents($manifestFile,json_encode($manifest));$applier->verifyManifestCoverage($manifestFile,$rules);foreach(glob($root.'/*.patch') as $patch){$applier->apply($patch,$root);}(new TypePhpPatchSourceVerifier())->verify($root,$manifestFile);
    echo 'PASS complete coverage applies approved final bytes elapsed='.round(microtime(true)-$start,3)."s\n";
} finally {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
