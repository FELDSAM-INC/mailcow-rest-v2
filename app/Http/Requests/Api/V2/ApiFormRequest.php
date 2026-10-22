<?php

namespace App\Http\Requests\Api\V2;

use App\Exceptions\ValidationProblemException;
use App\Rules\CodedRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\InvokableValidationRule;
use Illuminate\Validation\Validator as LaravelValidator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

/**
 * Base of every Form Request (FR-055, R26). A failed validation becomes a `validation-failed`
 * problem whose `errors` map each field (dot path, query name, or `body` for the body as a whole)
 * to violations with the contract's stable rule code, its parameters and an English message.
 *
 * Rules should start with `bail`, so that each field reports its first violation. A JSON body that
 * does not parse answers `400 about:blank`.
 */
abstract class ApiFormRequest extends FormRequest
{
    /** Refuse an empty JSON object (for example an empty merge patch) with `required` on `body`. */
    protected bool $requiresNonEmptyBody = false;

    /** @var array<string, list<array{code: string, params: array<string, mixed>, message: string}>> */
    private array $bodyViolations = [];

    /** @var array<string, list<array{code: string, params: array<string, mixed>, message: string}>> */
    private array $additionalViolations = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $content = (string) $this->getContent();

        if ($content === '') {
            return;
        }

        // Services consume the JSON body, so validation must consume that same body. Otherwise
        // text/plain JSON can bypass optional field rules while still reaching members()/patch().
        if (! $this->isJson()) {
            throw new UnsupportedMediaTypeHttpException('The request body must use a JSON content type.');
        }

