<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PhpParser\Error;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\SourceFileParser;
use Sillbeam\Core\Tests\Support\SourceWorkspace;

final class SourceFileParserTest extends TestCase
{
    public function testParserReadsChangedSourceAcrossSuccessfulAndFailedParses(): void
    {
        $source = new SourceWorkspace();
        $parser = new SourceFileParser();
        $relative = 'src/Example.php';
        $path = $source->directory.'/'.$relative;
        try {
            $source->write($relative, "<?php\nnamespace Before;\nuse External\\First as Port;\nfinal class Example extends Port {}");
            $first = $parser->parse($path);
            self::assertSame(['Before\\Example'], $first->classes);
            self::assertSame([['class' => 'External\\First', 'line' => 4]], $first->references);

            $source->write($relative, "<?php\nnamespace Broken;\nfinal class Example extends {");
            try {
                $parser->parse($path);
                self::fail('Invalid source must raise a parser error.');
            } catch (Error $error) {
                self::assertSame(3, $error->getStartLine());
            }

            $source->write($relative, "<?php\nnamespace After;\nuse Other\\Second as Port;\n\nfinal class Example extends Port {}");
            $last = $parser->parse($path);
            self::assertSame(['After\\Example'], $last->classes);
            self::assertSame([['class' => 'Other\\Second', 'line' => 5]], $last->references);
            self::assertEquals((new SourceFileParser())->parse($path), $last);

            $source->write($relative, "<?php\nnamespace FinalVersion;\nfinal class Example {}");
            $last = $parser->parse($path);
            self::assertSame(['FinalVersion\\Example'], $last->classes);
            self::assertSame([], $last->references);
        } finally {
            $source->remove();
        }
    }

    public function testStaticReferencesResolveGroupedImportsAndAllSupportedTypePositions(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Example.php', <<<'CODE'
use External\{Base, Contract as Port, Mix, Marker, Failure, Value, Other, Third};
#[Marker(Value::class)]
final class Example extends Base implements Port {
    use Mix;
    public Value|Other $union;
    public Port&Third $intersection;
    public function run(?Value $value): Other|Third {
        try {
            $other = new Other();
            Other::run();
            Other::$state;
            return $other instanceof Third ? $other : throw new Failure();
        } catch (Failure $error) { throw $error; }
    }
}
CODE);
            $file = (new SourceFileParser())->parse($source->directory.'/src/Example.php');
            $classes = array_values(array_unique(array_column($file->references, 'class')));
            sort($classes);
            self::assertSame(['External\Base', 'External\Contract', 'External\Failure', 'External\Marker', 'External\Mix', 'External\Other', 'External\Third', 'External\Value'], $classes);
            self::assertSame(['External\\Marker'], array_column(array_filter($file->references, static fn (array $reference): bool => 'attribute' === ($reference['kind'] ?? null)), 'class'));
            self::assertSame([$source->namespace.'\Example'], $file->classes);
            self::assertGreaterThan(1, $file->references[0]['line']);
        } finally {
            $source->remove();
        }
    }

    public function testDynamicNamesStringsPhpDocAndUnusedImportsAreOutsideStaticAnalysis(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->php('src/Example.php', <<<'CODE'
use External\Unused;
/** @property \External\Documented $value */
final class Example {
    public function run(string $class): object {
        $name = 'External\\StringTarget';
        $class::run();
        $class::$property;
        $class::CONSTANT;
        return new $class();
    }
}
CODE);
            $file = (new SourceFileParser())->parse($source->directory.'/src/Example.php');
            self::assertSame([], $file->references);
            self::assertSame([], $file->descriptors);
        } finally {
            $source->remove();
        }
    }
}
