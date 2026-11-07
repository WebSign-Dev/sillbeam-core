<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Api\CoreCapabilitiesProvider;
use Sillbeam\Core\Api\CoreDataDocument;
use Sillbeam\Core\Api\CoreDataJsonSerializer;
use Sillbeam\Core\Api\CoreDataObject;
use Sillbeam\Core\Api\CoreDataProfile;
use Sillbeam\Core\Api\CoreDataRequest;
use Sillbeam\Core\Architecture\ArchitectureReport;
use Sillbeam\Core\Architecture\ArchitectureValidationException;
use Sillbeam\Core\Architecture\Diagnostic\DiagnosticCollection;
use Sillbeam\Core\Architecture\SourceFileParser;
use Sillbeam\Core\Console\Doctor\InstallationInspector;
use Sillbeam\Core\I18n\CoreTranslator;

final class CoreDataFoundationTest extends TestCase
{
    public function testGraphAndReportCliRenderGermanResourceText(): void
    {
        $translator = new CoreTranslator('de');
        $report = ArchitectureReport::fromAnalysis(new \Sillbeam\Core\Architecture\ArchitectureAnalysisResult());
        $graph = new \Symfony\Component\Console\Tester\CommandTester(new \Sillbeam\Core\Architecture\Console\GraphCommand($report, $translator));
        self::assertSame(3, $graph->execute(['--format' => 'bad'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Nicht unterstütztes --format', $graph->getErrorOutput());
        $renderer = new \Sillbeam\Core\Architecture\Console\ReportRenderer($report, $translator);
        $markdown = $renderer->markdown('development', new \DateTimeImmutable('2026-11-07T00:00:00Z'));
        self::assertStringContainsString('# Sillbeam-Architekturbericht', $markdown);
        self::assertStringContainsString('Keine Architekturbefunde.', $markdown);
    }

    public function testDefaultAndAnalysisLocales(): void
    {
        self::assertSame('en', (new CoreDataRequest(CoreDataProfile::Live))->locale);
        self::assertNull((new CoreDataRequest(CoreDataProfile::Analysis))->locale);
        $this->expectException(\InvalidArgumentException::class);
        new CoreDataRequest(CoreDataProfile::Analysis, 'de');
    }

    public function testCapabilitiesAreAvailableWithoutAnApplication(): void
    {
        $contents = file_get_contents(__DIR__.'/../../docs/reference/core-data/examples/capabilities.json');
        self::assertIsString($contents);
        $expected = json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
        $expected['core_version'] = CoreCapabilitiesProvider::coreVersion();
        self::assertSame($expected, (new CoreCapabilitiesProvider())->capabilities()->jsonSerialize());
    }

    public function testParameterizedTranslationAndFallback(): void
    {
        $translator = new CoreTranslator('de');
        self::assertSame('Nicht unterstützte Schema-Version „2“.', $translator->text('api.unsupported_schema', ['version' => 2]));
        $fallback = new CoreTranslator('fr');
        self::assertSame('Unsupported schema version "2".', $fallback->text('api.unsupported_schema', ['version' => 2]));
        self::assertSame([['resource_key' => 'api.unsupported_schema', 'locale' => 'fr', 'source_locale' => 'en']], $fallback->fallbacks());
    }

    public function testBothCatalogsHaveTheSameKeysAndParameters(): void
    {
        $english = require __DIR__.'/../../src/I18n/Resources/en.php';
        $german = require __DIR__.'/../../src/I18n/Resources/de.php';
        self::assertSame(['en', 'de'], CoreTranslator::locales());
        self::assertSame(array_keys($english), array_keys($german));
        foreach ($english as $key => $text) {
            preg_match_all('/\{([a-z_]+)\}/', $text, $en);
            preg_match_all('/\{([a-z_]+)\}/', $german[$key], $de);
            self::assertEqualsCanonicalizing($en[1], $de[1], $key);
            self::assertNotSame('', trim($german[$key]), $key);
        }
    }

    public function testSourceFailureCanBeTranslatedWithoutChangingLegacyReport(): void
    {
        $path = __DIR__.'/missing-{path}.php';
        try {
            (new SourceFileParser())->parse($path);
            self::fail('Expected an unreadable source finding.');
        } catch (ArchitectureValidationException $error) {
            $analysis = $error->result;
        }
        $translator = new CoreTranslator('de');
        $finding = $analysis->findings[0];
        self::assertSame('Sillbeam-Quelldatei „'.$path.'“ kann nicht gelesen werden.', $finding->localizedMessage($translator));
        $detail = DiagnosticCollection::fromAnalysis($analysis, $translator)->diagnoses[0]->details[0];
        self::assertStringContainsString('Eine Quelldatei konnte nicht gelesen werden.', $detail->problem);
        self::assertStringContainsString('Prüfe, ob die gemeldete Datei existiert', $detail->resolutions[0]->steps[0]);
        self::assertSame([], $translator->fallbacks());
        $legacy = ArchitectureReport::fromAnalysis($analysis)->data['findings'][0];
        self::assertSame('Cannot read Sillbeam source "'.$path.'".', $legacy['message']);
        self::assertSame(['rule', 'message', 'context', 'severity'], array_keys($legacy));
    }

    public function testDoctorLocalizesCoreErrorsAndPreservesCheckIdentity(): void
    {
        $directory = __DIR__.'/missing-installation';
        $translator = new CoreTranslator('de');
        $inspector = new InstallationInspector();
        $english = $inspector->inspect($directory, 'test');
        $german = $inspector->inspect($directory, 'test', translator: $translator);
        self::assertSame(array_column($english, 'id'), array_column($german, 'id'));
        self::assertSame(array_column($english, 'status'), array_column($german, 'status'));
        self::assertSame('Bundle-Registrierung', $german[0]['label']);
        self::assertSame('Datei kann nicht gelesen werden: '.$directory.'/config/bundles.php Prüfe diese Datei manuell.', $german[0]['detail']);
        self::assertSame([], $translator->fallbacks());
    }

    public function testSerializerPreservesBothNormativeDocumentShapes(): void
    {
        foreach (['live.de.json', 'analysis.json'] as $file) {
            $contents = file_get_contents(__DIR__.'/../../docs/reference/core-data/examples/'.$file);
            self::assertIsString($contents);
            $example = json_decode($contents, flags: \JSON_THROW_ON_ERROR);
            self::assertInstanceOf(\stdClass::class, $example);
            $request = CoreDataRequest::fromProfile($example->profile, $example->i18n->requested_locale, $example->schema_version);
            $document = new CoreDataDocument(
                $request,
                self::object($example->metadata),
                self::object($example->i18n),
                self::object($example->analysis),
                self::object($example->export),
                self::object($example->completeness),
                self::object($example->summary),
                self::object($example->architecture),
                self::object($example->diagnostics),
                self::object($example->references),
                array_map(self::object(...), array_values($example->errors)),
                self::object($example->extensions),
                isset($example->graph) ? self::object($example->graph) : null,
                isset($example->source_inventory) ? self::object($example->source_inventory) : null,
                isset($example->doctor) ? self::object($example->doctor) : null,
            );

            $json = (new CoreDataJsonSerializer())->serialize($document);
            self::assertSame(json_encode($example, \JSON_THROW_ON_ERROR), json_encode(json_decode($json, flags: \JSON_THROW_ON_ERROR), \JSON_THROW_ON_ERROR));
        }
    }

    public function testUnknownProfileIsRejectedWithAStableMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported profile "unknown".');
        CoreDataRequest::fromProfile('unknown');
    }

    public function testUnsupportedLocaleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported locale "de-DE".');
        CoreDataRequest::fromProfile('live', 'de-DE');
    }

    public function testUnsupportedSchemaVersionIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported schema version "2".');
        CoreDataRequest::fromProfile('live', schemaVersion: 2);
    }

    public function testJsonObjectCopiesListValuesAndPreservesAnEmptyMap(): void
    {
        $value = 'original';
        $object = new CoreDataObject(['list' => [&$value], 'empty' => new CoreDataObject()]);
        $value = 'changed';

        self::assertSame('{"list":["original"],"empty":{}}', json_encode($object, \JSON_THROW_ON_ERROR));
    }

    private static function object(\stdClass $object): CoreDataObject
    {
        return new CoreDataObject(array_map(self::value(...), get_object_vars($object)));
    }

    private static function value(mixed $value): mixed
    {
        return match (true) {
            $value instanceof \stdClass => self::object($value),
            \is_array($value) => array_map(self::value(...), $value),
            default => $value,
        };
    }
}
