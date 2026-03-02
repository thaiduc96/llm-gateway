<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\Exceptions\AuthException;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;

final class RetryPolicyTest extends TestCase
{
    public function test_bounds_max_attempts_to_2(): void
    {
        $policy = new RetryPolicy(maxAttempts: 10, backoffMs: 100);
        $this->assertSame(2, $policy->getMaxAttempts());
    }

    public function test_bounds_max_attempts_minimum_0(): void
    {
        $policy = new RetryPolicy(maxAttempts: -1, backoffMs: 100);
        $this->assertSame(0, $policy->getMaxAttempts());
    }

    public function test_bounds_backoff_ms(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 99999);
        $this->assertSame(5000, $policy->getBackoffMs());
    }

    public function test_timeout_is_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $this->assertTrue($policy->shouldRetry(new TimeoutException()));
    }

    public function test_provider_exception_server_error_is_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $e = new ProviderException('error', 500, null, 'test', 500);
        $this->assertTrue($policy->shouldRetry($e));
    }

    public function test_provider_exception_malformed_is_not_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $e = new ProviderException('error', 0, null, 'test', 200, true);
        $this->assertFalse($policy->shouldRetry($e));
    }

    public function test_overloaded_not_retryable_by_default(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $this->assertFalse($policy->shouldRetry(new OverloadedException()));
    }

    public function test_overloaded_retryable_when_configured(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0, retryOnOverloaded: true);
        $this->assertTrue($policy->shouldRetry(new OverloadedException()));
    }

    public function test_auth_exception_never_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $this->assertFalse($policy->shouldRetry(new AuthException()));
    }

    public function test_bad_request_never_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $this->assertFalse($policy->shouldRetry(new BadRequestException()));
    }

    public function test_rate_limited_never_retryable(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $this->assertFalse($policy->shouldRetry(new RateLimitedException()));
    }

    public function test_execute_succeeds_on_first_try(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $callCount = 0;

        $result = $policy->execute(function () use (&$callCount) {
            $callCount++;

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(1, $callCount);
    }

    public function test_execute_retries_on_timeout_then_succeeds(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, backoffMs: 0);
        $callCount = 0;

        $result = $policy->execute(function () use (&$callCount) {
            $callCount++;
            if ($callCount === 1) {
                throw new TimeoutException('timeout', 0, null, 'test');
            }

            return 'success';
        });

        $this->assertSame('success', $result);
        $this->assertSame(2, $callCount);
    }

    public function test_execute_throws_non_retryable_immediately(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, backoffMs: 0);
        $callCount = 0;

        $this->expectException(AuthException::class);

        $policy->execute(function () use (&$callCount) {
            $callCount++;
            throw new AuthException('auth error');
        });
    }

    public function test_execute_exhausts_retries_then_throws(): void
    {
        $policy = new RetryPolicy(maxAttempts: 2, backoffMs: 0);
        $callCount = 0;

        try {
            $policy->execute(function () use (&$callCount) {
                $callCount++;
                throw new TimeoutException('timeout');
            });
            $this->fail('Expected TimeoutException');
        } catch (TimeoutException) {
            // 1 initial + 2 retries = 3 calls
            $this->assertSame(3, $callCount);
        }
    }
}
