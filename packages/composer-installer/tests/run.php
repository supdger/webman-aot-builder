<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Process.php';
require dirname(__DIR__) . '/src/Archive.php';
require dirname(__DIR__) . '/src/Installer.php';

use Supdger\WebmanAotInstaller\Archive;
use Supdger\WebmanAotInstaller\Installer;
use Supdger\WebmanAotInstaller\Process;

$temporaryRoot = getenv('WEBMAN_AOT_TEST_TMP') ?: (PHP_OS_FAMILY === 'Darwin' ? '/private/tmp' : sys_get_temp_dir());
$directory = $temporaryRoot . '/composer-aot-tests-' . bin2hex(random_bytes(6));
mkdir($directory, 0700, true);
$passed = 0;
$targetVersion = json_decode((string) file_get_contents(dirname(__DIR__) . '/resources/releases.json'), true, flags: JSON_THROW_ON_ERROR)['version'];
function check(bool $condition, string $message): void {
    global $passed;
    if (!$condition) { throw new RuntimeException($message); }
    $passed++;
    fwrite(STDOUT, "[通过] {$message}\n");
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable) { check(true, $message); return; }
    check(false, $message);
}

$metadata = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
check($metadata['require']['composer-plugin-api'] === '*' && $metadata['require']['composer'] === '*', 'Composer 与插件 API 元数据没有版本边界');
check($metadata['require']['php'] === '>=8.0', 'PHP 约束只保留 match 和字符串函数所需的运行能力');
$capabilitySource = <<<'PHP'
<?php
namespace Composer { class Composer { public function isGlobal(){return false;} public function getRepositoryManager(){} public function getInstallationManager(){} public function getConfig(){} } class Config { public function get($name){} } }
namespace Composer\IO { interface IOInterface { public function isInteractive(); public function writeError($message); } class IO implements IOInterface { public function isInteractive(){return false;} public function writeError($message){} } }
namespace Composer\Plugin { interface PluginInterface { public const PLUGIN_API_VERSION='999.0.0'; public function activate(\Composer\Composer $composer,\Composer\IO\IOInterface $io); public function deactivate(\Composer\Composer $composer,\Composer\IO\IOInterface $io); public function uninstall(\Composer\Composer $composer,\Composer\IO\IOInterface $io); } class PluginEvents { public const COMMAND='command'; } class CommandEvent { public function getCommandName(){} public function getInput(){} } }
namespace Composer\EventDispatcher { interface EventSubscriberInterface { public static function getSubscribedEvents(); } }
namespace Composer\Script { class Event { public function getComposer(){} } class ScriptEvents { public const POST_UPDATE_CMD='post-update-cmd'; } }
namespace Composer\Command { class RequireCommand { public function getDefinition(){} } }
namespace Composer\Console { class Application { public function getDefinition(){} } }
namespace Composer\Repository { class RepositoryManager { public function getLocalRepository(){} } interface RepositoryInterface { public function findPackage($name,$constraint); } }
namespace Composer\Installer { class InstallationManager { public function getInstallPath($package){} } }
namespace Composer\Package { interface PackageInterface { public function getType(); } }
namespace Symfony\Component\Console\Input { class ArgvInput { public function __construct($arguments = null, $definition = null) {} } interface InputInterface { public function hasOption($name); public function getOption($name); public function hasArgument($name); public function getArgument($name); public function isInteractive(); } class InputDefinition { public function getArguments(){} public function getOptions(){} public function addArguments($arguments){} public function addOptions($options){} } }
namespace {
    require $argv[1];
    try {
        (new \Supdger\WebmanAotInstaller\Plugin())->activate(new \Composer\Composer(), new \Composer\IO\IO());
        \Supdger\WebmanAotInstaller\Plugin::getSubscribedEvents();
        echo 'CAPABLE';
    } catch (\RuntimeException $error) { echo $error->getMessage(); }
}
PHP;
$pluginPath = dirname(__DIR__) . '/src/Plugin.php';
foreach ([
    [$capabilitySource, 'CAPABLE', '相同公共能力接受未知插件 API 标签'],
    [str_replace("PLUGIN_API_VERSION='999.0.0'", "PLUGIN_API_VERSION='0.0.1'", $capabilitySource), 'CAPABLE', '相同公共能力接受低插件 API 标签'],
    [str_replace('public function isGlobal(){return false;}', '', $capabilitySource), 'Composer\\Composer::isGlobal', '缺少 Composer global 能力有具体诊断'],
    [str_replace('public function getConfig(){}', 'private function getConfig(){}', $capabilitySource), 'Composer\\Composer::getConfig', '不可调用的 Composer 配置接口有具体诊断'],
    [str_replace("COMMAND='command'", "OTHER='command'", $capabilitySource), 'Composer\\Plugin\\PluginEvents::COMMAND', '缺少订阅事件有具体诊断'],
    [str_replace('public function getDefinition(){}', '', $capabilitySource), 'getDefinition', '缺少命令定义接口有具体诊断'],
    [str_replace('isGlobal()', 'isGlobal($required)', $capabilitySource), 'Composer\\Composer::isGlobal', '新增必填参数的公共方法明确拒绝'],
    [str_replace('__construct($arguments = null, $definition = null)', '__construct($arguments = null)', $capabilitySource), 'ArgvInput::__construct', '命令输入构造器缺少当前参数槽位明确拒绝'],
    [str_replace('get($name)', 'get(&$name)', $capabilitySource), 'Composer\\Config::get', '新增引用参数的公共方法明确拒绝'],
    [str_replace('class Application {', 'class Application { public function __construct($required) {}', $capabilitySource), 'Application::__construct', '命令应用新增必填构造参数明确拒绝'],
    [str_replace('class RequireCommand {', 'class RequireCommand { public function __construct($required) {}', $capabilitySource), 'RequireCommand::__construct', 'require 命令新增必填构造参数明确拒绝'],
] as [$source, $expected, $message]) {
    $fixture = $directory . '/plugin-capabilities.php';
    file_put_contents($fixture, $source);
    check(str_contains(Process::output([PHP_BINARY, $fixture, $pluginPath]), $expected), $message);
}

