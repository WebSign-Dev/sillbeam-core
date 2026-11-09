<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

$repositoryDirectory = dirname(__DIR__);
$recipeDirectory = $repositoryDirectory.'/flex-recipe/websign-dev/sillbeam-core/0.3';
$autoload = $repositoryDirectory.'/vendor/autoload.php';

if (!is_file($autoload)) {
    fwrite(\STDERR, "Install the Sillbeam development dependencies before testing the recipe.\n");
    exit(1);
}

require $autoload;

$withDoctrine = '1' === getenv('SILLBEAM_TEST_DOCTRINE');
$fresh = '1' === getenv('SILLBEAM_TEST_FRESH');
$symfony = getenv('SILLBEAM_SYMFONY_VERSION') ?: null;
$requirements = ['websign-dev/sillbeam-core' => '0.3.0', 'symfony/flex' => '^2.11', 'symfony/framework-bundle' => $symfony ?? '^7.4 || ^8.0', 'symfony/console' => $symfony ?? '^7.4 || ^8.0', 'symfony/yaml' => $symfony ?? '^7.4 || ^8.0'];
$conflicts = [];
if ($withDoctrine) {
    $requirements['doctrine/doctrine-bundle'] = '^3.3';
    $requirements['doctrine/orm'] = '^3.6';
    $requirements['symfony/doctrine-bridge'] = $symfony ?? '^7.4 || ^8.0';
}
if (null !== $symfony) {
    [$major, $minor] = array_map('intval', explode('.', $symfony));
    $lock = decodeJson($repositoryDirectory.'/composer.lock');
    foreach ([...$lock['packages'], ...$lock['packages-dev']] as $dependency) {
        if (str_starts_with($dependency['name'], 'symfony/') && preg_match('/^v?[78]\./', $dependency['version'])) {
            $conflicts[$dependency['name']] = '<'.$major.'.'.$minor.' || >='.$major.'.'.($minor + 1);
        }
    }
}

$temporaryDirectory = sys_get_temp_dir().'/sillbeam-flex-recipe-'.bin2hex(random_bytes(8));
$endpointDirectory = $temporaryDirectory.'/endpoint';
$applicationDirectory = $temporaryDirectory.'/application';
$endpointServer = null;
$endpointPipes = [];

mkdir($endpointDirectory, 0777, true);
mkdir($applicationDirectory, 0777, true);

