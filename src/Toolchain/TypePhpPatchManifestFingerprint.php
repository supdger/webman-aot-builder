<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Toolchain;

use WebmanAotBuilder\Cli\ConfigurationException;

final class TypePhpPatchManifestFingerprint
{
    public function digest(string $path): string
    {
        $contents = is_file($path) && !is_link($path)
            ? file_get_contents($path)
            : false;
        if (!is_string($contents)) {
            throw new ConfigurationException('TypePHP patch manifest is missing or unsafe');
        }
        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException('TypePHP patch manifest is invalid', previous: $exception);
        }
        if (!is_array($manifest)
            || count($manifest) !== 4
            || $manifest['component'] !== 'typephp-source'
            || $manifest['version'] !== '0.9.5'
            || !is_array($manifest['rules'])
            || !array_is_list($manifest['rules'])
            || $manifest['rules'] === []
            || !is_string($manifest['reason'])
        ) {
            throw new ConfigurationException('TypePHP patch manifest shape drifted');
        }
        $rules = [];
        foreach ($manifest['rules'] as $rule) {
            $path = is_array($rule) ? ($rule['path'] ?? null) : null;
            if (!is_array($rule)
                || array_diff(array_keys($rule), ['path', 'beforeSha256', 'afterSha256', 'added', 'preparedBeforeSha256']) !== []
                || !is_string($path)
                || preg_match('~^(?:composer\.json|(?:src|vendor)/[A-Za-z0-9._/-]+)$~D', $path) !== 1
                || in_array('..', explode('/', $path), true)
                || isset($rules[$path])
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($rule['beforeSha256'] ?? '')) !== 1
                || preg_match('/^[a-f0-9]{64}$/D', (string) ($rule['afterSha256'] ?? '')) !== 1
                || (array_key_exists('added', $rule)
                    && ($rule['added'] !== true || $rule['beforeSha256'] !== hash('sha256', '')
                        || array_key_exists('preparedBeforeSha256', $rule)))
                || (array_key_exists('preparedBeforeSha256', $rule)
                    && (!is_string($rule['preparedBeforeSha256'])
                        || preg_match('/^[a-f0-9]{64}$/D', $rule['preparedBeforeSha256']) !== 1))
            ) {
                throw new ConfigurationException('TypePHP patch manifest rule drifted');
            }
            $rules[$path] = [
                'path' => $path,
                'beforeSha256' => $rule['beforeSha256'],
                'afterSha256' => $rule['afterSha256'],
            ];
            if (array_key_exists('added', $rule)) {
                $rules[$path]['added'] = true;
            }
            if (array_key_exists('preparedBeforeSha256', $rule)) {
                $rules[$path]['preparedBeforeSha256'] = $rule['preparedBeforeSha256'];
            }
        }
        ksort($rules, SORT_STRING);
        $canonical = [
            'component' => $manifest['component'],
            'version' => $manifest['version'],
            'rules' => array_values($rules),
            'reason' => $manifest['reason'],
        ];
        return hash(
            'sha256',
            json_encode($canonical, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
        );
    }
}
