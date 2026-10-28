<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class UpstreamMappingContract
{
    /** Derive each core output from the actual source using the verified generator's conversion contracts. */
    public function prepare(string $mirror, array $mappings): array
    {
        $generator = new \Tinywan\Typephp\Compiler\ProjectGenerator($mirror);
        $class = new \ReflectionClass($generator);
        foreach ($mappings as $path => &$mapping) {
            $file = $mirror . '/' . $path;
            $source = is_file($file) && !is_link($file) ? file_get_contents($file) : false;
            if (!is_string($source)) { throw new ConfigurationException('upstream conversion source is missing or unsafe: ' . $path); }
            $result = null;
            foreach (['GUARDED_SOURCES', 'NULLABLE_STATIC_SOURCES', 'VARIADIC_HANDLER_SOURCES', 'STRAY_BOOTSTRAP_SOURCES', 'SWITCH_TERMINAL_SOURCES', 'REF_CAPTURE_SOURCES'] as $group) {
                $declared = $class->getConstant($group);
                if (!is_array($declared) || !isset($declared[$path])) { continue; }
                if ($declared[$path] !== $mapping['shadow']) { throw new ConfigurationException('upstream conversion mapping contract drift: ' . $path); }
                $result = match ($group) {
                    'GUARDED_SOURCES' => $this->call($generator, 'flattenGuardedSource', [$this->call($generator, 'prepareGuardedSource', [$path, $source])]),
                    'NULLABLE_STATIC_SOURCES' => $this->call($generator, 'stripStrayBootstrapCalls', [$this->call($generator, 'patchUninitializedScalarStatics', [$source])]),
                    'VARIADIC_HANDLER_SOURCES' => $this->call($generator, 'patchVariadicHandlers', [$path, $source]),
                    'STRAY_BOOTSTRAP_SOURCES' => $this->stray($generator, $path, $source),
                    'SWITCH_TERMINAL_SOURCES' => $this->call($generator, 'patchSwitchTerminals', [$path, $source]),
                    'REF_CAPTURE_SOURCES' => $this->call($generator, 'patchRefCaptures', [$path, $source]),
                };
            }
            if (!is_string($result)) { throw new ConfigurationException('upstream conversion has no declared source contract: ' . $path); }
            $this->call($generator, 'assertAotCompatibleTopLevel', [$result]);
            try { token_get_all($result, TOKEN_PARSE); } catch (\ParseError $error) {
                throw new ConfigurationException('upstream conversion emitted invalid PHP: ' . $path, previous: $error);
            }
            $mapping['sourceSha256'] = hash('sha256', $source);
            $mapping['contractSha256'] = hash('sha256', $result);
        }
        unset($mapping);
        return $mappings;
    }

    private function stray(object $generator, string $path, string $source): string
    {
        $result = $this->call($generator, 'stripStrayBootstrapCalls', [$source]);
        return $path === 'vendor/workerman/coroutine/src/Context/Fiber.php'
            ? $this->call($generator, 'patchCoroutineFiberContextWrites', [$result]) : $result;
    }

    private function call(object $generator, string $method, array $arguments): mixed
    {
        return (new \ReflectionMethod($generator, $method))->invokeArgs($generator, $arguments);
    }
}