try {
    $manifest = decodeJson($recipeDirectory.'/manifest.json');
    // The published endpoint embeds post-install.txt; it is not a source manifest key.
    $instructions = file($recipeDirectory.'/post-install.txt', \FILE_IGNORE_NEW_LINES);
    if (false === $instructions) {
        throw new RuntimeException('Unable to read post-install.txt.');
    }
    $manifest['post-install-output'] = $instructions;
    $files = [];

    foreach (recipeFiles($recipeDirectory.'/config') as $file) {
        $relativePath = substr($file, strlen($recipeDirectory) + 1);
        $contents = file($file, \FILE_IGNORE_NEW_LINES);

        if (false === $contents) {
            throw new RuntimeException(sprintf('Unable to read recipe file "%s".', $file));
        }

        $files[$relativePath] = [
            'contents' => $contents,
            'executable' => false,
        ];
    }

    $recipeResponse = [
        'manifests' => [
            'websign-dev/sillbeam-core' => [
                'manifest' => $manifest,
                'files' => $files,
                'ref' => hashDirectory($recipeDirectory),
            ],
        ],
    ];
    writeJson($endpointDirectory.'/websign-dev.sillbeam-core.0.3.json', $recipeResponse);

    $endpointPort = availablePort();
    $endpointBaseUrl = sprintf('http://127.0.0.1:%d', $endpointPort);
    $endpoint = [
        'recipes' => [
            'websign-dev/sillbeam-core' => ['0.3'],
        ],
        'branch' => 'main',
        'is_contrib' => true,
        '_links' => [
            'repository' => 'local/sillbeam-flex-recipe',
            'origin_template' => '{package}:{version}@local/sillbeam-flex-recipe:main',
            'recipe_template' => $endpointBaseUrl.'/{package_dotted}.{version}.json',
        ],
    ];
    writeJson($endpointDirectory.'/index.json', $endpoint);

    [$endpointServer, $endpointPipes] = startEndpointServer($endpointDirectory, $endpointPort);
    waitForEndpoint($endpointBaseUrl.'/index.json');

    $packageDirectory = $temporaryDirectory.'/package';
    foreach ([$repositoryDirectory.'/composer.json', $repositoryDirectory.'/LICENSE', $repositoryDirectory.'/README.md', ...recipeFiles($repositoryDirectory.'/src')] as $file) {
        $target = $packageDirectory.substr($file, strlen($repositoryDirectory));
        if (!is_dir(dirname($target))) {
            mkdir(dirname($target), 0777, true);
        }
        copy($file, $target);
    }

    writeJson($applicationDirectory.'/composer.json', [
        'name' => 'sillbeam/flex-recipe-test',
        'type' => 'project',
        'repositories' => '1' === getenv('SILLBEAM_TEST_RELEASED') ? [] : [[
            'type' => 'path',
            'url' => $packageDirectory,
            'options' => ['symlink' => false, 'versions' => ['websign-dev/sillbeam-core' => '0.3.0']],
        ]],
        'require' => $requirements,
        'conflict' => (object) $conflicts,
        'scripts' => [
            'auto-scripts' => (object) [],
            'post-install-cmd' => ['@auto-scripts'],
            'post-update-cmd' => ['@auto-scripts'],
        ],
        'autoload' => ['psr-4' => ['App\\' => 'src/']],
        'config' => [
            'allow-plugins' => ['symfony/flex' => true],
            'secure-http' => false,
            'sort-packages' => true,
        ],
        'extra' => [
            'symfony' => [
                'allow-contrib' => true,
                'endpoint' => [$endpointBaseUrl.'/index.json'],
            ],
        ],
        'prefer-stable' => true,
    ]);

    // Seed application-owned files before Flex runs, including a normal broad import.
    mkdir($applicationDirectory.'/config/routes', 0777, true);
    mkdir($applicationDirectory.'/bin', 0777, true);
    mkdir($applicationDirectory.'/config/packages', 0777, true);
    $applicationFiles = [
        'config/services.yaml' => <<<'YAML'
# Application-owned service configuration; preserve this comment and all imports.
services:
    _defaults: { autowire: true, autoconfigure: true }
    App\:
        resource: '../src/'
        exclude: '../src/{DependencyInjection,Entity,Kernel.php}'
YAML,
        'config/packages/application.yaml' => "parameters: { application_marker: preserved }\n",
        'config/routes/application.yaml' => "application_status: { path: /application-status }\n",
        'config/routes.yaml' => "# Application-owned routing entry point.\n{}\n",
        'bin/console' => <<<'PHP'
#!/usr/bin/env php
<?php
// Application-owned Console bootstrap. No recipe edits.
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
require dirname(__DIR__).'/vendor/autoload.php';
$kernel = new Kernel('dev', true);
$application = new Application($kernel);
$application->run();
PHP,
    ];
    if ('1' === getenv('SILLBEAM_TEST_EXISTING_ROUTE')) {
        $applicationFiles['config/routes/sillbeam.yaml'] = "# Existing application-authored import must not be overwritten.\n".file_get_contents($recipeDirectory.'/config/routes/sillbeam.yaml');
    }
    foreach ($applicationFiles as $file => $contents) {
        file_put_contents($applicationDirectory.'/'.$file, $contents);
    }
    // A real existing consumer has its Symfony dependencies installed and boots
    // before Core is added; a fresh consumer resolves everything in one install.
    mkdir($applicationDirectory.'/src', 0777, true);
    file_put_contents($applicationDirectory.'/src/Kernel.php', '<?php namespace App; final class Kernel extends \\Symfony\\Component\\HttpKernel\\Kernel { use \\Symfony\\Bundle\\FrameworkBundle\\Kernel\\MicroKernelTrait; }');
    $nativeBundles = [Symfony\Bundle\FrameworkBundle\FrameworkBundle::class => ['all' => true]];
    if ($withDoctrine) {
        $nativeBundles[Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class] = ['all' => true];
        file_put_contents($applicationDirectory.'/config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm: { auto_mapping: false }\n");
    }
    file_put_contents($applicationDirectory.'/config/bundles.php', '<?php return '.var_export($nativeBundles, true).';');
    file_put_contents($applicationDirectory.'/config/packages/framework.yaml', "framework:\n    secret: smoke\n    router: { utf8: true }\n");
    if (!$fresh) {
        $composer = decodeJson($applicationDirectory.'/composer.json');
        $composer['conflict'] = (object) $composer['conflict'];
        $composer['scripts']['auto-scripts'] = (object) $composer['scripts']['auto-scripts'];
        unset($composer['require']['websign-dev/sillbeam-core']);
        writeJson($applicationDirectory.'/composer.json', $composer);
        runComposer($applicationDirectory);
        consumerConsole($applicationDirectory, ['about'], 0);
        $composer['require']['websign-dev/sillbeam-core'] = '0.3.0';
        writeJson($applicationDirectory.'/composer.json', $composer);
    }
    $output = runComposer($applicationDirectory, $fresh ? 'install' : 'update');
    foreach ($instructions as $line) {
        if ('' !== trim($line) && !str_contains($output, $line)) {
            throw new RuntimeException('Flex did not print post-install.txt line: '.$line);
        }
    }
    foreach ($applicationFiles as $file => $contents) {
        assertSame($contents, file_get_contents($applicationDirectory.'/'.$file), 'Recipe installation changed '.$file);
    }
    assertSame(false, is_file($applicationDirectory.'/post-install.txt'), 'Installation instructions must be displayed, not copied into the application.');

    $bundles = require $applicationDirectory.'/config/bundles.php';
    assertSame(['all' => true], $bundles['Sillbeam\\Core\\SillbeamCoreBundle'] ?? null, 'SillbeamCoreBundle was not registered.');
    $sillbeamBundles = array_values(array_filter(
        array_keys($bundles),
        static fn (string $bundle): bool => str_starts_with($bundle, 'Sillbeam\\Core\\'),
    ));
    assertSame(['Sillbeam\\Core\\SillbeamCoreBundle'], $sillbeamBundles, 'The recipe must register only the central SillbeamCoreBundle.');

    assertSame(false, is_file($applicationDirectory.'/config/packages/sillbeam.yaml'), 'The recipe must not install an empty package configuration.');

    $routes = Yaml::parseFile($applicationDirectory.'/config/routes/sillbeam.yaml');
    assertSame(['sillbeam' => ['resource' => '.', 'type' => 'sillbeam']], $routes, 'The Sillbeam route loader was not installed.');

    $lock = decodeJson($applicationDirectory.'/symfony.lock');
    assertSame('0.3', $lock['websign-dev/sillbeam-core']['recipe']['version'] ?? null, 'The recipe is missing from symfony.lock.');
    $installedFiles = $lock['websign-dev/sillbeam-core']['files'] ?? [];
    sort($installedFiles);
    assertSame([
        'config/routes/sillbeam.yaml',
    ], $installedFiles, 'symfony.lock does not track only the Sillbeam route import.');

    // The pre-install "about" boot caches a container without Core. This fixture has
    // no cache-clear auto-script; same-second bundle changes can still look fresh.
    removeDirectory($applicationDirectory.'/var/cache');
    verifyOnboarding($applicationDirectory);
    // These two edits are explicit developer decisions in this disposable app,
    // not recipe or Doctor writes. Preserve the reviewed state on uninstall.
    foreach (['config/services.yaml', 'bin/console'] as $file) {
        $applicationFiles[$file] = file_get_contents($applicationDirectory.'/'.$file);
    }
    bootConsumer($applicationDirectory, $withDoctrine);
    verifyMaintenanceWithInvalidArchitecture($applicationDirectory, $temporaryDirectory);
    uninstallRecipe($applicationDirectory);
    // Flex owns the recipe destination even if it existed; document and test this boundary.
    unset($applicationFiles['config/routes/sillbeam.yaml']);
    foreach ($applicationFiles as $file => $contents) {
        assertSame($contents, file_get_contents($applicationDirectory.'/'.$file), 'Recipe removal changed '.$file);
    }
    fwrite(\STDOUT, 'Sillbeam Flex recipe installation, both consumer kernels and removal passed ('.($fresh ? 'fresh' : 'existing').' application, '.($withDoctrine ? 'with Doctrine' : 'without Doctrine').").\n");
} finally {
    if (is_resource($endpointServer)) {
        proc_terminate($endpointServer);
        proc_close($endpointServer);
    }
    foreach ($endpointPipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    removeDirectory($temporaryDirectory);
}

/** @return list<string> */
function recipeFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/** @return array<string, mixed> */
function decodeJson(string $file): array
{
    $contents = file_get_contents($file);

    if (false === $contents) {
        throw new RuntimeException(sprintf('Unable to read "%s".', $file));
    }

    return json_decode($contents, true, flags: \JSON_THROW_ON_ERROR);
}

/** @param array<string, mixed> $data */
function writeJson(string $file, array $data): void
{
    $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

    if (false === file_put_contents($file, $json."\n")) {
        throw new RuntimeException(sprintf('Unable to write "%s".', $file));
    }
}

function hashDirectory(string $directory): string
{
    $hash = hash_init('sha1');

    foreach (recipeFiles($directory) as $file) {
        hash_update($hash, substr($file, strlen($directory) + 1));
        hash_update_file($hash, $file);
    }

    return hash_final($hash);
}

function runComposer(string $workingDirectory, string $operation = 'install'): string
{
    $output = tmpfile();
    if (false === $output) {
        throw new RuntimeException('Unable to capture Composer output.');
    }
    $process = proc_open([
        'composer',
        $operation,
        '--no-interaction',
        ...('dump-autoload' === $operation ? [] : ['--no-progress', '--prefer-dist']),
    ], [\STDIN, $output, $output], $pipes, $workingDirectory);

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Composer.');
    }

    $exitCode = proc_close($process);
    rewind($output);
    $contents = stream_get_contents($output);
    fclose($output);
    if (false === $contents) {
        throw new RuntimeException('Unable to read Composer output.');
    }
    fwrite(\STDOUT, $contents);

    if (0 !== $exitCode) {
        throw new RuntimeException(sprintf('Composer %s exited with status %d.', $operation, $exitCode)."\n".$contents);
    }

    return $contents;
}

/** Runs the real application console; captures output for actionable failure messages.
 * @param list<string> $arguments
 */
function consumerConsole(string $directory, array $arguments, int $expected): string
{
    $output = tmpfile();
    if (false === $output) {
        throw new RuntimeException('Unable to capture consumer console output.');
    }
    $process = proc_open([\PHP_BINARY, 'bin/console', ...$arguments, '--no-ansi'], [\STDIN, $output, $output], $pipes, $directory);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to run consumer console.');
    }
    $code = proc_close($process);
    rewind($output);
    $text = (string) stream_get_contents($output);
    fclose($output);
    if ($expected !== $code) {
        throw new RuntimeException(implode(' ', $arguments).' returned '.$code.' instead of '.$expected.': '.$text);
    }

    return $text;
}

