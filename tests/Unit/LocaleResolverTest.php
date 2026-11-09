<?php

declare(strict_types=1);

namespace Sillbeam\Core\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sillbeam\Core\I18n\LocaleResolver;
use Sillbeam\Core\I18n\UserLocaleStore;
use Sillbeam\Core\Tests\Support\SourceWorkspace;

final class LocaleResolverTest extends TestCase
{
    public function testPrecedenceAndIndependentInstances(): void
    {
        $source = new SourceWorkspace();
        try {
            $path = $source->directory.'/settings/locale';
            $store = new UserLocaleStore($path);
            $default = new LocaleResolver($store);
            $project = new LocaleResolver($store, static fn (): string => 'de');

            self::assertSame('en', $default->resolve());
            self::assertSame('de', $project->resolve());
            self::assertDirectoryDoesNotExist(\dirname($path));

            $store->set('en');
            self::assertSame('en', (new UserLocaleStore($path))->read());
            self::assertSame('en', $project->resolve());
            self::assertSame('de', $project->resolve('de'));
            self::assertSame('en', $default->resolve());
            self::assertSame('en', $store->read());

            $store->reset();
            $store->reset();
            self::assertFileDoesNotExist($path);
            self::assertSame('de', $project->resolve());
        } finally {
            $source->remove();
        }
    }

    /** @param array<string, string> $variables */
    #[DataProvider('userDirectories')]
    public function testUserConfigurationPaths(string $platform, array $variables, string $expected): void
    {
        $source = new SourceWorkspace();
        try {
            $environment = static fn (string $name): string|false => isset($variables[$name])
                ? $source->directory.'/'.$variables[$name]
                : ('XDG_CONFIG_HOME' === $name ? 'ignored-relative-directory' : false);
            $store = UserLocaleStore::fromEnvironment($platform, $environment);
            self::assertNull($store->read());
            self::assertDirectoryDoesNotExist($source->directory.'/'.$expected);
            $store->set('de');
            self::assertSame("de\n", file_get_contents($source->directory.'/'.$expected.'/sillbeam/locale'));
        } finally {
            $source->remove();
        }
    }

    /** @return iterable<string, array{string, array<string, string>, string}> */
    public static function userDirectories(): iterable
    {
        yield 'Linux XDG' => ['Linux', ['XDG_CONFIG_HOME' => 'xdg', 'HOME' => 'home'], 'xdg'];
        yield 'Linux home fallback' => ['Linux', ['HOME' => 'home'], 'home/.config'];
        yield 'macOS' => ['Darwin', ['HOME' => 'home'], 'home/Library/Application Support'];
        yield 'Windows roaming' => ['Windows', ['APPDATA' => 'roaming', 'LOCALAPPDATA' => 'local'], 'roaming'];
        yield 'Windows local fallback' => ['Windows', ['LOCALAPPDATA' => 'local'], 'local'];
    }

    public function testConfigurationDirectoryMustBeALocalPath(): void
    {
        $source = new SourceWorkspace();
        try {
            $store = UserLocaleStore::fromEnvironment('Linux', static fn (string $name): string|false => 'XDG_CONFIG_HOME' === $name ? 'file://'.$source->directory.'/user' : false);
            self::assertNull($store->read());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Cannot locate the user configuration directory');
            $store->set('de');
        } finally {
            self::assertDirectoryDoesNotExist($source->directory.'/user');
            $source->remove();
        }
    }

    public function testInvalidExplicitLocaleDoesNotFallThrough(): void
    {
        $resolver = new LocaleResolver(new UserLocaleStore(null), static fn (): string => 'de');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('de-DE');
        $resolver->resolve('de-DE');
    }

    public function testCorruptPreferenceIsDiagnosableAndCanBeReset(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('locale', 'fr');
            $store = new UserLocaleStore($source->directory.'/locale');
            $resolver = new LocaleResolver($store, static fn (): string => 'de');
            self::assertSame('en', $resolver->resolve('en'));
            try {
                $resolver->resolve();
                self::fail('Corrupt preferences must be reported.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($source->directory.'/locale', $error->getMessage());
            }
            self::assertSame('fr', file_get_contents($source->directory.'/locale'));
            $store->reset();
            self::assertSame('de', $resolver->resolve());
        } finally {
            $source->remove();
        }
    }

    public function testNonFilePreferenceCannotBeReadOrRecursivelyRemoved(): void
    {
        $source = new SourceWorkspace();
        try {
            $source->write('locale/keep', 'keep');
            $store = new UserLocaleStore($source->directory.'/locale');
            try {
                $store->read();
                self::fail('A directory cannot be read as a preference.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($source->directory.'/locale', $error->getMessage());
            }
            $this->expectException(\RuntimeException::class);
            $store->reset();
        } finally {
            self::assertFileExists($source->directory.'/locale/keep');
            $source->remove();
        }
    }

    public function testInvalidSettingDoesNotReplaceExistingPreference(): void
    {
        $source = new SourceWorkspace();
        $store = new UserLocaleStore($source->directory.'/locale');
        $store->set('de');
        try {
            $this->expectException(\InvalidArgumentException::class);
            $store->set('DE');
        } finally {
            self::assertSame('de', $store->read());
            $source->remove();
        }
    }
}
