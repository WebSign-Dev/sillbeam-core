<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Documentation\JsonExport;
use Symfony\Component\Filesystem\Filesystem;

final class PhpDocJsonExportTest extends TestCase
{
    public function testComposerExportsPublicApiDeterministicallyWithoutRemovingHtml(): void
    {
        $root = \dirname(__DIR__, 2);
        $temp = sys_get_temp_dir().'/sillbeam-json-command-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir($temp.'/vendor/bin');
        $fs->mirror($root.'/src', $temp.'/src');
        $fs->mirror($root.'/tools/phpdoc-json', $temp.'/tools/phpdoc-json');
        $fs->copy($root.'/tools/export-phpdoc-json.php', $temp.'/tools/export-phpdoc-json.php');
        $fs->copy($root.'/composer.json', $temp.'/composer.json');
        $fs->copy($root.'/phpdoc.dist.xml', $temp.'/phpdoc.dist.xml');
        $fs->symlink($root.'/vendor/bin/phpdoc', $temp.'/vendor/bin/phpdoc');
        $sentinel = $temp.'/build/docs/api/export-html-sentinel.html';
        $fs->dumpFile($sentinel, 'Existing HTML must survive.');
        try {
            $this->runCommand(['composer', 'docs:export-json'], $temp);
            $json = (string) file_get_contents($temp.'/build/docs/api/sillbeam-core.en.json');
            $data = $this->decode($json);
            self::assertSame('websign-dev/sillbeam-core', $data['package']);
            self::assertSame('0.4.0', $data['version']);
            $ids = array_column($data['elements'], 'id');
            foreach (['\\Sillbeam\\Core\\Module\\ModuleRegistryInterface', '\\Sillbeam\\Core\\Module\\ModuleRegistryInterface::active()', '\\Sillbeam\\Core\\Architecture\\ArchitectureReport::toJson()'] as $id) {
                self::assertContains($id, $ids);
            }
            foreach (['\\Sillbeam\\Core\\Architecture\\SourceInspector', '\\Sillbeam\\Core\\Architecture\\Console\\GraphCommand', '\\Sillbeam\\Core\\Architecture\\ArchitectureReport::$presentation'] as $id) {
                self::assertNotContains($id, $ids);
            }
            self::assertSame('Existing HTML must survive.', file_get_contents($sentinel));
            $this->runCommand(['composer', 'docs:export-json'], $temp);
            self::assertSame($json, file_get_contents($temp.'/build/docs/api/sillbeam-core.en.json'));
            file_put_contents($temp.'/phpdoc.dist.xml', '<invalid configuration');
            $this->runCommand(['composer', 'docs:export-json'], $temp, 1);
            self::assertSame($json, file_get_contents($temp.'/build/docs/api/sillbeam-core.en.json'));
            self::assertSame([], glob($temp.'/.phpdoc/json-export-*'));
            self::assertSame([], glob($temp.'/build/docs/api/.sillbeam-json-*'));
        } finally {
            $fs->remove($temp);
        }
    }