/** Exercises Flex's real symfony-cmd auto-script in a disposable consumer. */
function verifyMaintenanceWithInvalidArchitecture(string $directory, string $temporaryDirectory): void
{
    $module = $directory.'/src/Module/Hello/HelloModule.php';
    $valid = (string) file_get_contents($module);
    file_put_contents($module, '<?php namespace App\Module\Hello; #[\Sillbeam\Core\Module\Attribute\SillbeamModule(dependencies: [\Missing\Module::class])] final class HelloModule {}');

    $kernelFile = $directory.'/src/Kernel.php';
    $kernel = (string) file_get_contents($kernelFile);
    $boot = <<<'PHP'
public function boot(): void {
    parent::boot();
    if (!class_exists(\SillbeamLifecyclePackage\Dependency::class)) {
        throw new \RuntimeException('The application requires '.\SillbeamLifecyclePackage\Dependency::class);
    }
}
PHP;
    file_put_contents($kernelFile, str_replace('public function getProjectDir()', $boot.' public function getProjectDir()', $kernel));
    $failure = consumerConsole($directory, ['cache:clear'], 1);
    assertSame(true, str_contains($failure, 'SillbeamLifecyclePackage\Dependency'), 'Symfony must report the missing application dependency.');
    assertSame(false, str_contains($failure, 'dependency.unknown'), 'The missing application class was misclassified as a Core finding.');
    $report = json_decode(consumerConsole($directory, ['sillbeam:architecture', '--format=json'], 1), true, flags: \JSON_THROW_ON_ERROR);
    assertSame('dependency.unknown', $report['findings'][0]['rule'], 'Architecture diagnostic could not run with the broken container.');
    $snapshot = json_decode(consumerConsole($directory, ['sillbeam:api', '--profile=analysis'], 1), true, flags: \JSON_THROW_ON_ERROR);
    assertSame('dependency.unknown', $snapshot['diagnostics']['findings'][0]['rule'], 'Core Data API lost the architecture finding.');
    $doctor = json_decode(consumerConsole($directory, ['sillbeam:doctor', '--format=json'], 2), true, flags: \JSON_THROW_ON_ERROR);
    assertSame('not verified', $doctor['status'], 'Doctor changed how it reports the custom consumer bootstrap.');
    assertSame([], $doctor['errors'], 'Doctor failed to inspect the installation before container boot.');

    $package = $temporaryDirectory.'/lifecycle-dependency';
    mkdir($package);
    writeJson($package.'/composer.json', [
        'name' => 'sillbeam-test/lifecycle-dependency',
        'version' => '1.0.0',
        'autoload' => ['psr-4' => ['SillbeamLifecyclePackage\\' => '']],
    ]);
    file_put_contents($package.'/Dependency.php', '<?php namespace SillbeamLifecyclePackage; class Dependency {}');

    $composer = decodeJson($directory.'/composer.json');
    $composer['require']['sillbeam-test/lifecycle-dependency'] = '1.0.0';
    $composer['repositories'][] = ['type' => 'path', 'url' => $package, 'options' => ['symlink' => false]];
    $composer['conflict'] = (object) $composer['conflict'];
    $composer['scripts']['auto-scripts'] = ['cache:clear' => 'symfony-cmd'];
    $composer['scripts']['post-autoload-dump'] = ['@auto-scripts'];
    writeJson($directory.'/composer.json', $composer);

    foreach (['update', 'install', 'dump-autoload'] as $operation) {
        if ('install' === $operation) {
            removeDirectory($directory.'/vendor');
            removeDirectory($directory.'/var/cache');
        }
        $output = runComposer($directory, $operation);
        assertSame(true, str_contains($output, 'Executing script cache:clear [OK]'), $operation.' did not run the Flex cache-clear auto-script.');
    }
    assertSame(true, is_file($directory.'/vendor/sillbeam-test/lifecycle-dependency/Dependency.php'), 'Composer did not install the missing package.');
    consumerConsole($directory, ['about'], 0);
    consumerConsole($directory, ['sillbeam:architecture', '--format=json'], 1);
    file_put_contents($module, $valid);
    file_put_contents($kernelFile, $kernel);
    consumerConsole($directory, ['cache:clear'], 0);
    consumerConsole($directory, ['sillbeam:architecture'], 0);
}

