<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Process\Process;

/**
 * A throwaway copy of tests/Fixtures/app in its own git repository, with a
 * bare repository beside it as `origin`. Git runs with no global or system
 * configuration, so the developer's hooks and signing settings never apply.
 */
final class FixtureRepository
{
    /** @var list<string> */
    private static array $created = [];

    public readonly string $path;

    public readonly string $remote;

    private readonly string $base;

    private function __construct()
    {
        $base = sys_get_temp_dir().'/laravel-kit-test-'.bin2hex(random_bytes(6));

        if (! mkdir($base, 0700, true) || ! touch($base.'/gitconfig')) {
            throw new RuntimeException("Could not create {$base}");
        }

        // Git reports real paths (/private/var on macOS), so use one here too.
        $real = realpath($base);
        $this->base = $real === false ? $base : $real;
        $this->path = $this->base.'/app';
        $this->remote = $this->base.'/origin.git';
        self::$created[] = $this->base;
    }

    /**
     * A committed copy of the fixture, pushed to origin/main, with the kit
     * pointed at this repository's own Pint and PHPStan.
     *
     * @param  array<string, string>  $config  extra .laravel-kit.conf settings
     */
    public static function create(array $config = []): self
    {
        $repository = new self;
        self::copyDirectory(self::fixturePath(), $repository->path);

        $settings = [
            'PINT' => self::kitPath('vendor/bin/pint'),
            'PHPSTAN' => self::kitPath('vendor/bin/phpstan'),
            ...$config,
        ];
        $repository->writeConfig($settings);

        $repository->git('init', '--quiet', '--initial-branch=main');
        $repository->git('add', '--all');
        $repository->git('commit', '--quiet', '-m', 'Initial commit');

        $bare = new Process(['git', 'init', '--quiet', '--bare', '--initial-branch=main', $repository->remote], null, $repository->environment());
        $bare->mustRun();

        $repository->git('remote', 'add', 'origin', $repository->remote);
        $repository->git('push', '--quiet', '-u', 'origin', 'main');

        return $repository;
    }

    /**
     * A git repository that is not a PHP project: no composer.json.
     */
    public static function createEmpty(): self
    {
        $repository = new self;

        if (! mkdir($repository->path, 0700, true)) {
            throw new RuntimeException("Could not create {$repository->path}");
        }

        $repository->git('init', '--quiet', '--initial-branch=main');

        return $repository;
    }

    /**
     * A directory that is not inside any git repository.
     */
    public static function plainDirectory(): string
    {
        $repository = new self;

        if (! mkdir($repository->path, 0700, true)) {
            throw new RuntimeException("Could not create {$repository->path}");
        }

        return $repository->path;
    }

    public static function cleanUp(): void
    {
        foreach (self::$created as $directory) {
            self::removeDirectory($directory);
        }

        self::$created = [];
    }

    public static function kitPath(string $relative = ''): string
    {
        return rtrim(dirname(__DIR__, 2).'/'.$relative, '/');
    }

    /**
     * @param  array<string, string>  $settings
     */
    public function writeConfig(array $settings): void
    {
        $lines = ['# Written by the test harness.'];

        foreach ($settings as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        $this->write('.laravel-kit.conf', implode("\n", $lines)."\n");
    }

    public function write(string $relative, string $contents): void
    {
        $file = $this->path.'/'.$relative;

        if (! is_dir(dirname($file)) && ! mkdir(dirname($file), 0700, true)) {
            throw new RuntimeException("Could not create the directory for {$relative}");
        }

        if (file_put_contents($file, $contents) === false) {
            throw new RuntimeException("Could not write {$relative}");
        }
    }

    public function read(string $relative): string
    {
        $contents = file_get_contents($this->path.'/'.$relative);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$relative}");
        }

        return $contents;
    }

    public function stage(string ...$paths): void
    {
        $this->git('add', '--', ...$paths);
    }

    public function commit(string $message = 'Change'): void
    {
        $this->git('commit', '--quiet', '--all', '-m', $message);
    }

    public function git(string ...$arguments): string
    {
        $process = new Process(['git', ...$arguments], $this->path, $this->environment());
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * Pushes a commit to origin/main from a second clone, so this copy falls behind.
     */
    public function pushCommitFromElsewhere(): void
    {
        $clone = $this->base.'/elsewhere';
        $environment = $this->environment();

        (new Process(['git', 'clone', '--quiet', $this->remote, $clone], null, $environment))->mustRun();
        file_put_contents($clone.'/NOTES.txt', "Pushed from another clone.\n");
        (new Process(['git', 'add', 'NOTES.txt'], $clone, $environment))->mustRun();
        (new Process(['git', 'commit', '--quiet', '-m', 'Elsewhere'], $clone, $environment))->mustRun();
        (new Process(['git', 'push', '--quiet', 'origin', 'HEAD:main'], $clone, $environment))->mustRun();
    }

    /**
     * Runs one of the kit's scripts in this repository.
     *
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function runScript(string $script, array $arguments = [], ?string $stdin = null, array $environment = []): Process
    {
        return self::runKitScript($script, $arguments, $this->path, $stdin, [...$this->environment(), ...$environment]);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public static function runKitScript(string $script, array $arguments, string $cwd, ?string $stdin = null, array $environment = []): Process
    {
        $command = str_ends_with($script, '.php')
            ? [PHP_BINARY, self::kitPath('scripts/'.$script), ...$arguments]
            : [self::kitPath('scripts/'.$script), ...$arguments];

        $process = new Process($command, $cwd, $environment, $stdin, 300);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => $this->base.'/gitconfig',
            'GIT_AUTHOR_NAME' => 'Fixture Author',
            'GIT_AUTHOR_EMAIL' => 'author@example.test',
            'GIT_COMMITTER_NAME' => 'Fixture Author',
            'GIT_COMMITTER_EMAIL' => 'author@example.test',
            'LARAVEL_KIT_FAST_GATE' => 'on',
        ];
    }

    private static function fixturePath(): string
    {
        return dirname(__DIR__).'/Fixtures/app';
    }

    private static function copyDirectory(string $from, string $to): void
    {
        if (! mkdir($to, 0700, true)) {
            throw new RuntimeException("Could not create {$to}");
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $target = $to.'/'.substr($item->getPathname(), strlen($from) + 1);

            if ($item->isDir()) {
                if (! is_dir($target) && ! mkdir($target, 0700, true)) {
                    throw new RuntimeException("Could not create {$target}");
                }

                continue;
            }

            if (! copy($item->getPathname(), $target) || ! chmod($target, $item->getPerms() & 0777)) {
                throw new RuntimeException("Could not copy {$item->getPathname()}");
            }
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if (! $item instanceof SplFileInfo) {
                continue;
            }

            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($directory);
    }
}