    public function testRealDescriptorsRetainDocumentationSourceSignaturesAndExamples(): void
    {
        $root = \dirname(__DIR__, 2);
        $temp = sys_get_temp_dir().'/sillbeam-phpdoc-json-'.bin2hex(random_bytes(6));
        $fs = new Filesystem();
        $fs->mkdir($temp);
        try {
            $this->runCommand([\PHP_BINARY, $root.'/vendor/bin/phpdoc', '--config=phpdoc.dist.xml', '--directory=tests/Fixtures/PhpDocApi', '--template=tools/phpdoc-json', '--target='.$temp.'/output', '--cache-folder='.$temp.'/cache', '--sourcecode', '--examples-dir='.$root.'/tests/Fixtures/PhpDocApi', '--no-extensions']);
            require_once $root.'/tools/phpdoc-json/JsonExport.php';
            $raw = json_decode((string) file_get_contents($temp.'/output/descriptors.json'), true, flags: \JSON_THROW_ON_ERROR);
            $json = (new JsonExport())->encode($raw, 'fixture/api', '1.2.3');
            $data = $this->decode($json);
            $elements = array_column($data['elements'], null, 'id');
            $api = $elements['\\DocumentationFixture\\PublicApi'];
            self::assertSame('English **summary** with café and `code`.', $api['summary']);
            self::assertStringContainsString("```php\n\$path = \"\\\\App\\\\Thing\";\necho \$path; // Ω\n```", $api['description']);
            self::assertStringContainsString('{@see', $api['description']);
            self::assertStringNotContainsString('<p>', $json);
            self::assertNull($api['examples'][0]['code']);
            self::assertSame('example.txt A source example.', $api['examples'][0]['description']);
            $method = $elements['\\DocumentationFixture\\PublicApi::combine()'];
            self::assertSame(<<<'SIGNATURE'
final public static function  combine(
        Clock|string $value,
        array $options = ['brace' => '{;"}', 'path' => '\\App\\Thing'],
    ): ?string
SIGNATURE, $method['signature']);
            self::assertSame(['value', 'options'], array_column($method['parameters'], 'name'));
            self::assertStringContainsString('DateTimeImmutable', $method['parameters'][0]['type']);
            self::assertSame('Options with **Markdown**.', $method['parameters'][1]['description']);
            self::assertSame('The combined value, or null.', $method['returns']['description']);
            self::assertSame(['\\RuntimeException', '\\LogicException'], array_column($method['throws'], 'type'));
            foreach (['Contract' => 'interface', 'Greets' => 'trait', 'Mode' => 'enum', 'PublicApi::VERSION' => 'constant', 'PublicApi::$items' => 'property', 'PublicApi::$label' => 'property', 'Mode::English' => 'enum_case', 'greeting()' => 'function', 'LABEL' => 'constant'] as $name => $kind) {
                self::assertSame($kind, $elements['\\DocumentationFixture\\'.$name]['kind']);
            }
            self::assertSame('protected', $elements['\\DocumentationFixture\\PublicApi::$items']['visibility']);
            self::assertSame('', $elements['\\DocumentationFixture\\PublicApi::undocumented()']['summary']);
            self::assertSame(['\\DocumentationFixture\\Contract'], $api['implements']);
            self::assertSame(['\\DocumentationFixture\\Greets'], $api['traits']);
            foreach (array_keys($elements) as $id) {
                self::assertStringNotContainsString('InternalApi', $id);
                self::assertStringNotContainsString('hidden', $id);
                self::assertStringNotContainsString('HIDDEN', $id);
                self::assertStringNotContainsString('private', $id);
            }
            self::assertStringNotContainsString('SECRET_', $json);
            self::assertArrayNotHasKey('files', $data);
            $raw['elements'] = array_reverse($raw['elements']);
            self::assertSame($json, (new JsonExport())->encode($raw, 'fixture/api', '1.2.3'));
        } finally {
            $fs->remove($temp);
        }
    }

    public function testRejectsMissingDescriptorCollections(): void
    {
        require_once \dirname(__DIR__, 2).'/tools/phpdoc-json/JsonExport.php';
        $this->expectException(\RuntimeException::class);
        (new JsonExport())->encode([], 'fixture/api', '1.0');
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $data = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(1, $data['schema_version']);
        self::assertSame('en', $data['language']);
        self::assertSame(\count($data['elements']), \count(array_unique(array_column($data['elements'], 'id'))));
        $ids = array_column($data['elements'], 'id');
        $sorted = $ids;
        sort($sorted, \SORT_STRING);
        self::assertSame($sorted, $ids);

        return $data;
    }

    /** @param list<string> $command */
    private function runCommand(array $command, ?string $directory = null, int $expected = 0): void
    {
        $process = proc_open($command, [\STDIN, ['pipe', 'w'], ['pipe', 'w']], $pipes, $directory ?? \dirname(__DIR__, 2));
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame($expected, proc_close($process), $stdout.$stderr);
    }
}
