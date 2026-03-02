<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Str;
use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\Contracts\ProviderRegistry;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Events\LLMFailed;
use Thaiduc96\LlmGateway\Events\LLMFallbackTriggered;
use Thaiduc96\LlmGateway\Events\LLMRequested;
use Thaiduc96\LlmGateway\Events\LLMSucceeded;
use Thaiduc96\LlmGateway\Exceptions\BadRequestException;
use Thaiduc96\LlmGateway\Exceptions\LLMException;
use Thaiduc96\LlmGateway\Exceptions\RateLimitedException;
use Thaiduc96\LlmGateway\Infrastructure\CooldownManager;
use Thaiduc96\LlmGateway\Infrastructure\RetryPolicy;

final class LLMGatewayManager
{
    private ?string $runtimePrimary = null;
    private ?string $runtimeFallback = null;
    private bool $fallbackExplicitlyDisabled = false;

    /**
     * @param  array<string, mixed>  $config  The full llm-gateway config array.
     */
    public function __construct(
        private readonly ProviderRegistry $registry,
        private readonly CooldownManager $cooldownManager,
        private readonly RetryPolicy $retryPolicy,
        private readonly Dispatcher $events,
        private readonly array $config,
    ) {}

    /**
     * Set a runtime override for the primary provider.
     */
    public function usingPrimary(string $provider): self
    {
        $clone = clone $this;
        $clone->runtimePrimary = $provider;

        return $clone;
    }

    /**
     * Set a runtime override for the fallback provider.
     * Pass null to explicitly disable fallback.
     */
    public function usingFallback(?string $provider): self
    {
        $clone = clone $this;
        if ($provider === null) {
            $clone->runtimeFallback = null;
            $clone->fallbackExplicitlyDisabled = true;
        } else {
            $clone->runtimeFallback = $provider;
            $clone->fallbackExplicitlyDisabled = false;
        }

        return $clone;
    }

    /**
     * Send a chat completion request with automatic fallback and circuit breaker.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     *
     * @throws LLMException
     */
    public function chat(array $messages, array $options = []): LLMResult
    {
        // H4: Validate messages
        $this->validateMessages($messages);

        $primaryName = $this->runtimePrimary ?? $this->config['default']['primary'];
        $fallbackName = $this->resolveFallbackName();
        $mergedOptions = $this->mergeOptions($options);
        // M3: Auto-generate request_id if not provided
        $requestId = $options['request_id'] ?? (string) Str::uuid();
        $fallbackOnList = $this->config['fallback_on'] ?? [];

        // Step: Check cooldown on primary
        if ($this->cooldownManager->isInCooldown($primaryName)) {
            // M2: Also check cooldown for fallback
            if ($fallbackName !== null && !$this->cooldownManager->isInCooldown($fallbackName)) {
                return $this->callProvider($fallbackName, $messages, $mergedOptions, $requestId);
            }
            // No fallback available or fallback also in cooldown, try primary anyway
        }

        // Step: Attempt primary
        $primaryProvider = $this->resolveProvider($primaryName);
        // H3: Get default model from provider config, not provider name
        $primaryDefaultModel = $this->defaultModelForProvider($primaryName);
        $optionsSummary = $this->optionsSummary($mergedOptions);

        $this->events->dispatch(new LLMRequested(
            provider: $primaryName,
            model: $mergedOptions['model'] ?? $primaryDefaultModel,
            options: $optionsSummary,
            requestId: $requestId,
        ));

        $startTime = hrtime(true);

        try {
            $result = $this->retryPolicy->execute(
                fn () => $primaryProvider->chat($messages, $mergedOptions),
            );

            $this->events->dispatch(new LLMSucceeded(
                provider: $result->provider,
                model: $result->model,
                latencyMs: $result->latencyMs,
                options: $optionsSummary,
                usage: $result->usage,
                requestId: $requestId,
            ));

            return $result;
        } catch (LLMException $primaryException) {
            $latencyMs = (hrtime(true) - $startTime) / 1_000_000;

            $this->events->dispatch(new LLMFailed(
                provider: $primaryName,
                model: $mergedOptions['model'] ?? $primaryDefaultModel,
                latencyMs: $latencyMs,
                exceptionClass: $primaryException::class,
                exceptionCode: $primaryException->getCode(),
                exceptionMessage: $primaryException->getMessage(),
                options: $optionsSummary,
                requestId: $requestId,
            ));

            // Check if this failure qualifies for fallback
            if ($fallbackName === null || !$primaryException->shouldFallback()) {
                throw $primaryException;
            }

            $reason = $primaryException->fallbackReason();
            if (!in_array($reason, $fallbackOnList, true)) {
                throw $primaryException;
            }

            // M2: Check cooldown for fallback before attempting
            if ($this->cooldownManager->isInCooldown($fallbackName)) {
                throw $primaryException;
            }

            // Activate cooldown if required (M1: pass Retry-After duration)
            if ($primaryException->shouldCooldown()) {
                $cooldownDuration = null;
                if ($primaryException instanceof RateLimitedException) {
                    $cooldownDuration = $primaryException->retryAfterSeconds;
                }
                $this->cooldownManager->activate($primaryName, $cooldownDuration);
            }

            $this->events->dispatch(new LLMFallbackTriggered(
                primaryProvider: $primaryName,
                fallbackProvider: $fallbackName,
                fallbackReason: $reason,
                exceptionClass: $primaryException::class,
                exceptionCode: $primaryException->getCode(),
                options: $optionsSummary,
                requestId: $requestId,
            ));

            // Attempt fallback
            try {
                return $this->callProvider($fallbackName, $messages, $mergedOptions, $requestId);
            } catch (LLMException $fallbackException) {
                $fallbackLatency = (hrtime(true) - $startTime) / 1_000_000;

                $this->events->dispatch(new LLMFailed(
                    provider: $fallbackName,
                    model: $mergedOptions['model'] ?? $this->defaultModelForProvider($fallbackName),
                    latencyMs: $fallbackLatency,
                    exceptionClass: $fallbackException::class,
                    exceptionCode: $fallbackException->getCode(),
                    exceptionMessage: $fallbackException->getMessage(),
                    options: $optionsSummary,
                    requestId: $requestId,
                ));

                throw $fallbackException;
            }
        }
    }

