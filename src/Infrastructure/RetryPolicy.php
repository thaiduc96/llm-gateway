<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Infrastructure;

use Thaiduc96\LlmGateway\Exceptions\LLMException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;

final class RetryPolicy
{
    private const MAX_ALLOWED_ATTEMPTS = 2;
    private const MAX_ALLOWED_BACKOFF_MS = 5000;

    private readonly int $maxAttempts;
    private readonly int $backoffMs;
    private readonly int $maxBackoffMs;

    public function __construct(
        int $maxAttempts,
        int $backoffMs,
        private readonly bool $retryOnOverloaded = false,
        int $maxBackoffMs = self::MAX_ALLOWED_BACKOFF_MS,
    ) {
        // Bound the values for safety
        $this->maxAttempts = min(max($maxAttempts, 0), self::MAX_ALLOWED_ATTEMPTS);
        $this->backoffMs = min(max($backoffMs, 0), self::MAX_ALLOWED_BACKOFF_MS);
        $this->maxBackoffMs = min(max($maxBackoffMs, $this->backoffMs), self::MAX_ALLOWED_BACKOFF_MS);
    }

    /**
     * Determine if the given exception is retryable.
     */
    public function shouldRetry(LLMException $exception): bool
    {
        return match (true) {
            $exception instanceof TimeoutException => true,
            $exception instanceof ProviderException && !$exception->isMalformedResponse() => true,
            $exception instanceof OverloadedException => $this->retryOnOverloaded,
            default => false,
        };
    }

    /**
     * Calculate backoff delay for a given attempt using exponential backoff with jitter.
     *
     * Formula: min(maxBackoff, baseBackoff * 2^attempt) + random(0, baseBackoff/2)
     */
    public function calculateBackoffMs(int $attempt): int
    {
        if ($this->backoffMs <= 0) {
            return 0;
        }

        $exponential = (int) min($this->maxBackoffMs, $this->backoffMs * (2 ** $attempt));
        $jitter = mt_rand(0, intdiv($this->backoffMs, 2));

        return $exponential + $jitter;
    }

    /**
     * Execute a callable with retry logic.
     *
     * @template T
     * @param  callable(): T  $operation
     * @return T
     *
     * @throws LLMException  The last exception if all attempts fail.
     */
    public function execute(callable $operation): mixed
    {
        $lastException = null;

        for ($attempt = 0; $attempt <= $this->maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (LLMException $e) {
                $lastException = $e;

                if ($attempt < $this->maxAttempts && $this->shouldRetry($e)) {
                    $backoff = $this->calculateBackoffMs($attempt);
                    if ($backoff > 0) {
                        usleep($backoff * 1000);
                    }
                    continue;
                }

                throw $e;
            }
        }

        // Should never reach here, but satisfy static analysis.
        throw $lastException ?? new ProviderException('All retry attempts exhausted');
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    public function getBackoffMs(): int
    {
        return $this->backoffMs;
    }

    public function getMaxBackoffMs(): int
    {
        return $this->maxBackoffMs;
    }
}
