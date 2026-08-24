<?php

declare(strict_types=1);

use Forte\Sheath\BladeCompiler\Tests\Datasets\CoreBladeCases;

it('reports invalid core Blade syntax', function (string $source, string $detail): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain($detail);
})->with(CoreBladeCases::invalid());

it('reports the compiler error without an explanatory wrapper', function (): void {
    $violations = lintCompiledBlade('@foreach($items as) value @endforeach');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Malformed @foreach statement.');
});

it('does not compile syntax protected by Blade comments, verbatim blocks, or escaped directives', function (string $source): void {
    expect(lintCompiledBlade($source))->toBe([]);
})->with([
    'Blade comment' => '{{-- @if($value +) {{ invalid }} @endif --}}',
    'verbatim block' => '@verbatim @if($value +) {{ invalid }} @endverbatim',
    'escaped directives' => '@@if($value +) @@endif',
]);
