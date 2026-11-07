<?php

declare(strict_types=1);

namespace Sillbeam\Core\Console\Doctor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Sillbeam\Core\Architecture\DiscoveryOptionsResolver;
use Sillbeam\Core\DependencyInjection\Configuration;
use Sillbeam\Core\I18n\CoreError;
use Sillbeam\Core\I18n\CoreTranslator;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Yaml\Yaml;

/**
 * Static evidence only. Never includes application PHP or prepares a kernel.
 *
 * @internal
 */
final class InstallationInspector
{
    /** @return list<array{id: string, label: string, status: string, detail: string}> */
    public function inspect(string $directory, string $environment, ?string $kernelFile = null, CoreTranslator $translator = new CoreTranslator()): array
    {
        $checks = [];
        $add = static function (string $id, string $label, string $status, string $detail = '') use (&$checks): void {
            $checks[] = compact('id', 'label', 'status', 'detail');
        };
        foreach (['bundle.registration', 'console.adapter'] as $id) {
            $label = $translator->text('doctor.check.'.$id);
            try {
                $nodes = $this->php($directory.('bundle.registration' === $id ? '/config/bundles.php' : '/bin/console'), $translator);
                if ('bundle.registration' === $id) {
                    if (1 !== \count($nodes) || !$nodes[0] instanceof Node\Stmt\Return_ || !$nodes[0]->expr instanceof Node\Expr\Array_) {
                        throw new \RuntimeException($translator->text('doctor.bundle.dynamic'));
                    }
                    $enabled = false;
                    foreach ($nodes[0]->expr->items as $item) {
                        if (null === $item || !$item->key instanceof Node\Expr\ClassConstFetch || !$item->key->class instanceof Node\Name || !$item->key->name instanceof Node\Identifier || 'class' !== strtolower($item->key->name->toString()) || $item->unpack || !$item->value instanceof Node\Expr\Array_) {
                            throw new \RuntimeException($translator->text('doctor.bundle.non_literal'));
                        }
                        $flags = [];
                        foreach ($item->value->items as $flag) {
                            if (null === $flag || !$flag->key instanceof Node\Scalar\String_ || !$flag->value instanceof Node\Expr\ConstFetch || !\in_array(strtolower($flag->value->name->toString()), ['true', 'false'], true)) {
                                throw new \RuntimeException($translator->text('doctor.bundle.dynamic_flag'));
                            }
                            $flags[$flag->key->value] = 'true' === strtolower($flag->value->name->toString());
                        }
                        if ('Sillbeam\\Core\\SillbeamCoreBundle' === $item->key->class->toString()) {
                            $enabled = $flags[$environment] ?? $flags['all'] ?? false;
                        }
                    }
                    $add($id, $label, $enabled ? 'verified' : 'action required', $enabled ? '' : $translator->text('doctor.bundle.enable', ['environment' => $environment]));
                } else {
                    $adapter = $this->consoleAdapter($nodes);
                    if ('Sillbeam\\Core\\Console\\Application' === $adapter) {
                        $add($id, $label, 'verified');
                    } elseif ('Symfony\\Bundle\\FrameworkBundle\\Console\\Application' === $adapter) {
                        $add($id, $label, 'action required', $translator->text('doctor.console.replace'));
                    } else {
                        throw new \RuntimeException($translator->text('doctor.console.ambiguous'));
                    }
                }
            } catch (\Throwable $error) {
                $add($id, $label, 'not verified', $translator->text('doctor.configuration.review_file', ['error' => CoreError::render($error, $translator)]));
            }
        }
        $kernelKnown = false;
        try {
            $nodes = $this->php($kernelFile ?? $directory.'/src/Kernel.php', $translator);
            if (!$this->standardKernel($nodes)) {
                throw new \RuntimeException($translator->text('doctor.configuration.kernel_unknown'));
            }
            $kernelKnown = true;
            $add('configuration.loading', $translator->text('doctor.check.configuration.loading'), 'verified');
        } catch (\Throwable $error) {
            $add('configuration.loading', $translator->text('doctor.check.configuration.loading'), 'not verified', $translator->text('doctor.configuration.review_kernel', ['error' => CoreError::render($error, $translator)]));
        }
        $documents = [];
        $unknown = ['route' => !$kernelKnown, 'discovery' => !$kernelKnown, 'service' => !$kernelKnown];
        try {
            $configDir = $directory.'/config';
            if (!is_dir($configDir)) {
                throw new \RuntimeException($translator->text('doctor.configuration.missing'));
            }
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($configDir, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = $file->getPathname();
                $relative = substr($path, \strlen($configDir) + 1);
                if ($file->isLink() && $file->isDir() && preg_match('~^(?:packages|routes)(?:/'.preg_quote($environment, '~').')?$~D', $relative)) {
                    if (str_starts_with($relative, 'routes')) {
                        $unknown['route'] = true;
                    } else {
                        $unknown['discovery'] = $unknown['service'] = true;
                    }
                }
                $serviceExtension = is_file($configDir.'/services.yaml') ? 'yaml' : 'php';
                $routeExtension = is_file($configDir.'/routes.yaml') ? 'yaml' : 'php';
                $selected = \in_array($relative, ['services.'.$serviceExtension, 'services_'.$environment.'.'.$serviceExtension, 'routes.'.$routeExtension], true)
                    || 1 === preg_match('~^(?:packages|routes)/(?:'.preg_quote($environment, '~').'/)?[^/]+\\.(?:yaml|php)$~D', $relative);
                if (!$selected) {
                    continue;
                }
                $routing = str_starts_with($relative, 'routes/') || str_starts_with($relative, 'routes.');
                if ($file->isLink() || !$file->isFile()) {
                    $this->markUnknown($unknown, $routing);
                    continue;
                }
                if (!\in_array($file->getExtension(), ['yaml', 'yml'], true)) {
                    $this->markUnknown($unknown, $routing);
                    continue;
                }
                try {
                    $data = Yaml::parse($this->read($path, $translator));
                    if (null === $data) {
                        $data = [];
                    }
                    if (!\is_array($data)) {
                        throw new \RuntimeException($translator->text('doctor.configuration.yaml_mapping', ['path' => $relative]));
                    }
                    foreach ($data as $key => $overlay) {
                        if (\is_string($key) && str_starts_with($key, 'when@')) {
                            unset($data[$key]);
                            if ('when@'.$environment === $key) {
                                // Avoid guessing Symfony merge/override order.
                                $this->markUnknown($unknown, $routing);
                            }
                        }
                    }
                    if (isset($data['services']) && !\is_array($data['services'])) {
                        throw new \RuntimeException($translator->text('doctor.configuration.services_mapping', ['path' => $relative]));
                    }
                    if (isset($data['imports'])) {
                        $this->markUnknown($unknown, $routing);
                    }
                    $documents[$path] = $data;
                } catch (\Throwable $error) {
                    $this->markUnknown($unknown, $routing);
                    $add('configuration.files', $translator->text('doctor.check.configuration.files'), 'not verified', $translator->text('doctor.configuration.review_named', ['file' => $relative, 'error' => CoreError::render($error, $translator)]));
                }
            }
        } catch (\Throwable $error) {
            $unknown = ['route' => true, 'discovery' => true, 'service' => true];
            $add('configuration.files', $translator->text('doctor.check.configuration.files'), 'not verified', $translator->text('doctor.configuration.review', ['error' => CoreError::render($error, $translator)]));
        }
        $routeImports = [];
        $routeUnknown = $unknown['route'];
        $routeNames = [];
        foreach ($documents as $path => $data) {
            if (!preg_match('~/config/routes(?:/|\.ya?ml$)~', $path)) {
                continue;
            }
            foreach ($data as $name => $entry) {
                if (isset($routeNames[$name])) {
                    $routeUnknown = true;
                }
                $routeNames[$name] = true;
                if (!\is_array($entry)) {
                    $routeUnknown = true;
                } elseif ('sillbeam' === ($entry['type'] ?? null)) {
                    // The loader ignores resource; require an import rather than a route definition.
                    if (isset($entry['resource']) && !isset($entry['path']) && ['resource', 'type'] === array_values(array_intersect(['resource', 'type'], array_keys($entry))) && 2 === \count($entry)) {
                        $routeImports[] = substr($path, \strlen($directory) + 1).': '.$name;
                    } else {
                        $routeUnknown = true;
                    }
                } elseif (isset($entry['resource']) && 'attribute' !== ($entry['type'] ?? null)) {
                    $routeUnknown = true;
                }
            }
        }
        $routeDetail = match (true) {
            $routeUnknown => $translator->text('doctor.routing.unknown'),
            [] === $routeImports => $translator->text('doctor.routing.add'),
            \count($routeImports) > 1 => $translator->text('doctor.routing.duplicate', ['imports' => implode(', ', $routeImports)]),
            default => '',
        };
        $add('routing.import', $translator->text('doctor.check.routing.import'), $routeUnknown ? 'not verified' : (1 === \count($routeImports) ? 'verified' : 'action required'), $routeDetail);
        $options = null;
        try {
            if ($unknown['discovery']) {
                throw new \RuntimeException($translator->text('doctor.discovery.dynamic'));
            }
            $configs = [];
            $discoveryDeclarations = 0;
            foreach ($documents as $path => $data) {
                if (!preg_match('~/config/routes(?:/|\.yaml$)~', $path) && isset($data['sillbeam'])) {
                    $configs[] = $data['sillbeam'];
                    if (isset($data['sillbeam']['discovery']) && ++$discoveryDeclarations > 1) {
                        throw new \RuntimeException($translator->text('doctor.discovery.merge_order'));
                    }
                }
            }
            $config = (new Processor())->processConfiguration(new Configuration(), $configs);
            if (preg_match('/%[^%]+%/', str_replace('%kernel.project_dir%', $directory, json_encode($config, \JSON_THROW_ON_ERROR)))) {
                throw new \RuntimeException($translator->text('doctor.discovery.parameters'));
            }
            $container = new ContainerBuilder();
            $container->setParameter('kernel.project_dir', $directory);
            $options = (new DiscoveryOptionsResolver())->resolve($container, $configs, $config);
            $add('discovery.configuration', $translator->text('doctor.check.discovery.configuration'), 'verified');
        } catch (\Throwable $error) {
            $add('discovery.configuration', $translator->text('doctor.check.discovery.configuration'), 'not verified', $translator->text('doctor.discovery.review', ['error' => CoreError::render($error, $translator)]));
        }
        $serviceUnknown = $unknown['service'] || null === $options;
        $overlaps = [];
        $serviceIds = [];
        foreach ($documents as $path => $data) {
            if (preg_match('~/config/routes(?:/|\.yaml$)~', $path)) {
                continue;
            }
            foreach (array_keys($data['services'] ?? []) as $id) {
                $serviceIds[$id] = ($serviceIds[$id] ?? 0) + 1;
            }
        }
        foreach ($documents as $path => $data) {
            if (preg_match('~/config/routes(?:/|\.yaml$)~', $path)) {
                continue;
            }
            foreach ($data['services'] ?? [] as $id => $service) {
                if ($serviceIds[$id] > 1 && !str_starts_with((string) $id, '_')) {
                    $serviceUnknown = true;
                    continue;
                }
                if (!\is_array($service) || !isset($service['resource'])) {
                    if (!str_starts_with((string) $id, '_')) {
                        $serviceUnknown = true;
                    }
                    continue;
                }
                $resource = $this->directory($service['resource'], \dirname($path), $directory);
                if (null === $resource) {
                    $serviceUnknown = true;
                    continue;
                }
                $excludes = [];
                $excludeUnknown = false;
                foreach ((array) ($service['exclude'] ?? []) as $exclude) {
                    $excludePaths = $this->exclusionDirectories($exclude, \dirname($path), $directory);
                    if (null === $excludePaths) {
                        $excludeUnknown = $serviceUnknown = true;
                    } else {
                        $excludes = [...$excludes, ...$excludePaths];
                    }
                }
                foreach (null === $options ? [] : [...$options->moduleRoots, ...$options->projectRoots] as $root) {
                    if (!str_starts_with($root->path.'/', rtrim($resource, '/').'/') && !str_starts_with(rtrim($resource, '/').'/', $root->path.'/')) {
                        continue;
                    }
                    $excluded = false;
                    foreach ($excludes as $excludePath) {
                        if (str_starts_with($root->path.'/', rtrim($excludePath, '/').'/') || str_starts_with(rtrim($resource, '/').'/', rtrim($excludePath, '/').'/')) {
                            $excluded = true;
                        }
                    }
                    if (!$excluded && !$excludeUnknown) {
                        $key = substr($path, \strlen($directory) + 1).': '.$id;
                        $overlaps[$key][] = str_starts_with($root->path, rtrim($directory, '/').'/')
                            ? '%kernel.project_dir%'.substr($root->path, \strlen(rtrim($directory, '/'))).'/'
                            : rtrim($root->path, '/').'/';
                    }
                }
            }
        }
        if ([] !== $overlaps) {
            foreach ($overlaps as $import => $areas) {
                $add('services.imports', $translator->text('doctor.check.services.imports'), 'action required', $translator->text('doctor.services.overlap', ['import' => $import, 'areas' => implode(', ', array_unique($areas))]).($serviceUnknown ? $translator->text('doctor.services.overrides') : ''));
            }
        }
        if ($serviceUnknown) {
            $add('services.imports', $translator->text('doctor.check.services.imports'), 'not verified', $translator->text('doctor.services.unknown'));
        } elseif ([] === $overlaps) {
            $add('services.imports', $translator->text('doctor.check.services.imports'), 'verified');
        }

        return $checks;
    }

