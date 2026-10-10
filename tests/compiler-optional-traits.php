<?php

declare(strict_types=1);

$toolchain = $argv[1] ?? '';
require $toolchain . '/vendor/autoload.php';
require $toolchain . '/src/gen_stub.php';

final class OptionalTraitCompiler extends TypePhp\CompilerTest
{
    public function __construct(string $root)
    {
        parent::__construct($root);
        $this->forTest = true;
        $this->setSourceIdentityMapping($root, '/typephp/optional-fixture');
        $this->setBuildDir($root . '/build');
        $this->setBuildMode('ext');
        $this->setTargetName('optional_fixture');
    }
    public function unavailable(): array { return $this->unavailableDeclarations; }
    public function has(string $name): bool { return $this->hasClass($name); }
    public function declarationState(): array
    {
        return [$this->unavailableDeclarations, $this->unavailableDeclarationFiles,
            $this->declarationAutoloadInputs, $this->declarationSourceFiles];
    }
}

function checkOptional(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-optional-' . bin2hex(random_bytes(6));
mkdir($root . '/vendor/example/optional', 0700, true);
mkdir($root . '/vendor/composer', 0700, true);
mkdir($root . '/vendor/example/child', 0700, true);
file_put_contents($root . '/composer.json', json_encode(['require' => ['example/optional' => '*']]));
$manifest = ['name' => 'example/optional', 'autoload' => ['psr-4' => ['Fixture\\' => '']],
    'suggest' => ['example/provider' => 'Optional trait provider']];
$writeMetadata = static function (array $manifest) use ($root): void {
    $child = ['name' => 'example/child', 'autoload' => ['psr-4' => ['ChildFixture\\' => '']],
        'require' => ['example/optional' => '*']];
    file_put_contents($root . '/vendor/example/optional/composer.json', json_encode($manifest));
    file_put_contents($root . '/vendor/example/child/composer.json', json_encode($child));
    file_put_contents($root . '/vendor/composer/installed.json', json_encode(['packages' => [
        $manifest + ['install-path' => '../example/optional'],
        $child + ['install-path' => '../example/child'],
    ]]));
};
$writeMetadata($manifest);
$sources = [
    'Unavailable' => '<?php namespace Fixture; class Unavailable { use \\Absent\\Provider; public function work(): string { return "never"; } }',
    'Child' => '<?php namespace Fixture; class Child extends Unavailable {}',
    'TraitConsumer' => '<?php namespace Fixture; trait TraitConsumer { use \\Absent\\Provider; }',
    'TraitChild' => '<?php namespace Fixture; class TraitChild { use TraitConsumer; }',
    'Available' => '<?php namespace Fixture; class Available { public function work(): string { return "available"; } }',
];
$files = [];
foreach ($sources as $name => $source) {
    $files[] = $file = $root . '/vendor/example/optional/' . $name . '.php';
    file_put_contents($file, $source);
}
$files[] = $childFile = $root . '/vendor/example/child/Derived.php';
file_put_contents($childFile, '<?php namespace ChildFixture; class Derived extends \\Fixture\\Unavailable {}');
try {
    $compiler = new OptionalTraitCompiler($root);
    $compiler->discoverUnavailableDeclarations($files);
    checkOptional(count($compiler->unavailable()) === 5, 'Unavailable dependency closure is incomplete');
    $keyMethod = new ReflectionMethod($compiler, 'preparedProjectKey');
    $cacheKey = $keyMethod->invoke($compiler, $files);
    foreach ($files as $file) {
        $compiler->prepareFile($file);
    }
    checkOptional(!$compiler->has('ChildFixture\\Derived'), 'A derived package without suggest was not quarantined');
    checkOptional($compiler->has('Fixture\\Available'), 'Available class was removed');
    foreach (['Unavailable', 'Child', 'TraitConsumer', 'TraitChild'] as $name) {
        checkOptional(!$compiler->has('Fixture\\' . $name), "Unavailable {$name} left a ClassDef");
    }
    $compiler->composeTraitDeclarations($files);
    foreach ($files as $file) {
        $compiler->convertFile($file);
    }
    echo "PASS complete trait/parent closure leaves no unavailable ClassDef and real conversion succeeds\n";
    $extension = file_get_contents($compiler->genExtension());
    checkOptional(str_contains($extension, 'typephp_guard_declaration_file')
        && str_contains($extension, 'typephp_unavailable_autoload_' . $compiler->getModuleName())
        && str_contains($extension, 'typephp_unavailable_request_shutdown();'),
        'Generated extension omitted the unavailable declaration lifecycle');
    $runtime = substr($extension, strpos($extension, 'struct typephp_unavailable_class_entry'),
        strpos($extension, 'static const zend_function_entry ext_functions[]')
            - strpos($extension, 'struct typephp_unavailable_class_entry'));
    checkOptional(!str_contains($runtime, $root), 'Generated runtime leaks a build-time absolute source path');
    checkOptional(!str_contains($extension, 'register_class_Fixture_Unavailable'),
        'Unavailable class was registered in MINIT');
    echo "PASS real extension emits scoped Zend lifecycle, relative source identity and no unavailable class registration\n";
    (new ReflectionMethod($compiler, 'storePreparedProject'))->invoke($compiler, $cacheKey);
    $cached = new OptionalTraitCompiler($root);
    $cached->discoverUnavailableDeclarations($files);
    checkOptional($keyMethod->invoke($cached, $files) === $cacheKey,
        'Equivalent selected source graph changed the prepared cache key');
    checkOptional((new ReflectionMethod($cached, 'restorePreparedProject'))->invoke($cached, $cacheKey),
        'Unavailable graph snapshot failed to restore');
    checkOptional($cached->declarationState() === $compiler->declarationState()
        && !$cached->has('Fixture\\Unavailable') && $cached->has('Fixture\\Available'),
        'Prepared cache restored an unavailable ClassDef or lost declaration inputs');
    $changedManifest = $manifest + ['description' => 'Changed metadata identity'];
    $writeMetadata($changedManifest);
    $changed = new OptionalTraitCompiler($root);
    $changed->discoverUnavailableDeclarations($files);
    checkOptional($keyMethod->invoke($changed, $files) !== $cacheKey,
        'Composer metadata change did not invalidate the prepared graph cache');
    $writeMetadata($manifest);
    file_put_contents($files[4], $sources['Available'] . "\n");
    $changed = new OptionalTraitCompiler($root);
    $changed->discoverUnavailableDeclarations($files);
    checkOptional($keyMethod->invoke($changed, $files) !== $cacheKey,
        'Selected source SHA change did not invalidate the prepared graph cache');
    file_put_contents($files[4], $sources['Available']);
    echo "PASS prepared snapshot preserves unavailable graph and invalidates metadata or selected source changes\n";
    foreach (['eager', 'mixed', 'top-level', 'unknown', 'required-provider', 'no-optional-evidence',
        'root-provider', 'second-missing-provider', 'wrong-trait-kind'] as $case) {
        $caseManifest = $manifest;
        $file = $files[0];
        file_put_contents($file, $sources['Unavailable']);
        file_put_contents($root . '/composer.json', json_encode(['require' => ['example/optional' => '*']]));
        $caseFiles = $files;
        if ($case === 'eager') {
            $caseManifest['autoload']['files'] = ['Unavailable.php'];
        } elseif ($case === 'mixed') {
            file_put_contents($file, $sources['Unavailable'] . ' class Sibling {}');
        } elseif ($case === 'top-level') {
            file_put_contents($file, $sources['Unavailable'] . ' echo "side effect";');
        } elseif ($case === 'unknown') {
            $caseManifest['autoload']['psr-4'] = ['Other\\' => ''];
        } elseif ($case === 'required-provider') {
            $caseManifest['require'] = ['example/provider' => '*'];
        } elseif ($case === 'no-optional-evidence') {
            unset($caseManifest['suggest']);
        } elseif ($case === 'root-provider' || $case === 'second-missing-provider') {
            if (!is_dir($root . '/app')) { mkdir($root . '/app', 0700, true); }
            $namespace = $case === 'root-provider' ? 'Absent' : 'Installed';
            file_put_contents($root . '/app/Provider.php', '<?php namespace ' . $namespace . '; trait Provider {}');
            file_put_contents($root . '/composer.json', json_encode(['autoload' => ['psr-4' => [$namespace . '\\' => 'app/']]]));
            if ($case === 'second-missing-provider') {
                file_put_contents($file, '<?php namespace Fixture; class Unavailable { use \\Absent\\Provider, \\Installed\\Provider; }');
            }
        } elseif ($case === 'wrong-trait-kind') {
            $caseFiles[] = $wrong = $root . '/vendor/example/optional/Wrong.php';
            file_put_contents($wrong, '<?php namespace Fixture; class Wrong {}');
            file_put_contents($file, '<?php namespace Fixture; class Unavailable { use Wrong, \\Absent\\Provider; }');
        }
        $writeMetadata($caseManifest);
        $rejected = false;
        try {
            (new OptionalTraitCompiler($root))->discoverUnavailableDeclarations($caseFiles);
        } catch (RuntimeException $exception) {
            $rejected = $case === 'wrong-trait-kind'
                ? str_contains($exception->getMessage(), 'target is not a trait')
                : str_contains($exception->getMessage(), 'Cannot defer unavailable declaration');
        }
        checkOptional($rejected, "{$case} source was silently deferred");
        echo "PASS {$case} source remains a compile failure\n";
    }
    $writeMetadata($manifest);
    file_put_contents($root . '/composer.json', json_encode(['require' => ['example/optional' => '*']]));
    file_put_contents($files[0], '<?php namespace Fixture; class Unavailable { use \\Fixture\\DoesNotExist; }');
    $missingPrefixCompiler = new OptionalTraitCompiler($root);
    $missingPrefixCompiler->discoverUnavailableDeclarations($files);
    checkOptional(count($missingPrefixCompiler->unavailable()) === 5,
        'A nonexistent provider under an installed PSR-4 prefix was treated as an existing source');
    echo "PASS missing trait under an installed PSR-4 prefix is distinguished from an existing provider file\n";
    echo 'PASS optional trait contracts elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    // The fixture exists only for this command.
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
