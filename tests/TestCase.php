<?php

declare(strict_types=1);

namespace Thaiduc96\LlmGateway\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Thaiduc96\LlmGateway\LLMGatewayServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            LLMGatewayServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'LLM' => \Thaiduc96\LlmGateway\Facades\LLM::class,
        ];
    }
}
