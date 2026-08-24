<?php

declare(strict_types=1);

use Forte\Sheath\BladeCompiler\Tests\TestCase;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\SheathManager;

uses(TestCase::class)->in(__DIR__);

/**
 * @param  array<string, mixed>  $options
 * @return list<Violation>
 */
function lintCompiledBlade(string $source, array $options = []): array
{
    $config = Config::make()->setRule(
        'blade-compiler-valid-output',
        $options === [] ? 'error' : ['error', $options],
    );

    return array_values(app(SheathManager::class)->lint(
        $source,
        'resources/views/probe.blade.php',
        $config,
    )->violations);
}

/** @return array{resource, resource, resource} */
function validatedProcessPipes(mixed $pipes): array
{
    if (! is_array($pipes)) {
        throw new UnexpectedValueException('The child process did not provide its standard streams.');
    }

    return [
        requiredProcessPipe($pipes[0] ?? null),
        requiredProcessPipe($pipes[1] ?? null),
        requiredProcessPipe($pipes[2] ?? null),
    ];
}

/** @return resource */
function requiredProcessPipe(mixed $pipe)
{
    if (! is_resource($pipe)) {
        throw new UnexpectedValueException('The child process did not provide its standard streams.');
    }

    return $pipe;
}

function requiredSourceOffset(string $source, string $needle): int
{
    $offset = strpos($source, $needle);
    if (! is_int($offset)) {
        throw new UnexpectedValueException("Expected source to contain [{$needle}].");
    }

    return $offset;
}