    /** @param array<Node> $nodes */
    private function consoleAdapter(array $nodes): ?string
    {
        $last = array_pop($nodes);
        if ($last instanceof Node\Stmt\Return_ && $last->expr instanceof Node\Expr\Closure && !$last->expr->byRef && [] === $last->expr->uses) {
            foreach ($nodes as $node) {
                if (!$this->bootstrapStatement($node)) {
                    return null;
                }
            }
            $body = $last->expr->stmts;
            $return = array_pop($body);
            if (!$return instanceof Node\Stmt\Return_) {
                return null;
            }
            $assignment = null;
            foreach ($body as $node) {
                if ($node instanceof Node\Stmt\Nop) {
                    continue;
                }
                if (!$node instanceof Node\Stmt\Expression || !$node->expr instanceof Node\Expr\Assign || !$node->expr->var instanceof Node\Expr\Variable || !\is_string($node->expr->var->name) || !$node->expr->expr instanceof Node\Expr\New_ || !$node->expr->expr->class instanceof Node\Name) {
                    return null;
                }
                $assignment = $node->expr;
            }
            $application = $return->expr;
            if ($application instanceof Node\Expr\Variable && \is_string($application->name)) {
                // Only a final assignment proves the returned variable: later
                // constructors could overwrite it, including via reference arguments.
                $application = null !== $assignment && $assignment->var instanceof Node\Expr\Variable && $application->name === $assignment->var->name ? $assignment->expr : null;
            }

            return $application instanceof Node\Expr\New_ && $application->class instanceof Node\Name ? $application->class->toString() : null;
        }
        // The conventional final assignment/run pair remains supported.
        $assignment = array_pop($nodes);
        if (!$last instanceof Node\Stmt\Expression || !$last->expr instanceof Node\Expr\MethodCall || !$last->expr->name instanceof Node\Identifier || 'run' !== $last->expr->name->toString() || !$last->expr->var instanceof Node\Expr\Variable
            || !$assignment instanceof Node\Stmt\Expression || !$assignment->expr instanceof Node\Expr\Assign || !$assignment->expr->var instanceof Node\Expr\Variable || !$assignment->expr->expr instanceof Node\Expr\New_ || !$assignment->expr->expr->class instanceof Node\Name
            || !\is_string($last->expr->var->name) || $last->expr->var->name !== $assignment->expr->var->name) {
            return null;
        }
        foreach ($nodes as $node) {
            if (!$this->bootstrapStatement($node) && !($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Assign)) {
                return null;
            }
        }

        return $assignment->expr->expr->class->toString();
    }

