<?php

namespace App\Logging;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Removes secrets from the log context (FR-050, R24): every key, at any depth, whose name matches
 * password, secret, token, code, OTP, credential, assertion, authorization, cookie or X-API-Key.
 */
class RedactSecrets implements ProcessorInterface
{
    private const PATTERN = '/pass|secret|token|code|otp|credential|assertion|authorization|cookie|x-api-key/i';

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->redact($record->context));
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
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
