<?php

declare(strict_types=1);

/*
 * Shared setup for the examples. Keys are read from the environment, with an optional local
 * .env in the package root loaded on top. With TYPESAFE_API_KEY set the examples call the real
 * API; otherwise Decision::fake() answers with plausible random distributions so every example
 * runs offline.
 */

require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use Swis\DecisionEngine\Client;
use Swis\DecisionEngine\Config;
use Swis\DecisionEngine\Decision;
use Swis\DecisionEngine\Questions\Choice;
use Swis\DecisionEngine\Questions\QuestionType;
use Swis\DecisionEngine\Questions\Score;
use Swis\DecisionEngine\Request\DecisionRequest;
use Swis\DecisionEngine\Testing\Fake;

function bootstrap(): bool
{
    loadEnv();

    $live = envValue('TYPESAFE_API_KEY') !== '';

    if ($live) {
        Decision::resolveClientUsing(fn(): Client => Client::fromConfig(Config::fromEnv()));

        return true;
    }

    mt_srand(42);

    Decision::fake(function (DecisionRequest $request): array {
        $answers = [];

        foreach ($request->questions as $id => $question) {
            $answers[$id] = match ($question->type()) {
                QuestionType::Choice => Fake::choice(
                    $question instanceof Choice ? $question->optionNames()[array_rand($question->optionNames())] : 'unknown',
                    confidence: mt_rand(30, 99) / 100,
                ),
                QuestionType::Score => Fake::score(mt_rand(0, ($question instanceof Score ? $question->count() : 2) * 100 - 100) / 100),
                QuestionType::Noul => Fake::noul(mt_rand(0, 100) / 100),
            };
        }

        return $answers;
    });

    return false;
}

function envValue(string $key): string
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

    return is_string($value) ? trim($value) : '';
}

/**
 * Load an optional local .env from the package root.
 * Unsafe = also populates getenv(); immutable = never overrides real environment variables.
 */
function loadEnv(): void
{
    if (! class_exists(Dotenv::class)) {
        return;
    }

    Dotenv::createUnsafeImmutable(dirname(__DIR__))->safeLoad();
}

function banner(string $title, bool $live): void
{
    printf("== %s (%s) ==\n", $title, $live ? 'live TypeSafe API' : 'offline fake, random answers');
}