    private function bootstrapStatement(Node $node): bool
    {
        if ($node instanceof Node\Stmt\If_ && null === $node->else && [] === $node->elseifs && 1 === \count($node->stmts)
            && $node->stmts[0] instanceof Node\Stmt\Expression && $node->stmts[0]->expr instanceof Node\Expr\Throw_
            && $node->stmts[0]->expr->expr instanceof Node\Expr\New_ && $node->stmts[0]->expr->expr->class instanceof Node\Name
            && 'LogicException' === $node->stmts[0]->expr->expr->class->toString()
            && $node->cond instanceof Node\Expr\BooleanNot && $node->cond->expr instanceof Node\Expr\FuncCall
            && $node->cond->expr->name instanceof Node\Name && \in_array($node->cond->expr->name->toString(), ['is_dir', 'is_file'], true)
            && 1 === \count($node->cond->expr->args)) {
            $argument = $node->cond->expr->args[0];
            if (!$argument instanceof Node\Arg || !$argument->value instanceof Node\Expr\BinaryOp\Concat) {
                return false;
            }
            $path = $argument->value;

            return $path->left instanceof Node\Expr\FuncCall && $path->left->name instanceof Node\Name && 'dirname' === $path->left->name->toString()
                && 1 === \count($path->left->args) && $path->left->args[0] instanceof Node\Arg && $path->left->args[0]->value instanceof Node\Scalar\MagicConst\Dir
                && $path->right instanceof Node\Scalar\String_ && \in_array($path->right->value, ['/vendor', '/vendor/autoload_runtime.php'], true);
        }

        return ($node instanceof Node\Stmt\InlineHTML && 0 === $node->getStartFilePos() && 1 === preg_match('/^#![^\r\n]+\r?\n$/D', $node->value))
            || $node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse || $node instanceof Node\Stmt\Declare_ || $node instanceof Node\Stmt\Nop
            || ($node instanceof Node\Stmt\Expression && $node->expr instanceof Node\Expr\Include_);
    }

