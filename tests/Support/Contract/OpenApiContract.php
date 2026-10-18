<?php

namespace Tests\Support\Contract;

use Illuminate\Http\Request;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validates HTTP exchanges against the bundled OpenAPI 3.1 contract (research R13, FR-051).
 *
 * The contract is `build/contract/openapi.json` (dereferenced bundle, `composer contract:bundle`).
 * A request is matched to an operation by method and templated path, with literal segments
 * winning over templates and the server base `/api/v2` stripped. Then:
 *
 *  - path, query (including repeated `explode` arrays) and header parameters, and the request
 *    body for its content type, are validated against their schemas;
 *  - the response status must be documented, required response headers must be present, and
 *    JSON and problem+json bodies must validate against the documented schema.
 *
 * A request that matches no operation is valid only when it is answered with a `404` or `405`
 * problem that validates against `components.schemas.Problem`. Paths under `/api/v2/__testing/`
 * are exempt: tests register such routes for framework behaviour no operation exercises.
 */
final class OpenApiContract
{
    private const BASE_PATH = '/api/v2';

    private const TESTING_PREFIX = '/api/v2/__testing/';

    private const DOCUMENT_ID = 'https://contract.restv2.invalid/openapi.json';

    private const METHODS = ['get', 'put', 'post', 'delete', 'options', 'head', 'patch', 'trace'];

    private static ?self $shared = null;

    private readonly Validator $validator;

    /** @var list<string> */
    private array $covered = [];

    private function __construct(
        private readonly stdClass $document,
        private readonly bool $reportsCoverage,
    ) {
        $this->validator = new Validator;
        $this->validator->setMaxErrors(5);
        $this->validator->setStopAtFirstError(false);
        $this->validator->parser()->setDefaultDraftVersion('2020-12');
        $this->validator->resolver()?->registerRaw($document, self::DOCUMENT_ID);
    }

    /**
     * The contract of this repository, loaded once per test run. Its covered operations are
     * reported to the contract coverage extension.
     */
    public static function shared(): self
    {
        if (self::$shared === null) {
            $path = dirname(__DIR__, 3).'/build/contract/openapi.json';

            if (! is_file($path)) {
                throw new RuntimeException("Contract bundle {$path} is missing: run `composer contract:bundle` first.");
            }

            self::$shared = new self(self::decode((string) file_get_contents($path)), true);
        }

        return self::$shared;
    }

    /**
     * A contract from a JSON document (used by the unit test); it reports no coverage.
     */
    public static function fromJson(string $json): self
    {
        return new self(self::decode($json), false);
    }

    /**
     * The operation that answers a method and path, or null.
     */
    public function match(string $method, string $path): ?MatchedOperation
    {
        $candidates = $this->matchPath($path);

        foreach ($candidates as [$template, $parameters]) {
            $item = $this->document->paths->{$template};
            $lower = strtolower($method);

            if (isset($item->{$lower}) && $item->{$lower} instanceof stdClass) {
                $operation = $item->{$lower};

                return new MatchedOperation(
                    operationId: (string) ($operation->operationId ?? "{$lower} {$template}"),
                    method: $lower,
                    pathTemplate: $template,
                    pathParameters: $parameters,
                    operation: $operation,
                    pathItem: $item,
                );
            }

            // The best-matching path exists, but not with this method: no operation.
            return null;
        }

        return null;
    }

    /**
     * Violations of the request against its operation.
     *
     * @return list<string>
     */
    public function validateRequest(Request $request): array
    {
        $path = $request->getPathInfo();

        if (str_starts_with($path, self::TESTING_PREFIX)) {
            return [];
        }

        $match = $this->match($request->getMethod(), $path);

        if ($match === null) {
            // Validated through the response (404 or 405 problem).
            return [];
        }

        $label = $this->label($request, $match);
        $violations = [];

        foreach ($this->parameters($match) as $parameter) {
            $violations = [...$violations, ...$this->validateParameter($request, $match, $parameter, $label)];
        }

        $violations = [...$violations, ...$this->unknownQueryParameters($request, $match, $label)];

        return [...$violations, ...$this->validateRequestBody($request, $match, $label)];
    }

