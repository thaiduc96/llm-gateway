<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Thaiduc96\LlmGateway\Contracts\LLMProvider;
use Thaiduc96\LlmGateway\DTOs\LLMResult;
use Thaiduc96\LlmGateway\Exceptions\ProviderException;
use Thaiduc96\LlmGateway\Infrastructure\DefaultProviderRegistry;

final class DefaultProviderRegistryTest extends TestCase
{
    private function dummyProvider(string $name): LLMProvider
    {
        return new class ($name) implements LLMProvider {
            public function __construct(private readonly string $n) {}

            public function name(): string
            {
                return $this->n;
            }

            public function chat(array $messages, array $options = []): LLMResult
            {
                return new LLMResult(content: '', provider: $this->n, model: 'test', latencyMs: 0);
            }

            public function chatStream(array $messages, array $options = []): \Generator
            {
                yield '';
            }
        };
    }

    public function test_register_and_resolve(): void
    {
        $registry = new DefaultProviderRegistry();
        $provider = $this->dummyProvider('openai');

        $registry->register('openai', fn () => $provider);

        $resolved = $registry->resolve('openai', ['driver' => 'openai']);
        $this->assertSame($provider, $resolved);
    }

    public function test_has_driver(): void
    {
        $registry = new DefaultProviderRegistry();
        $registry->register('openai', fn () => $this->dummyProvider('openai'));

        $this->assertTrue($registry->hasDriver('openai'));
        $this->assertFalse($registry->hasDriver('gemini'));
    }

    public function test_resolve_throws_on_missing_driver(): void
    {
        $registry = new DefaultProviderRegistry();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No factory registered');
        $registry->resolve('openai', ['driver' => 'openai']);
    }

    public function test_resolve_caches_instance(): void
    {
        $callCount = 0;
        $registry = new DefaultProviderRegistry();
        $registry->register('openai', function () use (&$callCount) {
            $callCount++;

            return $this->dummyProvider('openai');
        });

        $first = $registry->resolve('openai', ['driver' => 'openai']);
        $second = $registry->resolve('openai', ['driver' => 'openai']);

        $this->assertSame($first, $second);
        $this->assertSame(1, $callCount);
    }

    /**
     * M6: Re-registering a driver clears ALL resolved instances.
     *
     * This ensures that providers using the re-registered driver get
     * fresh instances from the new factory on next resolve().
     */
    public function test_m6_register_clears_all_resolved_instances(): void
    {
        $registry = new DefaultProviderRegistry();

        // Register and resolve two providers
        $registry->register('openai', fn () => $this->dummyProvider('openai-v1'));
        $registry->register('gemini', fn () => $this->dummyProvider('gemini-v1'));

        $openaiV1 = $registry->resolve('openai', ['driver' => 'openai']);
        $geminiV1 = $registry->resolve('gemini', ['driver' => 'gemini']);

        $this->assertSame('openai-v1', $openaiV1->name());
        $this->assertSame('gemini-v1', $geminiV1->name());

        // Re-register the openai driver — should clear ALL resolved
        $registry->register('openai', fn () => $this->dummyProvider('openai-v2'));

        // OpenAI should now resolve to the new factory
        $openaiV2 = $registry->resolve('openai', ['driver' => 'openai']);
        $this->assertSame('openai-v2', $openaiV2->name());
        $this->assertNotSame($openaiV1, $openaiV2);

        // Gemini should also be re-resolved (cleared) from fresh factory
        $geminiResolved = $registry->resolve('gemini', ['driver' => 'gemini']);
        $this->assertNotSame($geminiV1, $geminiResolved);
    }

    public function test_different_name_same_driver(): void
    {
        $registry = new DefaultProviderRegistry();
        $registry->register('openai', fn () => $this->dummyProvider('openai'));

        // Provider 'custom-openai' uses driver 'openai'
        $resolved = $registry->resolve('custom-openai', ['driver' => 'openai']);
        $this->assertSame('openai', $resolved->name());
    }
}
