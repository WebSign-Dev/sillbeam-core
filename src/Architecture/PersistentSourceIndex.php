<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Composer\InstalledVersions;

/**
 * One replaceable snapshot of static parse results per project, discovery options and parse mode.
 *
 * @internal
 */
final class PersistentSourceIndex
{
    private const FORMAT = 1;
    private const MAX_BYTES = 32 * 1024 * 1024;

    public function __construct(private readonly string $projectDirectory)
    {
    }

    public function open(DiscoveryOptions $options, bool $inventory, bool $inventoryOnly): ?SourceIndexSnapshot
    {
        $project = realpath($this->projectDirectory);
        if (false === $project) {
            return null;
        }
        // Creating the cache must leave the source inventory unchanged.
        $var = $project.'/var';
        $storage = $var.'/sillbeam/source-index';
        $storagePrefix = FilesystemPath::directoryPrefix($storage);
        foreach ($options->roots() as $root) {
            $resolved = realpath($root->path);
            if ($root->contains($var) || str_starts_with(FilesystemPath::directoryPrefix($root->path), $storagePrefix)) {
                return null;
            }
            if (false !== $resolved && (str_starts_with($var.'/', FilesystemPath::directoryPrefix($resolved))
                || str_starts_with(FilesystemPath::directoryPrefix($resolved), $storagePrefix))) {
                return null;
            }
        }
        $directory = $project;
        foreach (['var', 'sillbeam', 'source-index'] as $part) {
            $directory .= '/'.$part;
            if (is_link($directory) || (!is_dir($directory) && !@mkdir($directory, 0700) && !is_dir($directory))) {
                return null;
            }
        }
        $permissions = @fileperms($directory);
        if (false === $permissions || 0 !== ($permissions & 0077)) {
            return null;
        }
        $key = hash('sha256', serialize([
            self::FORMAT, \PHP_VERSION, \ini_get('short_open_tag'), \ini_get('zend.multibyte'), \ini_get('zend.script_encoding'),
            InstalledVersions::getVersion('nikic/php-parser'),
            InstalledVersions::getReference('nikic/php-parser'), InstalledVersions::getReference('websign-dev/sillbeam-core'),
            hash_file('sha256', __FILE__), hash_file('sha256', __DIR__.'/SourceFileParser.php'),
            hash_file('sha256', __DIR__.'/SourceFile.php'), hash_file('sha256', __DIR__.'/SourceIndexSnapshot.php'),
            $project, $options, $inventory, $inventoryOnly,
        ]));

        return new SourceIndexSnapshot($directory.'/'.$key.'.json', self::MAX_BYTES);
    }
}
