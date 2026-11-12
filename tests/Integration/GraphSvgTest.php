<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\Console\GraphCommand;
use Sillbeam\Core\Architecture\Console\GraphLayout;
use Sillbeam\Core\Architecture\Console\GraphView;
use Sillbeam\Core\Architecture\Console\SvgGraph;
use Sillbeam\Core\Architecture\Console\SvgOutput;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;

final class GraphSvgTest extends TestCase
{
    private SourceWorkspace $source;

    protected function setUp(): void
    {
        $this->source = new SourceWorkspace();
    }

    protected function tearDown(): void
    {
        $this->source->remove();
    }

    public function testDefaultSvgUsesCwdAndRefusesOverwriteUnlessForced(): void
    {
        $tester = new CommandTester(new GraphCommand($this->report()));
        $cwd = getcwd();
        self::assertNotFalse($cwd);
        chdir($this->source->directory);
        try {
            self::assertSame(0, $tester->execute([]));
            $path = $this->source->directory.'/architecture.svg';
            self::assertStringContainsString($path, $tester->getDisplay());
            self::assertStringContainsString('<svg', (string) file_get_contents($path));
            file_put_contents($path, 'existing artifact');
            self::assertSame(4, $tester->execute([], ['capture_stderr_separately' => true]));
            self::assertSame('', $tester->getDisplay());
            self::assertStringContainsString('--force', $tester->getErrorOutput());
            self::assertSame('existing artifact', file_get_contents($path));
            self::assertSame(0, $tester->execute(['--force' => true], ['verbosity' => OutputInterface::VERBOSITY_QUIET]));
            self::assertSame('', $tester->getDisplay());
            self::assertStringContainsString('<svg', (string) file_get_contents($path));
            self::assertSame([], glob($this->source->directory.'/.sillbeam-svg-*'));
        } finally {
            chdir($cwd);
        }
    }

    public function testExplicitOutputTreatsShellCharactersAsLiteralPath(): void
    {
        $path = $this->source->directory.'/graph $(touch injected); ü.svg';
        $tester = new CommandTester(new GraphCommand($this->report('violations')));
        self::assertSame(1, $tester->execute(['--format' => 'svg', '--output' => $path]));
        self::assertFileExists($path);
        self::assertFileDoesNotExist($this->source->directory.'/injected');
        self::assertStringContainsString($path, $tester->getDisplay());
    }

    public function testMissingOutputDirectoriesAreCreatedWithRealSvg(): void
    {
        $path = $this->source->directory.'/docs/deep/architecture.svg';
        $tester = new CommandTester(new GraphCommand($this->report()));
        self::assertSame(0, $tester->execute(['--output' => $path]));
        $xml = simplexml_load_file($path);
        self::assertNotFalse($xml);
        self::assertSame('svg', $xml->getName());
        self::assertStringContainsString('Billing', (string) file_get_contents($path));
        self::assertStringContainsString('Payments', (string) file_get_contents($path));
    }

    public function testSvgNeedsNeitherPathExecutablesNorProcessExecution(): void
    {
        $path = $this->source->directory.'/without-tools.svg';
        $originalPath = getenv('PATH');
        putenv('PATH=');
        try {
            $tester = new CommandTester(new GraphCommand($this->report()));
            self::assertSame(0, $tester->execute(['--output' => $path]));
            self::assertFileExists($path);
            self::assertNotFalse(simplexml_load_file($path));
        } finally {
            putenv(false === $originalPath ? 'PATH' : 'PATH='.$originalPath);
        }
    }

    public function testOutputErrorsAndSymlinksNeverClobberTargets(): void
    {
        $this->source->write('keep.svg', 'keep');
        symlink($this->source->directory.'/keep.svg', $this->source->directory.'/link.svg');
        symlink($this->source->directory.'/absent.svg', $this->source->directory.'/dangling.svg');
        foreach (['keep.svg/graph.svg', 'src', 'link.svg', 'dangling.svg'] as $path) {
            $tester = new CommandTester(new GraphCommand($this->report()));
            self::assertSame(4, $tester->execute(['--output' => $this->source->directory.'/'.$path, '--force' => true]));
        }
        self::assertSame('keep', file_get_contents($this->source->directory.'/keep.svg'));
        self::assertFileDoesNotExist($this->source->directory.'/absent.svg');
    }

