<?php

declare(strict_types=1);

namespace Sillbeam\Core\Routing;

use Sillbeam\Core\Module\ModuleDefinition;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Project\ProjectRegistryInterface;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Config\Resource\DirectoryResource;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Loader\AttributeFileLoader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Imports native attribute routes once from active modules and Project-owned controllers.
 *
 * Project routes receive host constraints and module routes are deduplicated per graph.
 * Source and configuration resources participate in Symfony cache invalidation.
 *
 * @internal
 */
final class SillbeamRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param list<string>                $resources
     * @param list<string>                $configurationResources
     * @param array<string, list<string>> $controllerFiles
     */
    public function __construct(
        private readonly AttributeFileLoader $attributes,
        private readonly ?ModuleRegistryInterface $modules,
        private readonly ?ProjectRegistryInterface $projects,
        private readonly array $resources = [],
        private readonly array $controllerFiles = [],
        private readonly array $configurationResources = [],
    ) {
        parent::__construct();
    }

    /**
     * Imports controller files, applies Project host constraints and tracks cache resources.
     *
     * This loader is stateful and permits one load only; use the sillbeam resource type.
     *
     * @throws \LogicException if called more than once
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new \LogicException('The Sillbeam route loader can only be loaded once.');
        }
        $this->loaded = true;
        $routes = new RouteCollection();
        $this->addArchitectureRoutes($routes);
        foreach ($this->resources as $path) {
            $routes->addResource(is_dir($path) ? new DirectoryResource($path, '/\\.php$/') : (is_file($path) ? new FileResource($path) : new FileExistenceResource($path)));
        }

        foreach ($this->configurationResources as $path) {
            $routes->addResource(is_dir($path) ? new DirectoryResource($path) : (is_file($path) ? new FileResource($path) : new FileExistenceResource($path)));
        }

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'sillbeam' === $type;
    }

    private function addArchitectureRoutes(RouteCollection $routes): void
    {
        // Keep the configured import resolvable during cache warmup with integration deferred.
        if (null === $this->modules || null === $this->projects) {
            return;
        }
        if ([] === $this->projects->all()) {
            foreach ($this->modules->forApplication() as $module) {
                $this->addModule($routes, $module);
            }
        } else {
            foreach ($this->projects->all() as $project) {
                $collection = new RouteCollection();
                $this->addFiles($collection, $project->class);
                foreach ($this->modules->forProject($project->class) as $module) {
                    $this->addModule($collection, $module);
                }
                $collection->addNamePrefix($project->id.'.');
                if ([] !== $project->hosts) {
                    $collection->setHost('{_sillbeam_host}', ['_sillbeam_host' => $project->canonicalHost()], ['_sillbeam_host' => implode('|', array_map(static fn (string $host): string => preg_quote($host, '#'), $project->hosts))]);
                }
                $routes->addCollection($collection);
            }
        }
    }

    private function addModule(RouteCollection $routes, ModuleDefinition $module): void
    {
        $this->addFiles($routes, $module->class);
    }

    private function addFiles(RouteCollection $routes, string $class): void
    {
        foreach ($this->controllerFiles[$class] ?? [] as $file) {
            $collection = $this->attributes->load($file, 'attribute');
            if (null !== $collection) {
                $routes->addCollection($collection);
            }
        }
    }
}
