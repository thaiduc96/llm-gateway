<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Events\LLMFailed;
use Thaiduc96\LlmGateway\Events\LLMFallbackTriggered;
use Thaiduc96\LlmGateway\Events\LLMRequested;
use Thaiduc96\LlmGateway\Events\LLMSucceeded;
use Thaiduc96\LlmGateway\Exceptions\AuthException;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\OverloadedException;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Exceptions\TimeoutException;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;
use Thaiduc96\LlmGateway\Infrastructure\DefaultProviderRegistry;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;
use Thaiduc96\LlmGateway\LLMGatewayManager;

final class LLMGatewayManagerTest extends TestCase
{
    /** @var array<object> */
    private array $dispatchedEvents = [];

    private function makeManager(
        ?LLMProvider $primary = null,
        ?LLMProvider $fallback = null,
        array $configOverrides = [],
        ?CooldownManager $cooldownManager = null,
        ?RetryPolicy $retryPolicy = null,
    ): LLMGatewayManager {
        $registry = new DefaultProviderRegistry();

        if ($primary !== null) {
            $registry->register($primary->name(), fn () => $primary);
        }
        if ($fallback !== null) {
            $registry->register($fallback->name(), fn () => $fallback);
        }

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = $cooldownManager ?? new CooldownManager($cache, $configOverrides['cooldown_seconds'] ?? 60);
        $retry = $retryPolicy ?? new RetryPolicy(
            maxAttempts: $configOverrides['retry_attempts'] ?? 0,
            backoffMs: $configOverrides['retry_backoff_ms'] ?? 0,
        );

        $events = new Dispatcher();
        $this->dispatchedEvents = [];

        $events->listen('*', function (string $eventName, array $payload) {
            if (!empty($payload)) {
                $this->dispatchedEvents[] = $payload[0];
            }
        });

        $config = array_merge([
            'default' => [
                'primary' => $primary?->name() ?? 'openai',
                'fallback' => $fallback?->name() ?? null,
            ],
            'fallback_on' => ['rate_limit', 'timeout', 'overloaded', 'server_error', 'malformed_response'],
            'cooldown_seconds' => 60,
            'timeout_seconds' => 30,
            'connect_timeout_seconds' => 5,
            'retry_attempts' => 0,
            'retry_backoff_ms' => 0,
            'defaults' => [
                'temperature' => 0.7,
                'max_output_tokens' => 1024,
            ],
            'providers' => [
                ($primary?->name() ?? 'openai') => ['driver' => $primary?->name() ?? 'openai'],
                ($fallback?->name() ?? 'gemini') => ['driver' => $fallback?->name() ?? 'gemini'],
            ],
        ], $configOverrides);

        return new LLMGatewayManager($registry, $cooldown, $retry, $events, $config);
    }

    private function successProvider(string $name, string $content = 'Hello'): LLMProvider
    {
        return new class ($name, $content) implements LLMProvider {
            public int $callCount = 0;

            public function __construct(
                private readonly string $providerName,
                private readonly string $content,
            ) {}

            public function name(): string
            {
                return $this->providerName;
            }

            public function chat(array $messages, array $options = []): LLMResult
            {
                $this->callCount++;

                return new LLMResult(
                    content: $this->content,
                    provider: $this->providerName,
                    model: 'test-model',
                    latencyMs: 100.0,
                    usage: ['prompt_tokens' => 10, 'completion_tokens' => 5, 'total_tokens' => 15],
                    finishReason: 'stop',
                );
            }

            public function chatStream(array $messages, array $options = []): \Generator
            {
                $this->callCount++;
                $content = $this->content;

                return (function () use ($content) {
                    yield $content;
                })();
            }
        };
    }

    private function failingProvider(string $name, \Throwable $exception): LLMProvider
    {
        return new class ($name, $exception) implements LLMProvider {
            public int $callCount = 0;

            public function __construct(
                private readonly string $providerName,
                private readonly \Throwable $exception,
            ) {}

            public function name(): string
            {
                return $this->providerName;
            }

            public function chat(array $messages, array $options = []): LLMResult
            {
                $this->callCount++;
                throw $this->exception;
            }

            public function chatStream(array $messages, array $options = []): \Generator
            {
                $this->callCount++;
                throw $this->exception;
            }
        };
    }

    private function messages(): array
    {
        return [['role' => 'user', 'content' => 'Hello']];
    }