        $decoded = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new BadRequestHttpException('The request body is not valid JSON.');
        }

        if (! is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
            $this->bodyViolations['body'][] = self::make('invalidType', ['expected' => 'object']);
        } elseif ($this->requiresNonEmptyBody && $decoded === []) {
            $this->bodyViolations['body'][] = self::make('required', [], 'The request body must contain at least one member.');
        } else {
            foreach ($this->memberViolations($decoded) as $field => $violation) {
                $this->bodyViolations[$field][] = $violation;
            }
        }
    }

    /**
     * Violations of the body's members that rules cannot express, for example members the schema
     * does not define (feature 003: `immutable`). Keyed by field path, one violation each.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, array{code: string, params: array<string, mixed>, message: string}>
     */
    protected function memberViolations(array $body): array
    {
        return [];
    }

    /**
     * Violations that need more than one rule code per field or the outcome of the other rules,
     * computed after the rules ran (feature 008: every bad custom imapsync option at once). They are
     * reported together with the rules' violations, after them for the same field.
     *
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    protected function additionalViolations(Validator $validator): array
    {
        return [];
    }

    public function withValidator(Validator $validator): void
    {
        if ($this->bodyViolations !== []) {
            $field = (string) array_key_first($this->bodyViolations);
            $validator->after(function (Validator $validator) use ($field): void {
                $validator->errors()->add($field, $this->bodyViolations[$field][0]['message']);
            });
        }

        $validator->after(function (Validator $validator): void {
            $this->additionalViolations = array_filter($this->additionalViolations($validator));

            foreach ($this->additionalViolations as $field => $violations) {
                $validator->errors()->add($field, $violations[0]['message']);
            }
        });
    }

    protected function failedValidation(Validator $validator): never
    {
        $errors = array_merge($this->bodyViolations, self::violations($validator));

        foreach ($this->additionalViolations as $field => $violations) {
            $errors[$field] = [...($errors[$field] ?? []), ...$violations];
        }

        throw new ValidationProblemException($errors);
    }

    /**
     * Maps a validator's failures to the contract's field violations.
     *
     * @return array<string, list<array{code: string, params: array<string, mixed>, message: string}>>
     */
    public static function violations(Validator $validator): array
    {
        $rules = $validator instanceof LaravelValidator ? $validator->getRules() : [];
        $data = $validator instanceof LaravelValidator ? $validator->getData() : [];
        $errors = [];

        foreach ($validator->failed() as $attribute => $failedRules) {
            $attributeRules = (array) ($rules[$attribute] ?? []);

            foreach ($failedRules as $rule => $parameters) {
                $errors[$attribute][] = self::violation(
                    (string) $attribute,
                    (string) $rule,
                    array_values((array) $parameters),
                    $attributeRules,
                    Arr::get($data, (string) $attribute),
                );
            }
        }

        return $errors;
    }

    /**
     * @param  list<mixed>  $parameters
     * @param  array<int, mixed>  $attributeRules
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    private static function violation(string $attribute, string $rule, array $parameters, array $attributeRules, mixed $value): array
    {
        if (is_subclass_of($rule, CodedRule::class)) {
            foreach ($attributeRules as $candidate) {
                $candidate = $candidate instanceof InvokableValidationRule ? $candidate->invokable() : $candidate;

                if ($candidate instanceof $rule && ($violation = $candidate->violationFor($attribute)) !== null) {
                    return $violation;
                }
            }

            return self::make('invalidFormat', []);
        }

        $params = array_map(fn (mixed $p): mixed => is_string($p) && is_numeric($p) ? $p + 0 : $p, $parameters);
        $sizeType = self::sizeType($attributeRules, $value);

        return match ($rule) {
            'Required', 'Filled', 'Present', 'RequiredIf', 'RequiredUnless', 'RequiredWith', 'RequiredWithAll',
            'RequiredWithout', 'RequiredWithoutAll', 'Accepted' => self::make('required', []),
            'String' => self::make('invalidType', ['expected' => 'string']),
            'Integer' => self::make('invalidType', ['expected' => 'integer']),
            'Numeric', 'Decimal' => self::make('invalidType', ['expected' => 'number']),
            'Boolean' => self::make('invalidType', ['expected' => 'boolean']),
            'Array', 'List' => self::make('invalidType', ['expected' => 'array']),
            'Min' => match ($sizeType) {
                'numeric' => self::make('outOfRange', ['min' => $params[0]]),
                default => self::make('tooShort', ['min' => $params[0]]),
            },
            'Max' => match ($sizeType) {
                'numeric' => self::make('outOfRange', ['max' => $params[0]]),
                'array' => self::make('tooMany', ['max' => $params[0]]),
                default => self::make('tooLong', ['max' => $params[0]]),
            },
            'Between', 'Size' => self::sizeViolation($sizeType, $params, $value),
            'In' => self::make('notInList', ['allowed' => array_map('strval', $parameters)]),
            'Date', 'DateFormat', 'After', 'AfterOrEqual', 'Before', 'BeforeOrEqual' => self::make('invalidFormat', ['format' => 'dateTime']),
            'Ip', 'Ipv4', 'Ipv6' => self::make('invalidFormat', ['format' => 'ipAddress']),
            'Email' => self::make('invalidFormat', ['format' => 'email']),
            default => self::make('invalidFormat', []),
        };
    }

    /**
     * @param  list<mixed>  $params
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    private static function sizeViolation(string $sizeType, array $params, mixed $value): array
    {
        $min = $params[0] ?? null;
        $max = $params[1] ?? $params[0] ?? null;

        if ($sizeType === 'numeric') {
            return self::make('outOfRange', ['min' => $min, 'max' => $max]);
        }

        $size = $sizeType === 'array' ? count((array) $value) : mb_strlen(is_scalar($value) ? (string) $value : '');

        if ($sizeType === 'array') {
            return self::make('tooMany', ['max' => $max]);
        }

        return $size < $min ? self::make('tooShort', ['min' => $min]) : self::make('tooLong', ['max' => $max]);
    }

    /**
     * How Laravel measures the attribute's size: numeric, array or string length.
     *
     * @param  array<int, mixed>  $attributeRules
     */
    private static function sizeType(array $attributeRules, mixed $value): string
    {
        $names = array_map(
            fn (mixed $rule): string => is_string($rule) ? strtolower(explode(':', $rule, 2)[0]) : '',
            $attributeRules,
        );

        if (array_intersect($names, ['integer', 'numeric', 'decimal']) !== []) {
            return 'numeric';
        }

        return is_array($value) || array_intersect($names, ['array', 'list']) !== [] ? 'array' : 'string';
    }

    /**
     * A violation with the default English message of its code.
     *
     * @param  array<string, mixed>  $params
     * @return array{code: string, params: array<string, mixed>, message: string}
     */
    public static function make(string $code, array $params, ?string $message = null): array
    {
        $message ??= match ($code) {
            'required' => ($params['because'] ?? null) === 'password' ? 'A password needs a username.' : 'This field is required.',
            'invalidType' => 'Must be of type '.($params['expected'] ?? 'the documented type').'.',
            'tooShort' => "Must be at least {$params['min']} characters long.",
            'tooLong' => ($params['unit'] ?? null) === 'bytes' ? "Must be at most {$params['max']} bytes long." : "Must be at most {$params['max']} characters long.",
            'tooMany' => "Must contain at most {$params['max']} items.",
            'outOfRange' => match (true) {
                isset($params['min'], $params['max']) => "Must be between {$params['min']} and {$params['max']}.",
                isset($params['min']) => "Must be at least {$params['min']}.",
                isset($params['max']) => "Must be at most {$params['max']}.",
                default => 'Out of range.',
            },
            'notInList' => 'Must be one of: '.implode(', ', array_map('strval', (array) ($params['allowed'] ?? []))).'.',
            'invalidFormat' => match ($params['format'] ?? null) {
                'dateTime' => 'Not an RFC 3339 date-time.',
                'ipNetwork' => 'Not an IP address or prefix.',
                'ipAddress' => 'Not an IP address.',
                'domainName' => 'Not a valid domain name.',
                'dkimSelector' => 'Only letters, digits, ".", "-" and "_" are allowed.',
                'email' => 'Not a valid e-mail address.',
                'localPart' => 'Not a valid local part of an e-mail address.',
                'attributeName' => 'Must be 1 to 255 characters without whitespace.',
                'nextHop' => 'Not a valid next hop: use host, host:port, [host] or [host]:port.',
                'transportDestination' => 'Not a valid destination: use *, a domain, .domain or an e-mail address.',
                'recipientMapDestination' => 'Not a valid domain name or e-mail address.',
                'tlsPolicyDestination' => 'Not a valid destination: use a domain, .domain, [host] or [host]:port.',
                'tlsPolicyParameters' => 'Use name=value items separated by spaces.',
                'singleLine' => 'Must not contain line breaks or other control characters.',
                'appLinkUrl' => 'Must be an absolute http or https URL, or a path starting with a single /.',
                'hexColor' => 'Must be a colour of the form #RRGGBB.',
                'origin' => 'Must be * or an origin of the form scheme://host[:port].',
                default => 'Invalid format.',
            },
            'notMultipleOf' => 'Must be a whole multiple of '.($params['factor'] ?? 'the unit').'.',
            'mustNotExceed' => 'Must not exceed '.($params['field'] ?? 'the related field').'.',
            'belowInUse' => 'Must not be below the value in use ('.($params['inUse'] ?? '?').').',
            'reserved' => 'This value is reserved by the server.',
            'immutable' => 'This value cannot be changed.',
            'mustBeInFuture' => 'Must be in the future.',
            'notFound' => 'The referenced object does not exist.',
            'notEligible' => 'The referenced object cannot be used here.',
            'missingCharacterClass' => match ($params['characterClass'] ?? null) {
                'letter' => 'The password must contain a letter.',
                'digit' => 'The password must contain a digit.',
                'special' => 'The password must contain a special character.',
                'lowerAndUpper' => 'The password must contain a lower-case and an upper-case letter.',
                default => 'The password lacks a required character class.',
            },
            'conflictsWith' => 'Not allowed together with '.($params['field'] ?? 'another field').(isset($params['value']) && is_scalar($params['value']) ? ' = '.$params['value'] : '').'.',
            'unchanged' => 'Must differ from the current value.',
            'invalidCharacter' => 'Must not contain: '.implode(', ', array_map(fn (mixed $c): string => '"'.(is_scalar($c) ? (string) $c : '').'"', (array) ($params['characters'] ?? []))).'.',
            'notResolvable' => 'The host name resolves to no address.',
            'invalidRegex' => 'Not a valid regular expression.',
            // Feature 013: system settings.
            'unsupportedImageType' => 'Not a PNG, JPEG, GIF or SVG image.',
            'invalidImage' => 'The image cannot be decoded.',
            'imageDimensionsExceeded' => 'The image is larger than '.($params['maxWidth'] ?? '?').' × '.($params['maxHeight'] ?? '?').' pixels.',
            'unsafeSvgContent' => 'The SVG contains active content or external references ('.($params['construct'] ?? 'unknown').').',
            'insufficientContrast' => 'The contrast against '.($params['surface'] ?? 'the reference surface').' is '.($params['ratio'] ?? '?').':1, below '.($params['minimum'] ?? '?').':1.',
            'invalidRegexLine' => 'Line '.($params['line'] ?? '?').' is not a valid regular expression line ('.($params['reason'] ?? 'invalid').').',
            'unbalancedStructure' => 'The structure is not balanced at line '.($params['line'] ?? '?').' ('.($params['construct'] ?? 'unknown').').',
            'sieveSyntax' => (string) ($params['message'] ?? 'Invalid Sieve syntax.'),
            'mutuallyExclusive' => 'Not allowed together with '.($params['with'] ?? 'another field').'.',
            'tooFew' => 'Must contain at least '.($params['min'] ?? 1).' item(s).',
            default => 'Invalid value.',
        };

        return ['code' => $code, 'params' => $params, 'message' => $message];
    }
}