    /**
     * Violations of the response against the operation that answered the request.
     *
     * @return list<string>
     */
    public function validateResponse(Request $request, Response $response): array
    {
        $path = $request->getPathInfo();
        $status = $response->getStatusCode();

        if (str_starts_with($path, self::TESTING_PREFIX)) {
            if ($status >= 400 && $this->mediaType($response) === 'application/problem+json') {
                return $this->validateUnmatchedProblem($request, $response, false);
            }

            return [];
        }

        $match = $this->match($request->getMethod(), $path);

        if ($match === null) {
            return $this->validateUnmatchedProblem($request, $response, true);
        }

        $label = $this->label($request, $match);
        $responses = $match->operation->responses ?? new stdClass;
        $key = $this->responseKey($responses, $status);

        if ($key === null) {
            return ["{$label}: status {$status} is not documented"];
        }

        $documented = $responses->{$key};
        $violations = [];

        foreach (get_object_vars($documented->headers ?? new stdClass) as $name => $header) {
            $value = $response->headers->get($name);

            if ($value === null) {
                if (($header->required ?? false) === true) {
                    $violations[] = "{$label}: required response header {$name} is missing (status {$status})";
                }

                continue;
            }

            if (isset($header->schema)) {
                $pointer = $this->pointer(['paths', $match->pathTemplate, $match->method, 'responses', $key, 'headers', $name, 'schema']);
                $violations = [...$violations, ...$this->validateAt($this->coerce($value, $header->schema), $pointer, "{$label}: response header {$name}")];
            }
        }

        $content = $documented->content ?? null;
        $body = (string) $response->getContent();

        if (! $content instanceof stdClass || get_object_vars($content) === []) {
            if ($body !== '') {
                $violations[] = "{$label}: status {$status} documents no body, but one was sent";
            }

            if ($violations === [] && $status >= 200 && $status < 400) {
                $this->recordCoverage($match->operationId);
            }

            return $violations;
        }

        $mediaType = $this->mediaType($response);
        $documentedType = $this->documentedMediaType($content, $mediaType);

        if ($documentedType === null) {
            $violations[] = "{$label}: content type ".($mediaType ?? '(none)')." is not documented for status {$status}";

            return $violations;
        }

        if ($this->isJson($documentedType) && isset($content->{$documentedType}->schema)) {
            $data = json_decode($body, false);

            if ($body === '' || json_last_error() !== JSON_ERROR_NONE) {
                return [...$violations, "{$label}: status {$status} body is not valid JSON"];
            }

            $pointer = $this->pointer(['paths', $match->pathTemplate, $match->method, 'responses', $key, 'content', $documentedType, 'schema']);
            $violations = [...$violations, ...$this->validateAt($data, $pointer, "{$label}: response body (status {$status})")];
        }

        if ($violations === [] && $status >= 200 && $status < 400) {
            $this->recordCoverage($match->operationId);
        }

        return $violations;
    }

    /**
     * Operations answered with a documented 2xx or 3xx status by this instance.
     *
     * @return list<string>
     */
    public function coveredOperations(): array
    {
        return $this->covered;
    }

    /**
     * Every operationId of the contract.
     *
     * @return list<string>
     */
    public function operationIds(): array
    {
        $ids = [];

        foreach (get_object_vars($this->document->paths ?? new stdClass) as $item) {
            if (! $item instanceof stdClass) {
                continue;
            }

            foreach (self::METHODS as $method) {
                if (isset($item->{$method}->operationId)) {
                    $ids[] = (string) $item->{$method}->operationId;
                }
            }
        }

        return $ids;
    }

