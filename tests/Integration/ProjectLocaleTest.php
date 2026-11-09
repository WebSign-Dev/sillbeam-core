<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Sillbeam\Core\Console\KernelArchitectureReporter;
use Sillbeam\Core\DependencyInjection\Configuration;
use Sillbeam\Core\I18n\LocaleResolver;
use Sillbeam\Core\I18n\UserLocaleStore;
use Sillbeam\Core\Tests\Support\SourceWorkspace;
use Sillbeam\Core\Tests\Support\TestApplicationKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ProjectLocaleTest extends TestCase
{
    public function testProjectLocaleReadsEnvironmentOverlayWithoutArchitectureOrCompilation(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('config/packages/sillbeam.yaml', 'sillbeam: { locale: en }');
            $source->write('config/packages/test/sillbeam.yaml', 'sillbeam: { locale: de }');
            $source->write('src/Module/Broken/Broken.php', '<?php class {');
            $source->write('config/services.yaml', "services:\n    broken: { class: stdClass, public: true, arguments: ['@nonexistent_service'] }");
            $kernel = new TestApplicationKernel($source);
            $reporter = new KernelArchitectureReporter();
            $resolver = new LocaleResolver(new UserLocaleStore(null), static fn (): string => $reporter->projectLocale($kernel));
            self::assertSame('de', $resolver->resolve());
            self::assertDirectoryDoesNotExist($kernel->getCacheDir());
        } finally {
            $source->remove();
        }
    }

    public function testSymfonyDefaultsToEnglishAndRejectsUnsupportedLocale(): void
    {
        $processor = new Processor();
        self::assertSame('en', $processor->processConfiguration(new Configuration(), [])['locale']);
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('sillbeam.locale');
        $processor->processConfiguration(new Configuration(), [['locale' => 'fr']]);
    }
}