/** Manual corrections in a disposable consumer; Doctor itself must remain read-only. */
function verifyOnboarding(string $directory): void
{
    $native = consumerConsole($directory, ['sillbeam:doctor'], 1);
    assertSame(true, str_contains($native, 'Console adapter: action required'), 'Missing adapter needs instructions: '.$native);
    $nativeJson = json_decode(consumerConsole($directory, ['sillbeam:doctor', '--format=json'], 1), true, flags: \JSON_THROW_ON_ERROR);
    assertSame(1, $nativeJson['schema_version'], 'Native Doctor JSON schema version changed.');
    assertSame('action required', $nativeJson['status'], 'Native Doctor JSON lost action items.');
    $console = (string) file_get_contents($directory.'/bin/console');
    file_put_contents($directory.'/bin/console', str_replace('use Symfony\\Bundle\\FrameworkBundle\\Console\\Application;', 'use Sillbeam\\Core\\Console\\Application;', $console));
    $bundles = (string) file_get_contents($directory.'/config/bundles.php');
    $route = (string) file_get_contents($directory.'/config/routes/sillbeam.yaml');
    // Keep the same conventional class::class syntax as Flex's bundle configurator.
    file_put_contents($directory.'/config/bundles.php', preg_replace('/^.*SillbeamCoreBundle::class.*\n/m', '', $bundles));
    file_put_contents($directory.'/config/routes/sillbeam.yaml', '{}');
    $before = hashDirectory($directory);
    $broken = consumerConsole($directory, ['sillbeam:doctor'], 1);
    foreach (['Bundle registration: action required', 'Route import: action required', 'Service imports: action required'] as $message) {
        assertSame(true, str_contains($broken, $message), 'Missing broken-install diagnosis: '.$message);
    }
    $brokenJson = json_decode(consumerConsole($directory, ['sillbeam:doctor', '--format=json'], 1), true, flags: \JSON_THROW_ON_ERROR);
    assertSame([], $brokenJson['errors'], 'Expected Inspector action items became execution errors.');
    assertSame($before, hashDirectory($directory), 'Doctor wrote consumer files while diagnosing a broken installation.');
    file_put_contents($directory.'/config/bundles.php', $bundles);
    file_put_contents($directory.'/config/routes/sillbeam.yaml', $route);
    $services = (string) file_get_contents($directory.'/config/services.yaml');
    file_put_contents($directory.'/config/services.yaml', str_replace('{DependencyInjection,Entity,Kernel.php}', '{DependencyInjection,Entity,Kernel.php,Module,Project}', $services));
    $before = hashDirectory($directory);
    consumerConsole($directory, ['sillbeam:doctor'], 0);
    $verifiedJson = json_decode(consumerConsole($directory, ['sillbeam:doctor', '--format=json'], 0), true, flags: \JSON_THROW_ON_ERROR);
    assertSame('verified', $verifiedJson['status'], 'Doctor JSON lost the verified state.');
    assertSame('bundle.registration', $verifiedJson['checks'][0]['id'], 'Doctor JSON check ID changed.');
    assertSame($before, hashDirectory($directory), 'Doctor wrote consumer files after manual repair.');
    // Dynamic PHP must not be executed even when every literal check looks good.
    file_put_contents($directory.'/config/packages/unresolved.php', '<?php file_put_contents(__DIR__."/executed", "bad");');
    $before = hashDirectory($directory);
    consumerConsole($directory, ['sillbeam:doctor'], 2);
    $unknownJson = json_decode(consumerConsole($directory, ['sillbeam:doctor', '--format=json'], 2), true, flags: \JSON_THROW_ON_ERROR);
    assertSame('not verified', $unknownJson['status'], 'Doctor JSON claimed success for dynamic configuration.');
    assertSame($before, hashDirectory($directory), 'Doctor executed unresolved configuration.');
    unlink($directory.'/config/packages/unresolved.php');
    consumerConsole($directory, ['sillbeam:make:module', 'Hello'], 0);
    consumerConsole($directory, ['sillbeam:architecture'], 0);
    fwrite(\STDOUT, "Doctor repair, unchanged consumer files, unresolved configuration and first module creation passed.\n");
}

