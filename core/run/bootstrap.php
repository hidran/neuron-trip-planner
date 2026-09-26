<?php

declare(strict_types=1);

use NeuronAI\Providers\AIProviderInterface;
use NeuronAI\Providers\Anthropic\Anthropic;
use NeuronAI\Providers\Gemini\Gemini;
use NeuronAI\Providers\Mistral\Mistral;
use NeuronAI\Providers\Ollama\Ollama;
use NeuronAI\Providers\OpenAI\OpenAI;

/*
 * Shared setup for the command-line runner: Composer, .env, and the model.
 *
 * The library itself never chooses a provider - TripServices is handed one.
 * This file is where the CLI makes that choice; the Laravel app makes it in
 * its service provider, the tests with a FakeAIProvider.
 */

require_once __DIR__ . '/../vendor/autoload.php';

if (\is_file(__DIR__ . '/../.env')) {
    Dotenv\Dotenv::createImmutable(\dirname(__DIR__))->safeLoad();
}

function env_value(string $key, string $default = ''): string
{
    $value = $_ENV[$key] ?? \getenv($key);

    return \is_string($value) && $value !== '' ? $value : $default;
}

function provider_from_env(): AIProviderInterface
{
    return match (env_value('NEURON_PROVIDER', 'openai')) {
        'anthropic' => new Anthropic(key: env_value('ANTHROPIC_API_KEY'), model: env_value('ANTHROPIC_MODEL', 'claude-sonnet-4-5')),
        'gemini' => new Gemini(key: env_value('GEMINI_API_KEY'), model: env_value('GEMINI_MODEL', 'gemini-2.5-flash')),
        'mistral' => new Mistral(key: env_value('MISTRAL_API_KEY'), model: env_value('MISTRAL_MODEL', 'mistral-large-latest')),
        'ollama' => new Ollama(url: env_value('OLLAMA_URL', 'http://localhost:11434/api'), model: env_value('OLLAMA_MODEL', 'llama3.2')),
        default => new OpenAI(key: env_value('OPENAI_API_KEY'), model: env_value('OPENAI_MODEL', 'gpt-5.4-mini')),
    };
}
