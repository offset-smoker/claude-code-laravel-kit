<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

/**
 * A file as it sits in the git index, with the line numbers the staged diff
 * adds or changes.
 */
final readonly class StagedFile
{
    /** @var list<string> */
    public array $lines;

    /** @var array<int, true> */
    private array $added;

    /**
     * @param  list<int>  $addedLines  1-based line numbers in the staged version
     */
    public function __construct(
        public string $path,
        string $contents,
        array $addedLines,
    ) {
        $this->lines = Lines::split($contents);
        $this->added = array_fill_keys($addedLines, true);
    }

    /**
     * @return list<int>
     */
    public function addedLines(): array
    {
        return array_keys($this->added);
    }

    public function isAdded(int $number): bool
    {
        return isset($this->added[$number]);
    }

    public function line(int $number): string
    {
        return $this->lines[$number - 1] ?? '';
    }

    public function contents(): string
    {
        return implode("\n", $this->lines);
    }

    public function isPhp(): bool
    {
        return str_ends_with($this->path, '.php');
    }

    public function isUnder(string $directory): bool
    {
        return str_starts_with($this->path, rtrim($directory, '/').'/');
    }

    public function isTestCode(): bool
    {
        return $this->isUnder('tests')
            || $this->isUnder('database/factories')
            || $this->isUnder('database/seeders');
    }

    public function isLivewireComponent(): bool
    {
        if (! $this->isPhp()) {
            return false;
        }

        if ($this->isUnder('app/Livewire') || $this->isUnder('app/Http/Livewire')) {
            return true;
        }

        $contents = $this->contents();

        return str_contains($contents, 'Livewire\\Component')
            && preg_match('/\bextends\s+Component\b/', $contents) === 1;
    }

    public function isController(): bool
    {
        return $this->isPhp()
            && $this->isUnder('app/Http/Controllers')
            && basename($this->path) !== 'Controller.php';
    }
}
