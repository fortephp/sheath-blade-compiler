<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\BladeCompiler\Analysis\SourceMarkerMap;
use Forte\Sheath\BladeCompiler\Compiler\IsolatedBladeCompiler;
use Forte\Sheath\Results\Position;
use Illuminate\View\Compilers\BladeCompiler;

it('explains an early escaped or raw echo delimiter at the exact echo', function (string $source, string $message): void {
    $violations = lintCompiledBlade("<p>before</p>\n  {$source}\n<p>after</p>");

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('blade-compiler-valid-output')
        ->and($violations[0]->message)->toBe($message)
        ->and($violations[0]->message)->not->toContain('Forte')
        ->and($violations[0]->start)->toEqual(new Position(16, 2, 3))
        ->and($violations[0]->end->offset)->toBe(16 + strlen($source));
})->with([
    'escaped echo' => [
        "{{ '}}' }}",
        'Echo ends at the first `}}`, before the expression is complete.',
    ],
    'raw echo' => [
        "{!! '!!}' !!}",
        'Echo ends at the first `!!}`, before the expression is complete.',
    ],
]);

it('reports other malformed echoes without guessing at their cause', function (): void {
    $violations = lintCompiledBlade('{{ $value + }}');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('syntax error, ')
        ->and($violations[0]->message)->not->toContain('delimiter');
});

it('maps multiline PHP syntax errors to the exact authored line', function (
    string $phpValidation,
    string $source,
): void {
    $violations = lintCompiledBlade($source, ['phpValidation' => $phpValidation]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('syntax error')
        ->and($violations[0]->start)->toEqual(new Position(
            requiredSourceOffset($source, '$broken = ;'),
            4,
            5,
        ))
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('$broken = ;');
})->with([
    'process with a Blade PHP block' => [
        'process',
        "<h1>Before</h1>\n@php\n    \$valid = 1;\n    \$broken = ;\n    \$after = 2;\n@endphp\n<footer>After</footer>",
    ],
    'parser with a Blade PHP block' => [
        'parser',
        "<h1>Before</h1>\n@php\n    \$valid = 1;\n    \$broken = ;\n    \$after = 2;\n@endphp\n<footer>After</footer>",
    ],
    'process with a native PHP tag' => [
        'process',
        str_replace(
            "\n",
            "\r\n",
            "<h1>Before</h1>\n<?php\n    \$valid = 1;\n    \$broken = ;\n    \$after = 2;\n?>\n<footer>After</footer>",
        ),
    ],
    'parser with a native PHP tag' => [
        'parser',
        "<h1>Before</h1>\n<?php\n    \$valid = 1;\n    \$broken = ;\n    \$after = 2;\n?>\n<footer>After</footer>",
    ],
]);

it('maps multiline PHP compile-time errors to the exact authored line', function (string $source): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain("'break' not in the 'loop' or 'switch' context")
        ->and($violations[0]->start)->toEqual(new Position(
            requiredSourceOffset($source, 'break;'),
            4,
            5,
        ))
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('break;');
})->with([
    'Blade PHP block' => [
        "<h1>Before</h1>\n@php\n    \$valid = 1;\n    break;\n@endphp\n<footer>After</footer>",
    ],
    'native PHP tag' => [
        "<h1>Before</h1>\n<?php\n    \$valid = 1;\n    break;\n?>\n<footer>After</footer>",
    ],
]);

it('reports raw PHP syntax that an earlier compiled-template error would mask', function (): void {
    $source = str_replace(
        "\n",
        "\r\n",
        "@if(\$ready +)\n    Before\n@endif\n@php\n\n    3d 3+aklsdfsadf;\n\n@endphp",
    );
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(2)
        ->and($violations[0]->ruleId)->toBe('blade-compiler-valid-output')
        ->and($violations[0]->message)->toContain('syntax error')
        ->and($violations[0]->start->line)->toBe(1)
        ->and($violations[1]->ruleId)->toBe('blade-compiler-valid-output')
        ->and($violations[1]->message)->toContain('unexpected identifier "d"')
        ->and($violations[1]->start)->toEqual(new Position(
            requiredSourceOffset($source, '3d 3+aklsdfsadf;'),
            6,
            5,
        ))
        ->and(substr(
            $source,
            $violations[1]->start->offset,
            $violations[1]->end->offset - $violations[1]->start->offset,
        ))->toBe('3d 3+aklsdfsadf;');
});

it('accepts raw PHP control flow completed by a Blade directive', function (): void {
    $violations = lintCompiledBlade(
        "@php if (\$ready): @endphp\n<p>Ready</p>\n@endif",
    );

    expect($violations)->toBe([]);
});

it('falls back to the PHP region when a compiler hook changes its line mapping', function (): void {
    app(BladeCompiler::class)->prepareStringsForCompilationUsing(
        static fn (string $source): string => str_replace(
            '$applicationHook;',
            "\n    \$broken = ;",
            $source,
        ),
    );
    $php = "@php\n    \$valid = 1;\n    \$applicationHook;\n@endphp";
    $source = "<h1>Before</h1>\n{$php}\n<footer>After</footer>";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('syntax error')
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($php);
});

