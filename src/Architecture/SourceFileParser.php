<?php

declare(strict_types=1);

namespace Sillbeam\Core\Architecture;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use Sillbeam\Core\I18n\CoreMessage;
use Sillbeam\Core\Module\Attribute\SillbeamModule;
use Sillbeam\Core\Project\Attribute\SillbeamProject;

/**
 * Reads PHP source into resolved declarations and static references without executing it.
 *
 * @internal
 */
final class SourceFileParser
{
    /**
     * Reads a file and collects name-resolved static references without executing the file.
     *
     * @throws ArchitectureValidationException if the file is unreadable or descriptor attributes are ambiguous
     * @throws \PhpParser\Error                if the file contains invalid PHP syntax
     */
    public function parse(string $path): SourceFile
    {
        $contents = @file_get_contents($path);
        if (false === $contents) {
            throw ArchitectureValidationException::fatalMessage('source.read', new CoreMessage('finding.source.read', ['path' => $path]), ['file' => $path]);
        }
        $nodes = (new ParserFactory())->createForNewestSupportedVersion()->parse($contents) ?? [];
        $collector = new class($path) extends NodeVisitorAbstract {
            public function __construct(private readonly string $path)
            {
            }

            /** @var list<class-string> */
            public array $classes = [];
            /** @var array<class-string, string> */
            public array $descriptors = [];
            /** @var list<array{class: string, line: int, kind?: 'attribute'}> */
            public array $references = [];

            public function enterNode(Node $node): ?int
            {
                if ($node instanceof Node\Stmt\Use_ || $node instanceof Node\Stmt\GroupUse) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }
                if ($node instanceof Node\Stmt\ClassLike && null !== $node->name) {
                    /** @var class-string $class */
                    $class = (string) $node->namespacedName;
                    $this->classes[] = $class;
                    foreach ($node->attrGroups as $group) {
                        foreach ($group->attrs as $attribute) {
                            if (null !== ($descriptor = $this->descriptorType($attribute->name))) {
                                if (isset($this->descriptors[$class])) {
                                    throw ArchitectureValidationException::fatalMessage('discovery.multiple_attributes', new CoreMessage('finding.discovery.multiple_attributes', ['class' => $class]), ['file' => $this->path, 'line' => $node->getStartLine(), 'class' => $class]);
                                }
                                $this->descriptors[$class] = $descriptor;
                            }
                        }
                    }
                }
                if ($node instanceof Node\Stmt\ClassLike) {
                    foreach ((array) ($node->implements ?? []) as $type) {
                        $this->type($type);
                    }
                    $extends = $node->extends ?? null;
                    if (\is_array($extends)) {
                        foreach ($extends as $type) {
                            $this->type($type);
                        }
                    } else {
                        $this->type($extends);
                    }
                }
                if ($node instanceof Node\Param || $node instanceof Node\Stmt\Property || $node instanceof Node\Stmt\ClassConst) {
                    $this->type($node->type);
                }
                if ($node instanceof Node\FunctionLike) {
                    $this->type($node->getReturnType());
                }
                if ($node instanceof Node\Expr\New_ || $node instanceof Node\Expr\StaticCall || $node instanceof Node\Expr\StaticPropertyFetch || $node instanceof Node\Expr\ClassConstFetch || $node instanceof Node\Expr\Instanceof_) {
                    $this->type($node->class);
                }
                if ($node instanceof Node\Attribute) {
                    if (null !== $this->descriptorType($node->name)) {
                        return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                    }
                    $this->type($node->name, attribute: true);
                }
                if ($node instanceof Node\Stmt\Catch_) {
                    foreach ($node->types as $type) {
                        $this->type($type);
                    }
                }
                if ($node instanceof Node\Stmt\TraitUse) {
                    foreach ($node->traits as $type) {
                        $this->type($type);
                    }
                }

                return null;
            }

            private function descriptorType(Node\Name $name): ?string
            {
                foreach ([SillbeamModule::class, SillbeamProject::class] as $attribute) {
                    if (0 === strcasecmp((string) $name, $attribute)) {
                        return $attribute;
                    }
                }

                return null;
            }

            private function type(?Node $type, bool $attribute = false): void
            {
                if ($type instanceof Node\Name) {
                    if (!\in_array(strtolower((string) $type), ['self', 'static', 'parent'], true)) {
                        $reference = ['class' => (string) $type, 'line' => $type->getStartLine()];
                        if ($attribute) {
                            $reference['kind'] = 'attribute';
                        }
                        $this->references[] = $reference;
                    }
                } elseif ($type instanceof Node\NullableType) {
                    $this->type($type->type);
                } elseif ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
                    foreach ($type->types as $member) {
                        $this->type($member);
                    }
                }
            }
        };
        (new NodeTraverser(new NameResolver(), $collector))->traverse($nodes);

        return new SourceFile($path, $collector->classes, $collector->descriptors, $collector->references);
    }
}