    public function testConcurrentCreationIsNotOverwritten(): void
    {
        $writer = new SvgOutput();
        $path = $writer->destination($this->source->directory.'/race.svg', false);
        file_put_contents($path, 'concurrent');
        try {
            $writer->write($path, (new SvgGraph())->render(GraphView::fromReport($this->report())), false);
            self::fail('Concurrent file must not be replaced.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('--force', $error->getMessage());
        }
        self::assertSame('concurrent', file_get_contents($path));
        self::assertSame([], glob($this->source->directory.'/.sillbeam-svg-*'));
    }

    public function testInvalidSvgOptionsFailBeforeAnalysis(): void
    {
        foreach ([['--format' => 'mermaid', '--output' => 'x'], ['--format' => 'mermaid', '--force' => true], ['--module' => ''], ['--project' => ''], ['--output' => ''], ['--format' => 'SVG']] as $options) {
            $called = false;
            $tester = new CommandTester(new GraphCommand(static function () use (&$called): ArchitectureReport {
                $called = true;

                return ArchitectureReport::unavailable(new \RuntimeException('unexpected'));
            }));
            self::assertSame(3, $tester->execute($options, ['capture_stderr_separately' => true]));
            self::assertFalse($called);
            self::assertSame('', $tester->getDisplay());
            self::assertNotSame('', $tester->getErrorOutput());
        }
    }

    public function testSuccessfulSvgPreservesAllThreeAnalysisExitCodes(): void
    {
        foreach (['valid' => 0, 'violations' => 1, 'unavailable' => 2] as $status => $code) {
            $path = $this->source->directory.'/'.$status.'.svg';
            $tester = new CommandTester(new GraphCommand($this->report($status)));
            self::assertSame($code, $tester->execute(['--output' => $path]));
            self::assertFileExists($path);
            $svg = (string) file_get_contents($path);
            self::assertNotFalse(simplexml_load_string($svg));
            if (0 !== $code) {
                self::assertStringContainsString('NOT a validated runtime graph', $svg);
                self::assertStringNotContainsString('state=active', $svg);
                self::assertStringContainsString('state=unknown', $svg);
            }
        }
    }

    public function testSvgIsDeterministicEscapesLabelsAndPreservesDirectEdges(): void
    {
        $data = $this->report()->data;
        $data['modules'][0]['id'] = 'Bälling <script>alert("x")</script> & \\\\\Name';
        $view = GraphView::fromReport(new ArchitectureReport($data));
        $svg = (new SvgGraph())->render($view);
        self::assertNotFalse(simplexml_load_string($svg));
        self::assertStringNotContainsString('<script>', $svg);
        self::assertStringContainsString('&lt;script&gt;', $svg);
        self::assertStringContainsString('data-source="m0001" data-target="m0002"', $svg);
        self::assertSame(1, substr_count($svg, 'class="dependency declared"'));
        self::assertSame($svg, (new SvgGraph())->render($view));
        self::assertStringContainsString('App\\Billing', $svg);
    }

    public function testSvgRetainsObservedCycleDisabledAndUnknownEvidence(): void
    {
        $data = $this->report('violations')->data;
        $data['modules'][0]['enabled'] = false;
        $data['modules'][0]['observed_dependencies'] = ['App\\Unknown'];
        $data['findings'][] = ['rule' => 'dependency.cycle', 'message' => 'Existing cycle finding', 'context' => ['cycle' => 'App\\Billing, App\\Payments']];
        $svg = (new SvgGraph())->render(GraphView::fromReport(new ArchitectureReport($data)));
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');
        foreach ([
            ['//s:path[@class="dependency declared" or @class="dependency observed"]', 2],
            ['//s:path[@class="dependency observed"][@stroke-dasharray="6 4"]', 1],
            ['//s:g[@class="module"]/s:rect[@stroke="#c22"]', 2],
            ['//s:g[@class="module"]/s:rect[@fill="#eee"]', 1],
        ] as [$query, $count]) {
            $matches = $xml->xpath($query);
            self::assertIsArray($matches);
            self::assertCount($count, $matches);
        }
        self::assertStringContainsString('unresolved module target', $svg);
        self::assertStringContainsString('state=unknown', $svg);
        self::assertStringNotContainsString('state=active', $svg);
    }

    public function testExportWorksWithProcessFunctionsDisabled(): void
    {
        $autoload = var_export(\dirname(__DIR__, 2).'/vendor/autoload.php', true);
        $data = var_export($this->report()->data, true);
        $path = $this->source->directory.'/disabled-processes.svg';
        $this->source->write('export.php', '<?php require '.$autoload.'; $report = new \\Sillbeam\\Core\\Architecture\\ArchitectureReport('.$data.'); $command = new \\Sillbeam\\Core\\Architecture\\Console\\GraphCommand($report); $tester = new \\Symfony\\Component\\Console\\Tester\\CommandTester($command); exit($tester->execute(["--output" => '.var_export($path, true).']));');
        $process = proc_open([\PHP_BINARY, '-d', 'disable_functions=proc_open,exec,shell_exec,system,passthru,popen', $this->source->directory.'/export.php'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout.$stderr);
        self::assertFileExists($path);
        self::assertNotFalse(simplexml_load_file($path));
    }

    public function testWideLabelsStayWithinTheNodeTextArea(): void
    {
        $data = $this->report()->data;
        $data['modules'][0]['id'] = str_repeat('w', 72);
        $svg = (new SvgGraph())->render(GraphView::fromReport(new ArchitectureReport($data)));
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');
        $texts = $xml->xpath('//s:g[@id="m0001"]/s:text');
        self::assertIsArray($texts);
        foreach ($texts as $text) {
            self::assertTrue(isset($text['textLength']), 'Text must have an explicit bounded SVG width.');
            self::assertLessThanOrEqual(296, (float) $text['textLength']);
        }
    }

    public function testSvgUsesLayoutGeometryForCardsScopesAndEdges(): void
    {
        $view = GraphView::fromReport($this->report());
        $layout = (new GraphLayout())->layout($view);
        $svg = (new SvgGraph())->render($view);
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');
        self::assertSame('0 0 '.$layout['width'].' '.$layout['height'], (string) $xml['viewBox']);
        foreach (['m0001' => $layout['nodes']['App\\Billing'], 'scope-shared_scope' => $layout['scopes']['shared_scope']] as $id => $box) {
            $rectangles = $xml->xpath('//s:g[@id="'.$id.'"]/s:rect');
            self::assertIsArray($rectangles);
            self::assertCount(1, $rectangles);
            foreach (['x', 'y', 'width', 'height'] as $property) {
                self::assertSame((string) $box[$property], (string) $rectangles[0][$property]);
            }
        }
        $paths = $xml->xpath('//s:path[@id="edge-1"]');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        preg_match_all('/\d+/', (string) $paths[0]['d'], $coordinates);
        self::assertSame(array_merge(...$layout['edges'][0]['points']), array_map(intval(...), $coordinates[0]));
    }

    public function testArrowTipsAreAnchoredAtTargetBordersAndPaintedAboveCards(): void
    {
        $data = $this->report('violations')->data;
        $data['modules'][0]['observed_dependencies'] = ['App\\Unknown'];
        $data['modules'][1]['observed_dependencies'] = ['App\\Billing'];
        $data['findings'][] = ['rule' => 'dependency.cycle', 'message' => 'Existing cycle finding', 'context' => ['cycle' => 'App\\Billing, App\\Payments']];
        $svg = (new SvgGraph())->render(GraphView::fromReport(new ArchitectureReport($data)));
        $xml = simplexml_load_string($svg);
        self::assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.w3.org/2000/svg');

        foreach (['declared', 'observed'] as $kind) {
            $markers = $xml->xpath('//s:marker[@id="arrow-'.$kind.'"]');
            self::assertIsArray($markers);
            self::assertCount(1, $markers);
            self::assertSame('10', (string) $markers[0]['refX']);
            self::assertSame('5', (string) $markers[0]['refY']);
            self::assertSame('auto', (string) $markers[0]['orient']);
        }
        foreach ([
            ['edge-1', 'm0002', 'declared', -1],
            ['edge-2', 'u0001', 'observed', 1],
            ['edge-3', 'm0001', 'observed', -1],
        ] as [$id, $target, $kind, $direction]) {
            $paths = $xml->xpath('//s:path[@id="'.$id.'"]');
            $cards = $xml->xpath('//s:path[@id="'.$id.'"]/preceding-sibling::s:g[@id="'.$target.'"]/s:rect');
            self::assertIsArray($paths);
            self::assertCount(1, $paths);
            self::assertIsArray($cards);
            self::assertCount(1, $cards, 'The target card must be painted before the arrow tip.');
            self::assertSame('url(#arrow-'.$kind.')', (string) $paths[0]['marker-end']);
            preg_match_all('/\d+/', (string) $paths[0]['d'], $coordinates);
            [$fromX, $fromY, $toX, $toY] = array_map(intval(...), \array_slice($coordinates[0], -4));
            $border = (int) $cards[0]['x'] + (1 === $direction ? 0 : (int) $cards[0]['width']);
            self::assertSame($border, $toX);
            self::assertSame($fromY, $toY);
            self::assertGreaterThan(0, ($toX - $fromX) * $direction);
        }
    }

    public function testLocalizedSvgIncludesExternalAndRelationshipLabels(): void
    {
        $data = $this->report('violations')->data;
        $data['modules'][0]['observed_dependencies'] = ['App\\Unknown'];
        $data['modules'][1]['scope'] = 'private';
        $data['modules'][1]['project'] = 'App\\Admin';
        $data['projects'] = [
            ['id' => 'shop', 'class' => 'App\\Shop', 'modules' => null],
            ['id' => 'admin', 'class' => 'App\\Admin', 'modules' => null],
        ];
        $data['complete'] = false;
        $tester = new CommandTester(new GraphCommand(new ArchitectureReport($data)));
        $path = $this->source->directory.'/localized.svg';
        $options = ['--project' => 'shop', '--output' => $path];
        self::assertSame(1, $tester->execute($options + ['--locale' => 'en']));
        $english = (string) file_get_contents($path);
        self::assertSame(1, $tester->execute($options + ['--locale' => 'de', '--force' => true]));
        $german = (string) file_get_contents($path);
        foreach (['external to Project context', '(declared)', '(observed undeclared)', 'Analysis incomplete'] as $text) {
            self::assertStringContainsString($text, $english);
            self::assertStringNotContainsString($text, $german);
        }
        foreach (['außerhalb des Projektkontexts', '(deklariert)', '(beobachtet, nicht deklariert)', 'Analyse unvollständig', 'Zustand=unbekannt'] as $text) {
            self::assertStringContainsString($text, $german);
        }
        self::assertStringContainsString('data-source="m0001" data-target="m0002"', $german);
        self::assertStringNotContainsString('Zustand=aktiv', $german);
        self::assertStringNotContainsString('Zustand=unknown', $german);
    }

    private function report(string $status = 'valid'): ArchitectureReport
    {
        $modules = [];
        foreach (['Billing', 'Payments'] as $id) {
            $modules[] = ['id' => $id, 'class' => 'App\\'.$id, 'scope' => 'shared', 'project' => null, 'enabled' => true,
                'active' => 'valid' === $status ? true : null, 'declared_dependencies' => 'Billing' === $id ? ['App\\Payments'] : [], 'observed_dependencies' => [], 'references' => []];
        }

        return new ArchitectureReport(['schema_version' => 1, 'status' => $status, 'complete' => 'unavailable' !== $status, 'valid' => 'valid' === $status, 'modules' => $modules, 'projects' => [], 'findings' => []]);
    }
}
