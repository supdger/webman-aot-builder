#!/usr/bin/env php
<?php
declare(strict_types=1);
use WebmanAotBuilder\Compatibility\SaiAdminProfileOverlay;
use WebmanAotBuilder\Cli\ConfigurationException;
$repo=dirname(__DIR__);
spl_autoload_register(static function(string $class)use($repo):void{
    if(str_starts_with($class,'WebmanAotBuilder\\')) require $repo.'/src/'.str_replace('\\','/',substr($class,17)).'.php';
});
function checkProfile(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$source=$argv[1]??'';
checkProfile(is_file($source),'Pass the locked upstream SaiAdminProfile.php');
$carbon=$argv[2]??'';
checkProfile(is_file($carbon.'/CarbonPeriod.php')&&is_file($carbon.'/CarbonInterval.php'),'Pass installed Carbon source directory');
$orm=$argv[3]??'';
checkProfile(is_file($orm.'/src/model/Collection.php'),'Pass installed ThinkORM source directory');
$policy=json_decode(file_get_contents($repo.'/compatibility/locks/webman-workerman-2026-09-25.json'),true,flags:JSON_THROW_ON_ERROR);
$expected=$policy['generator']['profileSha256'];
$carbonPolicy=$policy['optionalAdaptations']['nesbot/carbon'];
checkProfile(hash_file('sha256',$source)===$expected,'upstream profile SHA differs');
$root=sys_get_temp_dir().'/webman-aot-profile-test-'.bin2hex(random_bytes(6));
mkdir($root.'/plugin/saiadmin',0700,true);
register_shutdown_function(static function()use($root):void{
    if(!is_dir($root))return;
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $file){if($file->isDir()&&!$file->isLink())rmdir($file->getPathname());else unlink($file->getPathname());}
    rmdir($root);
});
mkdir($root.'/vendor/nesbot/carbon/src/Carbon',0700,true);
foreach(['CarbonPeriod.php','CarbonInterval.php'] as $file) copy($carbon.'/'.$file,$root.'/vendor/nesbot/carbon/src/Carbon/'.$file);
mkdir($root.'/vendor/saithink/saiadmin/src/plugin/saiadmin',0700,true);
mkdir($root.'/vendor/topthink/think-orm/src/model',0700,true);
copy($orm.'/src/model/Collection.php',$root.'/vendor/topthink/think-orm/src/model/Collection.php');
$started=microtime(true);
$probe=$root.'/probe.php';
file_put_contents($probe, <<<'PROBE'
<?php
require $argv[1];
try { $result=(new Tinywan\Typephp\Compiler\Profile\SaiAdminProfile($argv[2]))->assertSupported(); echo $result['nesbot/carbon']; }
catch(Throwable $error){fwrite(STDERR,$error->getMessage());exit(70);}
PROBE);
function runProfile(string $profile,string $root,string $probe):array{
    $process=proc_open([PHP_BINARY,$probe,$profile,$root],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
    checkProfile(is_resource($process),'cannot start profile probe');
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    return [proc_close($process),$out,$err];
}
foreach(['3.13.2','3.14.0','3.14.1','3.14.2'] as $version){
    file_put_contents($root.'/composer.lock',json_encode(['packages'=>[
        ['name'=>'nesbot/carbon','version'=>$version],['name'=>'saithink/saiadmin','version'=>'v6.0.0'],['name'=>'topthink/think-orm','version'=>'v3.0.34']
    ]]));
    if($version==='3.14.1'){
        [$exit,,$error]=runProfile($source,$root,$probe);
        checkProfile($exit===70&&str_contains($error,'Unsupported nesbot/carbon version 3.14.1'),'original Carbon rejection not reproduced');
        echo "PASS original 3.14.1 rejection reproduced\n";
    }
    $adapted=(new SaiAdminProfileOverlay())->prepare($source,$expected,$root.'/composer.lock',$root.'/cache',$carbonPolicy);
    [$exit,$out,$error]=runProfile($adapted,$root,$probe);
    checkProfile($exit===0&&$out===$version,'same supported source must not be rejected by version metadata: '.$error);
    echo 'PASS Carbon '.$version." metadata with checked source accepted\n";
}
file_put_contents($root.'/composer.lock',json_encode(['packages'=>[['name'=>'nesbot/carbon','version'=>'3.14.1']]]));
$drift=$root.'/drift.php';file_put_contents($drift,file_get_contents($source)."\n// drift\n");
try{(new SaiAdminProfileOverlay())->prepare($drift,$expected,$root.'/composer.lock',$root.'/cache',$carbonPolicy);throw new RuntimeException('source drift must fail');}
catch(ConfigurationException $error){checkProfile(str_contains($error->getMessage(),'source drifted'),'wrong drift failure');}
echo 'PASS source drift rejected; original profile unchanged; elapsed '.number_format(microtime(true)-$started,2)."s\n";
checkProfile(hash_file('sha256',$source)===$expected,'original profile changed');

$periodOriginal = file_get_contents($root . '/vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php');
$intervalOriginal = file_get_contents($root . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php');
foreach ([
    ['3.13.2', 'v3.0.34', true, 'historical labels accepted by source capability'],
    ['99.0.0', 'v99.0.0', true, 'future major versions have no ceiling'],
    ['4.0.0-rc.1', '3.0.35-beta.1', true, 'higher prerelease versions accepted'],
    ['3.13.2+build.4', 'v3.0.34+build.2', true, 'build metadata does not raise minimum'],
    ['3.13.1', 'v3.0.34', true, 'lower Carbon label with supported source accepted'],
    ['3.13.2-beta.1', 'v3.0.34', true, 'lower prerelease label with supported source accepted'],
    ['3.14.1', 'v3.0.33', true, 'lower ThinkORM label with supported collection accepted'],
    ['dev-main', 'v3.0.34', true, 'unknown Carbon label uses checked source'],
    ['3.14.1', 'dev-main', true, 'development ThinkORM label with source capability accepted'],
    ['3.14.1', 'release-current', true, 'unknown ThinkORM label with source capability accepted'],
    ['0.0.1', 'v0.0.1', true, 'low labels do not impose a numeric floor'],
    ['release-current', 'future-abi-compatible', true, 'arbitrary labels use supported source capability'],
] as [$carbonVersion, $ormVersion, $accepted, $message]) {
    file_put_contents($root . '/composer.lock', json_encode(['packages' => [
        ['name' => 'nesbot/carbon', 'version' => $carbonVersion],
        ['name' => 'topthink/think-orm', 'version' => $ormVersion],
        ['name' => 'saithink/saiadmin', 'version' => 'v6.0.0'],
    ]]));
    $adapted = (new SaiAdminProfileOverlay())->prepare($source, $expected, $root . '/composer.lock', $root . '/cache', $carbonPolicy);
    $shadow = file_get_contents($adapted);
    checkProfile(!str_contains($shadow, 'SUPPORTED_EXACT_VERSIONS') && str_contains($shadow, 'REQUIRED_PACKAGES') && !str_contains($shadow, 'version_compare('), 'actual profile retains exact whitelist');
    [$exit, $out, $error] = runProfile($adapted, $root, $probe);
    checkProfile($accepted ? $exit === 0 && $out === $carbonVersion : $exit === 70 && str_contains($error, $message), $message . ': ' . $error);
    echo 'PASS ' . $message . "\n";
}
checkProfile(file_get_contents($root . '/vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php') === $periodOriginal
    && file_get_contents($root . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php') === $intervalOriginal, 'version probing changed vendor');
checkProfile(hash_file('sha256', $source) === $expected, 'version probing changed fixed upstream profile');
$collectionPath = $root . '/vendor/topthink/think-orm/src/model/Collection.php';
$collectionOriginal = file_get_contents($collectionPath);
foreach ([
    str_replace('namespace think\\model;', 'namespace unrelated;', $collectionOriginal),
    str_replace('class Collection extends BaseCollection', 'class Collection extends UnknownParent', $collectionOriginal),
    "<?php // namespace think\\model; class Collection extends BaseCollection\nclass Other {}",
] as $invalidCollection) {
    file_put_contents($collectionPath, $invalidCollection);
    try {
        (new SaiAdminProfileOverlay())->prepare($source, $expected, $root . '/composer.lock', $root . '/cache', $carbonPolicy);
        throw new RuntimeException('unsupported ThinkORM collection must fail');
    } catch (ConfigurationException $error) {
        checkProfile(str_contains($error->getMessage(), 'ThinkORM model collection'), 'wrong collection capability failure');
    }
    echo "PASS version metadata cannot bypass missing ThinkORM collection capability\n";
}
file_put_contents($collectionPath, str_replace('BaseCollection', 'CompatibleCollection', str_replace('class Collection extends BaseCollection', 'class Collection /* equivalent */ extends BaseCollection', $collectionOriginal)));
(new SaiAdminProfileOverlay())->prepare($source, $expected, $root . '/composer.lock', $root . '/cache', $carbonPolicy);
echo "PASS equivalent ThinkORM import alias and class formatting accepted\n";
file_put_contents($collectionPath, $collectionOriginal);
file_put_contents($root . '/composer.lock', json_encode(['packages' => [
    ['name' => 'nesbot/carbon', 'version' => '99.0.0'], ['name' => 'topthink/think-orm', 'version' => 'v99.0.0'],
    ['name' => 'saithink/saiadmin', 'version' => 'v6.0.0'],
]]));
file_put_contents($root.'/vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php', "<?php namespace Carbon; class CarbonPeriod { function test(){ return CarbonInterval::unknownMagic(); } }");
try{(new SaiAdminProfileOverlay())->prepare($source,$expected,$root.'/composer.lock',$root.'/cache',$carbonPolicy);throw new RuntimeException('unsupported Carbon source must fail');}
catch(ConfigurationException $error){checkProfile(str_contains($error->getMessage(),'unknownMagic'),'wrong shape failure');}
echo "PASS version metadata cannot bypass unsupported Carbon source\n";
echo 'PASS profile capability regression completed in '.number_format(microtime(true)-$started,3)."s\n";
