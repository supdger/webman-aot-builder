<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/Cli/ConfigurationException.php';
require dirname(__DIR__) . '/src/Toolchain/UnifiedPatchApplier.php';
require dirname(__DIR__) . '/src/Toolchain/TypePhpPatchManifestFingerprint.php';
require dirname(__DIR__) . '/src/Toolchain/TypePhpPatchSourceVerifier.php';

use WebmanAotBuilder\Toolchain\UnifiedPatchApplier;
use WebmanAotBuilder\Toolchain\TypePhpPatchManifestFingerprint;
use WebmanAotBuilder\Toolchain\TypePhpPatchSourceVerifier;

function checkPatch(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function rejectPatch(Closure $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException($message);
}
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-source-patches-' . bin2hex(random_bytes(6));
mkdir($root . '/src', 0700, true);
$patch = $root . '/change.patch';
$new = "--- /dev/null\n+++ b/src/Added.php\n@@ -0,0 +1,2 @@\n+<?php\n+class Added {}\n";
$existing = "--- a/src/Existing.php\n+++ b/src/Existing.php\n@@ -1 +1 @@\n-before\n+after\n";
try {
    file_put_contents($root . '/src/Existing.php', "before\n");
    file_put_contents($patch, $existing . $new);
    $applier = new UnifiedPatchApplier();
    $applier->apply($patch, $root);
    checkPatch(file_get_contents($root . '/src/Existing.php') === "after\n", 'Legacy modification failed');
    checkPatch(file_get_contents($root . '/src/Added.php') === "<?php\nclass Added {}\n", 'New source creation failed');
    echo "PASS legacy modification and missing source creation\n";
    file_put_contents($patch, $new);
    rejectPatch(fn() => $applier->apply($patch, $root), 'Creation overwrote an existing source');
    checkPatch(file_get_contents($root . '/src/Added.php') === "<?php\nclass Added {}\n", 'Rejected overwrite changed bytes');
    echo "PASS existing source is never overwritten by a creation patch\n";
    file_put_contents($root . '/src/Existing.php', "before\n");
    file_put_contents($patch, $existing . str_replace(['Added.php', '+1,2'], ['Broken.php', '+1,3'], $new));
    rejectPatch(fn() => $applier->apply($patch, $root), 'Malformed final hunk accepted');
    checkPatch(file_get_contents($root . '/src/Existing.php') === "before\n"
        && !file_exists($root . '/src/Broken.php'), 'Invalid patch left partial writes');
    echo "PASS later malformed hunk leaves every target unchanged\n";
    symlink($root . '/src', $root . '/alias');
    file_put_contents($patch, str_replace('src/Added.php', 'alias/Unsafe.php', $new));
    rejectPatch(fn() => $applier->apply($patch, $root), 'Symlink parent accepted');
    foreach (['../Outside.php', '/Outside.php', 'C:/Outside.php', 'missing/NoParent.php'] as $path) {
        file_put_contents($patch, str_replace('src/Added.php', $path, $new));
        rejectPatch(fn() => $applier->apply($patch, $root), 'Unsafe creation path accepted: ' . $path);
    }
    file_put_contents($patch, str_replace('--- /dev/null', '--- a/src/Other.php', $new));
    rejectPatch(fn() => $applier->apply($patch, $root), 'Rename header accepted');
    echo "PASS unsafe paths, parent aliases and rename headers refused\n";
    $manifest = ['component' => 'typephp-source', 'version' => 'fixture', 'rules' => [[
        'path' => 'src/Added.php', 'beforeSha256' => hash('sha256', ''),
        'afterSha256' => hash_file('sha256', $root . '/src/Added.php'), 'added' => true,
    ]], 'reason' => 'Added source identity fixture'];
    $manifestFile = $root . '/manifest.json';
    file_put_contents($manifestFile, json_encode($manifest));
    $fingerprint = (new TypePhpPatchManifestFingerprint())->digest($manifestFile);
    (new TypePhpPatchSourceVerifier())->verify($root, $manifestFile);
    foreach (['false-marker', 'nonempty-before', 'prepared-added'] as $case) {
        $changed = $manifest;
        if ($case === 'false-marker') { $changed['rules'][0]['added'] = false; }
        elseif ($case === 'nonempty-before') { $changed['rules'][0]['beforeSha256'] = str_repeat('a', 64); }
        else { $changed['rules'][0]['preparedBeforeSha256'] = str_repeat('a', 64); }
        file_put_contents($manifestFile, json_encode($changed));
        rejectPatch(fn() => (new TypePhpPatchManifestFingerprint())->digest($manifestFile), 'Invalid added source identity accepted');
        rejectPatch(fn() => (new TypePhpPatchSourceVerifier())->verify($root, $manifestFile), 'Invalid added source verification accepted');
    }
    file_put_contents($manifestFile, json_encode($manifest));
    file_put_contents($root . '/src/Added.php', "<?php\nclass Changed {}\n");
    rejectPatch(fn() => (new TypePhpPatchSourceVerifier())->verify($root, $manifestFile), 'Changed new source digest accepted');
    checkPatch(strlen($fingerprint) === 64, 'Added source was omitted from the fingerprint');
    echo "PASS added-source fingerprint, strict absence identity and post-source digest\n";
    checkPatch(glob($root . '/src/.typephp-patch-*') === [], 'Patch staging resources leaked');
    echo 'PASS source patch contracts elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,
        FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