    /** @param array<Node> $nodes */
    private function standardKernel(array $nodes): bool
    {
        $classes = (new NodeFinder())->findInstanceOf($nodes, Node\Stmt\Class_::class);
        if (1 !== \count($classes)) {
            return false;
        }
        $class = $classes[0];
        $base = $class->extends;
        if (null === $base) {
            return false;
        }
        $sillbeam = 'Sillbeam\\Core\\Kernel\\SillbeamKernel' === $base->toString();
        if (!$sillbeam && 'Symfony\\Component\\HttpKernel\\Kernel' !== $base->toString()) {
            return false;
        }
        $traits = 0;
        foreach ($class->stmts as $statement) {
            if ($statement instanceof Node\Stmt\TraitUse && !$sillbeam && 1 === \count($statement->traits) && 'Symfony\\Bundle\\FrameworkBundle\\Kernel\\MicroKernelTrait' === $statement->traits[0]->toString() && [] === $statement->adaptations) {
                ++$traits;
                continue;
            }
            if ($statement instanceof Node\Stmt\ClassMethod && 'getAllowedEnvs' === $statement->name->toString() && $statement->isPrivate() && !$statement->isStatic() && !$statement->byRef && [] === $statement->params && $statement->returnType instanceof Node\Identifier && 'array' === $statement->returnType->toString() && 1 === \count($statement->stmts ?? []) && $statement->stmts[0] instanceof Node\Stmt\Return_ && $statement->stmts[0]->expr instanceof Node\Expr\Array_) {
                foreach ($statement->stmts[0]->expr->items as $item) {
                    if (null === $item || null !== $item->key || $item->unpack || $item->byRef || !$item->value instanceof Node\Scalar\String_) {
                        return false;
                    }
                }
                continue;
            }

            return false;
        }

        return $sillbeam || 1 === $traits;
    }