    /**
     * The schema of a named component (for example `WebAuthnRequestOptions`), as a JSON pointer
     * usable with validateSchema().
     *
     * @return list<string>
     */
    public function validateComponent(mixed $data, string $schemaName): array
    {
        if (! isset($this->document->components->schemas->{$schemaName})) {
            return ["schema {$schemaName} is not in the contract"];
        }

        return $this->validateAt(self::toJsonValue($data), $this->pointer(['components', 'schemas', $schemaName]), $schemaName);
    }

    private function recordCoverage(string $operationId): void
    {
        if (! in_array($operationId, $this->covered, true)) {
            $this->covered[] = $operationId;
        }

        if ($this->reportsCoverage) {
            ContractCoverageRecorder::record($operationId);
        }
    }

    /**
     * Templates that match the path, best first: more literal segments win, and among equals the
     * left-most literal wins.
     *
     * @return list<array{0: string, 1: array<string, string>}>
     */
    private function matchPath(string $path): array
    {
        if (! str_starts_with($path, self::BASE_PATH.'/')) {
            return [];
        }

        $segments = explode('/', substr($path, strlen(self::BASE_PATH) + 1));
        $matches = [];

        foreach (array_keys(get_object_vars($this->document->paths ?? new stdClass)) as $template) {
            $templateSegments = explode('/', ltrim((string) $template, '/'));

            if (count($templateSegments) !== count($segments)) {
                continue;
            }

            $parameters = [];
            $score = '';

            foreach ($templateSegments as $i => $templateSegment) {
                if (preg_match('/^\{([^}]+)\}$/', $templateSegment, $m) === 1) {
                    if ($segments[$i] === '') {
                        continue 2;
                    }
                    $parameters[$m[1]] = rawurldecode($segments[$i]);
                    $score .= '0';
                } elseif ($templateSegment === $segments[$i]) {
                    $score .= '1';
                } else {
                    continue 2;
                }
            }

            $matches[] = [$score, (string) $template, $parameters];
        }

        usort($matches, fn (array $a, array $b): int => strcmp($b[0], $a[0]));

        return array_map(fn (array $m): array => [$m[1], $m[2]], $matches);
    }

    /**
     * Path-item and operation parameters; operation-level ones override by name and location.
     *
     * @return list<stdClass>
     */
    private function parameters(MatchedOperation $match): array
    {
        $byKey = [];

        foreach ([$match->pathItem->parameters ?? [], $match->operation->parameters ?? []] as $list) {
            foreach ($list as $parameter) {
                if ($parameter instanceof stdClass && isset($parameter->name, $parameter->in)) {
                    $byKey[$parameter->in.':'.strtolower((string) $parameter->name)] = $parameter;
                }
            }
        }

        return array_values($byKey);
    }

    /**
     * @return list<string>
     */
    private function validateParameter(Request $request, MatchedOperation $match, stdClass $parameter, string $label): array
    {
        $name = (string) $parameter->name;
        $schema = $parameter->schema ?? new stdClass;
        $isArray = $this->schemaType($schema) === 'array';

        $value = match ($parameter->in) {
            'path' => $match->pathParameters[$name] ?? null,
            'query' => $this->queryValue($request, $name, $isArray, ($parameter->explode ?? true) !== false),
            'header' => $request->headers->get($name),
            'cookie' => $request->cookies->get($name),
            default => null,
        };

        if ($value === null) {
            return (($parameter->required ?? false) === true || $parameter->in === 'path')
                ? ["{$label}: required {$parameter->in} parameter {$name} is missing"]
                : [];
        }

        $location = $this->parameterLocation($match, $parameter);

        return $this->validateAt($this->coerce($value, $schema), $location, "{$label}: {$parameter->in} parameter {$name}");
    }

