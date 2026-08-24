<?php

declare(strict_types=1);

use Forte\Parser\Directives\Directives;
use Forte\Sheath\BladeCompiler\Compiler\CompilerFingerprint;
use Forte\Sheath\BladeCompiler\Compiler\IsolatedBladeCompiler;
use Forte\Sheath\BladeCompiler\Validation\PhpValidator;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\DynamicComponent;

it('validates application directive output and maps failures to the custom directive', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->directive('applicationHook', static fn (): string => '<?php if (; ?>');
    app(Directives::class)->registerDirective('applicationHook');
    $source = "<p>before</p>\n@applicationHook()\n<p>after</p>";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('syntax error, ')
        ->and($violations[0]->start->offset)->toBe(strlen("<p>before</p>\n"))
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('@applicationHook()');
});

it('maps application compiler exceptions to the failing directive occurrence', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->directive('applicationPosition', static function (string $expression): string {
        if (str_contains($expression, 'invalid')) {
            throw new RuntimeException('Invalid @applicationPosition expression');
        }

        return '';
    });
    app(Directives::class)->registerDirective('applicationPosition');
    $source = "@applicationPosition('valid')\n@applicationPosition('invalid')";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Invalid @applicationPosition expression.')
        ->and($violations[0]->start->offset)->toBe(strrpos($source, '@applicationPosition'));
});

it('uses file-level mapping when an early compiler callback makes a repeated directive ambiguous', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->prepareStringsForCompilationUsing(static function (string $source): string {
        if (str_contains($source, "@preparedProbe('invalid')")) {
            throw new RuntimeException('Invalid @preparedProbe expression');
        }

        return $source;
    });
    app(Directives::class)->registerDirective('preparedProbe');
    $source = "@preparedProbe('valid')\n@preparedProbe('invalid')";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toBe('Invalid @preparedProbe expression.')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and($violations[0]->end->offset)->toBe(1);
});

it('validates compiler hook transformations and maps failures to their authored syntax', function (string $kind): void {
    $compiler = app(BladeCompiler::class);
    $directive = $kind.'Syntax';
    $callback = static fn (string $source): string => str_replace(
        '@'.$directive,
        '@if($value +)',
        $source,
    );
    match ($kind) {
        'extension' => $compiler->extend($callback),
        'preparation' => $compiler->prepareStringsForCompilationUsing($callback),
        'precompiler' => $compiler->precompiler($callback),
        default => throw new UnexpectedValueException("Unsupported compiler hook [{$kind}]."),
    };
    app(Directives::class)->registerDirective($directive);
    $source = "<p>before</p>\n@{$directive}\n<p>after</p>";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('syntax error, ')
        ->and($violations[0]->start->offset)->toBe(strlen("<p>before</p>\n"))
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe('@'.$directive);
})->with(['extension', 'preparation', 'precompiler']);

it('uses file-level attribution when a compiler hook removes every source marker', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->precompiler(static function (string $source): string {
        $withoutMarkers = preg_replace('/\n?<!--__SHEATH_BC_[^>]+-->\n?/', '', $source) ?? $source;

        return str_replace('@markerStripped', '<?php if (; ?>', $withoutMarkers);
    });
    app(Directives::class)->registerDirective('markerStripped');
    $source = "{{ \$innocent }}\n@markerStripped";

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('syntax error, ')
        ->and($violations[0]->start->offset)->toBe(0)
        ->and($violations[0]->end->offset)->toBe(1);
});

it('maps application component compilation failures to the responsible component', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->component(DynamicComponent::class, 'known-probe');
    $source = '<x-known-probe component="button" /> <x-sheath-definitely-missing />';

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('Unable to locate a class or view')
        ->and($violations[0]->start->offset)->toBe(strpos($source, '<x-sheath-definitely-missing'));
});

it('does not mutate the application compiler or execute compiled template PHP', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->setPath('original-view.blade.php');

    expect(lintCompiledBlade('@php throw new RuntimeException("template PHP executed"); @endphp'))->toBe([])
        ->and($compiler->getPath())->toBe('original-view.blade.php');
});

it('restores Laravel component compilation state after every failed isolated compilation', function (): void {
    $compiler = app(BladeCompiler::class);
    $compiler->component(DynamicComponent::class, 'known-probe');
    $componentHashStack = (new ReflectionObject($compiler))->getProperty('componentHashStack');
    $originalStack = $componentHashStack->getValue();
    $sentinelStack = ['application-compilation-in-progress'];
    $componentHashStack->setValue(null, $sentinelStack);

    try {
        $violations = lintCompiledBlade(
            '<x-known-probe component="button">@foreach($items as)</x-known-probe>',
        );

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->message)->toContain('Malformed @foreach statement')
            ->and($componentHashStack->getValue())->toBe($sentinelStack);
    } finally {
        $componentHashStack->setValue(null, $originalStack);
    }
});

