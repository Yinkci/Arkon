<?php

namespace App\Http\Api;

use App\Arkon\Errors\ArkonException;
use App\Arkon\Errors\RateLimitedException;
use App\Arkon\Errors\StaleVersionException;
use App\Arkon\Errors\ValidationException;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The public API's one error shape:
 *
 *   {"error": {"code": "validation_error", "message": "…", "fields": {"title": ["…"]}}}
 *
 * Codes are stable snake_case strings (documented in docs/api.md); messages are for people.
 */
final class ApiError extends RuntimeException
{
    /** @param array<string, list<string>> $fields */
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message, public readonly array $fields = [], public readonly array $extra = [], public readonly array $headers = [])
    {
        parent::__construct($message);
    }

    public static function unauthenticated(): self
    {
        return new self(401, 'unauthenticated', 'This request needs an API token: send it as "Authorization: Bearer <token>".', headers: ['WWW-Authenticate' => 'Bearer realm="arkon"']);
    }

    public static function invalidToken(): self
    {
        return new self(401, 'invalid_token', 'The API token is invalid, expired, revoked, or not valid for this site.', headers: ['WWW-Authenticate' => 'Bearer realm="arkon", error="invalid_token"']);
    }

    public static function insufficientScope(string $scope): self
    {
        return new self(403, 'insufficient_scope', "This token does not have the {$scope} scope.", extra: ['required_scope' => $scope], headers: ['WWW-Authenticate' => 'Bearer realm="arkon", error="insufficient_scope", scope="'.$scope.'"']);
    }

    public static function notFound(string $what = 'Resource'): self
    {
        return new self(404, 'not_found', "{$what} not found.");
    }

    /** @param array<string, list<string>> $fields */
    public static function invalidParameter(string $message, array $fields = []): self
    {
        return new self(400, 'invalid_parameter', $message, $fields);
    }

    /** Domain errors from the services, in the API's shape. */
    public static function fromDomain(ArkonException $e): self
    {
        return match (true) {
            $e instanceof ValidationException => new self(422, 'validation_error', $e->getMessage(), self::fields($e->issues)),
            $e instanceof StaleVersionException => new self(409, 'version_conflict', 'The item changed since you read it. Read it again and retry with its current version.', extra: ['current_version' => $e->currentVersion]),
            $e instanceof RateLimitedException => new self(429, 'rate_limited', $e->getMessage(), headers: ['Retry-After' => (string) $e->retryAfter]),
            default => new self($e->status(), match ($e->code()) {
                'NOT_FOUND' => 'not_found', 'FORBIDDEN' => 'forbidden', 'CONFLICT' => 'conflict', 'GONE' => 'gone', default => strtolower($e->code()),
            }, $e->getMessage()),
        };
    }

    /** @param list<array{path?: string, nodeId?: string, message: string}> $issues */
    public static function fields(array $issues): array
    {
        $fields = [];
        foreach ($issues as $issue) {
            $fields[$issue['path'] ?? ($issue['nodeId'] ?? 'content')][] = $issue['message'];
        }

        return $fields;
    }

    public function render(): JsonResponse
    {
        $error = ['code' => $this->errorCode, 'message' => $this->getMessage(), ...($this->fields === [] ? [] : ['fields' => $this->fields]), ...$this->extra];

        return response()->json(['error' => $error], $this->status, [...$this->headers, 'Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
