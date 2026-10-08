<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use WebmanAotBuilder\Cli\ConfigurationException;

final class SaiAdminGeneratorOverlay
{
    /**
     * @return array{path:string,sha256:string}
     */
    public function prepare(
        string $sourceFile,
        string $expectedSourceSha256,
        string $expectedStubSha256,
        string $privateCache,
        ?string $mirror = null,
        array $carbonPolicy = [],
        bool $saiAdmin = true
    ): array {
        $source = is_file($sourceFile) && !is_link($sourceFile)
            ? file_get_contents($sourceFile)
            : false;
        if (!is_string($source)
            || !hash_equals($expectedSourceSha256, hash('sha256', $source))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator source drifted');
        }
        $overlay = $source;
        if ($saiAdmin) {
            $before = "                \$sourceRel === 'plugin/saiadmin/exception/SystemException.php',\n";
            $groupEnd = <<<'PHP'
                    $sourceRel === 'vendor/webman/console/src/Application.php',
                        => 1,
    PHP;
            $after = <<<'PHP'
                    $sourceRel === 'vendor/webman/console/src/Application.php',
                        => 1,
                    $sourceRel === 'plugin/saiadmin/exception/SystemException.php'
                        => match (true) {
                            substr_count($content, ', Throwable $previous = null)') === 1
                                && substr_count($content, ', ?Throwable $previous = null)') === 0 => 1,
                            substr_count($content, ', Throwable $previous = null)') === 0
                                && substr_count($content, ', ?Throwable $previous = null)') === 1 => 0,
                            default => -1,
                        },
    PHP;
            if (substr_count($source, $before) !== 1 || substr_count($source, $groupEnd) !== 1) {
                throw new ConfigurationException('locked SaiAdmin exception rule structure drifted');
            }
            $overlay = str_replace($groupEnd, $after, str_replace($before, '', $source));
            if ($mirror !== null) {
                $overlay = $this->applyCarbonSourceRules($overlay, $mirror);
            }
        }
        $patchEntry = <<<'PHP'
    protected function patchSwitchTerminals(string $sourceRel, string $content): string
    {
PHP;
        $intervalPatch = <<<'PHP'
        if ($sourceRel === 'vendor/nesbot/carbon/src/Carbon/CarbonInterval.php') {
            $content = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonIntervalRule())->transform($content);
        }
PHP;
        if (substr_count($overlay, $patchEntry) !== 1) {
            throw new ConfigurationException('locked Carbon interval patch entry structure drifted');
        }
        $overlay = str_replace($patchEntry, $patchEntry . "\n" . $intervalPatch, $overlay);
        $start = strpos($overlay, "        if (\$sourceRel === 'vendor/illuminate/support/functions.php') {");
        $end = strpos($overlay, "        if (\$sourceRel === 'vendor/cakephp/core/functions.php') {");
        if ($start === false || $end === false || $end <= $start) {
            throw new ConfigurationException('locked Illuminate interval rule structure drifted');
        }
        $intervalRule = <<<'PHP'
        if ($sourceRel === 'vendor/illuminate/support/functions.php') {
            return (new \WebmanAotBuilder\Compatibility\IlluminateIntervalRule())->transform($content);
        }
PHP;
        $overlay = substr_replace($overlay, $intervalRule . "\n", $start, $end - $start);
        $digest = hash('sha256', $overlay);
        $stubSource = dirname($sourceFile, 2) . '/Stubs/main.php.stub';
        $stub = is_file($stubSource) && !is_link($stubSource)
            ? file_get_contents($stubSource)
            : false;
        if (!is_string($stub)
            || !hash_equals($expectedStubSha256, hash('sha256', $stub))
        ) {
            throw new ConfigurationException('locked SaiAdmin generator stub drifted');
        }
        $directory = $privateCache . '/saiadmin-generator-overlays/' . $digest . '/src';
        $compilerDirectory = $directory . '/Compiler';
        $stubDirectory = $directory . '/Stubs';
        if ((!is_dir($compilerDirectory) && !mkdir($compilerDirectory, 0700, true)
                && !is_dir($compilerDirectory))
            || (!is_dir($stubDirectory) && !mkdir($stubDirectory, 0700, true)
                && !is_dir($stubDirectory))
        ) {
            throw new ConfigurationException('cannot create private SaiAdmin generator overlay directory');
        }
        $stubTarget = $stubDirectory . '/main.php.stub';
        if (is_file($stubTarget)) {
            if (is_link($stubTarget) || hash_file('sha256', $stubTarget) !== $expectedStubSha256) {
                throw new ConfigurationException('private SaiAdmin generator stub drifted');
            }
        } elseif (file_put_contents($stubTarget, $stub, LOCK_EX) !== strlen($stub)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator stub');
        }
        $target = $compilerDirectory . '/ProjectGenerator.php';
        if (is_file($target)) {
            if (is_link($target) || hash_file('sha256', $target) !== $digest) {
                throw new ConfigurationException('private SaiAdmin generator overlay drifted');
            }
        } elseif (file_put_contents($target, $overlay, LOCK_EX) !== strlen($overlay)) {
            throw new ConfigurationException('cannot write private SaiAdmin generator overlay');
        }
        return ['path' => $target, 'sha256' => $digest];
    }
    private function applyCarbonSourceRules(string $generator, string $mirror): string
    {
        $sourcePath = 'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php';
        $period = $this->readCarbonSource($mirror . '/' . $sourcePath);
        $interval = $this->readCarbonSource($mirror . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php');
        (new SaiAdminCarbonPeriodRule())->replacements($period, $interval);
        (new SaiAdminCarbonIntervalRule())->transform($interval);
        $anchor = "        '{$sourcePath}' => [\n";
        $start = strpos($generator, $anchor);
        $end = strpos($generator, "        'vendor/illuminate/database/Eloquent/Casts/ArrayObject.php' => [", $start ?: 0);
        if ($start === false || $end === false || $end <= $start) {
            throw new ConfigurationException('Carbon generator mapping structure drifted');
        }
        $mapping = substr($generator, $start, $end - $start);
        $oldIntervalRules = <<<'PHP'
            '\Carbon\CarbonInterval::day()' => 'new \Carbon\CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::day()' => 'new CarbonInterval(0, 0, 0, 1)',
            'CarbonInterval::month()' => 'new CarbonInterval(0, 1)',
PHP;
        if (substr_count($mapping, $oldIntervalRules) !== 1) {
            throw new ConfigurationException('locked Carbon interval mapping structure drifted');
        }
        $generator = substr_replace($generator, str_replace($oldIntervalRules . "\n", '', $mapping), $start, $end - $start);
        $method = <<<'PHP'
    protected function patchSwitchTerminals(string $sourceRel, string $content): string
    {
PHP;
        $rule = <<<'PHP'
        if ($sourceRel === 'vendor/nesbot/carbon/src/Carbon/CarbonPeriod.php') {
            $intervalPath = $this->basePath . '/vendor/nesbot/carbon/src/Carbon/CarbonInterval.php';
            $interval = is_file($intervalPath) && !is_link($intervalPath) ? file_get_contents($intervalPath) : false;
            if (!is_string($interval)) {
                throw new \WebmanAotBuilder\Cli\ConfigurationException('CarbonInterval compatibility source is missing');
            }
            $content = (new \WebmanAotBuilder\Compatibility\SaiAdminCarbonPeriodRule())->transform($content, $interval);
        }
PHP;
        if (substr_count($generator, $method) !== 1) {
            throw new ConfigurationException('locked Carbon patch entry structure drifted');
        }
        return str_replace($method, $method . "\n" . $rule, $generator);
    }

    private function readCarbonSource(string $path): string
    {
        $source = is_file($path) && !is_link($path) ? file_get_contents($path) : false;
        if (!is_string($source)) {
            throw new ConfigurationException('Carbon compatibility source is missing: ' . $path);
        }
        return $source;
    }
}
