<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Architecture\ArchitectureAnalyzer;
use Sillbeam\Core\Architecture\ArchitectureValidationException;
use Sillbeam\Core\Architecture\DiscoveryOptions;
use Sillbeam\Core\Module\ModuleRegistryInterface;
use Sillbeam\Core\Project\ProjectRegistryInterface;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class ArchitectureAnalysisTest extends TestCase
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

    public function testIndependentGraphAndCodeViolationsShareOneResultWithSourceLines(): void
    {
        $a = $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\Missing\\Module::class])] final class AModule {}');
        $b = $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class BModule {}');
        $port = $this->source->php('src/Module/B/Port.php', 'interface Port {}');
        $this->source->php('src/Module/A/Consumer.php', "final class Consumer {\n public \\".$port.' $one; public \\'.$port." \$two;\n public \\".$port." \$three;\n}");
        $analyzer = new ArchitectureAnalyzer();
        $result = $analyzer->analyze($this->options());
        self::assertTrue($result->isComplete());
        self::assertNull($result->architecture);
        self::assertSame(['code.undeclared_dependency', 'code.undeclared_dependency', 'dependency.unknown'], array_column($result->findings, 'rule'));
        self::assertSame([2, 3], array_map(static fn ($finding) => $finding->context['line'], \array_slice($result->findings, 0, 2)));
        self::assertSame($a, $result->findings[0]->context['module']);
        self::assertSame($b, $result->findings[0]->context['target']);
        self::assertCount(2, $result->references[$a]);
        self::assertEquals($result->findings, $analyzer->analyze($this->options())->findings);
    }

    public function testDiagnosticSnapshotRetainsStatesWithoutPublishingInvalidCandidateOrChangingJson(): void
    {
        $a = $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $b = $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class BModule {}');
        $c = $this->source->php('src/Module/C/CModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class CModule {}');
        $port = $this->source->php('src/Module/B/Port.php', 'interface Port {}');
        $this->source->php('src/Module/A/Consumer.php', 'final class Consumer { public \\'.$port.' $port; }');
        $this->source->php('src/Project/Shop/ShopProject.php', '#[\\Sillbeam\\Core\\Project\\Attribute\\SillbeamProject(modules: [\\'.$a.'::class])] final class ShopProject {}');
        $config = ['modules' => ['b' => ['enabled' => false]]];
        $result = (new ArchitectureAnalyzer())->analyze($this->options(), $config);
        self::assertNull($result->architecture);
        self::assertTrue($result->isComplete());
        $context = \Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext::fromAnalysis($result);
        self::assertSame('active', $context->state($a));
        self::assertSame('disabled', $context->state($b));
        self::assertSame('unassigned', $context->state($c));
        self::assertSame($result->references, $context->references);
        self::assertSame($result->discovery?->modules[$a]->descriptorFile, $context->modules[$a]->descriptorFile);
        $before = \Sillbeam\Core\Architecture\ArchitectureReport::fromAnalysis($result, $config)->toJson();
        $collection = \Sillbeam\Core\Architecture\Diagnostic\DiagnosticCollection::fromAnalysis($result);
        self::assertCount(\count($result->findings), $collection->assignments);
        self::assertSame($before, \Sillbeam\Core\Architecture\ArchitectureReport::fromAnalysis($result, $config)->toJson());
        $withoutSnapshot = new \Sillbeam\Core\Architecture\ArchitectureAnalysisResult($result->findings, references: $result->references, discovery: $result->discovery, applicationReferences: $result->applicationReferences);
        self::assertSame($before, \Sillbeam\Core\Architecture\ArchitectureReport::fromAnalysis($withoutSnapshot, $config)->toJson());
        self::assertSame(1, json_decode($before, true, flags: \JSON_THROW_ON_ERROR)['schema_version']);
    }

    public function testSyntaxErrorIsAFatalAnalysisFailureWithOriginalCause(): void
    {
        $this->source->write('src/Module/Broken/Broken.php', "<?php\nclass {");
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertFalse($result->isComplete());
        self::assertFalse(\Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext::fromAnalysis($result)->complete);
        self::assertFalse($result->isValid());
        self::assertNull($result->architecture);
        self::assertCount(1, $result->findings);
        self::assertSame('source.parse', $result->findings[0]->rule);
        self::assertSame('fatal', $result->findings[0]->severity);
        self::assertSame(2, $result->findings[0]->context['line']);
        try {
            $result->throwIfInvalid();
            self::fail('A fatal analysis must reject runtime use.');
        } catch (ArchitectureValidationException $error) {
            self::assertInstanceOf(\PhpParser\Error::class, $error->getPrevious());
        }
    }

    public function testOverlappingAreasAreFatalAndNeverProduceAPartialRuntimeGraph(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $this->source->php('src/Module/A/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class BModule {}');
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertFalse($result->isComplete());
        self::assertNull($result->architecture);
        self::assertSame('discovery.module_overlap', $result->findings[0]->rule);
    }

    #[DataProvider('doctrineModes')]
    public function testKernelReportsAllCollectedViolationsWithoutRegisteringRuntime(bool $doctrine): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\Missing\\One::class])] final class AModule {}');
        $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\Missing\\Two::class])] final class BModule {}');
        $this->source->write('config/packages/framework.yaml', "framework: {secret: test, test: true}\n");
        $this->source->write('config/services.yaml', 'services: {}');
        $this->source->php('src/Module/A/ModuleService.php', '#[\Symfony\Component\DependencyInjection\Attribute\AsAlias(id: "a.module_service", public: true)] final class ModuleService {}');
        if ($doctrine) {
            $entity = '#[\Doctrine\ORM\Mapping\Entity] final class Record { #[\Doctrine\ORM\Mapping\Id] #[\Doctrine\ORM\Mapping\Column] public int $id; }';
            $nativeEntity = $this->source->php('src/Entity/Record.php', $entity);
            $this->source->php('src/Module/A/Entity/Record.php', $entity);
            $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm:\n        auto_mapping: false\n        mappings:\n            Native:\n                type: attribute\n                is_bundle: false\n                dir: '%kernel.project_dir%/src/Entity'\n                prefix: '".$this->source->namespace."\\Entity'\n");
        }
        $kernel = new TestApplicationKernel($this->source, $doctrine);
        try {
            $kernel->boot();
            self::assertFalse($kernel->getContainer()->has(ModuleRegistryInterface::class));
            self::assertFalse($kernel->getContainer()->has(ProjectRegistryInterface::class));
            self::assertFalse($kernel->getContainer()->has('a.module_service'));
            if (isset($nativeEntity)) {
                $manager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
                self::assertInstanceOf(EntityManagerInterface::class, $manager);
                self::assertSame([$nativeEntity], array_map(static fn ($metadata): string => $metadata->getName(), $manager->getMetadataFactory()->getAllMetadata()));
            }
            $tester = new CommandTester((new Application($kernel))->find('sillbeam:architecture'));
            self::assertSame(1, $tester->execute(['--format' => 'json']));
            $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertTrue($report['complete']);
            self::assertCount(2, $report['findings']);
            self::assertStringContainsString('Missing\One', $report['findings'][0]['message']);
            self::assertStringContainsString('Missing\Two', $report['findings'][1]['message']);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testValidResultIsReusableAndDoesNotRetainPreviousFindings(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $analyzer = new ArchitectureAnalyzer();
        $invalid = $analyzer->analyze($this->options(), ['modules' => ['missing' => ['enabled' => false]]]);
        self::assertFalse($invalid->isValid());
        $valid = $analyzer->analyze($this->options());
        self::assertTrue($valid->isValid());
        self::assertTrue($valid->isComplete());
        self::assertSame([], $valid->findings);
        self::assertNotNull($valid->architecture);
        $valid->throwIfInvalid();
    }

    public function testDisabledDependenciesRemainInactiveForCodeAnalysis(): void
    {
        $b = $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class BModule {}');
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\'.$b.'::class])] final class AModule {}');
        $port = $this->source->php('src/Module/B/Port.php', 'interface Port {}');
        $this->source->php('src/Module/A/Consumer.php', 'final class Consumer { public \\'.$port.' $port; }');
        $result = (new ArchitectureAnalyzer())->analyze($this->options(), ['modules' => ['b' => ['enabled' => false]]]);
        self::assertSame(['code.inactive', 'dependency.disabled'], array_column($result->findings, 'rule'));
        self::assertNull($result->architecture);
    }

    public function testDuplicateSourceSymbolsRetainEarlierViolationsAndStopCodeAnalysis(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: [\\Missing\\Module::class])] final class AModule {}');
        $this->source->php('src/Module/A/One.php', 'class Duplicate {}');
        $this->source->php('src/Module/A/Two.php', 'class Duplicate {}');
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertFalse($result->isComplete());
        self::assertSame(['dependency.unknown', 'source.duplicate_class'], array_column($result->findings, 'rule'));
        $context = \Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext::fromAnalysis($result);
        self::assertFalse($context->complete);
        foreach (array_keys($context->modules) as $class) {
            self::assertSame('unknown', $context->state($class));
        }
        self::assertSame([], $result->references);
        self::assertNull($result->architecture);
    }

    public function testFatalIdentityAmbiguityDoesNotPublishDiagnosticActivation(): void
    {
        $a = $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "duplicate")] final class AModule {}');
        $b = $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(id: "duplicate")] final class BModule {}');
        $result = (new ArchitectureAnalyzer())->analyze($this->options(), ['modules' => ['duplicate' => ['enabled' => false]]]);
        self::assertFalse($result->isComplete());
        self::assertNull($result->architecture);
        $context = \Sillbeam\Core\Architecture\Diagnostic\DiagnosticContext::fromAnalysis($result);
        self::assertFalse($context->complete);
        self::assertSame('unknown', $context->state($a));
        self::assertSame('unknown', $context->state($b));
        self::assertNull($context->assessDependency($a, $b)->hasReturnPath);
        self::assertNull($context->assessDependency($a, $b)->resolution());
    }

    public function testInvalidDescriptorMetadataIsFatalRatherThanAnEmptyArchitecture(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule(dependencies: "invalid")] final class AModule {}');
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertFalse($result->isComplete());
        self::assertNull($result->architecture);
        self::assertSame('discovery.descriptor_metadata', $result->findings[0]->rule);
        self::assertSame($this->source->directory.'/src/Module/A/AModule.php', $result->findings[0]->context['file']);
    }

    #[DataProvider('doctrineModes')]
    public function testKernelPreservesFatalSourceFailure(bool $doctrine): void
    {
        $this->source->write('src/Module/Broken/Broken.php', '<?php class {');
        $this->source->write('config/packages/framework.yaml', "framework: {secret: test, test: true}\n");
        $this->source->write('config/services.yaml', 'services: {}');
        if ($doctrine) {
            $this->source->write('config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm: { auto_mapping: false }\n");
        }
        $kernel = new TestApplicationKernel($this->source, $doctrine);
        try {
            $kernel->boot();
            self::assertFalse($kernel->getContainer()->has(ModuleRegistryInterface::class));
            $tester = new CommandTester((new Application($kernel))->find('sillbeam:architecture'));
            self::assertSame(2, $tester->execute(['--format' => 'json']));
            $report = json_decode($tester->getDisplay(), true, flags: \JSON_THROW_ON_ERROR);
            self::assertFalse($report['complete']);
            self::assertSame('source.parse', $report['findings'][0]['rule']);
        } finally {
            $kernel->shutdown();
        }
    }

    public function testFatalSourceSelectionIsIndependentOfDiscoveryRootOrder(): void
    {
        $this->source->write('z/Bad.php', '<?php class {');
        $this->source->write('a/Bad.php', '<?php class {');
        $roots = [
            new \Sillbeam\Core\Architecture\DiscoveryRoot($this->source->directory.'/z', 'Z\\'),
            new \Sillbeam\Core\Architecture\DiscoveryRoot($this->source->directory.'/a', 'A\\'),
        ];
        $options = new DiscoveryOptions($this->source->directory.'/src', $this->source->namespace.'\\', $roots, []);
        $result = (new ArchitectureAnalyzer())->analyze($options);
        self::assertSame($this->source->directory.'/a/Bad.php', $result->findings[0]->context['file']);
        self::assertCount(2, $result->findings);
        $reversed = new DiscoveryOptions($this->source->directory.'/src', $this->source->namespace.'\\', array_reverse($roots), []);
        self::assertEquals($result->findings, (new ArchitectureAnalyzer())->analyze($reversed)->findings);
    }

    public function testUnexpectedDescriptorExceptionsAreNotSwallowedAsRuleViolations(): void
    {
        $this->source->php('src/Module/A/AModule.php', 'throw new \\RuntimeException("descriptor execution failed"); #[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('descriptor execution failed');
        (new ArchitectureAnalyzer())->analyze($this->options());
    }

    public function testReferenceDeduplicationUsesPhpCaseInsensitiveSymbolIdentity(): void
    {
        $this->source->php('src/Module/A/AModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $this->source->php('src/Module/B/BModule.php', '#[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class BModule {}');
        $port = $this->source->php('src/Module/B/Port.php', 'interface Port {}');
        $this->source->php('src/Module/A/Consumer.php', 'final class Consumer { public \\'.$port.' $one; public \\'.strtolower($port).' $two; }');
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertCount(1, $result->findings);
        self::assertSame('code.undeclared_dependency', $result->findings[0]->rule);
    }

    public function testDescriptorExecutionErrorsPropagateWithoutBecomingMetadataFindings(): void
    {
        $this->source->php('src/Module/A/AModule.php', 'sillbeamMissingDescriptorFunction(); #[\\Sillbeam\\Core\\Module\\Attribute\\SillbeamModule] final class AModule {}');
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('sillbeamMissingDescriptorFunction');
        (new ArchitectureAnalyzer())->analyze($this->options());
    }

    #[DataProvider('invalidIdentities')]
    public function testInvalidExplicitAndDerivedDescriptorIdsBlockReliableAnalysis(string $area, string $name, string $argument, string $rule): void
    {
        $attribute = 'Sillbeam\\Core\\'.$area.'\\Attribute\\Sillbeam'.$area;
        $class = $this->source->php('src/'.$area.'/Invalid/'.$name.'.php', '#[\\'.$attribute.'('.$argument.')] final class '.$name.' {}');
        $result = (new ArchitectureAnalyzer())->analyze($this->options());
        self::assertFalse($result->isComplete());
        self::assertNull($result->architecture);
        self::assertSame($rule, $result->findings[0]->rule);
        self::assertSame($class, $result->findings[0]->context['class']);
    }

    /** @return iterable<string, array{string, string, string, string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'module explicit' => ['Module', 'AModule', 'id: "Bad_ID"', 'discovery.descriptor_metadata'];
        yield 'project explicit' => ['Project', 'AProject', 'id: "Bad_ID"', 'discovery.descriptor_metadata'];
        yield 'module derived' => ['Module', 'Module', '', 'discovery.descriptor_id'];
        yield 'project derived' => ['Project', 'Project', '', 'discovery.descriptor_id'];
    }

    /** @return iterable<string, array{bool}> */
    public static function doctrineModes(): iterable
    {
        yield 'without Doctrine' => [false];
        yield 'with Doctrine' => [true];
    }

    private function options(): DiscoveryOptions
    {
        return new DiscoveryOptions($this->source->directory.'/src', $this->source->namespace.'\\');
    }
}
