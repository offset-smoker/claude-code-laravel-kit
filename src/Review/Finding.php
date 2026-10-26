<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

final readonly class Finding
{
    /**
     * @param  string|null  $snippet  the offending source line, or null when it must not be printed
     */
    public function __construct(
        public string $rule,
        public string $message,
        public ?string $path = null,
        public ?int $line = null,
        public ?string $snippet = null,
    ) {}

    public static function at(string $rule, StagedFile $file, int $line, string $message, bool $withSnippet = true): self
    {
        return new self(
            rule: $rule,
            message: $message,
            path: $file->path,
            line: $line,
            snippet: $withSnippet ? trim($file->line($line)) : null,
        );
    }

    public function location(): string
    {
        if ($this->path === null) {
            return '(change set)';
        }

        return $this->line === null ? $this->path : "{$this->path}:{$this->line}";
    }

    /**
     * @return array{rule: string, message: string, path: string|null, line: int|null, snippet: string|null}
     */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'message' => $this->message,
            'path' => $this->path,
            'line' => $this->line,
            'snippet' => $this->snippet,
        ];
    }
}
