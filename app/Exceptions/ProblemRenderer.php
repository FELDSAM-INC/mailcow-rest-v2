<?php

namespace App\Exceptions;

use App\Clients\SharedStore\SharedStoreUnavailable;
use App\Http\Requests\Api\V2\ApiFormRequest;
use App\Support\Http\Problem;
use App\Support\Http\ProblemType;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\RecordsNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Renders every exception as an RFC 9457 problem (FR-049, R27). Unexpected exceptions become a
 * `500 about:blank` problem without internals; Laravel reports them (with their trace) to the log.
 */
final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): Response
    {
        return match (true) {
            $e instanceof ProblemException => Problem::response($e->type, $request, $e->detail, $e->extensions, $e->headers),
            $e instanceof ValidationProblemException => self::validation($e->errors, $request),
            $e instanceof SharedStoreUnavailable => Problem::response(ProblemType::TemporaryFailure, $request, null, ['dependency' => 'sharedStore'], ['Retry-After' => '10']),
            $e instanceof ValidationException => self::validation(ApiFormRequest::violations($e->validator), $request),
            $e instanceof TokenMismatchException => Problem::response(ProblemType::CsrfTokenMismatch, $request),
            $e instanceof AuthorizationException, $e instanceof AccessDeniedHttpException => Problem::response(ProblemType::Forbidden, $request),
            $e instanceof ModelNotFoundException, $e instanceof RecordsNotFoundException, $e instanceof NotFoundHttpException => Problem::blank(404, $request, 'The resource does not exist.'),
            $e instanceof MethodNotAllowedHttpException => Problem::blank(405, $request, 'The method is not allowed for this resource.', self::headers($e)),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => Problem::blank($e->getStatusCode(), $request, self::clientDetail($e), self::headers($e)),
            $e instanceof HttpExceptionInterface => Problem::blank($e->getStatusCode(), $request, null, self::headers($e)),
            default => Problem::blank(500, $request, 'An unexpected error occurred.'),
        };
    }

    /**
     * @param  array<string, list<array{code: string, params: array<string, mixed>, message: string}>>  $errors
     */
    private static function validation(array $errors, Request $request): Response
    {
        // `params` is a JSON object, also when the rule has none.
        $errors = array_map(
            fn (array $violations): array => array_map(fn (array $v): array => ['params' => (object) $v['params']] + $v, $violations),
            $errors,
        );

        return Problem::response(ProblemType::ValidationFailed, $request, null, ['errors' => (object) $errors]);
    }

    /**
     * @return array<string, string>
     */
    private static function headers(HttpExceptionInterface $e): array
    {
        return array_map(fn (mixed $v): string => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders());
    }

    private static function clientDetail(HttpExceptionInterface $e): ?string
    {
        $message = $e->getMessage();

        return $message === '' ? null : $message;
    }
}
