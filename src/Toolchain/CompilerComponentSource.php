<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

/**
 * Proves a complete compiler replacement while reusing an unchanged prepared
 * runtime. It never extracts archives, changes inputs, or edits Composer maps.
 */
final class CompilerComponentSource
{
    private const SDK = 'vendor/swoole/phpx/full-static/sdk/';
    private const COMPOSER_OUTPUTS = [
        'vendor/composer/autoload_classmap.php',
        'vendor/composer/autoload_static.php',
    ];

    public function verify(string $source, string $prepared, array $archives, string $manifest): array
    {
        $this->canonical($source);
        $metadata = json_decode($this->read($prepared . '/prepared-toolchain.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['typephp', 'php', 'phpx'] as $key) {
            $relative = $metadata[$key] ?? null;
            if (!is_string($relative) || str_starts_with($relative, '/')
                || str_contains($relative, '\\') || in_array('..', explode('/', $relative), true)
                || !is_string(realpath($prepared . '/' . $relative))
                || !str_starts_with(realpath($prepared . '/' . $relative), $prepared . '/')
            ) {
                throw new \RuntimeException('prepared compiler input path escaped its root');
            }
        }
        $old = $prepared . '/' . ($metadata['typephp'] ?? '');
        $this->canonical($old);
        $before = $this->inventory($source);
        $runtime = $this->inventory($old . '/vendor/swoole/phpx', true);
        $expected = $this->archive($archives['typephp-source']);
        foreach ($this->archive($archives['typephp-macos-arm64'] ?? $archives['typephp-windows-x64']) as $path => $digest) {
            if (str_starts_with($path, 'vendor/')) {
                $expected[$path] = $digest;
            }
        }
        // The TypePHP 0.9.5 host packages no longer ship vendor/; the locked
        // vendor bundle provides the compiler's Composer dependencies instead.
        foreach ($this->archive($archives['typephp-vendor']) as $path => $digest) {
            if (str_starts_with($path, 'vendor/')) {
                $expected[$path] = $digest;
            }
        }
        foreach (array_keys($expected) as $path) {
            if (str_starts_with($path, 'vendor/swoole/phpx/')) {
                unset($expected[$path]);
            }
        }
        foreach ($this->archive($archives['phpx-source']) as $path => $digest) {
            $expected['vendor/swoole/phpx/' . $path] = $digest;
        }
        $rules = json_decode($this->read($manifest), true, flags: JSON_THROW_ON_ERROR);
        (new TypePhpPatchSourceVerifier())->verify($source, $manifest);
        foreach ($rules['rules'] ?? [] as $rule) {
            $path = $rule['path'];
            if (!is_string($rule['beforeSha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $rule['beforeSha256']) !== 1
                || (($rule['added'] ?? false) && $rule['beforeSha256'] !== hash('sha256', ''))
            ) {
                throw new \RuntimeException('compiler maintenance pristine rule is invalid');
            }
            $pristine = $expected[$path] ?? null;
            if (str_starts_with($path, self::SDK)) {
                $pristine = $this->sdkArchiveFile($archives['phpx-sdk-linux-x64'], substr($path, strlen(self::SDK)));
            }
            if (($rule['added'] ?? false) ? $pristine !== null : $pristine !== $rule['beforeSha256']) {
                throw new \RuntimeException('compiler maintenance pristine source differs: ' . $path);
            }
            $expected[$path] = $rule['afterSha256'];
        }
        foreach ($before as $path => $record) {
            // Embedded SDK files are inventoried but never projected. The
            // runtime is the existing prepared SDK, validated separately below.
            if (!str_starts_with($path, self::SDK) && !in_array($path, self::COMPOSER_OUTPUTS, true)
                && ($expected[$path] ?? null) !== $record['sha256']
            ) {
                throw new \RuntimeException('unknown or changed compiler source: ' . $path);
            }
        }
        foreach ($expected as $path => $_) {
            // These are the complete compiler consumer roots. Upstream examples,
            // tests and documentation remain optional, as in the default bundle.
            if ((str_starts_with($path, 'src/') || str_starts_with($path, 'bin/')
                || str_starts_with($path, 'vendor/') || $path === 'composer.json')
                && !str_starts_with($path, self::SDK) && !isset($before[$path])
            ) {
                throw new \RuntimeException('complete compiler source is missing: ' . $path);
            }
        }
        foreach ($runtime as $path => $record) {
            if (str_starts_with($path, 'src/') || str_starts_with($path, 'include/')
                || str_starts_with($path, 'thirdparty/')
            ) {
                if (($before['vendor/swoole/phpx/' . $path]['sha256'] ?? null) !== $record['sha256']) {
                    throw new \RuntimeException('PHPX runtime source or SDK ABI input differs: ' . $path);
                }
            }
        }
        $contract = $this->runtimeContract($source, $old, $before, $runtime);
        $sdk = $old . '/' . rtrim(self::SDK, '/');
        $sdkDigest = (new StaticSdkFingerprint())->digest($sdk);
        if (($metadata['sdkSha256'] ?? null) !== $sdkDigest) {
            throw new \RuntimeException('prepared static SDK fingerprint differs');
        }
        $sdkMetadata = json_decode($this->read($sdk . '/sdk-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $runtimeMetadata = json_decode($this->read($sdk . '/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $versionHeader = $this->read($sdk . '/include/php/main/php_version.h');
        $configHeader = $this->read($sdk . '/include/php/main/php_config.h');
        if (($sdkMetadata['schema'] ?? null) !== 'typephp-phpx-sdk-v1'
            || preg_match('/^#define\s+PHP_VERSION\s+"([^"]+)"/m', $versionHeader, $version) !== 1
            || ($sdkMetadata['php_version'] ?? null) !== $version[1]
            || ($runtimeMetadata['php_version'] ?? null) !== $version[1]
            || ($runtimeMetadata['zts'] ?? null) !== true
            || preg_match('/^#define\s+ZTS\s+1/m', $configHeader) !== 1
        ) {
            throw new \RuntimeException('prepared PHP ABI metadata and actual headers differ');
        }
        $autoload = $this->autoload($prepared . '/' . $metadata['php'], $source, $before);
        $this->unchanged($source, $before);
        $this->unchanged($old . '/vendor/swoole/phpx', $runtime, true);
        return [
            'schema' => 'webman-aot-builder-compiler-composition-v1',
            'sourceInventorySha256' => hash('sha256', json_encode($before, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'sourceFiles' => count($before),
            'patchManifestSha256' => hash_file('sha256', $manifest),
            'sourceArchives' => array_map(static fn (string $path): string => hash_file('sha256', $path), $archives),
            'sdkSha256' => $sdkDigest,
            'phpVersion' => $version[1],
            'composer' => $autoload,
            'consumerContract' => $contract,
            'files' => $before,
            'runtimeFiles' => $runtime,
            'oldCompilerRoot' => $old,
        ];
    }

    public function unchanged(string $root, array $before, bool $runtimeLinks = false): void
    {
        if ($this->inventory($root, $runtimeLinks) !== $before) {
            throw new \RuntimeException('component compiler input changed during projection');
        }
    }

    private function canonical(string $root): void
    {
        if (!is_dir($root) || is_link($root) || realpath($root) !== $root) {
            throw new \RuntimeException('compiler component root must be a canonical directory');
        }
    }

    private function read(string $path): string
    {
        $stat = lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
            throw new \RuntimeException('compiler component input must be an exclusive regular file: ' . $path);
        }
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            throw new \RuntimeException('compiler component input cannot be read: ' . $path);
        }
        return $bytes;
    }

    private function inventory(string $root, bool $runtimeLinks = false): array
    {
        $this->canonical($root);
        $files = [];
        $bytes = 0;
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        ) as $entry) {
            $path = $entry->getPathname();
            $stat = lstat($path);
            if (!is_array($stat)) {
                throw new \RuntimeException('compiler composition contains a link or unknown input');
            }
            if (is_link($path)) {
                $resolved = realpath($path);
                $target = readlink($path);
                $resolvedStat = is_string($resolved) ? lstat($resolved) : false;
                if (!$runtimeLinks || !is_string($target) || str_starts_with($target, '/')
                    || !is_string($resolved) || !str_starts_with($resolved, $root . '/')
                    || !is_array($resolvedStat) || ($resolvedStat['mode'] & 0170000) !== 0100000
                    || $resolvedStat['nlink'] !== 1
                ) {
                    throw new \RuntimeException('compiler composition contains an unsafe link');
                }
                $files[substr($path, strlen($root) + 1)] = [
                    'device' => $stat['dev'], 'inode' => $stat['ino'], 'size' => $stat['size'],
                    'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'],
                    'sha256' => hash_file('sha256', $resolved), 'link' => $target,
                ];
                continue;
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile() || $stat['nlink'] !== 1 || realpath($path) !== $path) {
                throw new \RuntimeException('compiler composition input is not an exclusive regular file');
            }
            $bytes += $stat['size'];
            if (count($files) >= 32768 || $bytes > 4 * 1073741824) {
                throw new \RuntimeException('compiler composition input exceeds its bounded contract');
            }
            $files[substr($path, strlen($root) + 1)] = [
                'device' => $stat['dev'], 'inode' => $stat['ino'], 'size' => $stat['size'],
                'mtime' => $stat['mtime'], 'ctime' => $stat['ctime'], 'sha256' => hash_file('sha256', $path),
            ];
        }
        ksort($files, SORT_STRING);
        return $files;
    }

    private function runtimeContract(string $source, string $old, array $files, array $runtime): array
    {
        $repository = dirname(__DIR__, 2);
        $contracts = [];
        foreach (['FullStaticProjectOverlay', 'TypePhpProjectCompiler'] as $class) {
            $path = $repository . '/src/Toolchain/' . $class . '.php';
            $contents = $this->read($path);
            // This component is for the existing public builder contract.
            // Bare TypePHP's other modes are not inferred from SDK equality.
            if (!str_contains($contents, 'native-runtime-declarations: true')) {
                throw new \RuntimeException('public compiler runtime declaration contract is missing');
            }
            $contracts[$class] = hash('sha256', $contents);
        }
        $distribution = $this->read($repository . '/src/Project/DistributionAssembler.php');
        if (!str_contains($distribution, "['--full-static']")) {
            throw new \RuntimeException('public distribution full-static contract is missing');
        }
        $contracts['DistributionAssembler'] = hash('sha256', $distribution);
        foreach ($runtime as $path => $record) {
            if (!str_starts_with($path, 'full-static/sdk/include/')) {
                continue;
            }
            $sourceRecord = $files['vendor/swoole/phpx/' . $path] ?? null;
            if (!is_array($sourceRecord)) {
                throw new \RuntimeException('compiler ABI header is missing: ' . $path);
            }
            if ($path !== 'full-static/sdk/include/phpx/typephp_helper.h'
                && $sourceRecord['sha256'] !== $record['sha256']
            ) {
                throw new \RuntimeException('compiler and prepared SDK ABI headers differ: ' . $path);
            }
        }
        $relative = self::SDK . 'include/phpx/typephp_helper.h';
        $oldHeader = $this->read($old . '/' . $relative);
        $newHeader = $this->read($source . '/' . $relative);
        $addedPrototypes = [
            'typephp_property_ref_scoped',
            'typephp_unset_property_scoped',
        ];
        if ($newHeader !== $oldHeader) {
            $prototypes = '\s+PHPX_API\s+php::Reference\s+typephp_property_ref_scoped'
                . '\(const php::Variant &object,\s+const php::Variant &member,\s+zend_class_entry \*scope\);'
                . '\s+PHPX_API\s+void\s+typephp_unset_property_scoped'
                . '\(const php::Variant &object,\s+const php::Variant &member,\s+zend_class_entry \*scope\);\s*';
            if (!str_starts_with($newHeader, $oldHeader)
                || preg_match('~^' . $prototypes . '$~D', substr($newHeader, strlen($oldHeader))) !== 1
                || $newHeader !== $this->read($source . '/vendor/swoole/phpx/include/typephp_helper.h')
            ) {
                throw new \RuntimeException('compiler helper differs beyond runtime declaration prototypes');
            }
        }
        $runtimeSource = $this->read($source . '/src/Build/RuntimeDeclarationsTrait.php');
        $propertySource = $this->read($source . '/src/Parser/PropertyAccessTrait.php');
        foreach (['typephp_runtime_property_reference', 'typephp_runtime_unset_property'] as $symbol) {
            if (!str_contains($runtimeSource, $symbol . '(') || !str_contains($propertySource, $symbol)) {
                throw new \RuntimeException('generated property runtime implementation is missing');
            }
        }
        return [
            'mode' => 'full-static',
            'nativeRuntimeDeclarations' => true,
            'publicContractSha256' => $contracts,
            'preparedSdkIsReused' => true,
            'embeddedSdkIsNotProjected' => true,
            'preparedHelperSha256' => hash('sha256', $oldHeader),
            'sourceHelperSha256' => hash('sha256', $newHeader),
            'helperPrototypeAdditions' => $addedPrototypes,
            'generatedRuntimeSourceSha256' => hash('sha256', $runtimeSource),
            'propertyAccessSourceSha256' => hash('sha256', $propertySource),
            'closureSourceSha256' => $runtime['src/core/closure.cc']['sha256'] ?? null,
            'preparedLibphpxSha256' => $runtime['full-static/sdk/lib/libphpx.a']['sha256'] ?? null,
        ];
    }

    private function archive(string $path): array
    {
        $archive = new \PharData($path);
        $prefix = 'phar://' . $path . '/';
        $files = [];
        $root = null;
        foreach (new \RecursiveIteratorIterator($archive) as $entry) {
            $member = substr($entry->getPathname(), strlen($prefix));
            [$top, $relative] = array_pad(explode('/', $member, 2), 2, '');
            $root ??= $top;
            if ($root !== $top || $relative === '' || in_array('..', explode('/', $relative), true)
                || $entry->isLink() || !$entry->isFile() || isset($files[$relative])
                || count($files) >= 32768
            ) {
                throw new \RuntimeException('compiler source archive has an unsafe member');
            }
            $files[$relative] = hash_file('sha256', $entry->getPathname());
        }
        return $files;
    }

    private function sdkArchiveFile(string $archive, string $relative): string
    {
        $tar = PHP_OS_FAMILY === 'Windows' ? 'tar.exe' : '/usr/bin/tar';
        $members = explode("\n", $this->command([$tar, '-tf', $archive]));
        $matches = array_values(array_filter($members, static fn (string $member): bool =>
            str_ends_with($member, '/' . $relative) && !str_contains($member, '/../')));
        if (count($matches) !== 1) {
            throw new \RuntimeException('locked SDK pristine header is missing or ambiguous');
        }
        return hash('sha256', $this->command([$tar, '-xOf', $archive, $matches[0]]));
    }

    private function command(array $command): string
    {
        $process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
            1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot start compiler composition verifier');
        }
        $bytes = stream_get_contents($pipes[1], 16 * 1048576 + 1);
        fclose($pipes[1]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($bytes) || strlen($bytes) > 16 * 1048576) {
            throw new \RuntimeException('compiler composition verifier failed with exit ' . $exit
                . ' or exceeded its output bound: ' . substr((string) $bytes, -2048));
        }
        return $bytes;
    }

    private function autoload(string $php, string $source, array $files): array
    {
        $code = <<<'PHP'
$root=$argv[1]; $loader=require $root.'/vendor/autoload.php';
if (!$loader->isClassMapAuthoritative()) {throw new RuntimeException('Composer classmap is not authoritative');}
$map=$loader->getClassMap();
foreach ($map as $class=>$path) {
    $canonical=realpath($path);
    if (!is_file($path)||is_link($path)||!is_string($canonical)||!str_starts_with($canonical,$root.'/')) {
        throw new RuntimeException('Composer classmap escaped compiler source');
    }
    $map[$class]=$canonical;
}
foreach (['TypePhp\\CompilerBase','TypePhp\\Build\\GeneratedArtifactReceipt','TypePhp\\Backend\\CompilerBackend'] as $class) {
    $file=(new ReflectionClass($class))->getFileName();
    if (($map[$class]??null)!==$file) {throw new RuntimeException('Composer compiler reflection differs');}
}
echo json_encode($map,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
PHP;
        $map = json_decode($this->command([$php, '-n', '-r', $code, $source]), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($map) || count($map) > 32768 || $map === []) {
            throw new \RuntimeException('Composer compiler classmap is missing or exceeds its bound');
        }
        foreach ($map as $path) {
            if (!isset($files[substr($path, strlen($source) + 1)])) {
                throw new \RuntimeException('Composer classmap references an unknown source');
            }
        }
        $composer = $this->executable(['composer.phar', 'composer']);
        $hostPhp = $this->executable(PHP_OS_FAMILY === 'Windows' ? ['php.exe'] : ['php']);
        $composerDigest = hash_file('sha256', $composer);
        $hostPhpDigest = hash_file('sha256', $hostPhp);
        $recompute = <<<'PHP'
putenv('COMPOSER_DISABLE_NETWORK=1');
putenv('COMPOSER_HOME='.(PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'));
putenv('COMPOSER_HTACCESS_PROTECT=false');
putenv('COMPOSER_CACHE_READ_ONLY=1');
putenv('COMPOSER_CACHE_DIR='.(PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null'));
Phar::loadPhar($argv[1], 'composer.phar');
require 'phar://composer.phar/vendor/autoload.php';
chdir($argv[2]);
$composer=Composer\Factory::create(new Composer\IO\NullIO(), $argv[2].'/composer.json', true, true);
$generator=$composer->getAutoloadGenerator();
$generator->setRunScripts(false);
$generator->setClassMapAuthoritative(true);
$generator->setDryRun(true);
$installed=json_decode(file_get_contents($argv[2].'/vendor/composer/installed.json'),true,flags:JSON_THROW_ON_ERROR);
$generator->setDevMode($installed['dev']);
$result=$generator->dump($composer->getConfig(),$composer->getRepositoryManager()->getLocalRepository(),
    $composer->getPackage(),$composer->getInstallationManager(),'composer',true,null,$composer->getLocker(),true);
if (!$result instanceof Composer\ClassMapGenerator\ClassMap) {throw new RuntimeException('Composer dry-run did not return a classmap');}
echo json_encode(['composerVersion'=>Composer\Composer::VERSION,'classMap'=>$result->getMap()],
    JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
PHP;
        $recomputed = json_decode($this->command([$hostPhp, '-r', $recompute, $composer, $source]),
            true, flags: JSON_THROW_ON_ERROR);
        $expected = $recomputed['classMap'] ?? null;
        if (!is_array($expected) || count($expected) > 32768) {
            throw new \RuntimeException('Composer complete classmap exceeds its bound');
        }
        foreach ($expected as $class => $path) {
            $canonical = realpath($path);
            if (!is_string($canonical) || !str_starts_with($canonical, $source . '/')
                || !isset($files[substr($canonical, strlen($source) + 1)])
            ) {
                throw new \RuntimeException('Composer recomputed map escaped verified compiler source');
            }
            $expected[$class] = $canonical;
        }
        $this->compareClassMaps($map, $expected);
        if ($composerDigest !== hash_file('sha256', $composer)
            || $hostPhpDigest !== hash_file('sha256', $hostPhp)
        ) {
            throw new \RuntimeException('Composer verifier executable changed');
        }
        return [
            'classes' => count($map),
            'completeClassmapSha256' => hash('sha256', json_encode($expected, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'composerSha256' => $composerDigest,
            'composerVersion' => $recomputed['composerVersion'],
            'composerPhpSha256' => $hostPhpDigest,
            'method' => 'Composer AutoloadGenerator dump dry-run, same installed autoload rules, no plugins or scripts',
        ];
    }

    private function compareClassMaps(array $actual, array $expected): void
    {
        ksort($actual, SORT_STRING);
        ksort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException('authoritative Composer classmap differs from complete official recomputation');
        }
    }

    private function executable(array $names): string
    {
        foreach ($names as $name) {
            foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
                $path = realpath($directory . '/' . $name);
                if (is_string($path) && is_file($path) && is_executable($path)) {
                    return $path;
                }
            }
        }
        throw new \RuntimeException('Composer and a compatible host PHP are required for complete classmap verification');
    }
}
