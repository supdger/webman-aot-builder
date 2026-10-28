<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminProfileOverlay
{
    /** Legacy upstream profile entrypoint; project identities are not compilation gates. */
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $projectLockFile,
        string $privateCache
    ): string {
        $source = is_file($sourceFile) && !is_link($sourceFile) ? file_get_contents($sourceFile) : false;
        if (!is_string($source) || !hash_equals($expectedSourceSha256, hash('sha256', $source))) {
            throw new ConfigurationException('locked upstream profile source drifted');
        }
        $tokens = token_get_all($source);
        $offset = 0;
        $function = false;
        $target = false;
        $start = null;
        $depth = 0;
        $end = null;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_FUNCTION) {
                $function = true;
            } elseif ($function && is_array($token) && $token[0] === T_STRING) {
                $target = $token[1] === 'assertSupported';
                $function = false;
            }
            if ($target && ($token === '{'
                    || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)))
            ) {
                if ($start === null) {
                    $start = $offset + 1;
                }
                ++$depth;
            } elseif ($target && $token === '}' && $start !== null && --$depth === 0) {
                $end = $offset;
                break;
            }
            $offset += strlen($text);
        }
        if ($start === null || $end === null) {
            throw new ConfigurationException('locked upstream profile method structure drifted');
        }
        $shadow = substr($source, 0, $start) . "\n        return [];\n    " . substr($source, $end);
        token_get_all($shadow, TOKEN_PARSE);
        $directory = $privateCache . '/php-profile-overlays';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new ConfigurationException('cannot create private PHP profile overlay directory');
        }
        $digest = hash('sha256', $shadow);
        $target = $directory . '/' . $digest . '.php';
        if (is_link($target) || (is_file($target) && hash_file('sha256', $target) !== $digest)) {
            throw new ConfigurationException('private PHP profile overlay drifted');
        }
        if (!is_file($target) && file_put_contents($target, $shadow, LOCK_EX) !== strlen($shadow)) {
            throw new ConfigurationException('cannot write private PHP profile overlay');
        }
        return $target;
    }
}
