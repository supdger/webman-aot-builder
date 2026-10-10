<?php
declare(strict_types=1);

namespace Supdger\WebmanAotInstaller;

use Composer\Command\RequireCommand;
use Composer\Composer;
use Composer\Console\Application;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;
use Composer\Plugin\PluginEvents;
use Composer\Plugin\CommandEvent;
use Composer\Plugin\PreCommandRunEvent;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

/** Open the existing guide after an explicit, interactive installation of this global package. */
final class Plugin implements PluginInterface, EventSubscriberInterface
{
    private const PACKAGE = 'supdger/webman-aot-builder';
    private ?Composer $composer = null;
    private ?IOInterface $io = null;
    private bool $requested = false;
    private bool $opened = false;

    public function activate(Composer $composer, IOInterface $io): void
    {
        self::assertComposerCapabilities();
        $this->composer = $composer;
        $this->io = $io;
        // A newly installed/replaced plugin has missed the earlier command event.
        // Parse the original CLI with Composer's public definitions, never guess from installed packages.
        $this->requested = $this->eligible() && self::explicitGlobalRequire($_SERVER['argv'] ?? []);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->requested = false;
        $this->composer = null;
        $this->io = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        $this->deactivate($composer, $io);
    }

    public static function getSubscribedEvents(): array
    {
        self::assertComposerCapabilities();
        $events = [
            PluginEvents::COMMAND => 'onCommand',
            // Autoload and package installation are complete here. Composer's audit still follows.
            ScriptEvents::POST_UPDATE_CMD => ['onInstalled', -1000],
        ];
        // Older capable Composer builds can still use the direct binary or the documented environment override.
        if (defined(PluginEvents::class . '::PRE_COMMAND_RUN')
            && method_exists(PreCommandRunEvent::class, 'getCommand')
            && method_exists(PreCommandRunEvent::class, 'getInput')
            && is_callable([\Composer\Config::class, 'disableProcessTimeout'])) {
            $events[PluginEvents::PRE_COMMAND_RUN] = 'onBeforeCommand';
        }
        return $events;
    }

    public function onBeforeCommand(PreCommandRunEvent $event): void
    {
        $input = $event->getInput();
        if ($this->composer === null || !$this->composer->isGlobal() || $event->getCommand() !== 'exec'
            || !$input->hasArgument('binary') || $input->getArgument('binary') !== 'webman-aot') {
            return;
        }
        // This runs in Composer itself, before exec starts its timed child process.
        \Composer\Config::disableProcessTimeout();
    }

    public function onCommand(CommandEvent $event): void
    {
        $this->requested = $this->eligible() && $event->getCommandName() === 'require'
            && self::requestedPackage($event->getInput());
    }

    public function onInstalled(Event $event): void
    {
        if ($this->opened || !$this->requested || !$this->eligible()
            || $event->getComposer() !== $this->composer) {
            return;
        }
        $package = $this->composer->getRepositoryManager()->getLocalRepository()->findPackage(self::PACKAGE, '*');
        if ($package === null || $package->getType() !== 'composer-plugin') {
            return;
        }
        $root = $this->composer->getInstallationManager()->getInstallPath($package);
        $entry = dirname(__DIR__) . '/bin/webman-aot';
        if (!is_string($root) || realpath($root) !== realpath(dirname(__DIR__, 3))
            || !is_file($entry) || is_link($entry)
            || !is_file($this->composer->getConfig()->get('vendor-dir') . '/autoload.php')) {
            $this->io->writeError('<warning>Composer 入口已安装；无法确认引导入口，请重新安装本包后运行 composer global exec -- webman-aot guide。</warning>');
            return;
        }
        $this->opened = true;
        $this->io->writeError('<info>[已安装] Composer 入口已就绪，下面准备组件并选择项目。</info>');
        try {
            // Use this package's entry, without PATH lookup or another Composer command.
            $code = Process::run([PHP_BINARY, $entry, 'guide']);
            $this->io->writeError($code === 0
                ? '<info>[项目流程结束] Composer 入口已安装；下次运行 composer global exec -- webman-aot guide。</info>'
                : '<warning>[项目流程未完成] 退出码 ' . $code . '；Composer 入口已安装，请按上方诊断处理后运行 composer global exec -- webman-aot guide。</warning>');
        } catch (\Throwable $error) {
            $this->io->writeError('<warning>[引导未启动] ' . $error->getMessage() . '；Composer 入口已安装，可运行 composer global exec -- webman-aot guide。</warning>');
        }
    }

