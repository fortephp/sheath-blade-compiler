<?php

declare(strict_types=1);

use Forte\Sheath\BladeCompiler\Compiler\IsolatedBladeCompiler;
use Forte\Sheath\BladeCompiler\Tests\Datasets\CoreBladeCases;
use Illuminate\View\Compilers\BladeCompiler;

it('accepts valid core Blade syntax', function (string $source, array $directives, array $generatedDirectives = []): void {
    expect(lintCompiledBlade($source))->toBe([]);

    $compiled = app(IsolatedBladeCompiler::class)->compile($source, 'resources/views/coverage.blade.php');
    foreach ($directives as $directive) {
        if (! is_string($directive)) {
            throw new UnexpectedValueException('Core directive names must be strings.');
        }

        $pattern = '/(?<!@)@'.preg_quote($directive, '/').'\b/i';

        if (! in_array($directive, $generatedDirectives, true)) {
            expect(preg_match($pattern, $source))->toBe(1, "The case does not author @{$directive}.");
        }

        expect(preg_match($pattern, $compiled))->toBe(0, "Laravel did not compile @{$directive}.");
    }
})->with(CoreBladeCases::valid());

it("keeps its cases aligned with Laravel's core directives", function (): void {
    $excludedCompilerPasses = [
        'classcomponentopening',
        'comments',
        'echos',
        'escapedechos',
        'rawechos',
        'regularechos',
    ];
    $expected = [];
    $reflection = new ReflectionClass(BladeCompiler::class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if (! str_starts_with($method->getName(), 'compile')) {
            continue;
        }

        $file = $method->getFileName();
        if (! is_string($file) || ! str_contains(str_replace('\\', '/', $file), '/Compilers/Concerns/')) {
            continue;
        }

        $directive = strtolower(substr($method->getName(), strlen('compile')));
        if (! in_array($directive, $excludedCompilerPasses, true)) {
            $expected[] = $directive;
        }
    }

    $expected = array_values(array_unique($expected));
    sort($expected);

    expect(CoreBladeCases::coveredDirectives())->toBe($expected);
});
