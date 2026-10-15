<?php

namespace Cmd\Reports\Services;

use GuzzleHttp\Exception\BadResponseException;
use RuntimeException;
use Throwable;

/** Safe diagnostics only: never retain a request, response body, or previous exception. */
final class SnowflakeReadFailure extends RuntimeException
{
    private function __construct(
        public readonly string $operation,
        public readonly ?int $httpStatus,
        public readonly ?string $snowflakeCode,
        public readonly ?string $queryId,
        public readonly int $attempts,
    ) {
        parent::__construct(sprintf(
            'Snowflake %s failed (HTTP %s, code %s, query %s, attempts %d).',
            $operation, $httpStatus ?? 'unknown', $snowflakeCode ?? 'unknown', $queryId ?? 'unknown', $attempts
        ));
    }

    public static function fromError(Throwable $error, string $operation, int $attempts): self
    {
        $status = null;
        $code = $queryId = null;
        if ($error instanceof BadResponseException) {
            $response = $error->getResponse();
            $status = $response->getStatusCode();
            $body = json_decode((string) $response->getBody(), true);
            if (is_array($body)) {
                $rawCode = $body['code'] ?? null;
                if ((is_string($rawCode) || is_int($rawCode)) && preg_match('/^\d{1,6}$/D', (string) $rawCode)) {
                    $code = (string) $rawCode;
                }
                $rawId = $body['statementHandle'] ?? null;
                if (is_string($rawId) && preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $rawId)) {
                    $queryId = $rawId;
                }
            }
        }
        return new self($operation, $status, $code, $queryId, $attempts);
    }

    public function isCancelledTimeout(): bool
    {
        // A transport timeout or an unrecognized 408 does not prove server cancellation.
        return $this->httpStatus === 408 && $this->snowflakeCode !== null && (int) $this->snowflakeCode === 630;
    }

    public static function diagnosticSuffix(Throwable $error): string
    {
        return $error instanceof self ? ' ' . $error->getMessage() : '';
    }
}
