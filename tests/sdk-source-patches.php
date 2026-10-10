<?php

declare(strict_types=1);

require dirname(__DIR__) . '/tools/rebuild-phpx-sdk.php';

function ensureSource(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
function rejectSource(Closure $operation, string $message): void
{
    try { $operation(); } catch (RuntimeException $error) {
        ensureSource(str_contains($error->getMessage(), $message), 'Wrong rejection: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}
$started = microtime(true);
$root = sys_get_temp_dir() . '/webman-aot-sdk-source-' . bin2hex(random_bytes(6));
foreach (['baseline', 'candidate', 'target'] as $name) { mkdir($root . '/' . $name . '/src', 0700, true); }
$baseline = $root . '/baseline'; $candidate = $root . '/candidate'; $target = $root . '/target';
$rules = [
    ['path' => 'src/Existing.php', 'beforeSha256' => hash('sha256', "before\n"), 'afterSha256' => hash('sha256', "after\n")],
    ['path' => 'src/Added.php', 'beforeSha256' => hash('sha256', ''), 'afterSha256' => hash('sha256', "added\n"), 'added' => true],
];
try {
    foreach ([$baseline, $target] as $directory) { file_put_contents($directory . '/src/Existing.php', "before\n"); }
    file_put_contents($candidate . '/src/Existing.php', "after\n");
    file_put_contents($candidate . '/src/Added.php', "added\n");
    ensureSource(strlen(sdkVerifySource($baseline, $candidate, $rules, '', [])) === 64, 'Added source identity failed');
    sdkApplySourceRules($target, $candidate, $rules);
    ensureSource(sdkTree($target) === sdkTree($candidate), 'Approved additions were not copied exactly');
    echo "PASS absent official source and complete approved source copy\n";
    rejectSource(fn() => sdkApplySourceRules($target, $candidate, $rules), 'Patch input differs');
    echo "PASS already patched producer input is refused\n";
    foreach (['', "occupied\n"] as $existing) {
        file_put_contents($baseline . '/src/Added.php', $existing);
        rejectSource(fn() => sdkVerifySource($baseline, $candidate, $rules, '', []), 'must be absent');
        file_put_contents($target . '/src/Existing.php', "before\n");
        file_put_contents($target . '/src/Added.php', $existing);
        rejectSource(fn() => sdkApplySourceRules($target, $candidate, $rules), 'must be absent');
        ensureSource(file_get_contents($target . '/src/Existing.php') === "before\n"
            && file_get_contents($target . '/src/Added.php') === $existing, 'Later addition conflict changed earlier source');
    }
    unlink($baseline . '/src/Added.php'); unlink($target . '/src/Added.php');
    echo "PASS empty and occupied files are conflicts; full preflight preserves earlier source\n";
    file_put_contents($candidate . '/src/Added.php', "drifted\n");
    rejectSource(fn() => sdkVerifySource($baseline, $candidate, $rules, '', []), 'Unapproved whole-source drift');
    rejectSource(fn() => sdkApplySourceRules($target, $candidate, $rules), 'Approved candidate source differs');
    ensureSource(file_get_contents($target . '/src/Existing.php') === "before\n" && !file_exists($target . '/src/Added.php'), 'Candidate digest failure left partial source');
    echo "PASS added after digest drift refused before any source replacement\n";
    file_put_contents($candidate . '/src/Added.php', "added\n");
    foreach ([false, 'nonempty-before', 'prepared-before'] as $invalid) {
        $bad = $rules;
        if ($invalid === false) { $bad[1]['added'] = false; }
        elseif ($invalid === 'nonempty-before') { $bad[1]['beforeSha256'] = hash('sha256', 'not empty'); }
        else { $bad[1]['preparedBeforeSha256'] = hash('sha256', 'prepared'); }
        rejectSource(fn() => sdkVerifySource($baseline, $candidate, $bad, '', []), 'must be absent');
        rejectSource(fn() => sdkApplySourceRules($target, $candidate, $bad), 'must be absent');
    }
    symlink($candidate . '/src/Added.php', $target . '/src/Added.php');
    rejectSource(fn() => sdkApplySourceRules($target, $candidate, $rules), 'must be absent');
    echo 'PASS invalid addition identities and linked target refused; elapsed=' . round(microtime(true) - $started, 3) . "s\n";
} finally {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
        $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}