/** Boots the installed consumer in another process without the repository development autoloader. */
function bootConsumer(string $directory, bool $withDoctrine): void
{
    if (!is_dir($directory.'/config/packages')) {
        mkdir($directory.'/config/packages', 0777, true);
    }
    $bundles = require $directory.'/config/bundles.php';
    $bundles[Symfony\Bundle\FrameworkBundle\FrameworkBundle::class] = ['all' => true];
    if ($withDoctrine) {
        $bundles[Doctrine\Bundle\DoctrineBundle\DoctrineBundle::class] = ['all' => true];
        file_put_contents($directory.'/config/packages/doctrine.yaml', "doctrine:\n    dbal: { url: 'sqlite:///:memory:' }\n    orm: { auto_mapping: false }\n");
    }
    file_put_contents($directory.'/config/bundles.php', '<?php return '.var_export($bundles, true).';');
    file_put_contents($directory.'/config/packages/framework.yaml', "framework:\n    secret: smoke\n    router: { utf8: true }\n");
    mkdir($directory.'/src/Module/Off', 0777, true);
    file_put_contents($directory.'/src/Module/Off/OffModule.php', '<?php namespace App\Module\Off; #[\Sillbeam\Core\Module\Attribute\SillbeamModule] final class OffModule {}');
    file_put_contents($directory.'/src/Module/Off/Leak.php', '<?php namespace App\Module\Off; #[\Symfony\Component\DependencyInjection\Attribute\AsAlias(id: "off.leak", public: true)] final class Leak {}');
    file_put_contents($directory.'/src/Legacy.php', '<?php namespace App; #[\Symfony\Component\DependencyInjection\Attribute\AsAlias(id: "legacy", public: true)] final class Legacy {}');
    mkdir($directory.'/src/Module/Hello/Controller', 0777, true);
    assertSame(true, is_file($directory.'/src/Module/Hello/HelloModule.php'), 'Onboarding must create Hello with the existing make command.');
    // Execute the documented service/controller example, not a parallel imitation.
    $guide = (string) file_get_contents(__DIR__.'/../docs/guides/getting-started.md');
    preg_match_all('/```php\n(.*?)\n```/s', $guide, $examples);
    foreach (['Greeting', 'HelloController'] as $class) {
        $snippets = array_values(array_filter($examples[1], static fn (string $code): bool => str_contains($code, 'class '.$class)));
        assertSame(1, count($snippets), 'Missing or ambiguous documented class: '.$class);
        $path = 'HelloController' === $class ? 'Controller/'.$class : $class;
        file_put_contents($directory.'/src/Module/Hello/'.$path.'.php', "<?php\n".$snippets[0]."\n");
    }
    if ($withDoctrine) {
        mkdir($directory.'/src/Module/Hello/Entity', 0777, true);
        file_put_contents($directory.'/src/Module/Hello/Entity/Record.php', '<?php namespace App\Module\Hello\Entity; #[\Doctrine\ORM\Mapping\Entity] final class Record { #[\Doctrine\ORM\Mapping\Id] #[\Doctrine\ORM\Mapping\Column(type: "integer")] public int $id = 1; }');
    }
    file_put_contents($directory.'/src/Kernel.php', <<<'PHP'
<?php
namespace App;
final class Kernel extends \Symfony\Component\HttpKernel\Kernel
{
    use \Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
    public function getProjectDir(): string { return \dirname(__DIR__); }
}
PHP);
    file_put_contents($directory.'/smoke.php', <<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
if (class_exists('Doctrine\Bundle\DoctrineBundle\DoctrineBundle') !== ('1' === $argv[1])) { throw new \RuntimeException('Unexpected Doctrine installation.'); }
$zero = 'zero' === ($argv[3] ?? null);
if ($zero && is_file(__DIR__.'/config/packages/sillbeam.yaml')) { throw new \RuntimeException('Zero-config installation requires no package configuration.'); }
$kernel = new App\Kernel($argv[2], 'dev' === $argv[2]);
$kernel->boot();
try {
    if (!$kernel->getContainer()->has('legacy') || $kernel->getContainer()->has('off.leak') !== $zero) { throw new \RuntimeException('Skeleton service import boundaries failed.'); }
    $registry = $kernel->getContainer()->get(\Sillbeam\Core\Module\ModuleRegistryInterface::class);
    if (($zero ? 2 : 1) !== \count($registry->active())) { throw new \RuntimeException('Missing active module.'); }
    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    foreach (['hello' => 'active', 'off' => ($zero ? 'active' : 'disabled')] as $module => $state) {
        $output = new \Symfony\Component\Console\Output\BufferedOutput();
        $code = $application->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'sillbeam:inspect:module', 'module' => $module]), $output);
        $text = $output->fetch();
        if (0 !== $code || !str_contains($text, 'Module: '.$module.' — '.ucfirst($state))) { throw new \RuntimeException('Compiled module inspection failed (exit '.$code.'): '.$text); }
        $verboseOutput = new \Symfony\Component\Console\Output\BufferedOutput(\Symfony\Component\Console\Output\OutputInterface::VERBOSITY_VERBOSE);
        $verboseCode = $application->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'sillbeam:inspect:module', 'module' => $module]), $verboseOutput);
        $verboseText = $verboseOutput->fetch();
        foreach (['Module: '.$module.' — '.ucfirst($state), 'ID: '.$module, 'State: '.$state, 'Active: '.('active' === $state ? 'true' : 'false')] as $expected) {
            if (0 !== $verboseCode || !str_contains($verboseText, $expected)) { throw new \RuntimeException('Verbose compiled module inspection failed (exit '.$verboseCode.'): '.$verboseText); }
        }
    }
    $graphOutput = new \Symfony\Component\Console\Output\BufferedOutput();
    if (0 !== $application->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'sillbeam:graph', '--format' => 'mermaid']), $graphOutput) || !str_starts_with($graphOutput->fetch(), 'flowchart LR')) { throw new \RuntimeException('Compiled Mermaid export failed.'); }
    $response = $kernel->handle(\Symfony\Component\HttpFoundation\Request::create('/hello'));
    if (200 !== $response->getStatusCode() || 'Hello from Sillbeam' !== $response->getContent()) { throw new \RuntimeException('Module route failed.'); }
    if ('1' === $argv[1]) {
        $manager = $kernel->getContainer()->get('doctrine.orm.entity_manager');
        if (1 !== count($manager->getMetadataFactory()->getAllMetadata())) { throw new \RuntimeException('Recipe consumer mapping failed.'); }
    }
    echo 'PASS smoke: kernel='.get_parent_class($kernel).' env='.$argv[2].' config='.($zero ? 'zero' : 'configured').' hello=active off='.($zero ? 'active' : 'disabled').' compact+verbose inspections, Mermaid, HTTP'.('1' === $argv[1] ? ', Doctrine mapping' : '')."\n";
} finally { $kernel->shutdown(); }
PHP);
    $process = proc_open([\PHP_BINARY, 'smoke.php', $withDoctrine ? '1' : '0', 'dev', 'zero'], [\STDIN, \STDOUT, \STDERR], $pipes, $directory);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException('Consumer boot without package configuration failed.');
    }
    removeDirectory($directory.'/var/cache');
    file_put_contents($directory.'/config/packages/sillbeam.yaml', "sillbeam: { modules: { off: { enabled: false } } }\n");
    foreach (['symfony', 'sillbeam'] as $kernel) {
        if ('sillbeam' === $kernel) {
            file_put_contents($directory.'/src/Kernel.php', '<?php namespace App; final class Kernel extends \\Sillbeam\\Core\\Kernel\\SillbeamKernel { public function getProjectDir(): string { return \\dirname(__DIR__); } }');
        }
        foreach (['dev', 'prod'] as $environment) {
            for ($boot = 0; $boot < 2; ++$boot) {
                $process = proc_open([\PHP_BINARY, 'smoke.php', $withDoctrine ? '1' : '0', $environment], [\STDIN, \STDOUT, \STDERR], $pipes, $directory);
                if (!is_resource($process) || 0 !== proc_close($process)) {
                    throw new RuntimeException('Consumer boot failed: '.$kernel.'/'.$environment);
                }
            }
        }
        // A different Kernel needs its own freshly built caches.
        removeDirectory($directory.'/var/cache');
    }
}

