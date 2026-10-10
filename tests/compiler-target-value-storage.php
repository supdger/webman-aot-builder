<?php

declare(strict_types=1);
$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';
require_once $toolchain . '/src/Build/TargetFunctionReturnMetadata.php';

final class TargetValueCompiler extends TypePhp\CompilerTest
{
    public ?string $selectedPhpx = null;
    public function __construct(string $root) { parent::__construct($root); $this->forTest=true; $this->setBuildDir($root.'/build'); }
    protected function getPhpxDir(): string { return $this->selectedPhpx ?? parent::getPhpxDir(); }
    public function key(string $file): string { $this->fullStatic=true; try { $method=new ReflectionMethod(TypePhp\Translator::class,'preparedProjectKey');$method->setAccessible(true);return $method->invoke($this,[$file]); } finally {$this->fullStatic=false;} }
    public function snapshot(string $key,bool $restore): bool { $method=new ReflectionMethod(TypePhp\Translator::class,$restore?'restorePreparedProject':'storePreparedProject');$method->setAccessible(true);return $restore?$method->invoke($this,$key):($method->invoke($this,$key)===null); }
    public function prepareTarget(string $file): void { $this->fullStatic=true; try {$this->prepareFile($file);} finally {$this->fullStatic=false;} }
    public function convertTarget(string $file): string { $this->fullStatic=true; try {$this->convertFile($file);} finally {$this->fullStatic=false;} return file_get_contents($this->getCppFile($file)); }
}
function targetCheck(bool $ok,string $message):void {if(!$ok){throw new RuntimeException($message);}}
$root=sys_get_temp_dir().'/webman-aot-target-value-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try {
    $sdk=$toolchain.'/vendor/swoole/phpx/full-static/sdk';
    $actual=TypePhp\Build\TargetFunctionReturnMetadata::read($sdk);
    targetCheck(($actual['returns']['stream_context_get_options']??null)===TypePhp\Type::ARRAY,'Selected target header return evidence absent');
    echo 'PASS selected SDK standard-header evidence returns='.count($actual['returns']).' sha256='.$actual['sha256']."\n";
    $fixture=$root.'/phpx/full-static/sdk/include/php/ext/standard';mkdir($fixture,0700,true);
    $header=$fixture.'/basic_functions_arginfo.h';
    $base="ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_fixture_value, 0, 0, IS_ARRAY, 0)\nZEND_END_ARG_INFO()\nstatic const zend_function_entry ext_functions[] = {\n ZEND_FE(fixture_value, arginfo_fixture_value)\n ZEND_FE_END\n};\n";
    file_put_contents($header,$base);$first=TypePhp\Build\TargetFunctionReturnMetadata::read($root.'/phpx/full-static/sdk');
    targetCheck(($first['returns']['fixture_value']??null)===TypePhp\Type::ARRAY,'Strict fixture candidate not read');
    foreach ([
        'comment'=>'/*'.$base.'*/',
        'comment-line-splice'=>str_replace(' ZEND_FE(fixture_value, arginfo_fixture_value)', " // fake \\\n ZEND_FE(fixture_value, arginfo_fixture_value)", $base),
        'string'=>'const char *fake = '.json_encode($base).';',
        'conditional'=>"#ifdef OPTIONAL\n".$base."#endif\n",
        'nullable'=>str_replace('IS_ARRAY, 0','IS_ARRAY, 1',$base),
        'alias'=>str_replace('ZEND_FE(fixture_value, arginfo_fixture_value)','ZEND_FALIAS(fixture_value, other_value, arginfo_fixture_value)',$base),
        'method-table'=>str_replace('ext_functions[]','class_Test_methods[]',$base),
        'duplicate'=>str_replace(' ZEND_FE_END',' ZEND_FE(fixture_value, arginfo_fixture_value)\n ZEND_FE_END',$base),
        'conditional-duplicate'=>str_replace(' ZEND_FE_END',"#ifdef OPTIONAL\n ZEND_FE(fixture_value, arginfo_fixture_value)\n#endif\n ZEND_FE_END",$base),
        'directive-continuation'=>"#define FAKE \\\n".$base,
        'mixed-return'=>str_replace('IS_ARRAY','IS_MIXED',$base),
    ] as $name=>$source) {
        file_put_contents($header,$source);$read=TypePhp\Build\TargetFunctionReturnMetadata::read($root.'/phpx/full-static/sdk');
        targetCheck(!isset($read['returns']['fixture_value']),$name.' falsely supplied return evidence');
        targetCheck($read['sha256']!==$first['sha256'],$name.' content change did not invalidate metadata cache');
        echo 'PASS conservative metadata '.$name."\n";
    }
    file_put_contents($header,$base);file_put_contents(dirname($fixture).'/other_arginfo.h',str_replace('ext_functions[]','other_functions[]',$base));
    targetCheck(!isset(TypePhp\Build\TargetFunctionReturnMetadata::read($root.'/phpx/full-static/sdk')['returns']['fixture_value']),'Cross-header duplicate accepted');
    targetCheck(TypePhp\Build\TargetFunctionReturnMetadata::read($root.'/absent')['returns']===[],'Absent SDK changed behavior');
    echo "PASS cross-header duplicate and absent SDK preserve no evidence\n";
    $warmFile=$root.'/warm.php';file_put_contents($warmFile,'<?php function target_warm():int{return 1;}');
    $warm=new TargetValueCompiler($root);$warm->selectedPhpx=$root.'/phpx';$warmKey=$warm->key($warmFile);$warm->prepareTarget($warmFile);$warm->snapshot($warmKey,false);
    $restored=new TargetValueCompiler($root);$restored->selectedPhpx=$root.'/phpx';targetCheck($restored->key($warmFile)===$warmKey && $restored->snapshot($warmKey,true),'Unchanged target header failed warm restore');
    file_put_contents($header,$base."\n/* content changed */\n");$changed=new TargetValueCompiler($root);$changed->selectedPhpx=$root.'/phpx';$changedKey=$changed->key($warmFile);
    targetCheck($changedKey!==$warmKey && !$changed->snapshot($changedKey,true),'Changed selected header restored stale warm snapshot');
    echo "PASS exact selected-header digest changes warm preparation key and rejects stale snapshot\n";
    foreach (['fallback'=>'stream_context_get_options', 'qualified'=>'\\stream_context_get_options', 'alias'=>'ctx_options'] as $name=>$call) {
        $dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/fixture.php';
        file_put_contents($file,'<?php namespace TargetValue; use function stream_context_get_options as ctx_options; function '.$name.'(int $options,mixed $context):mixed{$before=$options;$options='.$call.'($context);return [$before,$options];}');
        $compiler=new TargetValueCompiler($dir);$compiler->prepareTarget($file);$cpp=$compiler->convertTarget($file);
        targetCheck(str_contains($cpp,'php::Var options = __typephp_captured_arg_options;'),$name.' fixed int body storage retained');
        targetCheck(str_contains($cpp,'php::Int __typephp_captured_arg_options'),$name.' entry int ABI changed');
        file_put_contents($root.'/'.$name.'.cpp',$cpp);
        echo 'PASS target '.$name.' int entry ABI and body Var conversion'."\n";
    }
    foreach (['unknown'=>'unknown_target_call','target-absent'=>'stream_context_get_options'] as $name=>$call) {
        $dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php namespace TargetValue; function rejected(int $value,mixed $context):mixed{$value='.$call.'($context);return $value;}');
        $compiler=new TargetValueCompiler($dir);if($name==='target-absent'){$compiler->selectedPhpx=$root.'/missing-phpx';}
        $compiler->prepareTarget($file);$cpp=$compiler->convertTarget($file);
        targetCheck(!str_contains($cpp,'__typephp_captured_arg_value'),$name.' relaxed unknown or target-absent RHS');
        echo 'PASS '.$name.' retains original fixed storage'."\n";
    }
    $dir=$root.'/unknown-static';mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php class UnknownResult{public static function value(mixed $x):mixed{return $x;}} function probe(int $value,mixed $x):mixed{$value=UnknownResult::value($x);return $value;}');
    $compiler=new TargetValueCompiler($dir);$compiler->selectedPhpx=$root.'/missing-phpx';$compiler->prepareTarget($file);$cpp=$compiler->convertTarget($file);
    targetCheck(!str_contains($cpp,'__typephp_captured_arg_value'),'Unknown mixed StaticCall widened fixed storage');
    echo "PASS unknown mixed StaticCall retains original storage without SDK\n";
    $dir=$root.'/byref';mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php namespace TargetValue; function rejected(int &$options,mixed $context):mixed{$options=stream_context_get_options($context);return $options["x"];}');
    try {$compiler=new TargetValueCompiler($dir);$compiler->prepareTarget($file);$compiler->convertTarget($file);throw new RuntimeException('By-reference parameter unexpectedly widened');}
    catch(TypePhp\Exception\TestError $error){targetCheck($error->getMessage()!=='','By-reference diagnostic missing');echo 'PASS by-reference boundary: '.$error->getMessage()."\n";}
    echo 'PASS target value storage elapsed='.round(microtime(true)-$start,3)."s\n";
} finally {
    $entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($entries as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);
}