    /**
     * Send a streaming chat completion request with automatic fallback.
     *
     * Connection-level errors on the primary provider trigger fallback.
     * Mid-stream errors propagate to the caller.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return \Generator<int, string, mixed, void>
     *
     * @throws LLMException
     */
    public function stream(array $messages, array $options = []): \Generator
    {
        $this->validateMessages($messages);

        $primaryName = $this->runtimePrimary ?? $this->config['default']['primary'];
        $fallbackName = $this->resolveFallbackName();
        $mergedOptions = $this->mergeOptions($options);
        $fallbackOnList = $this->config['fallback_on'] ?? [];

        $generator = null;

        // Check cooldown on primary
        if ($this->cooldownManager->isInCooldown($primaryName)) {
            if ($fallbackName !== null && !$this->cooldownManager->isInCooldown($fallbackName)) {
                yield from $this->resolveProvider($fallbackName)->chatStream($messages, $mergedOptions);
                return;
            }
        }

        // Attempt primary (chatStream makes HTTP request eagerly)
        try {
            $generator = $this->resolveProvider($primaryName)->chatStream($messages, $mergedOptions);
        } catch (LLMException $e) {
            if ($fallbackName === null || !$e->shouldFallback()) {
                throw $e;
            }

            $reason = $e->fallbackReason();
            if (!in_array($reason, $fallbackOnList, true)) {
                throw $e;
            }

            if ($this->cooldownManager->isInCooldown($fallbackName)) {
                throw $e;
            }

            if ($e->shouldCooldown()) {
                $cooldownDuration = null;
                if ($e instanceof RateLimitedException) {
                    $cooldownDuration = $e->retryAfterSeconds;
                }
                $this->cooldownManager->activate($primaryName, $cooldownDuration);
            }

            // generator stays null, falls through to fallback
        }

        if ($generator !== null) {
            yield from $generator;
            return;
        }

        // Fallback
        if ($fallbackName !== null) {
            yield from $this->resolveProvider($fallbackName)->chatStream($messages, $mergedOptions);
            return;
        }

        // No fallback, try primary despite cooldown
        yield from $this->resolveProvider($primaryName)->chatStream($messages, $mergedOptions);
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     */
    private function callProvider(string $name, array $messages, array $options, ?string $requestId): LLMResult
    {
        $provider = $this->resolveProvider($name);
        $optionsSummary = $this->optionsSummary($options);

        $this->events->dispatch(new LLMRequested(
            provider: $name,
            model: $options['model'] ?? $this->defaultModelForProvider($name),
            options: $optionsSummary,
            requestId: $requestId,
        ));

        $result = $this->retryPolicy->execute(
            fn () => $provider->chat($messages, $options),
        );

        $this->events->dispatch(new LLMSucceeded(
            provider: $result->provider,
            model: $result->model,
            latencyMs: $result->latencyMs,
            options: $optionsSummary,
            usage: $result->usage,
            requestId: $requestId,
        ));

        return $result;
    }

    private function resolveProvider(string $name): LLMProvider
    {
        $providerConfig = $this->config['providers'][$name] ?? [];

        return $this->registry->resolve($name, $providerConfig);
    }

    private function resolveFallbackName(): ?string
    {
        if ($this->fallbackExplicitlyDisabled) {
            return null;
        }

        if ($this->runtimeFallback !== null) {
            return $this->runtimeFallback;
        }

        return $this->config['default']['fallback'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function mergeOptions(array $options): array
    {
        $defaults = $this->config['defaults'] ?? [];

        return array_merge([
            'temperature' => $defaults['temperature'] ?? 0.7,
            'max_output_tokens' => $defaults['max_output_tokens'] ?? 1024,
        ], $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function optionsSummary(array $options): array
    {
        return [
            'temperature' => $options['temperature'] ?? null,
            'max_output_tokens' => $options['max_output_tokens'] ?? null,
        ];
    }

    /**
     * H3: Get the default model name from provider config.
     */
    private function defaultModelForProvider(string $providerName): string
    {
        return $this->config['providers'][$providerName]['model'] ?? $providerName;
    }

    /**
     * H4: Validate the messages array structure.
     *
     * @param  array<int, mixed>  $messages
     *
     * @throws BadRequestException
     */
    private function validateMessages(array $messages): void
    {
        if (empty($messages)) {
            throw new BadRequestException('Messages array must not be empty.');
        }

        foreach ($messages as $index => $message) {
            if (
                !is_array($message)
                || !isset($message['role'])
                || !isset($message['content'])
                || !is_string($message['role'])
                || !is_string($message['content'])
            ) {
                throw new BadRequestException(
                    "Invalid message at index {$index}: each message must have 'role' and 'content' string keys.",
                );
            }
        }
    }
}
