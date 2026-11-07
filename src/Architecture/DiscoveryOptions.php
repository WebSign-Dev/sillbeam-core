<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use Sillbeam\Core\I18n\CoreError;

/**
 * Immutable input paths, PSR-4 namespaces and exclusion patterns for architecture analysis.
 *
 * Paths are normalized without filesystem writes. Null module/Project roots select
 * conventional directories; empty lists explicitly disable those discovery areas.
 *
 * @api
 */
final readonly class DiscoveryOptions
{
    public string $root;

    /** @var array<string, true> */
    private array $requiredRoots;

    /** @var list<DiscoveryRoot> */
    public array $moduleRoots;
    /** @var list<DiscoveryRoot> */
    public array $projectRoots;

    /**
     * Normalizes roots and rejects conflicting namespaces for the same path.
     *
     * @param list<DiscoveryRoot>|null $moduleRoots        explicit module roots; null selects the application root plus /Module, an empty list disables shared-module roots
     * @param list<DiscoveryRoot>|null $projectRoots       explicit Project roots; null selects the application root plus /Project, an empty list disables Project roots
     * @param list<string>             $excludes           global absolute or application-root-relative glob-like exclusion patterns
     * @param list<string>             $controllerPatterns area-relative patterns used to discover controller directories
     * @param list<string>             $entityPatterns     area-relative patterns used to discover entity directories
     * @param list<DiscoveryRoot>      $applicationRoots   additional Composer namespace/path mappings for reference classification, not recursive scanning
     * @param list<string>             $serviceExcludes    area-relative patterns excluded from automatic service registration
     * @param string                   $root               application source root
     * @param string                   $namespace          PSR-4 namespace prefix for the application source root
     *
     * @throws \LogicException if the same discovery path has conflicting namespace mappings
     */
    public function __construct(
        string $root,
        public string $namespace,
        ?array $moduleRoots = null,
        ?array $projectRoots = null,
        public array $excludes = [],
        public array $controllerPatterns = ['Controller', '**/Controller'],
        public array $entityPatterns = ['Entity', '**/Entity'],
        public array $serviceExcludes = ['Entity/**', '**/Entity/**', 'Dto/**', '**/Dto/**', 'Tests/**', '**/Tests/**', 'Fixtures/**', '**/Fixtures/**', 'Migrations/**', '**/Migrations/**'],
        public array $applicationRoots = [],
    ) {
        $root = $this->root = FilesystemPath::normalize($root);
        $this->moduleRoots = $moduleRoots ?? [new DiscoveryRoot(FilesystemPath::directoryPrefix($root).'Module', $namespace.'Module\\')];
        $this->projectRoots = $projectRoots ?? [new DiscoveryRoot(FilesystemPath::directoryPrefix($root).'Project', $namespace.'Project\\')];
        $this->requiredRoots = array_fill_keys([$root, ...array_column($moduleRoots ?? [], 'path'), ...array_column($projectRoots ?? [], 'path')], true);
        $namespaces = [];
        foreach ($this->roots() as $discoveryRoot) {
            if (isset($namespaces[$discoveryRoot->path]) && $namespaces[$discoveryRoot->path] !== $discoveryRoot->namespace) {
                throw CoreError::create(\LogicException::class, 'error.discovery.conflict', ['path' => $discoveryRoot->path]);
            }
            $namespaces[$discoveryRoot->path] = $discoveryRoot->namespace;
        }
    }

    /**
     * Returns the application source root followed by module and Project roots.
     *
     * Additional applicationRoots classify referenced application types; they are not scan roots.
     *
     * @return list<DiscoveryRoot>
     */
    public function roots(): array
    {
        return [new DiscoveryRoot($this->root, $this->namespace), ...$this->moduleRoots, ...$this->projectRoots];
    }

    /** @internal Conventional module and Project directories are optional. */
    public function requiresRoot(string $path): bool
    {
        return $path === $this->root || isset($this->requiredRoots[$path]);
    }

    /**
     * Tests normalized absolute or application-relative paths and root-local exclusion patterns.
     */
    public function excluded(string $file): bool
    {
        $file = FilesystemPath::normalize($file);
        if (PathMatcher::matches($file, $this->excludes) || (str_starts_with($file, FilesystemPath::directoryPrefix($this->root)) && PathMatcher::matches(substr($file, \strlen(FilesystemPath::directoryPrefix($this->root))), $this->excludes))) {
            return true;
        }
        foreach ([...$this->moduleRoots, ...$this->projectRoots] as $root) {
            if ($root->contains($file) && PathMatcher::matches(substr($file, \strlen(FilesystemPath::directoryPrefix($root->path))), $root->excludes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Derives a namespace using the longest configured containing root.
     *
     * @throws \LogicException if no configured root contains the directory
     */
    public function namespaceForDirectory(string $directory): string
    {
        $directory = FilesystemPath::normalize($directory);
        $roots = $this->roots();
        usort($roots, static fn (DiscoveryRoot $a, DiscoveryRoot $b): int => \strlen($b->path) <=> \strlen($a->path));
        foreach ($roots as $root) {
            if ($root->contains($directory)) {
                $relative = trim(substr($directory, \strlen($root->path)), '/');

                return rtrim($root->namespace.('' === $relative ? '' : str_replace('/', '\\', $relative)), '\\');
            }
        }
        throw CoreError::create(\LogicException::class, 'error.discovery.no_namespace', ['path' => $directory]);
    }
}
