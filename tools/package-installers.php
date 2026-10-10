<?php

declare(strict_types=1);

final class InstallerPackager
{
    /** @var array<string, string> */
    private array $options = [];

    public function __construct(private readonly string $root)
    {
    }

    /**
     * @param list<string> $arguments
     */
    public function run(array $arguments): void
    {
        $this->parseOptions($arguments);
        $lock = $this->readJson($this->root . '/installer/runtime.lock.json');
        if (($lock['schema'] ?? null) !== 'webman-aot-builder-installer-runtime-lock-v1'
            || !is_array($lock['runtimes'] ?? null)
        ) {
            throw new RuntimeException('installer runtime lock is invalid');
        }
        $toolchainLock = $this->readJson($this->root . '/toolchain.lock.json');
        $minimalComponent = $this->requiredOption('minimal-component');
        $flavor = $this->options['flavor'] ?? 'small';
        if (!in_array($flavor, ['small', 'full'], true)
            || !is_file($minimalComponent) || is_link($minimalComponent)
        ) {
            throw new InvalidArgumentException('minimal component or installer flavor is invalid');
        }
        $typePhpSource = $this->requiredOption('typephp-source-archive');
        $typePhpComponent = null;
        foreach ($toolchainLock['components'] ?? [] as $component) {
            if (is_array($component) && ($component['id'] ?? null) === 'typephp-source') {
                $typePhpComponent = $component;
                break;
            }
        }
        if (!is_array($typePhpComponent)
            || ($typePhpComponent['sha256'] ?? null) !== $this->digest($typePhpSource)
        ) {
            throw new RuntimeException('TypePHP source archive does not match toolchain lock');
        }
        $platform = $this->options['platform'] ?? 'both';
        if (!in_array($platform, ['both', 'macos-arm64', 'windows-x86_64'], true)) {
            throw new InvalidArgumentException('platform must be both, macos-arm64 or windows-x86_64');
        }

        $output = $this->requiredOption('output');
        $this->createDirectory($output);
        $workspace = sys_get_temp_dir() . '/webman-aot-builder-package-' . bin2hex(random_bytes(8));
        $this->createDirectory($workspace);
        try {
            $licenseDirectory = $workspace . '/typephp-license';
            $this->createDirectory($licenseDirectory);
            $typePhpLicense = $licenseDirectory . '/LICENSE';
            $typePhpLicenses = $this->sourceLicenses($typePhpSource, $typePhpComponent['sha256'], ['LICENSE']);
            if (file_put_contents($typePhpLicense, $typePhpLicenses['LICENSE']) === false) {
                throw new RuntimeException('unable to stage selected TypePHP source license');
            }
            $packages = [];
            if ($platform !== 'windows-x86_64') {
                $packages[] = $this->packageMac(
                    $workspace,
                    $output,
                    $this->requiredOption('mac-runtime'),
                    $this->requiredOption('mac-compiler-driver'),
                    $this->requiredOption('mac-runtime-license-dir'),
                    $this->requiredOption('php-source-archive'),
                    $typePhpLicense,
                    $lock['runtimes']['macos-arm64'] ?? null,
                    $minimalComponent,
                    $flavor === 'full'
                );
            }
            if ($platform !== 'macos-arm64') {
                $packages[] = $this->packageWindows(
                    $workspace,
                    $output,
                    $this->requiredOption('windows-runtime-archive'),
                    $typePhpLicense,
                    $lock['runtimes']['windows-x86_64'] ?? null,
                    $minimalComponent,
                    $flavor === 'full'
                );
            }
        } finally {
            $this->removeDirectory($workspace);
        }

        fwrite(STDOUT, json_encode([
            'schema' => 'webman-aot-builder-installer-package-result-v1',
            'revision' => $this->options['revision'] ?? 'unknown',
            'packages' => $packages,
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, string|int>
     */
    private function packageMac(
        string $workspace,
        string $output,
        string $runtimePath,
        string $compilerDriverPath,
        string $runtimeLicenseDirectory,
        string $phpSourceArchive,
        string $typePhpLicense,
        ?array $runtime,
        string $minimalComponent,
        bool $full
    ): array {
        if (!is_array($runtime)
            || ($runtime['binarySha256'] ?? null) !== $this->digest($runtimePath)
            || !is_array($runtime['compilerDriver'] ?? null)
            || ($runtime['compilerDriver']['binarySha256'] ?? null)
                !== $this->digest($compilerDriverPath)
            || ($runtime['compilerDriver']['sourceSha256'] ?? null)
                !== $this->digest($phpSourceArchive)
        ) {
            throw new RuntimeException('macOS runtime does not match installer lock');
        }
        if (!is_dir($runtimeLicenseDirectory)
            || $this->files($runtimeLicenseDirectory) === []
        ) {
            throw new RuntimeException('macOS runtime license directory is missing or empty');
        }
        $stage = $workspace . '/macos-arm64';
        $this->stageApplication($stage, $typePhpLicense);
        $this->stageProcessSupervisor($stage, true);
        $this->createDirectory($stage . '/payload/runtime/bin');
        if (!copy($runtimePath, $stage . '/payload/runtime/bin/php')) {
            throw new RuntimeException('unable to stage macOS private PHP runtime');
        }
        if (!copy($compilerDriverPath, $stage . '/payload/runtime/bin/php-compiler')) {
            throw new RuntimeException('unable to stage macOS private compiler PHP');
        }
        chmod($stage . '/payload/runtime/bin/php', 0700);
        chmod($stage . '/payload/runtime/bin/php-compiler', 0700);
        $this->copyDirectory($runtimeLicenseDirectory, $stage . '/payload/runtime/licenses');
        $phpLicenses = $this->sourceLicenses($phpSourceArchive, $runtime['compilerDriver']['sourceSha256'], [
            'ext/mbstring/libmbfl/LICENSE', 'ext/bcmath/libbcmath/LICENSE',
        ]);
        foreach ([
            'libmbfl-LGPL-2.1.txt' => 'ext/mbstring/libmbfl/LICENSE',
            'libbcmath-LGPL-2.1.txt' => 'ext/bcmath/libbcmath/LICENSE',
        ] as $name => $member) {
            $license = $phpLicenses[$member];
            if (!str_contains($license, 'GNU LESSER GENERAL PUBLIC LICENSE')
                || file_put_contents($stage . '/payload/runtime/licenses/' . $name, $license) === false
            ) {
                throw new RuntimeException("unable to stage PHP library license: {$member}");
            }
        }
        $this->createDirectory($stage . '/payload/launcher');
        copy($this->root . '/bin/webman-aot', $stage . '/payload/launcher/webman-aot');
        chmod($stage . '/payload/launcher/webman-aot', 0700);
        copy($this->root . '/installer/macos/install.sh', $stage . '/install.sh');
        $this->copyRequiredFile($this->root . '/installer/macos/install.command', $stage . '/install.command');
        chmod($stage . '/install.command', 0700);
        copy($this->root . '/installer/macos/uninstall.sh', $stage . '/uninstall.sh');
        chmod($stage . '/install.sh', 0700);
        chmod($stage . '/uninstall.sh', 0700);
        $this->stageMinimalComponent($stage, 'macos-arm64', $minimalComponent, $full);
        $this->writeMetadata($stage, 'macos-arm64', $runtime, $full);

        $archive = $output . '/webman-aot-builder-' . $this->version()
            . ($full ? '-full' : '') . '-macos-arm64.tar.gz';
        $tarPath = substr($archive, 0, -3);
        if (is_file($tarPath)) {
            unlink($tarPath);
        }
        if (is_file($archive)) {
            unlink($archive);
        }
        $tar = new PharData($tarPath);
        $this->addTreeToTar($tar, $stage, '');
        unset($tar);
        $source = fopen($tarPath, 'rb');
        $compressed = gzopen($archive, 'wb6');
        if (!is_resource($source) || $compressed === false) {
            throw new RuntimeException('unable to start streaming macOS installer compression');
        }
        $bytes = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, 1048576);
                if (!is_string($chunk)) {
                    throw new RuntimeException('unable to read macOS installer tar');
                }
                if ($chunk === '') {
                    continue;
                }
                if (gzwrite($compressed, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('unable to compress macOS installer tar');
                }
                $bytes += strlen($chunk);
                if ($bytes % (64 * 1048576) < 1048576) {
                    fwrite(STDERR, sprintf("[package] Compressed %.0f MiB from tar\n", $bytes / 1048576));
                }
            }
        } finally {
            fclose($source);
            gzclose($compressed);
        }
        unlink($tarPath);

        return $this->packageResult('macos-arm64', $archive);
    }

    /**
     * @param array<string, mixed>|null $runtime
     * @return array<string, string|int>
     */
    private function packageWindows(
        string $workspace,
        string $output,
        string $runtimeArchive,
        string $typePhpLicense,
        ?array $runtime,
        string $minimalComponent,
        bool $full
    ): array {
        if (!is_array($runtime)
            || ($runtime['archiveSha256'] ?? null) !== $this->digest($runtimeArchive)
            || ($runtime['provider'] ?? null) !== 'PHP official Windows x64 runtime'
        ) {
            throw new RuntimeException('Windows runtime archive does not match installer lock');
        }
        $stage = $workspace . '/windows-x86_64';
        $this->stageApplication($stage, $typePhpLicense);
        $this->stageProcessSupervisor($stage, false);
        $extract = $workspace . '/windows-runtime-extract';
        $this->extractLockedZip($runtimeArchive, $extract);
        $source = $extract;
        if (($runtime['binarySha256'] ?? null) !== $this->digest($source . '/php.exe')
            || ($runtime['phpLibrarySha256'] ?? null) !== $this->digest($source . '/php8ts.dll')
        ) {
            throw new RuntimeException('Windows private PHP runtime files do not match lock');
        }
        $runtimeStage = $stage . '/payload/runtime';
        $this->createDirectory($runtimeStage . '/ext');
        foreach ([
            'php.exe',
            'php8ts.dll',
            'libcrypto-3-x64.dll',
            'libssl-3-x64.dll',
            'license.txt',
            'readme-redist-bins.txt',
        ] as $file) {
            $this->copyRequiredFile($source . '/' . $file, $runtimeStage . '/' . $file);
        }
        foreach ($runtime['extensions'] ?? [] as $extension) {
            if (!is_string($extension) || preg_match('/^[a-z0-9_]+$/', $extension) !== 1) {
                throw new RuntimeException('invalid locked Windows PHP extension');
            }
            $file = 'php_' . $extension . '.dll';
            $this->copyRequiredFile($source . '/ext/' . $file, $runtimeStage . '/ext/' . $file);
        }
        if (file_put_contents(
            $runtimeStage . '/php.ini',
            "extension_dir=\"ext\"\n"
            . implode('', array_map(
                static fn (string $extension): string => "extension={$extension}\n",
                $runtime['extensions']
            ))
        ) === false) {
            throw new RuntimeException('unable to write Windows PHP runtime configuration');
        }
        $this->copyRequiredFile($this->root . '/tools/windows-php-bootstrap.php', $stage . '/payload/app/tools/windows-php-bootstrap.php');
        $this->copyRequiredFile(
            $source . '/extras/sbom/php.spdx.json',
            $runtimeStage . '/upstream-php.spdx.json'
        );
        $this->createDirectory($stage . '/payload/launcher');
        copy($this->root . '/bin/webman-aot.cmd', $stage . '/payload/launcher/webman-aot.cmd');
        copy($this->root . '/installer/windows/install.ps1', $stage . '/install.ps1');
        $this->copyRequiredFile($this->root . '/installer/windows/install.cmd', $stage . '/install.cmd');
        $this->copyRequiredFile($this->root . '/installer/windows/install-guided.ps1', $stage . '/install-guided.ps1');
        copy($this->root . '/installer/windows/uninstall.ps1', $stage . '/uninstall.ps1');
        $this->stageMinimalComponent($stage, 'windows-x86_64', $minimalComponent, $full);
        $this->writeMetadata($stage, 'windows-x86_64', $runtime, $full);

        $archive = $output . '/webman-aot-builder-' . $this->version()
            . ($full ? '-full' : '') . '-windows-x86_64.zip';
        if (is_file($archive)) {
            unlink($archive);
        }
        $zip = new ZipArchive();
        if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('unable to create Windows installer archive');
        }
        try {
            $this->addTreeToZip($zip, $stage, '');
        } finally {
            $zip->close();
        }

        return $this->packageResult('windows-x86_64', $archive);
    }

    private function stageMinimalComponent(
        string $stage,
        string $host,
        string $source,
        bool $full
    ): void {
        $lock = $this->readJson($this->root . '/toolchain/minimal-components.lock.json');
        $component = $lock['components'][$host] ?? null;
        if (($lock['schema'] ?? null) !== 'webman-aot-builder-minimal-components-lock-v1'
            || ($lock['version'] ?? null) !== $this->version()
            || ($lock['toolchainLockSha256'] ?? null)
                !== $this->digest($this->root . '/toolchain.lock.json')
            || !is_array($component)
            || ($component['archive'] ?? null)
                !== 'webman-aot-builder-' . $this->version() . '-' . $host . '-components.zip'
            || ($component['sha256'] ?? null) !== $this->digest($source)
        ) {
            throw new RuntimeException("minimal {$host} component does not match its source lock");
        }
        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            throw new RuntimeException('minimal component ZIP cannot be opened');
        }
        try {
            $manifest = $zip->getFromName('minimal-component.json');
            if (!is_string($manifest)
                || hash('sha256', $manifest) !== ($component['manifestSha256'] ?? null)
            ) {
                throw new RuntimeException('minimal component file manifest differs from its source lock');
            }
            $manifestData = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($manifestData)
                || ($manifestData['schema'] ?? null) !== 'webman-aot-builder-minimal-component-v1'
                || ($manifestData['host'] ?? null) !== $host
                || ($manifestData['toolchainLockSha256'] ?? null) !== $lock['toolchainLockSha256']
            ) {
                throw new RuntimeException('minimal component has an incompatible manifest identity');
            }
            if (!$full && isset($this->options['prepared-typephp'])) {
            // Ship exact target bytes, not a patch replay against unknown older prepared sources.
            $upgrade = $stage . '/payload/app/toolchain/minimal-upgrade';
            $this->createDirectory($upgrade . '/files');
            if (file_put_contents($upgrade . '/minimal-component.json', $manifest) === false) {
                throw new RuntimeException('cannot stage locked upgrade manifest');
            }
            $preparedJson = $zip->getFromName('prepared/prepared-toolchain.json');
            $prepared = is_string($preparedJson) ? json_decode($preparedJson, true, flags: JSON_THROW_ON_ERROR) : [];
            $typephp = $prepared['typephp'] ?? null;
            if (!is_string($typephp) || !preg_match('~^[A-Za-z0-9._/-]+$~D', $typephp)
                || in_array('..', explode('/', $typephp), true)) {
                throw new RuntimeException('target prepared TypePHP path is unsafe');
            }
            $patch = $this->readJson($this->root . '/toolchain/patches/typephp/0.9.2/manifest.json');
            $selected = ['manifest.json' => null, 'toolchain.lock.json' => null,
                'prepared/prepared-toolchain.json' => null, 'component-derivation.json' => null];
            $preparedTypephp = $this->requiredOption('prepared-typephp');
            require_once __DIR__ . '/../src/Cli/ConfigurationException.php';
            require_once __DIR__ . '/../src/Toolchain/TypePhpPatchSourceVerifier.php';
            (new WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier())->verify(
                $preparedTypephp, $this->root . '/toolchain/patches/typephp/0.9.2/manifest.json'
            );
            $guards = [];
            foreach ($patch['rules'] ?? [] as $rule) {
                $guards['prepared/' . $typephp . '/' . $rule['path']] = $rule;
                $selected['prepared/' . $typephp . '/' . $rule['path']] = $rule['afterSha256'];
            }
            foreach ($selected as $name => $ruleHash) {
                $entry = $manifestData['entries'][$name] ?? null;
                if ($entry === null && $ruleHash === null) { continue; }
                $bytes = $zip->getFromName($name);
                if (!is_array($entry) || ($entry['type'] ?? null) !== 'file' || !is_string($bytes)
                    || strlen($bytes) !== $entry['size'] || hash('sha256', $bytes) !== $entry['sha256']
                    || str_contains($name, '..') || str_starts_with($name, '/') || str_contains($name, '\\')) {
                    throw new RuntimeException('target upgrade replacement differs from locked component: ' . $name);
                }
                if ($ruleHash !== null) {
                    $rule = $guards[$name];
                    if (!in_array($entry['sha256'], array_filter([
                        $rule['beforeSha256'], $rule['preparedBeforeSha256'] ?? null, $rule['afterSha256'],
                    ]), true)) { throw new RuntimeException('target source is outside its reviewed patch chain: ' . $name); }
                    $sourceFile = rtrim($preparedTypephp, '/\\') . '/' . $rule['path'];
                    $bytes = is_file($sourceFile) && !is_link($sourceFile) ? file_get_contents($sourceFile) : false;
                    if (!is_string($bytes) || hash('sha256', $bytes) !== $ruleHash) {
                        throw new RuntimeException('prepared replacement differs from reviewed source: ' . $name);
                    }
                }
                $path = $upgrade . '/files/' . $name;
                $this->createDirectory(dirname($path));
                if (file_put_contents($path, $bytes) === false || hash_file('sha256', $path) !== hash('sha256', $bytes)) {
                    throw new RuntimeException('cannot stage target upgrade replacement: ' . $name);
                }
            }
            }
        } finally {
            $zip->close();
        }
        $this->createDirectory($stage . '/payload/app/installer');
        $this->copyRequiredFile(
            $this->root . '/installer/offline-prepare.php',
            $stage . '/payload/app/installer/offline-prepare.php'
        );
        if ($full) {
            $destination = $stage . '/payload/minimal-toolchain';
            $this->createDirectory($destination);
            $this->copyRequiredFile(
                $source,
                $destination . '/component.zip'
            );
        }
        fwrite(STDERR, sprintf(
            "[package] %s %s uses one verified minimal component (%d bytes)\n",
            $host,
            $full ? 'full installer' : 'small installer',
            filesize($source)
        ));
    }

    private function stageProcessSupervisor(string $stage, bool $mac): void
    {
        $destination = $stage . '/payload/app/installer/process-supervisor';
        $this->createDirectory($destination);
        $source = $this->root . '/installer/process-supervisor/' . ($mac ? 'macos.c' : 'windows.ps1');
        $this->copyRequiredFile($source, $destination . '/' . basename($source));
        if (!$mac) { return; }
        $binary = $stage . '/payload/app/bin/process-supervisor';
        $process = proc_open(['/usr/bin/clang', '-std=c11', '-Wall', '-Wextra', '-Werror', '-O2', $source, '-o', $binary],
            [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
        if (!is_resource($process) || proc_close($process) !== 0 || !is_file($binary)) {
            throw new RuntimeException('unable to build macOS command cleanup component');
        }
        chmod($binary, 0700);
    }

    private function stageApplication(string $stage, string $typePhpLicense): void
    {
        $app = $stage . '/payload/app';
        $this->createDirectory($app . '/bin');
        copy($this->root . '/bin/webman-aot-builder.php', $app . '/bin/webman-aot-builder.php');
        $this->copyDirectory($this->root . '/src', $app . '/src', ['php']);
        copy($this->root . '/toolchain.lock.json', $app . '/toolchain.lock.json');
        $this->createDirectory($app . '/toolchain');
        copy(
            $this->root . '/toolchain/minimal-components.lock.json',
            $app . '/toolchain/minimal-components.lock.json'
        );
        $this->createDirectory($app . '/installer');
        copy($this->root . '/installer/runtime.lock.json', $app . '/installer/runtime.lock.json');
        $this->createDirectory($app . '/packages/composer-installer/src');
        $this->copyRequiredFile($this->root . '/packages/composer-installer/src/Uninstaller.php', $app . '/packages/composer-installer/src/Uninstaller.php');
        $this->createDirectory($app . '/packages/composer-installer/resources');
        $this->copyRequiredFile($this->root . '/packages/composer-installer/resources/uninstall-launchers.json', $app . '/packages/composer-installer/resources/uninstall-launchers.json');
        $this->createDirectory($app . '/installer/windows');
        $this->copyRequiredFile($this->root . '/installer/windows/uninstall.ps1', $app . '/installer/windows/uninstall.ps1');
        $this->createDirectory($app . '/installer/macos');
        $this->copyRequiredFile($this->root . '/installer/macos/uninstall.sh', $app . '/installer/macos/uninstall.sh');
        $this->createDirectory($app . '/tools');
        foreach ([
            'guided.php',
            'windows-replay.ps1',
            'macos-prepare.php',
            'check-toolchain-capabilities.php',
            'apply-typephp-patches.php',
            'strip-sdk-debug.php',
            'assemble-sysroot.php',
        ] as $tool) {
            if (!copy($this->root . '/tools/' . $tool, $app . '/tools/' . $tool)) {
                throw new RuntimeException("unable to stage build tool: {$tool}");
            }
        }
        $this->copyDirectory(
            $this->root . '/toolchain/patches/typephp/0.9.2',
            $app . '/toolchain/patches/typephp/0.9.2',
            ['patch', 'json']
        );
        $this->createDirectory($app . '/compatibility/locks');
        if (!copy(
            $this->root . '/compatibility/locks/webman-workerman-2026-09-25.json',
            $app . '/compatibility/locks/webman-workerman-2026-09-25.json'
        )) {
            throw new RuntimeException('unable to stage compatibility lock');
        }
        copy($this->root . '/LICENSE', $app . '/LICENSE');
        copy($this->root . '/NOTICE.md', $app . '/NOTICE.md');
        $this->createDirectory($app . '/THIRD_PARTY_LICENSES');
        $this->copyRequiredFile(
            $typePhpLicense,
            $app . '/THIRD_PARTY_LICENSES/TypePHP-GPL-3.0.txt'
        );
        $llvmLicense = $this->root . '/toolchain/licenses/LLVM-19.1.7.txt';
        if ($this->digest($llvmLicense)
            !== '3340babe8ac7bc6ae294d93aa01c310a250d43d5b760e5c12954882d4e5c83c7'
        ) {
            throw new RuntimeException('locked LLVM 19.1.7 license text differs');
        }
        $this->copyRequiredFile(
            $llvmLicense,
            $app . '/THIRD_PARTY_LICENSES/LLVM-Apache-2.0-with-exceptions.txt'
        );
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function writeMetadata(
        string $stage,
        string $platform,
        array $runtime,
        bool $complete = false
    ): void
    {
        $manifest = [];
        $payload = $stage . '/payload';
        $files = $this->files($payload);
        foreach ($files as $relative => $path) {
            $manifest[] = $this->digest($path) . '  payload/' . $relative;
        }
        file_put_contents(
            $stage . '/payload-manifest.sha256',
            implode("\n", $manifest) . "\n"
        );
        file_put_contents(
            $stage . '/package.json',
            json_encode([
                'schema' => 'webman-aot-builder-installer-package-v1',
                'version' => $this->version(),
                'revision' => $this->options['revision'] ?? 'unknown',
                'platform' => $platform,
                'flavor' => $complete ? 'complete' : 'small',
                'runtime' => $runtime,
                'payloadFiles' => count($manifest),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    private function extractLockedZip(string $archive, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new RuntimeException('unable to open Windows runtime archive');
        }
        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (!is_string($name)) {
                    throw new RuntimeException('Windows runtime archive has unnamed entry');
                }
                $normalized = str_replace('\\', '/', $name);
                $segments = explode('/', $normalized);
                if ($normalized === ''
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:/', $normalized) === 1
                    || in_array('..', $segments, true)
                ) {
                    throw new RuntimeException("unsafe Windows runtime archive entry: {$name}");
                }
            }
            $this->createDirectory($destination);
            if (!$zip->extractTo($destination)) {
                throw new RuntimeException('unable to extract Windows runtime archive');
            }
        } finally {
            $zip->close();
        }
    }

    private function copyRequiredFile(string $source, string $destination): void
    {
        if (!is_file($source) || is_link($source) || !copy($source, $destination)) {
            throw new RuntimeException("unable to stage required runtime file: {$source}");
        }
    }

    /** @param list<string> $members @return array<string,string> */
    private function sourceLicenses(string $archive, string $sha256, array $members): array
    {
        if (!hash_equals($sha256, $this->digest($archive))) {
            throw new RuntimeException('selected source license archive digest differs');
        }
        $process = proc_open(['tar', '-tf', $archive], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('unable to inspect selected source archive root');
        }
        fclose($pipes[0]);
        $listing = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($listing) || trim($listing) === '') {
            throw new RuntimeException('selected source archive listing failed: ' . trim((string) $error));
        }
        $roots = [];
        $entries = [];
        foreach (explode("\n", rtrim($listing, "\n")) as $entry) {
            if (PHP_OS_FAMILY === 'Windows' && str_ends_with($entry, "\r")) {
                $entry = substr($entry, 0, -1);
            }
            $entry = rtrim($entry, '/');
            $parts = explode('/', $entry);
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/D', $parts[0]) !== 1
                || str_contains($entry, '\\') || preg_match('/[\x00-\x1f]/', $entry) === 1
                || in_array('..', $parts, true) || in_array('.', $parts, true) || in_array('', $parts, true)
                || isset($entries[$entry])) {
                throw new RuntimeException('selected source archive contains unsafe or repeated entries');
            }
            $roots[$parts[0]] = true;
            $entries[$entry] = true;
        }
        if (count($roots) !== 1) {
            throw new RuntimeException('selected source archive requires one source root');
        }
        $root = array_key_first($roots);
        $licenses = [];
        foreach ($members as $member) {
            if (!isset($entries[$root . '/' . $member])) {
                throw new RuntimeException('selected source license is missing: ' . $member);
            }
            $licenses[$member] = $this->readTarMember($archive, $root . '/' . $member);
        }
        return $licenses;
    }

    private function readTarMember(string $archive, string $member): string
    {
        $process = proc_open(
            ['tar', '-xOf', $archive, $member],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('unable to inspect locked PHP source archive');
        }
        fclose($pipes[0]);
        $contents = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($contents) || $contents === '') {
            throw new RuntimeException('PHP source license is missing: ' . $member . ' ' . trim((string) $error));
        }
        return $contents;
    }

    /**
     * @return array<string, string>
     */
    private function files(string $root): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr(
                $item->getPathname(),
                strlen($root) + 1
            ));
            $files[$relative] = $item->getPathname();
        }
        ksort($files, SORT_STRING);

        return $files;
    }

    private function addTreeToTar(PharData $tar, string $root, string $prefix): void
    {
        foreach ($this->files($root) as $relative => $path) {
            $tar->addFile($path, ltrim($prefix . '/' . $relative, '/'));
        }
    }

    private function addTreeToZip(ZipArchive $zip, string $root, string $prefix): void
    {
        foreach ($this->files($root) as $relative => $path) {
            if (!$zip->addFile($path, ltrim($prefix . '/' . $relative, '/'))) {
                throw new RuntimeException("unable to add installer file: {$relative}");
            }
        }
    }

    /**
     * @return array{platform:string,path:string,sha256:string,size:int}
     */
    private function packageResult(string $platform, string $path): array
    {
        $size = filesize($path);
        if (!is_int($size)) {
            throw new RuntimeException("unable to stat package: {$path}");
        }

        return [
            'platform' => $platform,
            'path' => $path,
            'sha256' => $this->digest($path),
            'size' => $size,
        ];
    }

    private function version(): string
    {
        $contents = file_get_contents($this->root . '/src/Version.php');
        if (!is_string($contents)
            || preg_match("/public const VALUE = '([^']+)'/", $contents, $matches) !== 1
        ) {
            throw new RuntimeException('unable to resolve Webman AOT Builder version');
        }

        return $matches[1];
    }

    private function digest(string $path): string
    {
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new RuntimeException("unable to hash file: {$path}");
        }

        return $digest;
    }

    /**
     * @return array<string, mixed>
     */
    private function readJson(string $path): array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException("unable to read JSON: {$path}");
        }
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return is_array($value) ? $value : [];
    }

    /**
     * @param list<string> $arguments
     */
    private function parseOptions(array $arguments): void
    {
        foreach (array_slice($arguments, 1) as $argument) {
            if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
                throw new InvalidArgumentException("invalid option: {$argument}");
            }
            [$name, $value] = explode('=', substr($argument, 2), 2);
            if ($name === '' || $value === '') {
                throw new InvalidArgumentException("invalid option: {$argument}");
            }
            $this->options[$name] = $value;
        }
    }

    private function requiredOption(string $name): string
    {
        if (!isset($this->options[$name])) {
            throw new InvalidArgumentException("missing option: --{$name}=...");
        }

        return $this->options[$name];
    }

    /**
     * @param list<string>|null $extensions
     */
    private function copyDirectory(string $source, string $destination, ?array $extensions = null): void
    {
        $this->createDirectory($destination);
        foreach (new DirectoryIterator($source) as $item) {
            if ($item->isDot()) {
                continue;
            }
            if ($item->isLink()) {
                throw new RuntimeException("installer source contains a symbolic link: {$item->getPathname()}");
            }
            $target = $destination . '/' . $item->getFilename();
            if ($item->isDir()) {
                $this->copyDirectory($item->getPathname(), $target, $extensions);
            } elseif ($extensions !== null
                && !in_array(strtolower($item->getExtension()), $extensions, true)
            ) {
                continue;
            } elseif (!copy($item->getPathname(), $target)) {
                throw new RuntimeException("unable to copy installer file: {$target}");
            }
        }
    }

    private function createDirectory(string $directory): void
    {
        if (!is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException("unable to create directory: {$directory}");
        }
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($directory);
    }
}

try {
    (new InstallerPackager(dirname(__DIR__)))->run($argv);
} catch (Throwable $throwable) {
    fwrite(STDERR, '[ERROR] ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}
