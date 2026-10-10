<?php

declare(strict_types=1);
$toolchain=$argv[1]??'';require $toolchain.'/vendor/autoload.php';require $toolchain.'/src/gen_stub.php';
final class BitwiseNotCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root){parent::__construct($root);$this->forTest=true;$this->setBuildDir($root.'/build');}
    public function bigOperator():string{$this->bigintTypes=true;return $this->parseBitwiseNot(new PhpParser\Node\Expr\BitwiseNot(new PhpParser\Node\Scalar\Int_(1)));}
    public function fixture(string $file):string{$this->prepareFile($file);$this->convertFile($file);return file_get_contents($this->getCppFile($file));}
}
function bitCheck(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);echo 'PASS '.$message.PHP_EOL;}
$root=sys_get_temp_dir().'/webman-aot-bitwise-not-'.bin2hex(random_bytes(6));mkdir($root,0700,true);$start=microtime(true);
try {
    $file=$root.'/fixture.php';file_put_contents($file,'<?php function bit_string(string $value):string{$value=~$value;return $value;}function bit_integer(int $value):int{return ~$value;}function bit_float(float $value):int{return ~$value;}function bit_dynamic(mixed $value):mixed{return ~$value;}function bit_bool(bool $value):mixed{return ~$value;}function bit_array(array $value):mixed{return ~$value;}function bit_null():mixed{return ~null;}');
    $cpp=(new BitwiseNotCompiler($root))->fixture($file);
    bitCheck(str_contains($cpp,'php::toStringExact((~php::Var(value)))'),'string bitwise not retains string result without numeric cast');
    bitCheck(str_contains($cpp,'php::toIntExact((~php::Var(value)))'),'float bitwise not uses Zend conversion and integer result');
    preg_match('/php::Var php_bit_dynamic\([^}]+\}/s',$cpp,$dynamic);bitCheck(str_contains($dynamic[0]??'','(~php::Var(value))'),'dynamic PHP values retain Zend operator result');
    bitCheck(!str_contains($cpp,'__typephp_captured_arg_value'),'ordinary string complement does not widen parameter storage');
    foreach(['native'=>'#[Native]class NativeBits{}function rejected(NativeBits $value):mixed{return ~$value;}'] as $name=>$source){$dir=$root.'/'.$name;mkdir($dir);$file=$dir.'/negative.php';file_put_contents($file,'<?php '.$source);try{(new BitwiseNotCompiler($dir))->fixture($file);throw new RuntimeException('Unsupported '.$name.' operand accepted');}catch(TypePhp\Exception\TestError $error){bitCheck($error->getMessage()!=='',$name.' original constraint retained: '.$error->getMessage());}}
    $dir=$root.'/std-vector';mkdir($dir);$file=$dir.'/fixture.php';file_put_contents($file,'<?php function rejected(#[StdVector(Type::String)]$value):mixed{return ~$value;}');$cpp=(new BitwiseNotCompiler($dir))->fixture($file);bitCheck(str_contains($cpp,'php::toInt(~php::toInt(value))')&&!str_contains($cpp,'~php::Var(value_ref)'),'StdVector preserves original opaque representation boundary');
    $compiler=new BitwiseNotCompiler($root);bitCheck(str_contains($compiler->bigOperator(),'php::BigInt::bitNot('),'BigInt specialized operator retained');
    echo 'PASS PHP bitwise-not frontend elapsed='.round(microtime(true)-$start,3)."s\n";
} finally {$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as $entry){$entry->isDir()?rmdir($entry->getPathname()):unlink($entry->getPathname());}rmdir($root);}
