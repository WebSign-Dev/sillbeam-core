<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class BenchmarkTest extends TestCase
{
    public function testPairedBenchmarkProducesComparableBuildAndHttpSamples(): void
    {
        $process = proc_open([\PHP_BINARY, \dirname(__DIR__, 2).'/tools/benchmark-architecture.php', '--smoke'], [\STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);
        $rows = array_map(static fn (string $line): array => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), explode("\n", trim($stdout)));
        self::assertSame('metadata', $rows[0]['type']);
        self::assertSame(['symfony', 'sillbeam'], array_column(\array_slice($rows, 1), 'variant'));
        foreach (\array_slice($rows, 1) as $row) {
            self::assertSame(2, $row['modules']);
            self::assertSame(2, $row['routes']);
            self::assertSame('ok:40', $row['response']);
            self::assertFalse($row['http_analyzer_loaded']);
            self::assertFalse($row['http_parser_loaded']);
            if ('symfony' === $row['variant']) {
                self::assertFalse($row['http_sillbeam_bundle_loaded']);
            }
            self::assertSame('prod', $row['environment']);
            foreach (['build_cold', 'build_rebuild', 'cache_clear', 'cache_warmup_cold', 'cache_warmup_warm', 'boot_warm', 'http_first', 'http_warm'] as $metric) {
                self::assertGreaterThan(0, $row['ms'][$metric]['p50']);
                self::assertGreaterThanOrEqual($row['ms'][$metric]['p50'], $row['ms'][$metric]['p95']);
                self::assertCount('http_warm' === $metric ? 5 : 1, $row['samples_ms'][$metric]);
            }
            $sillbeam = 'sillbeam' === $row['variant'];
            self::assertSame($sillbeam ? 2 : 0, $row['registry_modules']);
            self::assertSame(45, $row['source_php_files']);
            self::assertGreaterThan(0, $row['compiled_service_ids']);
            self::assertGreaterThan(0, $row['cache_bytes_after_build']);
            self::assertSame($sillbeam, $row['analysis_loaded']['build_cold']);
            self::assertSame($sillbeam, $row['analysis_loaded']['cache_clear']);
            self::assertFalse($row['analysis_loaded']['cache_warmup_warm']);
            self::assertFalse($row['analysis_loaded']['boot_warm']);
            if ($sillbeam) {
                self::assertTrue($row['analysis_loaded']['architecture']);
                self::assertGreaterThan(0, $row['ms']['architecture']['p50']);
            }
            self::assertGreaterThan(0, $row['peak_mib']['build_cold']);
            self::assertGreaterThan(0, $row['peak_mib']['http_warm']);
        }
    }
}
