<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Architecture\PersistentSourceIndex;
use Sillbeam\Core\Architecture\SourceInspector;
use Sillbeam\Core\Architecture\SourceInventoryEvidence;
use Sillbeam\Core\Tests\Support\SourceWorkspace;

final class PersistentSourceIndexTest extends TestCase
{
    public function testFreshInspectorsReuseOnlyMatchingContentsAndModes(): void
    {
        $source = new SourceWorkspace();
        try {
            $class = $source->php('src/Module/A.php', 'final class A {}');
            $path = $source->directory.'/src/Module/A.php';
            $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\');
            $inspect = static fn (?SourceInventoryEvidence $inventory = null): array => (new SourceInspector(
                inventory: $inventory,
                persistent: new PersistentSourceIndex($source->directory),
            ))->files($options);

            self::assertSame([$class], $inspect()[$path]->classes);
            $cache = glob($source->directory.'/var/sillbeam/source-index/*.json');
            self::assertIsArray($cache);
            self::assertCount(1, $cache);
            touch($cache[0], 123456789);
            self::assertSame([], $inspect()[$path]->symbols);
            self::assertSame(123456789, filemtime($cache[0]));
            self::assertSame([['name' => $class, 'type' => 'class']], $inspect(new SourceInventoryEvidence())[$path]->symbols);

            $mtime = filemtime($path);
            self::assertIsInt($mtime);
            $source->php('src/Module/A.php', 'final class B {}');
            touch($path, $mtime);
            $updated = $inspect();
            self::assertSame([$source->namespace.'\\Module\\B'], $updated[$path]->classes);
            file_put_contents($cache[0], '{bad');
            $recovered = $inspect();
            self::assertSame([$source->namespace.'\\Module\\B'], $recovered[$path]->classes);
            $source->php('src/Module/A.php', 'invalid PHP !!!');
            try {
                $inspect();
                self::fail('Invalid content must reach the parser.');
            } catch (\Sillbeam\Core\Architecture\ArchitectureValidationException $error) {
                self::assertSame('source.parse', $error->result->findings[0]->rule);
            }
            $source->php('src/Module/A.php', 'final class A {}');
            self::assertSame([$class], $inspect()[$path]->classes);
            $source->php('src/Module/C.php', 'final class C {}');
            $source->php('src/Module/D.php', 'final class D {}');
            self::assertCount(3, $inspect());
            rename($path, \dirname($path).'/Moved.php');
            $moved = $inspect();
            self::assertArrayNotHasKey($path, $moved);
            self::assertSame([$class], $moved[\dirname($path).'/Moved.php']->classes);
        } finally {
            $source->remove();
        }
    }

    public function testOptionsAndProjectsAreIsolated(): void
    {
        $source = new SourceWorkspace();
        $other = new SourceWorkspace();
        try {
            $source->php('src/Module/A.php', 'final class A {}');
            $root = $source->directory.'/src';
            $namespace = $source->namespace.'\\';
            $options = new DiscoveryOptions($root, $namespace);
            $index = new PersistentSourceIndex($source->directory);
            self::assertCount(1, (new SourceInspector(persistent: $index))->files($options));
            $excluded = new DiscoveryOptions($root, $namespace, excludes: ['Module']);
            self::assertSame([], (new SourceInspector(persistent: $index))->files($excluded));
            $local = glob($source->directory.'/var/sillbeam/source-index/*.json');
            self::assertIsArray($local);
            self::assertCount(1, $local);
            $otherIndex = new PersistentSourceIndex($other->directory);
            self::assertCount(1, (new SourceInspector(persistent: $otherIndex))->files($options));
            $separate = glob($other->directory.'/var/sillbeam/source-index/*.json');
            self::assertIsArray($separate);
            self::assertCount(1, $separate);
            self::assertNotSame(basename($local[0]), basename($separate[0]));
            self::assertNull($index->open(new DiscoveryOptions($source->directory, $namespace), false, false));
        } finally {
            $source->remove();
            $other->remove();
        }
    }

