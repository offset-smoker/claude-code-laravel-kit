<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Git;

use RuntimeException;

/**
 * Runs git in one repository. Arguments go to proc_open as an array, so no
 * shell ever parses a path or a ref.
 */
final readonly class Git
{
    public function __construct(private string $root) {}

    public static function discover(string $directory): self
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("Not a directory: {$directory}");
        }

        $toplevel = trim((new self($directory))->run(['rev-parse', '--show-toplevel']));

        return new self($toplevel);
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Paths of staged files that were added, copied, modified or renamed.
     *
     * @return list<string>
     */
    public function stagedFiles(): array
    {
        $output = $this->run(['diff', '--cached', '--name-only', '-z', '--diff-filter=ACMR', '--no-renames']);

        return array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
    }

    public function stagedDiff(string $path): string
    {
        return $this->run(['diff', '--cached', '--no-color', '--no-ext-diff', '--no-renames', '-U0', '--', $path]);
    }

    public function stagedContents(string $path): string
    {
        return $this->run(['show', ':'.$path]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments): string
    {
        $process = proc_open(
            ['git', '-C', $this->root, ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start git.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $message = trim(is_string($stderr) ? $stderr : '');

            $command = $arguments[0] ?? '';

            throw new RuntimeException("git {$command} failed: {$message}");
        }

        return is_string($stdout) ? $stdout : '';
    }
}
