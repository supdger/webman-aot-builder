<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

/**
 * Attribute a TypePHP project failure to the PHP sources that caused it.
 *
 * The compiler reports a native translation failure as a fatal diagnostic that
 * ends with the absolute input path, skips a file it cannot parse, or fails the
 * C++ compiler on a generated translation unit. Each of those shapes names the
 * originating PHP file, which lets the build demote exactly that file to the
 * interpreted tier instead of failing the whole project.
 */
final class CompilerFailureAttribution
{
    /**
     * Mirror-relative PHP sources named by a failed compiler run.
     *
     * @return list<array{path:string,reason:string}>
     */
    public function attribute(string $output, string $mirrorDirectory): array
    {
        $mirror = str_replace('\\', '/', rtrim($mirrorDirectory, '/\\'));
        $real = realpath($mirrorDirectory);
        $roots = [$mirror];
        if (is_string($real)) {
            $roots[] = str_replace('\\', '/', rtrim($real, '/\\'));
        }
        $found = [];
        foreach (explode("\n", $this->withoutAnsi($output)) as $line) {
            $line = rtrim($line, "\r");
            foreach ($this->candidates($line) as $candidate) {
                $path = $this->relative($candidate['path'], $roots);
                if ($path === null || isset($found[$path])) {
                    continue;
                }
                $found[$path] = ['path' => $path, 'reason' => $candidate['reason']];
            }
        }

        return array_values($found);
    }

    /**
     * @param list<string> $roots
     */
    private function relative(string $absolute, array $roots): ?string
    {
        $normalized = str_replace('\\', '/', $absolute);
        foreach ($roots as $root) {
            if ($root === '' || !str_starts_with($normalized, $root . '/')) {
                continue;
            }
            $relative = substr($normalized, strlen($root) + 1);
            // A generated translation unit lives under the build directory and
            // mirrors the input path, so strip the generated wrapper to recover
            // the PHP file that produced it.
            if (str_starts_with($relative, 'build/') && str_ends_with($relative, '.cc')) {
                $relative = substr($relative, strlen('build/'), -strlen('.cc'));
            }
            if (!str_ends_with(strtolower($relative), '.php')
                || $relative === ''
                || str_starts_with($relative, '/')
                || in_array('..', explode('/', $relative), true)
                || in_array('.', explode('/', $relative), true)
            ) {
                continue;
            }

            return $relative;
        }

        return null;
    }

    /**
     * @return list<array{path:string,reason:string}>
     */
    private function candidates(string $line): array
    {
        $candidates = [];
        // `Fatal error: <message> in <file>:<line>` is the shape produced by a
        // compiler diagnostic that knows its originating AST node.
        if (preg_match('~^Fatal error:\s*(.*?)\s+in\s+(\S.*?):(\d+)\s*$~D', $line, $match) === 1) {
            $candidates[] = [
                'path' => $match[2],
                'reason' => $this->reason($match[1]),
            ];
        }
        // A source the parser cannot accept is reported as a skip, which would
        // otherwise silently drop its declarations from the artifact.
        if (preg_match('~^\s*skip:\s*(\S.*)$~D', $line, $match) === 1) {
            $candidates[] = [
                'path' => $match[1],
                'reason' => 'unsupported syntax',
            ];
        }
        // The C++ compiler names the generated translation unit of the input.
        if (preg_match('~(\S+\.cc):\d+:\d+:\s*(?:fatal\s+)?error:\s*(.*)$~', $line, $match) === 1) {
            $candidates[] = [
                'path' => $match[1],
                'reason' => $this->reason('native compilation: ' . $match[2]),
            ];
        }

        return $candidates;
    }

    private function reason(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);
        if ($message === '') {
            return 'compilation failed';
        }

        return strlen($message) > 180 ? substr($message, 0, 177) . '...' : $message;
    }

    private function withoutAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;]*[A-Za-z]/', '', $text) ?? $text;
    }
}
