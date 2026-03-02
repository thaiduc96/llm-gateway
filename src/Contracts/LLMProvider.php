<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Contracts;

use Thaiduc96\LlmGateway\DTOs\LLMResult;

interface LLMProvider
{
    /**
     * Send a chat completion request to the provider.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options  Merged options (temperature, max_output_tokens, etc.)
     *
     * @throws \Thaiduc96\LlmGateway\Exceptions\LLMException
     */
    public function chat(array $messages, array $options = []): LLMResult;

    /**
     * Send a streaming chat completion request to the provider.
     *
     * The HTTP request is made eagerly when this method is called.
     * The returned Generator yields content chunks as strings when iterated.
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $options
     * @return \Generator<int, string, mixed, void>
     *
     * @throws \Thaiduc96\LlmGateway\Exceptions\LLMException
     */
    public function chatStream(array $messages, array $options = []): \Generator;

    /**
     * Get the provider name identifier.
     */
    public function name(): string;
}