it('rebinds every compiler-bound callback to the isolated compiler path', function (string $kind): void {
    $compiler = app(BladeCompiler::class);
    $compiler->setPath('original-view.blade.php');
    $callback = function (string $source): string {
        $path = (new ReflectionObject($this))->getProperty('path')->getValue($this);

        return $path === 'resources/views/probe.blade.php'
            ? '<?php if (; ?>'
            : $source;
    };
    $bound = $callback->bindTo($compiler, BladeCompiler::class);
    if (! $bound instanceof Closure) {
        throw new UnexpectedValueException('The test callback must bind to the application compiler.');
    }

    $source = 'plain text';
    match ($kind) {
        'directive' => $compiler->bindDirective('compilerCallbackPath', $bound),
        'extension' => $compiler->extend($bound),
        'preparation' => $compiler->prepareStringsForCompilationUsing($bound),
        'precompiler' => $compiler->precompiler($bound),
        default => throw new UnexpectedValueException("Unsupported callback kind [{$kind}]."),
    };
    if ($kind === 'directive') {
        app(Directives::class)->registerDirective('compilerCallbackPath');
        $source = '@compilerCallbackPath';
    }

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toStartWith('syntax error, ')
        ->and($compiler->getPath())->toBe('original-view.blade.php');
})->with(['directive', 'extension', 'preparation', 'precompiler']);

it('rebinds compiler-owned array callbacks to the isolated compiler path', function (string $kind): void {
    $compiler = new class(new Filesystem, sys_get_temp_dir()) extends BladeCompiler
    {
        public function pathSensitive(string $source): string
        {
            return $this->getPath() === 'resources/views/array-callback.blade.php'
                ? '<?php if (; ?>'
                : $source;
        }
    };
    $compiler->setPath('original-view.blade.php');
    $source = 'plain text';
    $method = 'pathSensitive';
    $callback = [$compiler, $method];

    match ($kind) {
        'directive' => $compiler->directive('arrayCallbackPath', $callback),
        'extension' => $compiler->extend($callback),
        'preparation' => $compiler->prepareStringsForCompilationUsing($callback),
        'precompiler' => $compiler->precompiler($callback),
        default => throw new UnexpectedValueException("Unsupported callback kind [{$kind}]."),
    };
    if ($kind === 'directive') {
        $source = '@arrayCallbackPath';
    }

    $compiled = (new IsolatedBladeCompiler($compiler))->compile(
        $source,
        'resources/views/array-callback.blade.php',
    );

    expect($compiled)->toBe('<?php if (; ?>')
        ->and($compiler->getPath())->toBe('original-view.blade.php');
})->with(['directive', 'extension', 'preparation', 'precompiler']);

it('rebinds an invokable compiler callback to its isolated clone', function (): void {
    $compiler = new class(new Filesystem, sys_get_temp_dir()) extends BladeCompiler
    {
        public function __invoke(string $source): string
        {
            return $this->getPath() === 'resources/views/invokable-callback.blade.php'
                ? '<?php if (; ?>'
                : $source;
        }
    };
    $compiler->setPath('original-view.blade.php');
    $compiler->extend($compiler);

    $compiled = (new IsolatedBladeCompiler($compiler))->compile(
        'plain text',
        'resources/views/invokable-callback.blade.php',
    );

    expect($compiled)->toBe('<?php if (; ?>')
        ->and($compiler->getPath())->toBe('original-view.blade.php');
});

it('uses a fresh cache context by default', function (): void {
    $compiler = app(BladeCompiler::class);

    $first = CompilerFingerprint::make($compiler);
    $second = CompilerFingerprint::make($compiler);

    expect($second)->not->toBe($first)
        ->and($first['compiler'] ?? null)->toBe($compiler::class)
        ->and($first['parser'] ?? null)->toBe(PhpValidator::parserConfiguration());
});

it('uses an opaque stable application cache identity when provided', function (): void {
    $compiler = app(BladeCompiler::class);

    $first = CompilerFingerprint::make($compiler, 'deployment-secret');
    $second = CompilerFingerprint::make($compiler, 'deployment-secret');
    $changed = CompilerFingerprint::make($compiler, 'next-deployment');

    expect($second)->toBe($first)
        ->and($changed)->not->toBe($first)
        ->and($first['application'] ?? null)->toBe([
            'provided' => hash('xxh128', 'deployment-secret'),
        ])
        ->and(json_encode($first, JSON_THROW_ON_ERROR))->not->toContain('deployment-secret');
});