    /**
     * @return array<class-string, list<object>>
     */
    private function eventsByType(): array
    {
        $grouped = [];
        foreach ($this->dispatchedEvents as $event) {
            $class = $event::class;
            $grouped[$class][] = $event;
        }

        return $grouped;
    }

    // ===================================================================
    // A) Primary success
    // ===================================================================

    public function test_a_primary_success(): void
    {
        $primary = $this->successProvider('openai');
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $result = $manager->chat($this->messages());

        $this->assertSame('Hello', $result->content);
        $this->assertSame('openai', $result->provider);
        $this->assertSame(1, $primary->callCount);
        $this->assertSame(0, $fallback->callCount);

        $events = $this->eventsByType();
        $this->assertCount(1, $events[LLMRequested::class] ?? []);
        $this->assertCount(1, $events[LLMSucceeded::class] ?? []);
        $this->assertEmpty($events[LLMFailed::class] ?? []);
        $this->assertEmpty($events[LLMFallbackTriggered::class] ?? []);
    }

    // ===================================================================
    // B) Primary 429 → fallback + cooldown
    // ===================================================================

    public function test_b_primary_429_triggers_fallback_and_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertSame('gemini', $result->provider);
        $this->assertTrue($cooldown->isInCooldown('openai'));

        $events = $this->eventsByType();
        $this->assertCount(1, $events[LLMFallbackTriggered::class] ?? []);
        $this->assertSame('rate_limit', $events[LLMFallbackTriggered::class][0]->fallbackReason);
    }

    // ===================================================================
    // C) Primary body RESOURCE_EXHAUSTED → fallback + cooldown
    // ===================================================================

    public function test_c_resource_exhausted_triggers_fallback_and_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('RESOURCE_EXHAUSTED', 0, null, 'openai', 200));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertTrue($cooldown->isInCooldown('openai'));
    }

    // ===================================================================
    // D) Cooldown active → skip primary, call fallback
    // ===================================================================

    public function test_d_cooldown_active_skips_primary_calls_fallback(): void
    {
        $primary = $this->successProvider('openai');
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $cooldown->activate('openai'); // Pre-activate cooldown

        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertSame('gemini', $result->provider);
        $this->assertSame(0, $primary->callCount);
        $this->assertSame(1, $fallback->callCount);
    }

    // ===================================================================
    // E) Primary timeout → fallback, no cooldown
    // ===================================================================

    public function test_e_primary_timeout_triggers_fallback_no_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('timeout', 0, null, 'openai'));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertFalse($cooldown->isInCooldown('openai'));
    }

    // ===================================================================
    // F) Primary 503 → fallback + cooldown
    // ===================================================================

    public function test_f_primary_503_triggers_fallback_and_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new OverloadedException('overloaded', 503, null, 'openai', 503));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertTrue($cooldown->isInCooldown('openai'));

        $events = $this->eventsByType();
        $this->assertSame('overloaded', $events[LLMFallbackTriggered::class][0]->fallbackReason);
    }

    // ===================================================================
    // G) Primary 500 → fallback, no cooldown
    // ===================================================================

    public function test_g_primary_500_triggers_fallback_no_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new ProviderException('server error', 500, null, 'openai', 500));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertFalse($cooldown->isInCooldown('openai'));
    }

    // ===================================================================
    // H) Primary 401 → NO fallback, throws AuthException
    // ===================================================================

    public function test_h_primary_401_no_fallback_throws_auth(): void
    {
        $primary = $this->failingProvider('openai', new AuthException('unauthorized', 401, null, 'openai', 401));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $this->expectException(AuthException::class);
        $manager->chat($this->messages());
    }

    // ===================================================================
    // I) Primary 400 → NO fallback, throws BadRequestException
    // ===================================================================

    public function test_i_primary_400_no_fallback_throws_bad_request(): void
    {
        $primary = $this->failingProvider('openai', new BadRequestException('bad request', 400, null, 'openai', 400));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $this->expectException(BadRequestException::class);
        $manager->chat($this->messages());
    }

    // ===================================================================
    // J) Primary 200 but malformed JSON → fallback (ProviderException)
    // ===================================================================

    public function test_j_primary_malformed_json_triggers_fallback(): void
    {
        $primary = $this->failingProvider('openai', new ProviderException('malformed', 0, null, 'openai', 200, true));
        $fallback = $this->successProvider('gemini', 'Fallback response');
        $manager = $this->makeManager($primary, $fallback);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);

        $events = $this->eventsByType();
        $this->assertSame('malformed_response', $events[LLMFallbackTriggered::class][0]->fallbackReason);
    }

    // ===================================================================
    // K) Both providers fail → throws final typed exception
    // ===================================================================

    public function test_k_both_providers_fail_throws_final_exception(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('primary timeout', 0, null, 'openai'));
        $fallback = $this->failingProvider('gemini', new ProviderException('fallback error', 500, null, 'gemini', 500));
        $manager = $this->makeManager($primary, $fallback);

        try {
            $manager->chat($this->messages());
            $this->fail('Expected ProviderException');
        } catch (ProviderException $e) {
            $this->assertSame('gemini', $e->provider);
        }

        $events = $this->eventsByType();
        // Should have 2 LLMFailed events: one for primary, one for fallback
        $this->assertCount(2, $events[LLMFailed::class] ?? []);
    }

    // ===================================================================
    // L) Runtime override primary/fallback works
    // ===================================================================

    public function test_l_runtime_override_primary(): void
    {
        $openai = $this->successProvider('openai', 'OpenAI response');
        $gemini = $this->successProvider('gemini', 'Gemini response');

        // Default primary is openai, but we override to gemini
        $manager = $this->makeManager($openai, $gemini);
        $result = $manager->usingPrimary('gemini')->chat($this->messages());

        $this->assertSame('Gemini response', $result->content);
        $this->assertSame('gemini', $result->provider);
    }

    public function test_l_runtime_override_fallback(): void
    {
        $openai = $this->failingProvider('openai', new TimeoutException('timeout', 0, null, 'openai'));
        $gemini = $this->successProvider('gemini', 'Gemini response');

        // Config has no fallback set, but we override at runtime
        $manager = $this->makeManager($openai, $gemini, [
            'default' => ['primary' => 'openai', 'fallback' => null],
            'providers' => [
                'openai' => ['driver' => 'openai'],
                'gemini' => ['driver' => 'gemini'],
            ],
        ]);

        $result = $manager->usingFallback('gemini')->chat($this->messages());

        $this->assertSame('Gemini response', $result->content);
    }

    public function test_l_runtime_disable_fallback(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('timeout', 0, null, 'openai'));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $this->expectException(TimeoutException::class);

        $manager->usingFallback(null)->chat($this->messages());
    }

    // ===================================================================
    // M) Events are dispatched with correct payload fields
    // ===================================================================

    public function test_m_success_events_have_correct_fields(): void
    {
        $primary = $this->successProvider('openai');
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $manager->chat($this->messages(), ['request_id' => 'req-123']);

        $events = $this->eventsByType();

        // LLMRequested
        $requested = $events[LLMRequested::class][0];
        $this->assertSame('openai', $requested->provider);
        $this->assertSame('req-123', $requested->requestId);
        $this->assertArrayHasKey('temperature', $requested->options);
        $this->assertArrayHasKey('max_output_tokens', $requested->options);

        // LLMSucceeded
        $succeeded = $events[LLMSucceeded::class][0];
        $this->assertSame('openai', $succeeded->provider);
        $this->assertSame('test-model', $succeeded->model);
        $this->assertGreaterThan(0, $succeeded->latencyMs);
        $this->assertSame('req-123', $succeeded->requestId);
        $this->assertNotNull($succeeded->usage);
        $this->assertArrayHasKey('temperature', $succeeded->options);
    }

    public function test_m_failure_events_have_correct_fields(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $manager->chat($this->messages(), ['request_id' => 'req-456']);

        $events = $this->eventsByType();

        // LLMFailed for primary
        $failed = $events[LLMFailed::class][0];
        $this->assertSame('openai', $failed->provider);
        $this->assertSame(RateLimitedException::class, $failed->exceptionClass);
        $this->assertSame('req-456', $failed->requestId);
        $this->assertGreaterThan(0, $failed->latencyMs);

        // LLMFallbackTriggered
        $fallbackEvent = $events[LLMFallbackTriggered::class][0];
        $this->assertSame('openai', $fallbackEvent->primaryProvider);
        $this->assertSame('gemini', $fallbackEvent->fallbackProvider);
        $this->assertSame('rate_limit', $fallbackEvent->fallbackReason);
        $this->assertSame(RateLimitedException::class, $fallbackEvent->exceptionClass);
        $this->assertSame('req-456', $fallbackEvent->requestId);
    }

    // ===================================================================
    // Additional edge cases
    // ===================================================================

    public function test_no_fallback_configured_throws_on_recoverable_failure(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('timeout'));

        $registry = new DefaultProviderRegistry();
        $registry->register('openai', fn () => $primary);

        $manager = new LLMGatewayManager(
            registry: $registry,
            cooldownManager: new CooldownManager(new CacheRepository(new ArrayStore()), 60),
            retryPolicy: new RetryPolicy(0, 0),
            events: new Dispatcher(),
            config: [
                'default' => ['primary' => 'openai', 'fallback' => null],
                'fallback_on' => ['timeout'],
                'defaults' => ['temperature' => 0.7, 'max_output_tokens' => 1024],
                'providers' => ['openai' => ['driver' => 'openai']],
            ],
        );

        $this->expectException(TimeoutException::class);
        $manager->chat($this->messages());
    }

    public function test_runtime_override_does_not_mutate_original(): void
    {
        $openai = $this->successProvider('openai', 'OpenAI');
        $gemini = $this->successProvider('gemini', 'Gemini');
        $manager = $this->makeManager($openai, $gemini);

        // Use override
        $overridden = $manager->usingPrimary('gemini');
        $result1 = $overridden->chat($this->messages());
        $this->assertSame('Gemini', $result1->content);

        // Original should still use openai
        $result2 = $manager->chat($this->messages());
        $this->assertSame('OpenAI', $result2->content);
    }

    public function test_fallback_reason_not_in_config_list_does_not_trigger_fallback(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('timeout', 0, null, 'openai'));
        $fallback = $this->successProvider('gemini');

        // Remove 'timeout' from fallback_on list
        $manager = $this->makeManager($primary, $fallback, [
            'fallback_on' => ['rate_limit', 'overloaded'],
            'default' => ['primary' => 'openai', 'fallback' => 'gemini'],
            'providers' => [
                'openai' => ['driver' => 'openai'],
                'gemini' => ['driver' => 'gemini'],
            ],
        ]);

        $this->expectException(TimeoutException::class);
        $manager->chat($this->messages());
    }

    // ===================================================================
    // H3: Model field in events uses config model, not provider name
    // ===================================================================

    public function test_h3_event_model_uses_provider_config_model(): void
    {
        $primary = $this->successProvider('openai');
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback, [
            'default' => ['primary' => 'openai', 'fallback' => 'gemini'],
            'providers' => [
                'openai' => ['driver' => 'openai', 'model' => 'gpt-4o'],
                'gemini' => ['driver' => 'gemini', 'model' => 'gemini-2.0-flash'],
            ],
        ]);

        $manager->chat($this->messages());

        $events = $this->eventsByType();

        $requested = $events[LLMRequested::class][0];
        $this->assertSame('gpt-4o', $requested->model);
    }

    public function test_h3_failed_event_model_uses_provider_config_model(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback, [
            'default' => ['primary' => 'openai', 'fallback' => 'gemini'],
            'providers' => [
                'openai' => ['driver' => 'openai', 'model' => 'gpt-4o'],
                'gemini' => ['driver' => 'gemini', 'model' => 'gemini-2.0-flash'],
            ],
        ]);

        $manager->chat($this->messages());

        $events = $this->eventsByType();

        $failed = $events[LLMFailed::class][0];
        $this->assertSame('gpt-4o', $failed->model);
    }

    // ===================================================================
    // H4: Validate messages
    // ===================================================================

    public function test_h4_empty_messages_throws_bad_request(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Messages array must not be empty');
        $manager->chat([]);
    }

    public function test_h4_message_missing_role_throws_bad_request(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid message at index 0');
        $manager->chat([['content' => 'Hello']]);
    }

    public function test_h4_message_missing_content_throws_bad_request(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('Invalid message at index 0');
        $manager->chat([['role' => 'user']]);
    }

    public function test_h4_message_with_non_string_role_throws_bad_request(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        $manager->chat([['role' => 123, 'content' => 'Hello']]);
    }

    public function test_h4_message_with_non_string_content_throws_bad_request(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        $manager->chat([['role' => 'user', 'content' => 123]]);
    }

    public function test_h4_valid_messages_pass_validation(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $result = $manager->chat([
            ['role' => 'system', 'content' => 'Be helpful'],
            ['role' => 'user', 'content' => 'Hello'],
        ]);

        $this->assertSame('Hello', $result->content);
    }

    // ===================================================================
    // M1: Retry-After duration passed to CooldownManager
    // ===================================================================

    public function test_m1_retry_after_duration_passed_to_cooldown(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429, 90));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $result = $manager->chat($this->messages());

        $this->assertSame('Fallback response', $result->content);
        $this->assertTrue($cooldown->isInCooldown('openai'));
    }

    // ===================================================================
    // M2: Fallback cooldown check
    // ===================================================================

    public function test_m2_fallback_in_cooldown_throws_primary_exception(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429));
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $cooldown->activate('gemini'); // Pre-activate fallback cooldown

        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $this->expectException(RateLimitedException::class);
        $manager->chat($this->messages());
    }

    public function test_m2_primary_cooldown_with_fallback_cooldown_tries_primary(): void
    {
        $primary = $this->successProvider('openai', 'Primary response');
        $fallback = $this->successProvider('gemini', 'Fallback response');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $cooldown->activate('openai');
        $cooldown->activate('gemini');

        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        // Both in cooldown, should try primary anyway
        $result = $manager->chat($this->messages());
        $this->assertSame('Primary response', $result->content);
    }

    // ===================================================================
    // M3: Auto-generate request_id
    // ===================================================================

    public function test_m3_auto_generates_request_id_when_not_provided(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $manager->chat($this->messages());

        $events = $this->eventsByType();
        $requested = $events[LLMRequested::class][0];
        $this->assertNotNull($requested->requestId);
        $this->assertNotEmpty($requested->requestId);
    }

    public function test_m3_uses_provided_request_id(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $manager->chat($this->messages(), ['request_id' => 'my-custom-id']);

        $events = $this->eventsByType();
        $requested = $events[LLMRequested::class][0];
        $this->assertSame('my-custom-id', $requested->requestId);
    }

    // ===================================================================
    // Streaming tests
    // ===================================================================

    public function test_stream_primary_success(): void
    {
        $primary = $this->successProvider('openai', 'Streamed content');
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $chunks = iterator_to_array($manager->stream($this->messages()));

        $this->assertSame(['Streamed content'], $chunks);
    }

    public function test_stream_primary_fails_falls_back(): void
    {
        $primary = $this->failingProvider('openai', new TimeoutException('timeout', 0, null, 'openai'));
        $fallback = $this->successProvider('gemini', 'Fallback stream');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $chunks = iterator_to_array($manager->stream($this->messages()));

        $this->assertSame(['Fallback stream'], $chunks);
    }

    public function test_stream_cooldown_skips_to_fallback(): void
    {
        $primary = $this->successProvider('openai', 'Primary stream');
        $fallback = $this->successProvider('gemini', 'Fallback stream');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $cooldown->activate('openai');

        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $chunks = iterator_to_array($manager->stream($this->messages()));

        $this->assertSame(['Fallback stream'], $chunks);
        $this->assertSame(0, $primary->callCount);
    }

    public function test_stream_non_fallback_error_throws(): void
    {
        $primary = $this->failingProvider('openai', new AuthException('unauthorized', 401, null, 'openai', 401));
        $fallback = $this->successProvider('gemini');
        $manager = $this->makeManager($primary, $fallback);

        $this->expectException(AuthException::class);
        iterator_to_array($manager->stream($this->messages()));
    }

    public function test_stream_activates_cooldown_on_rate_limit(): void
    {
        $primary = $this->failingProvider('openai', new RateLimitedException('rate limited', 429, null, 'openai', 429));
        $fallback = $this->successProvider('gemini', 'Fallback stream');

        $cache = new CacheRepository(new ArrayStore());
        $cooldown = new CooldownManager($cache, 60);
        $manager = $this->makeManager($primary, $fallback, cooldownManager: $cooldown);

        $chunks = iterator_to_array($manager->stream($this->messages()));

        $this->assertSame(['Fallback stream'], $chunks);
        $this->assertTrue($cooldown->isInCooldown('openai'));
    }

    public function test_stream_validates_messages(): void
    {
        $primary = $this->successProvider('openai');
        $manager = $this->makeManager($primary);

        $this->expectException(BadRequestException::class);
        iterator_to_array($manager->stream([]));
    }

    public function test_stream_with_runtime_override(): void
    {
        $openai = $this->successProvider('openai', 'OpenAI stream');
        $gemini = $this->successProvider('gemini', 'Gemini stream');
        $manager = $this->makeManager($openai, $gemini);

        $chunks = iterator_to_array(
            $manager->usingPrimary('gemini')->stream($this->messages()),
        );

        $this->assertSame(['Gemini stream'], $chunks);
    }
}