    /** @param array{route: bool, discovery: bool, service: bool} $unknown */
    private function markUnknown(array &$unknown, bool $routing): void
    {
        if ($routing) {
            $unknown['route'] = true;
        } else {
            // Container imports may contain both service and discovery declarations.
            $unknown['discovery'] = $unknown['service'] = true;
        }
    }

    private function read(string $path, CoreTranslator $translator): string
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw new \RuntimeException($translator->text('doctor.configuration.unreadable', ['path' => $path]));
        }

        return $contents;
    }

    /** @return array<Node> */
    private function php(string $path, CoreTranslator $translator): array
    {
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($this->read($path, $translator)) ?? [];

        return (new NodeTraverser(new NameResolver()))->traverse($nodes);
    }

    private function directory(mixed $value, string $base, string $project): ?string
    {
        if (!\is_string($value)) {
            return null;
        }
        $value = str_replace('%kernel.project_dir%', $project, $value);
        if (false !== strpbrk($value, '%*?{}![]')) {
            return null;
        }
        $path = str_starts_with($value, '/') ? $value : $base.'/'.$value;
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ('..' === $part) {
                array_pop($parts);
            } elseif ('' !== $part && '.' !== $part) {
                $parts[] = $part;
            }
        }
        $path = '/'.implode('/', $parts);

        return false !== ($real = realpath($path)) ? $real : $path;
    }

    /**
     * Expands a single finite brace group used by conventional Symfony excludes.
     *
     * No globbing or filesystem enumeration: absent module directories must still
     * be covered. Nested groups, wildcards and unresolved parameters remain unknown.
     *
     * @return list<string>|null
     */
    private function exclusionDirectories(mixed $value, string $base, string $project): ?array
    {
        if (!\is_string($value)) {
            return null;
        }
        if (!str_contains($value, '{')) {
            $path = $this->directory($value, $base, $project);

            return null === $path ? null : [$path];
        }
        if (!preg_match('/^([^{}]*)\{([^{}]+)\}([^{}]*)$/D', $value, $matches)) {
            return null;
        }
        $alternatives = explode(',', $matches[2]);
        if (\count($alternatives) < 2 || \in_array('', $alternatives, true)) {
            return null;
        }
        $paths = [];
        foreach ($alternatives as $alternative) {
            $path = $this->directory($matches[1].$alternative.$matches[3], $base, $project);
            if (null === $path) {
                return null;
            }
            $paths[] = $path;
        }

        return $paths;
    }
}
