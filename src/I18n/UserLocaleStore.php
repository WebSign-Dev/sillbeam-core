<?php

declare(strict_types=1);

namespace Sillbeam\Core\I18n;

use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;

/** Stores one locale in the current user's local configuration directory. */
final readonly class UserLocaleStore
{
    public function __construct(private ?string $path, private Filesystem $filesystem = new Filesystem())
    {
    }

    /** @param (\Closure(string): (string|false))|null $environment */
    public static function fromEnvironment(string $platform = \PHP_OS_FAMILY, ?\Closure $environment = null): self
    {
        $environment ??= getenv(...);
        $home = self::absoluteDirectory($environment('HOME'));
        $directory = match ($platform) {
            'Windows' => self::absoluteDirectory($environment('APPDATA'))
                ?? self::absoluteDirectory($environment('LOCALAPPDATA')),
            'Darwin' => null === $home ? null : Path::join($home, 'Library', 'Application Support'),
            default => self::absoluteDirectory($environment('XDG_CONFIG_HOME'))
                ?? (null === $home ? null : Path::join($home, '.config')),
        };

        return new self(null === $directory ? null : Path::join($directory, 'sillbeam', 'locale'));
    }

    public function read(): ?string
    {
        if (null === $this->path || (!$this->filesystem->exists($this->path) && !is_link($this->path))) {
            return null;
        }
        if (!is_file($this->path)) {
            throw CoreError::create(\RuntimeException::class, 'locale.preference_read', ['path' => $this->path]);
        }
        try {
            $locale = rtrim($this->filesystem->readFile($this->path), "\r\n");
        } catch (IOException $error) {
            throw CoreError::create(\RuntimeException::class, 'locale.preference_read', ['path' => $this->path], $error);
        }
        if (!\in_array($locale, CoreTranslator::locales(), true)) {
            throw CoreError::create(\RuntimeException::class, 'locale.preference_invalid', ['path' => $this->path]);
        }

        return $locale;
    }

    public function set(string $locale): void
    {
        LocaleResolver::requireSupported($locale);
        if (null === $this->path) {
            throw CoreError::create(\RuntimeException::class, 'locale.directory_unavailable');
        }
        $this->filesystem->dumpFile($this->path, $locale."\n");
    }

    public function reset(): void
    {
        if (null === $this->path || (!$this->filesystem->exists($this->path) && !is_link($this->path))) {
            return;
        }
        if ((!is_link($this->path) && is_dir($this->path)) || !@unlink($this->path)) {
            throw CoreError::create(\RuntimeException::class, 'locale.preference_reset', ['path' => $this->path]);
        }
    }

    private static function absoluteDirectory(string|false $path): ?string
    {
        return false !== $path && !str_contains($path, '://') && Path::isAbsolute($path) ? $path : null;
    }
}
