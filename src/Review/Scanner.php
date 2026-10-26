<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review;

use OffsetSmoker\LaravelKit\Review\Rules\DebugCall;
use OffsetSmoker\LaravelKit\Review\Rules\EnvOutsideConfig;
use OffsetSmoker\LaravelKit\Review\Rules\MassAssignment;
use OffsetSmoker\LaravelKit\Review\Rules\MissingAuthorization;
use OffsetSmoker\LaravelKit\Review\Rules\RawSqlInterpolation;
use OffsetSmoker\LaravelKit\Review\Rules\Secrets;
use OffsetSmoker\LaravelKit\Review\Rules\UnlockedLivewireId;

final readonly class Scanner
{
    /**
     * @param  list<Rule>  $rules
     */
    public function __construct(
        private array $rules,
        private TestsTouched $testsTouched = new TestsTouched,
    ) {}

    public static function default(): self
    {
        return new self([
            new Secrets,
            new MassAssignment,
            new RawSqlInterpolation,
            new MissingAuthorization,
            new UnlockedLivewireId,
            new EnvOutsideConfig,
            new DebugCall,
        ]);
    }

    /**
     * @param  list<StagedFile>  $files
     * @return list<Finding>
     */
    public function scan(array $files): array
    {
        $findings = [];

        foreach ($files as $file) {
            foreach ($this->rules as $rule) {
                array_push($findings, ...$rule->check($file));
            }
        }

        $changeSet = $this->testsTouched->check($files);

        if ($changeSet !== null) {
            $findings[] = $changeSet;
        }

        return $findings;
    }
}
