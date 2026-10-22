<?php

namespace App\Logging;

use Illuminate\Database\QueryException;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

/**
 * Removes secrets from the log context (FR-050, R24): every key, at any depth, whose name matches
 * password, secret, token, code, OTP, credential, assertion, authorization, cookie or X-API-Key, and
 * (feature 012) state, verifier or bind: single sign-on states, PKCE verifiers, LDAP bind passwords;
 * (feature 010) privateKey and private_key: imported or exported DKIM private keys; and feature
 * 005's Pushover user key (`userKey`); `currentPassword`, `newPassword`, `generatedPassword`,
 * `secretKey` and `qrCode` already match (FR-076).
 */
class RedactSecrets implements ProcessorInterface
{
    private const PATTERN = '/pass|secret|token|code|otp|credential|assertion|authorization|cookie|x-api-key|state|verifier|bind|private_?key|userkey/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(
            message: $this->containsQueryFailure($record->context) || $this->containsQueryFailure($record->extra) ? 'Database query failed.' : $record->message,
            context: $this->redact($record->context),
            extra: $this->redact($record->extra),
        );
    }

    private function containsQueryFailure(mixed $value): bool
    {
        if ($value instanceof Throwable) {
            do {
                if ($value instanceof QueryException) {
                    return true;
                }
            } while (($value = $value->getPrevious()) !== null);
        } elseif (is_array($value)) {
            foreach ($value as $entry) {
                if ($this->containsQueryFailure($entry)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(self::PATTERN, $key) === 1) {
                unset($values[$key]);
            } elseif ($value instanceof Throwable && $this->containsQueryFailure($value)) {
                // SQL, bindings, PDO messages and previous-exception traces can all contain
                // credentials. Keep the diagnostic location and type, never the exception object.
                $values[$key] = ['class' => $value::class, 'code' => $value->getCode(), 'file' => $value->getFile(), 'line' => $value->getLine()];
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
