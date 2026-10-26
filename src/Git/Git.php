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
    private const string SUBMODULE_MODE = '160000';

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
     * Submodules are left out: the index holds only their commit id, and
     * `git show` cannot read one as a file.
     *
     * @return list<string>
     */
    public function stagedFiles(): array
    {
        $fields = explode("\0", $this->run(['diff', '--cached', '--raw', '-z', '--diff-filter=ACMR', '--no-renames']));
        $count = count($fields);
        $paths = [];

        // -z --raw output: ":<old mode> <new mode> <old id> <new id> <status>\0<path>\0", repeated.
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $newMode = explode(' ', $fields[$i])[1] ?? '';

            if ($newMode !== self::SUBMODULE_MODE && $fields[$i + 1] !== '') {
                $paths[] = $fields[$i + 1];
            }
        }

        return $paths;
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
        // stderr goes to a temporary file rather than a second pipe: reading
        // one pipe to its end while git fills the other would block both.
        $errors = tmpfile();

        if ($errors === false) {
            throw new RuntimeException('Could not create a temporary file for git errors.');
        }

        $process = proc_open(
            ['git', '-C', $this->root, ...$arguments],
            [1 => ['pipe', 'w'], 2 => $errors],
            $pipes,
        );

        if (! is_resource($process)) {
            fclose($errors);

            throw new RuntimeException('Could not start git.');
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);

        rewind($errors);
        $stderr = stream_get_contents($errors);
        fclose($errors);

        if ($exitCode !== 0) {
            $message = trim(is_string($stderr) ? $stderr : '');

            $command = $arguments[0] ?? '';

            throw new RuntimeException("git {$command} failed: {$message}");
        }

        return is_string($stdout) ? $stdout : '';
    }
}
