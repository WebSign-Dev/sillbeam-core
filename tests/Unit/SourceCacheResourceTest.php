<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Routing\SourceDirectoriesResource;
use Sillbeam\Core\Routing\SourceFilesResource;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Component\Config\ConfigCache;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Config\Resource\SelfCheckingResourceChecker;

final class SourceCacheResourceTest extends TestCase
{
    public function testFileSetChecksChangesAndDeletionWithRestoredDirectoryMtime(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/A.php', 'final class A {}');
            $source->php('src/B.php', 'final class B {}');
            $paths = [$source->directory.'/src/A.php', $source->directory.'/src/B.php'];
            $cache = new ConfigCache($source->directory.'/cache.php', true);
            $cache->write('<?php return true;', [new SourceFilesResource($paths)]);

            $timestamp = time();
            foreach ($paths as $path) {
                touch($path, $timestamp - 10);
            }
            touch($cache->getPath(), $timestamp);
            clearstatcache();
            self::assertTrue($cache->isFresh());

            touch($paths[0], $timestamp + 10);
            touch($cache->getPath(), $timestamp + 1);
            clearstatcache();
            self::assertFalse($cache->isFresh());

            touch($paths[0], $timestamp - 10);
            unlink($paths[1]);
            touch(\dirname($paths[1]), $timestamp - 10);
            touch($cache->getPath(), $timestamp + 2);
            clearstatcache();
            self::assertFalse($cache->isFresh());
        } finally {
            $source->remove();
        }
    }

    public function testFileSetReusesNativeResourceDecisionsAtTheSameTimestamp(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/A.php', 'final class A {}');
            $source->php('src/B.php', 'final class B {}');
            $paths = [$source->directory.'/src/A.php', $source->directory.'/src/B.php'];
            $file = new FileResource($paths[0]);
            $batch = new SourceFilesResource($paths);
            $checker = new SelfCheckingResourceChecker();
            $timestamp = time();
            touch($paths[0], $timestamp + 10);
            clearstatcache();
            self::assertFalse($checker->isFresh($file, $timestamp));

            touch($paths[0], $timestamp - 10);
            clearstatcache();
            self::assertFalse($checker->isFresh($batch, $timestamp));
            self::assertTrue($checker->isFresh($batch, $timestamp + 1));
        } finally {
            $source->remove();
        }
    }

    public function testCompactionPreservesTheShortCircuitBeforeLaterFiles(): void
    {
        $source = new SourceWorkspace();
        try {
            $files = [];
            $timestamp = time();
            foreach (['A', 'B', 'C', 'D'] as $name) {
                $source->php('src/'.$name.'.php', 'final class '.$name.' {}');
                $path = $source->directory.'/src/'.$name.'.php';
                touch($path, $timestamp - 10);
                $files[] = new FileResource($path);
            }
            $pending = $source->directory.'/pending';
            $resources = [$files[0], $files[1], new FileExistenceResource($pending), $files[2], $files[3]];
            $cache = new ConfigCache($source->directory.'/cache.php', true);
            $cache->write('<?php return true;', iterator_to_array(SourceFilesResource::compact($resources)));
            touch($cache->getPath(), $timestamp);
            $source->write('pending', 'now present');
            clearstatcache();
            self::assertFalse($cache->isFresh());

            touch($files[2]->getResource(), $timestamp + 10);
            clearstatcache();
            self::assertFalse((new SelfCheckingResourceChecker())->isFresh($files[2], $timestamp));
        } finally {
            $source->remove();
        }
    }

    public function testCachedParentDoesNotReplaceAnUncheckedChildWalk(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/Service.php', 'final class Service {}');
            $path = $source->directory.'/src';
            $root = new DirectoryResource($path, '/\\.php$/');
            $child = new DirectoryResource($path.'/Module', '/\\.php$/');
            $timestamp = time() + 10;
            self::assertTrue((new SelfCheckingResourceChecker())->isFresh($root, $timestamp));

            touch($path.'/Module/Service.php', $timestamp + 1);
            clearstatcache();
            self::assertFalse((new SourceDirectoriesResource($root, [$child]))->isFresh($timestamp));
        } finally {
            $source->remove();
        }
    }

    public function testCoveredChildStillInvalidatesWhenRemovedOrReplacedWithASymlink(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Module/Service.php', 'final class Service {}');
            $path = $source->directory.'/src';
            $timestamp = time() + 10;
            $root = new DirectoryResource($path, '/\\.php$/');
            $child = new DirectoryResource($path.'/Module', '/\\.php$/');
            $resource = new SourceDirectoriesResource($root, [$child]);
            self::assertTrue($resource->isFresh($timestamp));

            $moved = $source->directory.'/Module';
            rename($path.'/Module', $moved);
            touch($path, $timestamp - 1);
            clearstatcache();
            self::assertFalse($resource->isFresh($timestamp + 1));

            if (!@symlink($moved, $path.'/Module')) {
                self::markTestSkipped('Directory symlinks are unavailable.');
            }
            touch($path, $timestamp - 1);
            touch($moved.'/Service.php', $timestamp + 3);
            clearstatcache(true);
            self::assertTrue($root->isFresh($timestamp + 2));
            self::assertFalse($resource->isFresh($timestamp + 2));
        } finally {
            $source->remove();
        }
    }
}
