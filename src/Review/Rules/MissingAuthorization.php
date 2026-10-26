<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

/**
 * Flags public controller and Livewire actions added to a file in which
 * nothing authorizes. It is a prompt for the reviewer, not proof: route
 * middleware and policies registered elsewhere can cover a controller.
 */
final class MissingAuthorization implements Rule
{
    private const string PUBLIC_METHOD = '/^\s*public\s+(?:static\s+)?function\s+(\w+)\s*\(/';

    private const string AUTHORIZES = '/->authorize(?:Resource)?\s*\(|\bGate::|->can(?:not|Any)?\s*\(|\bcan:|\babort_(?:if|unless)\s*\(|#\[\s*(?:Authorize|Can)\b|\b[A-Z]\w*Request\s+\$(?!request\b)\w+|\b(?!Form)[A-Z]\w+Request\s+\$request\b/';

    private const array LIFECYCLE = [
        'render', 'rules', 'messages', 'validationAttributes', 'boot', 'booted',
        'hydrate', 'dehydrate', 'placeholder', 'middleware', 'exception',
    ];

    public function check(StagedFile $file): array
    {
        $isLivewire = $file->isLivewireComponent();

        if (! $isLivewire && ! $file->isController()) {
            return [];
        }

        if (preg_match(self::AUTHORIZES, $file->contents()) === 1) {
            return [];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            if (preg_match(self::PUBLIC_METHOD, $file->line($number), $match) !== 1) {
                continue;
            }

            $method = $match[1];

            if (str_starts_with($method, '__') || in_array($method, self::LIFECYCLE, true)) {
                continue;
            }

            $findings[] = Finding::at('missing-authorization', $file, $number, $isLivewire
                ? "Livewire action {$method}() is callable straight from the browser, and nothing in this component authorizes. Route middleware does not run on Livewire updates; check the user in the action."
                : "Controller action {$method}() is added, and nothing in this controller authorizes (authorize(), Gate, can(), a form request, abort_unless). Confirm a policy or route middleware covers it.");
        }

        return $findings;
    }
}