it('reports invalid core directive output at the responsible construct', function (string $source, string $message, int $offset): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain($message)
        ->and($violations[0]->start->offset)->toBe($offset);
})->with([
    'invalid condition' => ['@if($value +) yes @endif', 'syntax error', 0],
    'malformed foreach' => ['@foreach($items as) value @endforeach', 'Malformed @foreach statement', 0],
    'invalid json' => ["@json(['value' => ])", 'syntax error', 0],
    'invalid class expression' => ["@class(['enabled' => ])", 'syntax error', 0],
    'word-adjacent closing condition' => ['@unless($hidden)shown@endunless', 'syntax error', 0],
    'word-adjacent closing loop' => ['@while($ready)@break@endwhile', 'syntax error', 14],
]);

it('maps invalid compiled output after many Blade nodes to the offending directive', function (): void {
    $prefix = str_repeat("{{ \$value }}\n", 50);
    $invalid = '@if($ready) yes @else($other) maybe @else no @endif';
    $source = $prefix.$invalid;
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('unexpected token "else"')
        ->and($violations[0]->start->offset)->toBe(strlen($prefix) + strlen('@if($ready) yes @else($other) maybe '))
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('@else');
});

it('maps a Blade compilation exception to the failing directive occurrence', function (): void {
    $source = <<<'BLADE'
        @foreach($valid as $item){{ $item }}@endforeach
        @foreach($invalid as){{ $item }}@endforeach
        BLADE;
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Malformed @foreach statement.')
        ->and($violations[0]->start->offset)->toBe(strrpos($source, '@foreach'));
});

it('maps an unterminated directive block to its opening directive', function (): void {
    $source = "@if(true)\n<p>body</p>\n{{ \$innocent }}";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('unexpected end of file')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('@if(true)');
});

it('does not accept a longer Laravel directive name as a block closer', function (
    string $source,
    string $opening,
): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('unexpected end of file')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($opening);
})->with([
    'conditional suffix' => ["@if(\$ok)\n@endifoo\n{{ \$innocent }}", '@if($ok)'],
    'loop suffix' => ["@foreach(\$items as \$item)\n@endforeachSuffix\n{{ \$innocent }}", '@foreach($items as $item)'],
    'static-style suffix' => ["@if(\$ok)\n@endif::suffix\n{{ \$innocent }}", '@if($ok)'],
]);

it('ignores apparent closing directives inside uncompiled regions', function (string $protected): void {
    $source = "@if(true)\n{$protected}\n{{ \$innocent }}";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('unexpected end of file')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('@if(true)');
})->with([
    'Blade comment' => '{{-- @endif --}}',
    'verbatim block' => '@verbatim @endif @endverbatim',
    'PHP block' => "@php \$value = '@endif'; @endphp",
    'native PHP tag' => "<?php \$value = '@endif'; ?>",
]);

it('maps footer-compiled layout expressions to their authored directive', function (string $directive): void {
    $invalid = "@{$directive}(\$layout +)";
    $source = $invalid."\n<p>{{ \$innocent }}</p>";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('syntax error')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($invalid);
})->with(['extends', 'extendsFirst']);

it('preserves source attribution when Laravel reverses multiple layout footers', function (
    string $source,
    string $invalid,
): void {
    $violations = lintCompiledBlade($source."\n<p>{{ \$innocent }}</p>");

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain('syntax error')
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($invalid);
})->with([
    'first footer invalid' => [
        "@extends(\$first +)\n@extends(\$second)",
        '@extends($first +)',
    ],
    'second footer invalid' => [
        "@extends(\$first)\n@extends(\$second +)",
        '@extends($second +)',
    ],
]);

it('keeps marker compilation linear for large echo-heavy templates', function (): void {
    $source = str_repeat("{{ \$value }}\n", 64_000);
    $markers = SourceMarkerMap::forDocument(Document::parse($source));
    $instrumented = $markers->instrumentedSource();

    expect($instrumented)->not->toContain('@'.$markers->directiveName())
        ->and(substr_count($instrumented, '<!--__SHEATH_BC_'))->toBe(64_000);

    $started = hrtime(true);
    app(IsolatedBladeCompiler::class)->compile($instrumented, 'resources/views/large.blade.php');
    $elapsedSeconds = (hrtime(true) - $started) / 1_000_000_000;

    expect($elapsedSeconds)->toBeLessThan(10.0);
});

it('does not map a repeated unexpected token to later innocent Blade syntax', function (
    string $source,
    string $expected,
): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($expected);
})->with([
    'later echo semicolon' => ['@php $a = ; @endphp {{ $later }}', '@php $a = ; @endphp'],
    'later stray endif tokens' => ['@if(true) @endif @endif @endif', '@endif'],
]);