check(Installer::inputPath('"C:\\Download dir\\builder.zip"') === 'C:\\Download dir\\builder.zip', 'Windows带空格引号路径可解析');
check(Installer::inputPath("'/tmp/中文 目录/builder.tar.gz'") === '/tmp/中文 目录/builder.tar.gz', '中文空格引号路径可解析');
if (PHP_OS_FAMILY !== 'Windows') {
    check(Installer::inputPath('/tmp/Download\\ dir/builder.tar.gz') === '/tmp/Download dir/builder.tar.gz', 'Mac拖入转义空格可解析');
}
rejects(fn() => Installer::inputPath("bad\0path"), '拒绝空字符路径');

$file = $directory . '/完整包.bin';
file_put_contents($file, 'matching package');
$package = ['filename' => 'fixed-package', 'url' => 'https://example.invalid/fixed',
    'size' => filesize($file), 'sha256' => hash_file('sha256', $file)];
Archive::verify($file, $package);
check(true, '匹配大小和摘要的本地资源通过');
file_put_contents($file, 'tampered package');
rejects(fn() => Archive::verify($file, $package), '损坏资源拒绝');

$identity = $directory . '/identity';
mkdir($identity);
file_put_contents($identity . '/package.json', json_encode([
    'schema' => 'webman-aot-builder-installer-package-v1', 'version' => '0.3.2',
    'platform' => 'macos-arm64', 'flavor' => 'complete']));
file_put_contents($identity . '/payload-manifest.sha256', '');
mkdir($identity . '/payload/minimal-toolchain', 0700, true);
file_put_contents($identity . '/payload/minimal-toolchain/component.zip', '');
Archive::identity($identity, 'macos-arm64', '0.3.2');
check(true, '匹配完整包身份通过');
rejects(fn() => Archive::identity($identity, 'windows-x86_64', '0.3.2'), '错架构包拒绝');
rejects(fn() => Archive::identity($identity, 'macos-arm64', '0.3.1'), '错版本包拒绝');
unlink($identity . '/payload/minimal-toolchain/component.zip');
rejects(fn() => Archive::identity($identity, 'macos-arm64', '0.3.2'), '缺完整包组件拒绝');

