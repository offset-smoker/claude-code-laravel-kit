<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

/**
 * Finds the first `git commit` in a Bash tool command and works out which
 * directory it runs in, following `cd`, `pushd`, `git -C`, `--git-dir` and
 * `--work-tree`.
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

    /** Words that run the rest of the command line as a command, with their options that take a separate value. */
    private const array WRAPPERS = [
        'env' => ['-u', '--unset', '-C', '--chdir', '-S', '--split-string'],
        'sudo' => ['-u', '--user', '-g', '--group', '-h', '--host', '-p', '--prompt', '-C', '--close-from', '-D', '--chdir', '-r', '--role', '-t', '--type', '-T', '--command-timeout', '-U', '--other-user'],
        'command' => [],
        'builtin' => [],
        'exec' => ['-a'],
        'nohup' => [],
        'time' => ['-f', '--format', '-o', '--output'],
        'nice' => ['-n', '--adjustment'],
    ];

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
                $directory = $this->changeDirectory($words, $directory);

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
        $workTree = null;
        $gitDir = null;

        for ($i = 0; $i < $count; $i++) {
            $argument = $arguments[$i];

            if ($argument === '-C') {
                $directory = $this->resolve($directory, $arguments[++$i] ?? '.');

                continue;
            }

            if (str_starts_with($argument, '--work-tree=') || $argument === '--work-tree') {
                $workTree = $this->resolve($directory, $this->optionValue($argument, $arguments, $i));

                continue;
            }

            if (str_starts_with($argument, '--git-dir=') || $argument === '--git-dir') {
                $gitDir = $this->resolve($directory, $this->optionValue($argument, $arguments, $i));

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
                directory: $workTree ?? $this->workTreeOf($gitDir) ?? $directory,
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
     * The value of `--name=value`, or of `--name value` (advancing $i past it).
     *
     * @param  list<string>  $arguments
     */
    private function optionValue(string $argument, array $arguments, int &$i): string
    {
        $equals = strpos($argument, '=');

        if ($equals !== false) {
            return substr($argument, $equals + 1);
        }

        return $arguments[++$i] ?? '.';
    }

    /**
     * The work tree git uses for a `.git` directory given with --git-dir.
     * Any other git directory (a bare repository, say) has no work tree to
     * find, so the directory is left as it was.
     */
    private function workTreeOf(?string $gitDir): ?string
    {
        if ($gitDir === null || basename(rtrim($gitDir, '/')) !== '.git') {
            return null;
        }

        return dirname(rtrim($gitDir, '/'));
    }

    /**
     * Follows `cd` or `pushd`, skipping their options: -L, -P, -e and -@,
     * `--`, and pushd's -n, which leaves the directory unchanged.
     *
     * @param  non-empty-list<string>  $words
     */
    private function changeDirectory(array $words, string $directory): string
    {
        $count = count($words);

        for ($i = 1; $i < $count; $i++) {
            $word = $words[$i];

            if ($word === '--') {
                return $this->resolve($directory, $words[$i + 1] ?? $this->homeOr($directory));
            }

            if ($words[0] === 'pushd' && $word === '-n') {
                return $directory;
            }

            if (preg_match('/^-[LPe@]+$/', $word) === 1) {
                continue;
            }

            return $this->resolve($directory, $word);
        }

        return $this->resolve($directory, $this->homeOr($directory));
    }

    private function homeOr(string $directory): string
    {
        return $this->home !== '' ? $this->home : $directory;
    }

    /**
     * Drops leading variable assignments and wrappers such as `env`, `sudo`
     * or `nice -n 10`, with the wrapper's own options.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function stripPrefix(array $words): array
    {
        while ($words !== []) {
            $first = array_shift($words);

            if (preg_match('/^[A-Za-z_]\w*=/', $first) === 1) {
                continue;
            }

            if (! array_key_exists($first, self::WRAPPERS)) {
                array_unshift($words, $first);

                break;
            }

            $words = $this->stripWrapperOptions($words, self::WRAPPERS[$first]);
        }

        return $words;
    }

    /**
     * @param  list<string>  $words  words after the wrapper
     * @param  list<string>  $withValue  the wrapper's options that take a separate value
     * @return list<string>
     */
    private function stripWrapperOptions(array $words, array $withValue): array
    {
        while ($words !== [] && str_starts_with($words[0], '-')) {
            $option = array_shift($words);

            if ($option === '--') {
                break;
            }

            if (in_array($option, $withValue, true)) {
                array_shift($words);
            }
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
