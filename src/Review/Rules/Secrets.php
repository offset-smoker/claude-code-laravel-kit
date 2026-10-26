<?php

declare(strict_types=1);

namespace OffsetSmoker\LaravelKit\Review\Rules;

use OffsetSmoker\LaravelKit\Review\Finding;
use OffsetSmoker\LaravelKit\Review\Rule;
use OffsetSmoker\LaravelKit\Review\StagedFile;

/**
 * Flags credentials in the change. Findings never carry the offending line,
 * and the Scanner withholds that line from every other rule's findings too,
 * so the report cannot leak the secret it found.
 */
final class Secrets implements Rule
{
    public const string RULE = 'secret';

    /** @var array<string, string> pattern => what it looks like */
    private const array TOKENS = [
        '/-----BEGIN (?:RSA |EC |DSA |OPENSSH |ENCRYPTED )?PRIVATE KEY-----/' => 'a private key',
        '/\bAKIA[0-9A-Z]{16}\b/' => 'an AWS access key id',
        '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/' => 'a GitHub token',
        '/\bglpat-[A-Za-z0-9_\-]{20,}/' => 'a GitLab token',
        '/\b(?:sk|rk)_live_[A-Za-z0-9]{16,}\b/' => 'a Stripe live key',
        '/\bxox[abprs]-[A-Za-z0-9\-]{10,}/' => 'a Slack token',
        '/\bsk-ant-[A-Za-z0-9_\-]{20,}/' => 'an Anthropic API key',
        '/\bAIza[0-9A-Za-z_\-]{35}\b/' => 'a Google API key',
    ];

    private const string ASSIGNED_LITERAL = '/[\'"]?\b(?:password|passwd|secret|client_secret|api_?key|access_?token|auth_?token|private_?key)\b[\'"]?\s*(?:=>|=|:)\s*[\'"][^\'"\s$]{8,}[\'"]/i';

    public function check(StagedFile $file): array
    {
        $fileFinding = $this->checkPath($file);

        if ($fileFinding !== null) {
            return [$fileFinding];
        }

        $findings = [];

        foreach ($file->addedLines() as $number) {
            $line = $file->line($number);

            foreach (self::TOKENS as $pattern => $description) {
                if (preg_match($pattern, $line) === 1) {
                    $findings[] = Finding::at(self::RULE, $file, $number, "This line looks like it contains {$description}. The value is not shown here. Remove it, rotate it, and read it from the environment through config.", withSnippet: false);

                    continue 2;
                }
            }

            if (! $file->isTestCode() && ! str_ends_with($file->path, '.example') && preg_match(self::ASSIGNED_LITERAL, $line) === 1) {
                $findings[] = Finding::at(self::RULE, $file, $number, 'A credential-like key is assigned a literal value. The value is not shown here. If it is real, move it to the environment and rotate it.', withSnippet: false);
            }
        }

        return $findings;
    }

    private function checkPath(StagedFile $file): ?Finding
    {
        $name = basename($file->path);

        $isEnvFile = $name === '.env'
            || (str_starts_with($name, '.env.') && ! str_ends_with($name, '.example'));

        $isKeyFile = in_array($name, ['oauth-private.key', 'id_rsa', 'id_ed25519', 'id_ecdsa'], true)
            || str_ends_with($name, '.pem')
            || str_ends_with($name, '.p12');

        if (! $isEnvFile && ! $isKeyFile) {
            return null;
        }

        return new Finding(
            rule: self::RULE,
            message: $isEnvFile
                ? 'An environment file is staged. Unstage it and add it to .gitignore.'
                : 'A private key file is staged. Unstage it, add it to .gitignore and rotate the key.',
            path: $file->path,
        );
    }
}