    private static function assertComposerCapabilities(): void
    {
        foreach ([
            Composer::class => ['isGlobal' => 0, 'getRepositoryManager' => 0, 'getInstallationManager' => 0, 'getConfig' => 0],
            IOInterface::class => ['isInteractive' => 0, 'writeError' => 1],
            CommandEvent::class => ['getCommandName' => 0, 'getInput' => 0],
            Event::class => ['getComposer' => 0],
            Application::class => ['__construct' => 0, 'getDefinition' => 0],
            RequireCommand::class => ['__construct' => 0, 'getDefinition' => 0],
            ArgvInput::class => ['__construct' => 2],
            InputInterface::class => ['hasOption' => 1, 'getOption' => 1, 'hasArgument' => 1, 'getArgument' => 1, 'isInteractive' => 0],
            'Composer\\Repository\\RepositoryManager' => ['getLocalRepository' => 0],
            'Composer\\Repository\\RepositoryInterface' => ['findPackage' => 2],
            'Composer\\Installer\\InstallationManager' => ['getInstallPath' => 1],
            'Composer\\Package\\PackageInterface' => ['getType' => 0],
            'Composer\\Config' => ['get' => 1],
            'Symfony\\Component\\Console\\Input\\InputDefinition' => ['getArguments' => 0, 'getOptions' => 0, 'addArguments' => 1, 'addOptions' => 1],
        ] as $class => $methods) {
            foreach ($methods as $method => $arguments) {
                if ($method === '__construct') {
                    if (!(new \ReflectionClass($class))->isInstantiable()) {
                        throw new \RuntimeException('Composer 入口不能构造必要命令接口：' . $class . '。');
                    }
                    if ($arguments === 0 && !method_exists($class, $method)) { continue; }
                }
                if (!method_exists($class, $method)) {
                    throw new \RuntimeException('Composer 入口缺少必要接口：' . $class . '::' . $method . '。');
                }
                $reflection = new \ReflectionMethod($class, $method);
                if (!$reflection->isPublic() || $reflection->getNumberOfRequiredParameters() > $arguments
                    || (!$reflection->isVariadic() && $reflection->getNumberOfParameters() < $arguments)
                ) {
                    throw new \RuntimeException('Composer 入口接口不能接受当前调用：' . $class . '::' . $method . '。');
                }
                foreach (array_slice($reflection->getParameters(), 0, $arguments) as $parameter) {
                    if ($parameter->isPassedByReference()) {
                        throw new \RuntimeException('Composer 入口接口要求不支持的引用参数：' . $class . '::' . $method . '。');
                    }
                }
            }
        }
        foreach ([PluginEvents::class . '::COMMAND', ScriptEvents::class . '::POST_UPDATE_CMD'] as $event) {
            if (!defined($event) || !is_string(constant($event))) {
                throw new \RuntimeException('Composer 入口缺少必要事件：' . $event . '。');
            }
        }
    }

    private function eligible(): bool
    {
        return $this->composer !== null && $this->composer->isGlobal()
            && $this->io !== null && $this->io->isInteractive() && !Console::unattended()
            && defined('STDIN') && defined('STDOUT') && stream_isatty(STDIN) && stream_isatty(STDOUT);
    }

    /** Conservative first-install fallback; aliases and unrelated invocations never open a menu. */
    public static function explicitGlobalRequire(array $argv): bool
    {
        if (($argv[1] ?? null) !== 'global' || ($argv[2] ?? null) !== 'require') {
            return false;
        }
        try {
            $definition = clone (new Application())->getDefinition();
            $require = (new RequireCommand())->getDefinition();
            $definition->addArguments($require->getArguments());
            $definition->addOptions($require->getOptions());
            $input = new ArgvInput([$argv[0], ...array_slice($argv, 2)], $definition);
            return self::requestedPackage($input);
        } catch (\Throwable) {
            return false;
        }
    }

    private static function requestedPackage(InputInterface $input): bool
    {
        foreach (['no-interaction', 'no-plugins', 'no-scripts', 'dry-run', 'no-install', 'no-update', 'help', 'version'] as $option) {
            if ($input->hasOption($option) && $input->getOption($option)) {
                return false;
            }
        }
        $packages = $input->hasArgument('packages') ? $input->getArgument('packages') : [];
        return $input->isInteractive() && is_array($packages) && count($packages) === 1
            && is_string($packages[0])
            && preg_match('~^supdger/webman-aot-builder(?:$|[:=\s])~D', $packages[0]) === 1;
    }
}
