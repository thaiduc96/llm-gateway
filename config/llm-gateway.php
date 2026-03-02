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
    | retry_max_backoff_ms: Maximum backoff cap for exponential backoff (bounded to 5000).
    | retry_on_overloaded: Whether to retry on 503 OverloadedException.
    |
    | Backoff formula: min(max_backoff, base_backoff * 2^attempt) + jitter
    | where jitter = random(0, base_backoff / 2).
    |
    */

    'retry_attempts' => env('LLM_RETRY_ATTEMPTS', 1),
    'retry_backoff_ms' => env('LLM_RETRY_BACKOFF_MS', 200),
    'retry_max_backoff_ms' => env('LLM_RETRY_MAX_BACKOFF_MS', 5000),
    'retry_on_overloaded' => env('LLM_RETRY_ON_OVERLOADED', false),

    /*
    |--------------------------------------------------------------------------
    | Response Cache
    |--------------------------------------------------------------------------
    |
    | When cache_ttl_seconds > 0, successful responses are cached using
    | Laravel's cache. The cache key is a SHA-256 hash of the provider,
    | model, messages, and options. Set to 0 (default) to disable caching.
    |
    */

    'cache_ttl_seconds' => env('LLM_CACHE_TTL_SECONDS', 0),

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
    | SECURITY NOTE: The Gemini API key is passed as a URL query parameter
    | (?key=...) per Google's API design. This means the key may appear in
    | HTTP access logs, proxy logs, or browser history. The gateway sanitizes
    | keys from exception messages, but ensure your infrastructure does not
    | log full request URLs. Prefer using environment variables and never
    | commit API keys to version control.
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
