<?php

declare(strict_types=1);

namespace Sillbeam\Core\Api;

/** @internal Before/after evidence for installation and configuration inputs. */
final readonly class SnapshotInputs
{
    /** @var array<string, string|false> */
    private array $before;

    public function __construct(private string $directory, private ?string $kernelFile)
    {
        $this->before = $this->observe();
    }

    public function changed(): bool
    {
        clearstatcache(true);

        return $this->before !== $this->observe();
    }

    /** @return array<string, string|false> */
    private function observe(): array
    {
        $paths = [$this->directory.'/composer.json', $this->directory.'/bin/console', $this->kernelFile ?? $this->directory.'/src/Kernel.php'];
        $config = $this->directory.'/config';
        if (is_dir($config) && is_readable($config)) {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($config, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
                \RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
            foreach ($entries as $entry) {
                if ($entry->isFile()) {
                    $paths[] = $entry->getPathname();
                }
            }
        }
        sort($paths, \SORT_STRING);
        $hashes = [];
        foreach ($paths as $path) {
            $hashes[$path] = is_file($path) ? @hash_file('sha256', $path) : false;
        }

        return $hashes;
    }
}