    public function testShortTagModeHasIndependentSnapshot(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('src/Module/A.php', '<? class A {}');
            $code = <<<'WORKER'
require $argv[1];
$options = new \Sillbeam\Core\Architecture\DiscoveryOptions($argv[2].'/src', 'Test\\');
$inspector = new \Sillbeam\Core\Architecture\SourceInspector(
    persistent: new \Sillbeam\Core\Architecture\PersistentSourceIndex($argv[2]),
);
echo json_encode($inspector->files($options)[$argv[2].'/src/Module/A.php']->classes);
WORKER;
            foreach ([['0', '[]'], ['1', '["A"]'], ['0', '[]']] as [$mode, $expected]) {
                $process = proc_open([\PHP_BINARY, '-d', 'short_open_tag='.$mode, '-r', $code, \dirname(__DIR__, 2).'/vendor/autoload.php', $source->directory], [1 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                self::assertSame($expected, stream_get_contents($pipes[1]));
                fclose($pipes[1]);
                self::assertSame(0, proc_close($process));
            }
        } finally {
            $source->remove();
        }
    }

    public function testBlockedCacheDirectoryFallsBackToParsing(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('var', 'not a directory');
            $class = $source->php('src/Module/A.php', 'final class A {}');
            $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\');
            $index = new PersistentSourceIndex($source->directory);
            self::assertNull($index->open($options, false, false));
            self::assertSame([$class], (new SourceInspector(persistent: $index))->files($options)[$source->directory.'/src/Module/A.php']->classes);
        } finally {
            $source->remove();
        }
    }

    public function testAnOldSnapshotCannotExhaustTheMemoryBudgetOfASmallScan(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Remaining.php', 'final class Remaining {}');
            $body = 'final class Generated { public function sample(): void {'.str_repeat('\Example\Reference::class;', 1000).'} }';
            for ($file = 0; $file < 90; ++$file) {
                $source->php('src/Generated/File'.$file.'.php', $body);
            }
            $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\');
            $persistent = new PersistentSourceIndex($source->directory);
            self::assertCount(91, (new SourceInspector(persistent: $persistent))->files($options));
            self::assertCount(1, glob($source->directory.'/var/sillbeam/source-index/*.json') ?: []);
            (new \Symfony\Component\Filesystem\Filesystem())->remove($source->directory.'/src/Generated');

            $code = <<<'WORKER'
require $argv[1];
$options = new \Sillbeam\Core\Architecture\DiscoveryOptions($argv[2].'/src', $argv[3].'\\');
$index = new \Sillbeam\Core\Architecture\PersistentSourceIndex($argv[2]);
echo count((new \Sillbeam\Core\Architecture\SourceInspector(persistent: $index))->files($options));
WORKER;
            $process = proc_open([\PHP_BINARY, '-d', 'memory_limit=32M', '-r', $code, \dirname(__DIR__, 2).'/vendor/autoload.php', $source->directory, $source->namespace], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            [$stdout, $stderr] = [stream_get_contents($pipes[1]), stream_get_contents($pipes[2])];
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame('1', $stdout);
        } finally {
            $source->remove();
        }
    }

    public function testConcurrentProcessesPublishCompleteSnapshots(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/A.php', 'final class A {}');
            $code = <<<'WORKER'
require $argv[1];
$options = new \Sillbeam\Core\Architecture\DiscoveryOptions($argv[2].'/src', $argv[3].'\\');
$index = new \Sillbeam\Core\Architecture\PersistentSourceIndex($argv[2]);
$files = (new \Sillbeam\Core\Architecture\SourceInspector(persistent: $index))->files($options);
echo count($files);
WORKER;
            $jobs = [];
            for ($i = 0; $i < 4; ++$i) {
                $process = proc_open([\PHP_BINARY, '-r', $code, \dirname(__DIR__, 2).'/vendor/autoload.php', $source->directory, $source->namespace], [1 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $jobs[] = [$process, $pipes[1]];
            }
            foreach ($jobs as [$process, $stdout]) {
                self::assertSame('1', stream_get_contents($stdout));
                fclose($stdout);
                self::assertSame(0, proc_close($process));
            }
            $cache = glob($source->directory.'/var/sillbeam/source-index/*.json');
            self::assertIsArray($cache);
            self::assertCount(1, $cache);
            $before = file_get_contents($cache[0]);
            self::assertIsString($before);
            $options = new DiscoveryOptions($source->directory.'/src', $source->namespace.'\\');
            $index = new PersistentSourceIndex($source->directory);
            self::assertCount(1, (new SourceInspector(persistent: $index))->files($options));
            self::assertSame($before, file_get_contents($cache[0]));
        } finally {
            $source->remove();
        }
    }
}
