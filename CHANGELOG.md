# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-03-02

### Added

- Provider-agnostic LLM gateway manager with automatic fallback
- Built-in OpenAI and Gemini providers
- Circuit breaker with configurable cooldown via Laravel Cache
- Retry policy with bounded attempts and backoff
- Typed exception hierarchy (RateLimited, Overloaded, Timeout, Auth, BadRequest, Provider)
- Laravel events for full observability (LLMRequested, LLMSucceeded, LLMFailed, LLMFallbackTriggered)
- Runtime provider override (usingPrimary / usingFallback)
- Extensible provider registry for custom drivers
- LLM facade for convenient access
- Publishable configuration file

[Unreleased]: https://github.com/thaiduc96/llm-gateway/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/thaiduc96/llm-gateway/releases/tag/v0.1.0