    private function parameterLocation(MatchedOperation $match, stdClass $parameter): string
    {
        foreach (['operation' => $match->operation, 'pathItem' => $match->pathItem] as $level => $owner) {
            foreach ($owner->parameters ?? [] as $i => $candidate) {
                if ($candidate === $parameter) {
                    $base = $level === 'operation'
                        ? ['paths', $match->pathTemplate, $match->method]
                        : ['paths', $match->pathTemplate];

                    return $this->pointer([...$base, 'parameters', (string) $i, 'schema']);
                }
            }
        }

        throw new RuntimeException('parameter not found in its operation');
    }

    /**
     * @return list<string>
     */
    private function unknownQueryParameters(Request $request, MatchedOperation $match, string $label): array
    {
        $known = [];

        foreach ($this->parameters($match) as $parameter) {
            if ($parameter->in === 'query') {
                $known[] = (string) $parameter->name;
            }
        }

        $violations = [];

        foreach (array_unique(array_keys($this->rawQuery($request))) as $name) {
            if (! in_array($name, $known, true)) {
                $violations[] = "{$label}: query parameter {$name} is not documented";
            }
        }

        return $violations;
    }

    /**
     * @return list<string>|string|null
     */
    private function queryValue(Request $request, string $name, bool $isArray, bool $explode): array|string|null
    {
        $values = $this->rawQuery($request)[$name] ?? null;

        if ($values === null) {
            return null;
        }

        if (! $isArray) {
            return $values[count($values) - 1];
        }

        if ($explode) {
            return $values;
        }

        return explode(',', $values[count($values) - 1]);
    }

    /**
     * The raw query string as name => list of values (PHP's parser keeps only the last of
     * repeated names without brackets).
     *
     * @return array<string, list<string>>
     */
    private function rawQuery(Request $request): array
    {
        $query = (string) $request->server->get('QUERY_STRING', '');
        $result = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $result[urldecode($name)][] = urldecode($value);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function validateRequestBody(Request $request, MatchedOperation $match, string $label): array
    {
        $body = (string) $request->getContent();
        $requestBody = $match->operation->requestBody ?? null;

        if (! $requestBody instanceof stdClass) {
            return $body === '' ? [] : ["{$label}: the operation documents no request body, but one was sent"];
        }

        if ($body === '') {
            return ($requestBody->required ?? false) === true ? ["{$label}: the required request body is missing"] : [];
        }

        $content = $requestBody->content ?? new stdClass;
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type', ''))[0]));

        if (! isset($content->{$type})) {
            return ["{$label}: request content type ".($type === '' ? '(none)' : $type).' is not documented'];
        }

        if (! $this->isJson($type) || ! isset($content->{$type}->schema)) {
            return [];
        }

        $data = json_decode($body, false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["{$label}: the request body is not valid JSON"];
        }

        $pointer = $this->pointer(['paths', $match->pathTemplate, $match->method, 'requestBody', 'content', $type, 'schema']);

        return $this->validateAt($data, $pointer, "{$label}: request body");
    }

    /**
     * @return list<string>
     */
    private function validateUnmatchedProblem(Request $request, Response $response, bool $mustBe404Or405): array
    {
        $label = $request->getMethod().' '.$request->getPathInfo().' (no contract operation)';
        $status = $response->getStatusCode();

        if ($mustBe404Or405 && ! in_array($status, [404, 405], true)) {
            return ["{$label}: answered {$status}; a request that matches no operation must get a 404 or 405 problem"];
        }

        if ($this->mediaType($response) !== 'application/problem+json') {
            return ["{$label}: answered {$status} with content type ".($this->mediaType($response) ?? '(none)').' instead of application/problem+json'];
        }

        $data = json_decode((string) $response->getContent(), false);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ["{$label}: the problem body is not valid JSON"];
        }

        return $this->validateAt($data, $this->pointer(['components', 'schemas', 'Problem']), "{$label}: problem body");
    }

