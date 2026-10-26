<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

/**
 * Finds the first `git commit` in a Bash tool command and works out which
 * directory it runs in, following `cd`, `pushd` and `git -C`.
 */
final readonly class CommitDetector
{
    /** git options placed before the subcommand that take a separate value. */
    private const array GIT_OPTIONS_WITH_VALUE = ['-C', '-c', '--git-dir', '--work-tree', '--namespace', '--exec-path', '--config-env'];

    /** commit options that take a separate value. */
    private const array COMMIT_OPTIONS_WITH_VALUE = [
        '-m', '--message', '-F', '--file', '-C', '--reuse-message', '-c', '--reedit-message',
        '-t', '--template', '--author', '--date', '--fixup', '--squash', '--cleanup',
        '--trailer', '--pathspec-from-file',
    ];

    /** commit short options that take a value, attached (-mfix) or as the next word. */
    private const string SHORT_WITH_VALUE = 'mFCct';

    /** Words that run the rest of the command line as a command. */
    private const array WRAPPERS = ['env', 'command', 'builtin', 'exec', 'nohup', 'time', 'nice'];

    public function __construct(private string $home = '') {}

    public function detect(string $command, string $cwd): ?CommitCommand
    {
        $directory = $cwd;

        foreach (ShellWords::commands($command) as $words) {
            $words = $this->stripPrefix($this->stripRedirections($words));

            if ($words === []) {
                continue;
            }

            if (in_array($words[0], ['cd', 'pushd'], true)) {
                $directory = $this->resolve($directory, $words[1] ?? ($this->home !== '' ? $this->home : $directory));

                continue;
            }

            if (basename($words[0]) !== 'git') {
                continue;
            }

            $commit = $this->parseGit(array_slice($words, 1), $directory);

            if ($commit !== null) {
                return $commit;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $arguments  words after `git`
     */
    private function parseGit(array $arguments, string $directory): ?CommitCommand
    {
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($argument === '-C') {
                $directory = $this->resolve($directory, $arguments[++$i] ?? '.');

                continue;
            }

            if (str_starts_with($argument, '--work-tree=')) {
                $directory = $this->resolve($directory, substr($argument, strlen('--work-tree=')));

                continue;
            }

            if ($argument === '--work-tree') {
                $directory = $this->resolve($directory, $arguments[++$i] ?? '.');

                continue;
            }

            if (in_array($argument, self::GIT_OPTIONS_WITH_VALUE, true)) {
                $i++;

                continue;
            }

            if (str_starts_with($argument, '-')) {
                continue;
            }

            if ($argument !== 'commit') {
                return null;
            }

            return new CommitCommand(
                directory: $directory,
                includesUnstaged: $this->includesUnstaged(array_slice($arguments, $i + 1)),
            );
        }

        return null;
    }

    /**
     * True when the commit takes content straight from the working tree:
     * `-a`/`--all`, `--include`/`--only`, or pathspecs.
     *
     * @param  list<string>  $arguments  words after `commit`
     */
    private function includesUnstaged(array $arguments): bool
    {
        $count = count($arguments);

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($argument === '--') {
                return $i + 1 < $count;
            }

            if (in_array($argument, ['--all', '--include', '--only'], true)) {
                return true;
            }

            if (in_array($argument, self::COMMIT_OPTIONS_WITH_VALUE, true)) {
                $i++;

                continue;
            }

            if (str_starts_with($argument, '--')) {
                continue;
            }

            if (str_starts_with($argument, '-') && strlen($argument) > 1) {
                [$takesWorkingTree, $valueFollows] = $this->readShortCluster(substr($argument, 1));

                if ($takesWorkingTree) {
                    return true;
                }

                if ($valueFollows) {
                    $i++;
                }

                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Reads a cluster such as `am` from `-am`. Returns whether it holds
     * -a, -i or -o, and whether its last option takes the next word as value.
     *
     * @return array{bool, bool}
     */
    private function readShortCluster(string $cluster): array
    {
        $length = strlen($cluster);

        for ($i = 0; $i < $length; $i++) {
            if (in_array($cluster[$i], ['a', 'i', 'o'], true)) {
                return [true, false];
            }

            if (str_contains(self::SHORT_WITH_VALUE, $cluster[$i])) {
                return [false, $i === $length - 1];
            }
        }

        return [false, false];
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function stripPrefix(array $words): array
    {
        while ($words !== []) {
            $first = $words[0];

            if (preg_match('/^[A-Za-z_]\w*=/', $first) === 1 || in_array($first, self::WRAPPERS, true)) {
                array_shift($words);

                continue;
            }

            break;
        }

        return $words;
    }

    /**
     * @param  list<string>  $words
     * @return list<string>
     */
    private function stripRedirections(array $words): array
    {
        $kept = [];
        $count = count($words);

        for ($i = 0; $i < $count; $i++) {
            if (preg_match('/^\d*(?:<<?|>>?)(.*)$/', $words[$i], $match) === 1) {
                if ($match[1] === '') {
                    $i++;
                }

                continue;
            }

            $kept[] = $words[$i];
        }

        return $kept;
    }

    private function resolve(string $base, string $path): string
    {
        if ($path === '' || str_contains($path, '$') || str_contains($path, '`')) {
            return $base;
        }

        if ($path === '~' || str_starts_with($path, '~/')) {
            if ($this->home === '') {
                return $base;
            }

            $path = $this->home.substr($path, 1);
        }

        if ($path === '-') {
            return $base;
        }

        return str_starts_with($path, '/') ? $path : rtrim($base, '/').'/'.$path;
    }
}
