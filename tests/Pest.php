<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Swis\DecisionEngine\Tests\TestCase;

// Load an optional local .env so live-provider tests can pick up API keys.
// Unsafe = also populates getenv(); immutable = never overrides phpunit.xml or real env vars.
Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();

pest()->extend(TestCase::class)->in('Unit', 'Feature');
pest()->extend(\Swis\DecisionEngine\Tests\Laravel\TestCase::class)->in('Laravel');