    private function responseKey(stdClass $responses, int $status): ?string
    {
        $exact = (string) $status;
        $range = intdiv($status, 100).'XX';

        foreach ([$exact, $range, strtolower($range), 'default'] as $key) {
            if (isset($responses->{$key})) {
                return $key;
            }
        }

        return null;
    }

    private function mediaType(Response $response): ?string
    {
        $type = $response->headers->get('Content-Type');

        return $type === null ? null : strtolower(trim(explode(';', $type)[0]));
    }

    private function documentedMediaType(stdClass $content, ?string $mediaType): ?string
    {
        if ($mediaType === null) {
            return null;
        }

        foreach (array_keys(get_object_vars($content)) as $documented) {
            $documented = (string) $documented;

            if ($documented === $mediaType || $documented === '*/*') {
                return $documented;
            }

            if (str_ends_with($documented, '/*') && str_starts_with($mediaType, substr($documented, 0, -1))) {
                return $documented;
            }
        }

        return null;
    }

    private function isJson(string $mediaType): bool
    {
        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }

    private function schemaType(stdClass $schema): ?string
    {
        $type = $schema->type ?? null;

        if (is_string($type)) {
            return $type;
        }

        if (is_array($type)) {
            foreach ($type as $candidate) {
                if ($candidate !== 'null') {
                    return (string) $candidate;
                }
            }
        }

        foreach (['oneOf', 'anyOf', 'allOf'] as $keyword) {
            foreach ($schema->{$keyword} ?? [] as $sub) {
                if ($sub instanceof stdClass && ($subType = $this->schemaType($sub)) !== null && $subType !== 'null') {
                    return $subType;
                }
            }
        }

        return null;
    }

    /**
     * Converts the string form of a parameter or header to the JSON type its schema expects.
     *
     * @param  list<string>|string  $value
     */
    private function coerce(array|string $value, stdClass $schema): mixed
    {
        $type = $this->schemaType($schema);

        if (is_array($value)) {
            $items = $schema->items ?? new stdClass;

            return array_map(fn (string $item): mixed => $this->coerce($item, $items instanceof stdClass ? $items : new stdClass), $value);
        }

        return match ($type) {
            'integer' => preg_match('/^-?\d+$/', $value) === 1 ? (int) $value : $value,
            'number' => is_numeric($value) ? $value + 0 : $value,
            'boolean' => match ($value) {
                'true' => true,
                'false' => false,
                default => $value,
            },
            default => $value,
        };
    }

    /**
     * @param  list<string>  $segments
     */
    private function pointer(array $segments): string
    {
        $escaped = array_map(fn (string $s): string => str_replace(['~', '/'], ['~0', '~1'], $s), $segments);

        return '#/'.implode('/', array_map('rawurlencode', $escaped));
    }

    /**
     * @return list<string>
     */
    private function validateAt(mixed $data, string $pointer, string $context): array
    {
        $result = $this->validator->validate($data, self::DOCUMENT_ID.$pointer);

        if ($result->isValid()) {
            return [];
        }

        $errors = (new ErrorFormatter)->format($result->error(), true);
        $lines = [];

        foreach ($errors as $path => $messages) {
            foreach ((array) $messages as $message) {
                $lines[] = "{$context}: {$path}: {$message}";
            }
        }

        return $lines === [] ? ["{$context}: invalid"] : $lines;
    }

    private function label(Request $request, MatchedOperation $match): string
    {
        return "{$match->operationId} ({$request->getMethod()} {$request->getPathInfo()})";
    }

    private static function decode(string $json): stdClass
    {
        $document = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

        if (! $document instanceof stdClass) {
            throw new RuntimeException('The contract is not a JSON object.');
        }

        return $document;
    }

    /**
     * Converts arrays to the object/array form json_decode() produces, so that JSON objects and
     * lists are told apart.
     */
    private static function toJsonValue(mixed $data): mixed
    {
        return json_decode((string) json_encode($data, JSON_THROW_ON_ERROR), false);
    }
}
