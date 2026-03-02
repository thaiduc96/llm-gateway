<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider Configuration
    |--------------------------------------------------------------------------
    |
    | The primary provider is used for all LLM requests. The fallback provider
    | is used when the primary fails with a recoverable error.
    |
    */

    'default' => [
        'primary' => env('LLM_PRIMARY_PROVIDER', 'openai'),
        'fallback' => env('LLM_FALLBACK_PROVIDER', 'gemini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fallback Conditions
    |--------------------------------------------------------------------------
    |
    | List of failure conditions that trigger a fallback to the secondary
    | provider. Only these conditions will cause automatic failover.
    |
    */

    'fallback_on' => [
        'rate_limit',
        'timeout',
        'overloaded',
        'server_error',
        'malformed_response',
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker / Cooldown
    |--------------------------------------------------------------------------
    |
    | When a provider triggers a cooldown condition (rate limit, overloaded),
    | it will be skipped for the configured number of seconds.
    | Cache key format: llm_gateway:cooldown:{provider}
    |
    */

    'cooldown_seconds' => env('LLM_COOLDOWN_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Timeout Configuration
    |--------------------------------------------------------------------------
    */

    'timeout_seconds' => env('LLM_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => env('LLM_CONNECT_TIMEOUT_SECONDS', 5),

    /*
    |--------------------------------------------------------------------------
    | Retry Policy
    |--------------------------------------------------------------------------
    |
    | retry_attempts: Number of retries (0–2 max). 0 means no retries.
    | retry_backoff_ms: Base backoff between retries in milliseconds (bounded).
    | retry_on_overloaded: Whether to retry on 503 OverloadedException.
    |
    */

    'retry_attempts' => env('LLM_RETRY_ATTEMPTS', 1),
    'retry_backoff_ms' => env('LLM_RETRY_BACKOFF_MS', 200),
    'retry_on_overloaded' => env('LLM_RETRY_ON_OVERLOADED', false),

    /*
    |--------------------------------------------------------------------------
    | Default Request Options
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'temperature' => env('LLM_DEFAULT_TEMPERATURE', 0.7),
        'max_output_tokens' => env('LLM_DEFAULT_MAX_OUTPUT_TOKENS', 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configurations
    |--------------------------------------------------------------------------
    |
    | Each provider must have: driver, api_key, model, base_url.
    | New providers can be added here and registered via ProviderRegistry.
    |
    */

    'providers' => [

        'openai' => [
            'driver' => 'openai',
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o'),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'api_key' => env('GEMINI_API_KEY'),
            'model' => env('GEMINI_MODEL', 'gemini-2.0-flash'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

    ],

];
