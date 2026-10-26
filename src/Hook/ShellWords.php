<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Hook;

/**
 * Splits a shell command line into simple commands and their words. It
 * understands quoting, escapes and the control operators, which is enough to
 * find a `git commit` inside `cd app && git add . && git commit -m "..."`.
 * It does not expand variables, globs or command substitutions.
 */
final class ShellWords
{
    /**
     * @return list<list<string>> one list of words per simple command, in order
     */
    public static function commands(string $line): array
    {
        $commands = [];
        $words = [];
        $word = '';
        $inWord = false;
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                } else {
                    $word .= $char;
                }

                continue;
            }

            if ($quote === '"') {
                if ($char === '"') {
                    $quote = null;
                } elseif ($char === '\\' && $i + 1 < $length && in_array($line[$i + 1], ['"', '\\', '$', '`'], true)) {
                    $word .= $line[++$i];
                } else {
                    $word .= $char;
                }

                continue;
            }

            if ($char === '\\' && $i + 1 < $length) {
                $next = $line[++$i];

                if ($next !== "\n") {
                    $word .= $next;
                    $inWord = true;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $inWord = true;

                continue;
            }

            if ($char === '#' && ! $inWord) {
                while ($i + 1 < $length && $line[$i + 1] !== "\n") {
                    $i++;
                }

                continue;
            }

            if (in_array($char, [';', '&', '|', "\n", '(', ')'], true)) {
                if ($inWord) {
                    $words[] = $word;
                }

                if ($words !== []) {
                    $commands[] = $words;
                }

                $words = [];
                $word = '';
                $inWord = false;

                continue;
            }

            if ($char === ' ' || $char === "\t") {
                if ($inWord) {
                    $words[] = $word;
                    $word = '';
                    $inWord = false;
                }

                continue;
            }

            $word .= $char;
            $inWord = true;
        }

        if ($inWord) {
            $words[] = $word;
        }

        if ($words !== []) {
            $commands[] = $words;
        }

        return $commands;
    }
}