/** Check that uninstall owns only the recipe files and bundle registration. */
function uninstallRecipe(string $directory): void
{
    $services = file_get_contents($directory.'/config/services.yaml');
    $configuration = file_get_contents($directory.'/config/packages/sillbeam.yaml');
    $process = proc_open(['composer', 'remove', 'websign-dev/sillbeam-core', '--no-interaction', '--no-progress', '--no-scripts'], [\STDIN, \STDOUT, \STDERR], $pipes, $directory);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException('Recipe removal failed.');
    }
    $bundles = require $directory.'/config/bundles.php';
    assertSame(false, isset($bundles['Sillbeam\\Core\\SillbeamCoreBundle']), 'Recipe bundle registration was not removed.');
    assertSame(true, isset($bundles['Symfony\\Bundle\\FrameworkBundle\\FrameworkBundle']), 'Native bundle registration must survive.');
    assertSame(false, is_file($directory.'/config/routes/sillbeam.yaml'), 'Recipe route import was not removed.');
    assertSame($services, file_get_contents($directory.'/config/services.yaml'), 'Recipe removal changed native services.');
    assertSame($configuration, file_get_contents($directory.'/config/packages/sillbeam.yaml'), 'Recipe removal deleted user configuration.');
    $lock = decodeJson($directory.'/symfony.lock');
    assertSame(false, isset($lock['websign-dev/sillbeam-core']), 'Recipe lock entry was not removed.');
    // Application-owned options and an optional custom Kernel need explicit cleanup.
    // Preserve the configuration contents while moving them out of Symfony's imports.
    rename($directory.'/config/packages/sillbeam.yaml', $directory.'/config/sillbeam.yaml.uninstalled');
    file_put_contents($directory.'/src/Kernel.php', '<?php namespace App; final class Kernel extends \\Symfony\\Component\\HttpKernel\\Kernel { use \\Symfony\\Bundle\\FrameworkBundle\\Kernel\\MicroKernelTrait; public function getProjectDir(): string { return \\dirname(__DIR__); } }');
    file_put_contents($directory.'/removed.php', <<<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
$kernel = new App\Kernel('prod', false);
try {
    $kernel->boot();
    if (!$kernel->getContainer()->has('legacy') || class_exists('Sillbeam\\Core\\SillbeamCoreBundle')) { throw new RuntimeException('Native consumer failed after removal and application cleanup.'); }
} finally { $kernel->shutdown(); }
PHP);
    $process = proc_open([\PHP_BINARY, 'removed.php'], [\STDIN, \STDOUT, \STDERR], $pipes, $directory);
    if (!is_resource($process) || 0 !== proc_close($process)) {
        throw new RuntimeException('Native consumer boot after removal failed.');
    }
}

function availablePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);

    if (false === $socket) {
        throw new RuntimeException(sprintf('Unable to allocate endpoint port: %s (%d).', $errorMessage, $errorCode));
    }

    $address = stream_socket_get_name($socket, false);
    fclose($socket);

    if (false === $address || !preg_match('/:(\d+)$/', $address, $matches)) {
        throw new RuntimeException('Unable to determine the endpoint port.');
    }

    return (int) $matches[1];
}

/** @return array{resource, array<int, resource>} */
function startEndpointServer(string $documentRoot, int $port): array
{
    $pipes = [];
    $process = proc_open(
        [\PHP_BINARY, '-S', sprintf('127.0.0.1:%d', $port), '-t', $documentRoot],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the local Flex endpoint.');
    }

    fclose($pipes[0]);
    unset($pipes[0]);

    return [$process, $pipes];
}

function waitForEndpoint(string $url): void
{
    $deadline = microtime(true) + 5;

    do {
        $contents = @file_get_contents($url);
        if (false !== $contents) {
            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    throw new RuntimeException('The local Flex endpoint did not become available.');
}

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($directory);
}
