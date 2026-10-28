<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Project;

use WebmanAotBuilder\Cli\ConfigurationException;

final class PathLiteralSanitizer
{
    public function __construct(private readonly string $projectRoot)
    {
    }

    /**
     * Rewrite mirror PHP sources so hardcoded absolute project paths resolve
     * against the deployed application root instead of the build machine.
     * Only the isolated mirror is touched; the user's files are never modified.
     *
     * @param list<string> $phpFiles project-relative PHP paths present in the mirror
     * @return list<string> rewritten relative paths
     */
    public function sanitize(string $mirror, array $phpFiles): array
    {
        $root = rtrim(str_replace('\\', '/', $this->projectRoot), '/');
        $rewritten = [];
        foreach ($phpFiles as $relative) {
            $file = $mirror . '/' . $relative;
            $source = is_file($file) && !is_link($file) ? file_get_contents($file) : false;
            if (!is_string($source) || !str_contains($source, $root)) {
                continue;
            }
            $output = '';
            $changed = false;
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING
                    || !str_contains($token[1], $root)
                ) {
                    $output .= is_array($token) ? $token[1] : $token;
                    continue;
                }
                $quote = $token[1][0];
                $inner = stripcslashes(substr($token[1], 1, -1));
                if ($quote !== "'" || !str_starts_with($inner, $root)) {
                    $output .= $token[1];
                    continue;
                }
                $rest = substr($inner, strlen($root));
                if ($rest === '' || str_starts_with($rest, '/')) {
                    $literal = "'" . addcslashes($rest, "\\'") . "'";
                    $output .= str_starts_with($rest, '/runtime/')
                        ? "runtime_path() . '" . addcslashes(substr($rest, strlen('/runtime')), "\\'") . "'"
                        : "base_path() . {$literal}";
                    $changed = true;
                } else {
                    $output .= $token[1];
                }
            }
            if (!$changed) {
                continue;
            }
            if (file_put_contents($file, $output, LOCK_EX) === false) {
                throw new ConfigurationException("cannot rewrite project path literal: {$relative}");
            }
            $rewritten[] = $relative;
        }
        return $rewritten;
    }
}