$bin = dirname(__DIR__) . '/bin/webman-aot';
$help = Process::output([PHP_BINARY, $bin, '--help', '--state-dir=' . $directory . '/untouched']);
check(str_contains($help, '尚未') === false && str_contains($help, '目标构建器：' . $targetVersion), 'help报告入口和目标版本');
check(str_contains($help, '复用校验通过的已完成单元') && str_contains($help, 'build --fresh')
    && !str_contains($help, '不提供编译断点续跑'), 'help解释成功单元恢复与全量重建');
check(!file_exists($directory . '/untouched'), 'help不创建运行时状态');
$version = Process::output([PHP_BINARY, $bin, '--version']);
check(str_contains($version, 'Composer 入口 ' . Installer::VERSION) && str_contains($version, '目标 Webman AOT Builder ' . $targetVersion), 'version不冒充已安装版本');

if (PHP_OS_FAMILY === 'Darwin' && php_uname('m') === 'arm64') {
    $code = Process::run([PHP_BINARY, $bin, '--state-dir=' . $directory . '/fresh', '--non-interactive', 'build']);
    check($code !== 0 && !is_dir($directory . '/fresh/runtime'), '非交互缺资源退出不安装');
    $unowned = $directory . '/unowned';
    mkdir($unowned . '/runtime/current', 0700, true);
    file_put_contents($unowned . '/runtime/current/sentinel', 'must survive');
    $code = Process::run([PHP_BINARY, $bin, 'setup', '--state-dir=' . $unowned, '--archive=' . $file, '--non-interactive']);
    check($code !== 0 && file_get_contents($unowned . '/runtime/current/sentinel') === 'must survive'
        && !file_exists($unowned . '/owner.json'), '未owned的已有runtime不接管，哨兵和所有权状态不变');
    $oldState = $directory . '/old package state';
    mkdir($oldState . '/runtime/current', 0700, true);
    file_put_contents($oldState . '/runtime/current/sentinel', 'legacy-owned');
    file_put_contents($oldState . '/owner.json', json_encode(['schema' => 1, 'package' => 'saiadmin/webman-aot-builder']));
    $code = Process::run([PHP_BINARY, $bin, 'setup', '--state-dir=' . $oldState, '--archive=' . $file, '--non-interactive']);
    check($code === 70 && file_get_contents($oldState . '/runtime/current/sentinel') === 'legacy-owned'
        && json_decode(file_get_contents($oldState . '/owner.json'), true)['package'] === 'saiadmin/webman-aot-builder', '新setup不接管旧包owner，不修改旧payload并提示逐项卸载迁移');
    $state = $directory . '/fake state';
    mkdir($state . '/runtime/current/runtime/bin', 0700, true);
    mkdir($state . '/runtime/current/app/bin', 0700, true);
    $record = $directory . '/forward.json';
    // A tiny process fixture observes the invocation, without installing or compiling anything.
    file_put_contents($state . '/runtime/current/runtime/bin/php', "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . " \"\$@\"\n");
    chmod($state . '/runtime/current/runtime/bin/php', 0700);
    file_put_contents($state . '/runtime/current/app/bin/webman-aot-builder.php',
        '<?php file_put_contents(' . var_export($record, true) . ', json_encode([getcwd(), array_slice($argv,1), getenv("WEBMAN_AOT_BUILDER_HOME")])); exit(23);');
    file_put_contents($state . '/owner.json', json_encode(['schema' => 1, 'package' => 'supdger/webman-aot-builder']));
    file_put_contents($state . '/ready.json', json_encode(['version' => $targetVersion, 'host' => 'macos-arm64']));
    $code = Process::run([PHP_BINARY, $bin, '--state-dir=' . $state, '--non-interactive', 'build', '--profile=saiadmin', '--', '--yes', '--archive=not-a-bridge-option', '--non-interactive'], $directory);
    $actual = json_decode((string) file_get_contents($record), true);
    check($code === 23, '原程序退出码透传');
    check($actual[0] === realpath($directory) && $actual[1] === ['build', '--profile=saiadmin', '--', '--yes', '--archive=not-a-bridge-option', '--non-interactive'], '原项目cwd和argv透传');
    check($actual[2] === realpath($state) . '/runtime', '私有运行时使用隔离home');
}
fwrite(STDOUT, "完成：{$passed} 项行为检查通过。测试资源：{$directory}\n");
