<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

final class NativeDownloader implements Downloader
{
    /**
     * @param (\Closure(string):void)|null $diagnostic
     */
    public function download(
        string $url,
        string $destination,
        ?\Closure $progress = null,
        ?\Closure $diagnostic = null
    ): void
    {
        $downloadUrl = self::sourceDownloadUrl($url);
        $this->downloadWithCurl($downloadUrl, $destination, $progress, $diagnostic);
    }

    public static function sourceDownloadUrl(string $url): string
    {
        if (preg_match(
            '~^https://github\.com/([^/]+)/([^/]+)/archive/refs/tags/([^/]+)\.tar\.gz$~D',
            $url,
            $matches
        ) === 1) {
            return "https://codeload.github.com/{$matches[1]}/{$matches[2]}/tar.gz/refs/tags/{$matches[3]}";
        }
        if (preg_match(
            '~^https://github\.com/([^/]+)/([^/]+)/archive/([^/]+)\.zip$~D',
            $url,
            $matches
        ) === 1) {
            return "https://codeload.github.com/{$matches[1]}/{$matches[2]}/zip/{$matches[3]}";
        }

        return $url;
    }

    /**
     * @param mixed $headers
     */
    private static function contentLength(mixed $headers): ?int
    {
        if (!is_array($headers)) {
            return null;
        }
        $length = null;
        $rangeTotal = null;
        foreach ($headers as $header) {
            if (!is_string($header)) {
                continue;
            }
            if (preg_match('/^HTTP\/\S+\s+\d{3}/i', $header) === 1) {
                $length = null;
                $rangeTotal = null;
            } elseif (preg_match('/^Content-Length:\s*(\d+)\s*$/i', $header, $matches) === 1) {
                $length = (int) $matches[1];
            } elseif (preg_match('/^Content-Range:\s*bytes\s+\d+-\d+\/(\d+)\s*$/i', $header, $matches) === 1) {
                $rangeTotal = (int) $matches[1];
            }
        }

        $total = $rangeTotal ?? $length;
        return $total !== null && $total > 0 ? $total : null;
    }

    /**
     * @param (\Closure(int,?int):void)|null $progress
     */
    private function downloadWithCurl(
        string $url,
        string $destination,
        ?\Closure $progress,
        ?\Closure $diagnostic,
        bool $allowRestart = true
    ): void
    {
        $systemRoot = getenv('SystemRoot');
        $curl = PHP_OS_FAMILY === 'Windows' && is_string($systemRoot)
            ? str_replace('\\', '/', $systemRoot) . '/System32/curl.exe'
            : (PHP_OS_FAMILY === 'Darwin' ? '/usr/bin/curl' : '');
        $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        if (!str_starts_with($url, 'https://') || !is_file($curl)) {
            throw new \RuntimeException("secure host downloader is unavailable: {$url}");
        }
        clearstatcache(true, $destination);
        $resume = $allowRestart && is_file($destination) && filesize($destination) > 0;
        $errorPath = $destination . '.curl-stderr-' . bin2hex(random_bytes(6));
        $headersPath = $destination . '.curl-headers-' . bin2hex(random_bytes(6));
        $command = [
            $curl,
            '-q',
            '--fail',
            '--location',
            '--no-progress-meter',
            '--show-error',
            '--retry', $resume ? '1' : '5',
            '--retry-delay', '2',
            '--retry-all-errors',
        ];
        if ($resume) {
            array_push($command, '--continue-at', '-');
        }
        array_push($command,
            '--connect-timeout', '15',
            '--speed-limit', '1024',
            '--speed-time', '120',
            '--proto', '=https',
            '--proto-redir', '=https',
            '--dump-header', $headersPath,
            '--output', $destination,
            $url
        );
        $process = proc_open(
            $command,
            [
                0 => ['file', $nullDevice, 'r'],
                1 => ['file', $nullDevice, 'w'],
                2 => ['file', $errorPath, 'w'],
            ],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            if (is_file($errorPath)) {
                unlink($errorPath);
            }
            if (is_file($headersPath)) {
                unlink($headersPath);
            }
            throw new \RuntimeException("unable to start secure host downloader: {$url}");
        }
        $errorOffset = 0;
        $pendingError = '';
        $reportErrors = static function (bool $final = false) use (
            $errorPath,
            $diagnostic,
            &$errorOffset,
            &$pendingError
        ): void {
            $chunk = @file_get_contents($errorPath, false, null, $errorOffset);
            if (is_string($chunk) && $chunk !== '') {
                $errorOffset += strlen($chunk);
                $lines = explode("\n", $pendingError . $chunk);
                $pendingError = array_pop($lines);
                foreach ($lines as $line) {
                    $line = rtrim($line, "\r");
                    if ($line !== '') {
                        if ($diagnostic !== null) {
                            $diagnostic($line);
                        } else {
                            fwrite(STDERR, $line . "\n");
                        }
                    }
                }
            }
            if ($final && $pendingError !== '') {
                if ($diagnostic !== null) {
                    $diagnostic($pendingError);
                } else {
                    fwrite(STDERR, $pendingError . "\n");
                }
            }
        };
        try {
            $exit = null;
            clearstatcache(true, $destination);
            $progress?->__invoke(is_file($destination) ? (int) filesize($destination) : 0, null);
            $nextReport = microtime(true) + 5;
            while (true) {
                $status = proc_get_status($process);
                $reportErrors();
                if (!$status['running']) {
                    $exit = $status['exitcode'];
                    break;
                }
                if (microtime(true) >= $nextReport) {
                    clearstatcache(true, $destination);
                    $bytes = is_file($destination) ? filesize($destination) : 0;
                    $downloaded = is_int($bytes) ? $bytes : 0;
                    if ($progress !== null) {
                        $headers = @file($headersPath, FILE_IGNORE_NEW_LINES);
                        $progress($downloaded, self::contentLength($headers));
                    } else {
                        fwrite(STDERR, sprintf(
                            "[download] %.1f MiB received; still downloading\n",
                            $downloaded / 1048576
                        ));
                    }
                    $nextReport = microtime(true) + 5;
                }
                usleep(200000);
            }
            $closed = proc_close($process);
            $reportErrors(true);
            $exitCode = $exit >= 0 ? $exit : $closed;
            $errors = @file_get_contents($errorPath);
            $rangeUnsupported = is_string($errors)
                && str_contains(strtolower($errors), 'support byte ranges');
            if ($resume && ($exitCode === 33 || $rangeUnsupported)) {
                if (!unlink($destination)) {
                    throw new \RuntimeException("unable to restart unsupported ranged download: {$url}");
                }
                $message = 'Source does not support resuming; retrying from start.';
                if ($diagnostic !== null) {
                    $diagnostic($message);
                } else {
                    fwrite(STDERR, $message . PHP_EOL);
                }
                $this->downloadWithCurl($url, $destination, $progress, $diagnostic, false);
                return;
            }
            if ($exitCode !== 0) {
                throw new \RuntimeException(
                    "unable to download locked component: {$url} (curl exit code {$exitCode})"
                );
            }
            clearstatcache(true, $destination);
            $progress?->__invoke((int) filesize($destination), self::contentLength(@file($headersPath, FILE_IGNORE_NEW_LINES)));
        } finally {
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process);
                }
                proc_close($process);
            }
            if (is_file($errorPath)) {
                unlink($errorPath);
            }
            if (is_file($headersPath)) {
                unlink($headersPath);
            }
        }
    }
}
