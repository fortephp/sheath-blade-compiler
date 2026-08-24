<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Results\Position;
use Forte\Sheath\SheathManager;

afterEach(function (): void {
    PackagePresets::reset();
});

it('replaces the core PHP syntax rule in the package preset', function (): void {
    $manager = app(SheathManager::class);
    $config = DefaultConfigFactory::resolve(
        ['preset' => ['empty', 'blade-compiler']],
        $manager->getRuleRegistry(),
    );

    expect($config->getRules())->toBe([
        'blade-valid-php-syntax' => 'off',
        'blade-compiler-valid-output' => 'error',
    ]);
});

it('supersedes the core PHP syntax rule when combined with recommended', function (): void {
    $manager = app(SheathManager::class);
    $config = DefaultConfigFactory::resolve(
        ['preset' => ['recommended', 'blade-compiler']],
        $manager->getRuleRegistry(),
    );
    $source = "@php\n\n    3d 3+aklsdfsadf;\n\n@endphp";
    $violations = array_values($manager->lint(
        $source,
        'resources/views/probe.blade.php',
        $config,
    )->violations);

    expect($config->getRules())
        ->toHaveKey('blade-valid-php-syntax', 'off')
        ->toHaveKey('blade-compiler-valid-output', 'error')
        ->and($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('blade-compiler-valid-output')
        ->and($violations[0]->start)->toEqual(
            new Position(
                requiredSourceOffset($source, '3d 3+aklsdfsadf;'),
                3,
                5,
            ),
        );
});
