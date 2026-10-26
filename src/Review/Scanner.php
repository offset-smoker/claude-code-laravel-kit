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
     * @param  list<string>  $binaryPaths  staged binary files, checked by name only
     * @return list<Finding>
     */
    public function scan(array $files, array $binaryPaths = []): array
    {
        $findings = [];

        foreach ($binaryPaths as $path) {
            $finding = Secrets::checkPath($path);

            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        foreach ($files as $file) {
            foreach ($this->rules as $rule) {
                array_push($findings, ...$rule->check($file));
            }
        }

        $findings = $this->withholdSecretLines($findings);

        $changeSet = $this->testsTouched->check($files);

        if ($changeSet !== null) {
            $findings[] = $changeSet;
        }

        return $findings;
    }

    /**
     * A line the secret rule flagged is never printed, whichever rule reports
     * it, and nothing at all is printed from a staged environment or key file.
     *
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    private function withholdSecretLines(array $findings): array
    {
        $secretFiles = [];
        $secretLines = [];

        foreach ($findings as $finding) {
            if ($finding->rule !== Secrets::RULE || $finding->path === null) {
                continue;
            }

            if ($finding->line === null) {
                $secretFiles[$finding->path] = true;

                continue;
            }

            $secretLines["{$finding->path}:{$finding->line}"] = true;
        }

        return array_map(
            static fn (Finding $finding): Finding => $finding->snippet !== null
                && (isset($secretFiles[$finding->path ?? '']) || isset($secretLines[$finding->location()]))
                    ? $finding->withoutSnippet()
                    : $finding,
            $findings,
        );
    }
}
