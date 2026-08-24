<?php

declare(strict_types=1);

use Forte\Sheath\BladeCompiler\Analysis\CompilerDiagnostic;
use Forte\Sheath\BladeCompiler\Validation\PhpValidationException;
use Forte\Sheath\BladeCompiler\Validation\PhpValidator;
use Forte\Sheath\Exceptions\ConfigurationException;

it('uses the PHP process for compile-time errors the parser accepts', function (string $source, string $detail): void {
    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain($detail);
})->with([
    'break outside loop' => ['@php break 2; @endphp', "'break' not in the 'loop' or 'switch' context"],
    'duplicate import alias' => ['@php use First\Thing as Duplicate; use Second\Thing as Duplicate; @endphp', 'already in use'],
    'goto into loop' => ['@php goto inside; while (true) { inside: break; } @endphp', "'goto' into loop or switch statement is disallowed"],
]);

it('can validate in-process without executing the compiled template', function (): void {
    expect(lintCompiledBlade(
        '@php throw new RuntimeException("compiled template executed"); @endphp',
        ['phpValidation' => 'parser'],
    ))->toBe([])
        ->and(lintCompiledBlade(
            "{{ '}}' }}",
            ['phpValidation' => 'parser'],
        ))->toHaveCount(1)
        ->and(lintCompiledBlade(
            '@php break; @endphp',
            ['phpValidation' => 'parser'],
        ))->toBe([]);
});

it('does not load PHP declarations while validating in-process', function (): void {
    $suffix = str_replace('.', '', uniqid('SheathParser', true));
    $class = $suffix.'Class';
    $function = $suffix.'Function';
    $source = "@php class {$class} {} function {$function}() {} @endphp";

    expect(lintCompiledBlade($source, ['phpValidation' => 'parser']))->toBe([])
        ->and(class_exists($class, false))->toBeFalse()
        ->and(function_exists($function))->toBeFalse();
});

it('rejects unknown PHP validation engines through Sheath rule options', function (): void {
    expect(fn () => lintCompiledBlade('', ['phpValidation' => 'wasm']))
        ->toThrow(
            ConfigurationException::class,
            "Invalid option 'phpValidation' for rule 'blade-compiler-valid-output'. Expected one of process, parser.",
        );
});

it('requires cacheIdentity to be a string', function (mixed $identity): void {
    expect(fn () => lintCompiledBlade('', ['cacheIdentity' => $identity]))
        ->toThrow(
            ConfigurationException::class,
            "Invalid option 'cacheIdentity' for rule 'blade-compiler-valid-output'. Expected a string.",
        );
})->with([
    'integer' => [42],
    'array' => [['deployment']],
]);

it('fails closed when the PHP validation process cannot start', function (): void {
    $validator = new PhpValidator(__DIR__.'/missing-php-binary');

    expect(fn (): ?CompilerDiagnostic => $validator->validate('<?php echo "safe";'))
        ->toThrow(PhpValidationException::class);
});

it('does not start native PHP for output containing no executable PHP', function (string $compiled): void {
    $validator = new PhpValidator(__DIR__.'/missing-php-binary');

    expect($validator->validate($compiled))->toBeNull();
})->with([
    'empty output' => '',
    'plain HTML' => '<main><h1>Safe</h1></main>',
    'PHP trivia only' => "<?php /* comment */\n // comment\n ?>",
]);

it('matches native lint short-tag parsing to the current PHP runtime', function (): void {
    if (filter_var(ini_get('short_open_tag'), FILTER_VALIDATE_BOOL)) {
        $this->markTestSkipped('The current PHP runtime intentionally treats XML declarations as short PHP tags.');
    }

    $validator = new PhpValidator;

    expect($validator->validate("<?xml version=\"1.0\"?>\n<main>Safe</main>"))->toBeNull();
});

it('matches native lint multibyte parsing to the current PHP runtime', function (): void {
    if (! function_exists('proc_open') || ! extension_loaded('mbstring')) {
        $this->markTestSkipped('Multibyte process validation requires proc_open and mbstring.');
    }

    $compiled = "<?php declare(encoding='SJIS'); \$value='".pack('H*', '835c')."';";
    $script = <<<'PHP'
        require $argv[1];
        $compiled = base64_decode($argv[2], true);
        if (! is_string($compiled)) {
            throw new RuntimeException('Unable to decode the compiled test source.');
        }
        token_get_all($compiled, TOKEN_PARSE);
        $diagnostic = (new Forte\Sheath\BladeCompiler\Validation\PhpValidator())->validate($compiled);
        echo json_encode([
            'loadedIni' => php_ini_loaded_file(),
            'mbstring' => extension_loaded('mbstring'),
            'zend.multibyte' => ini_get('zend.multibyte'),
            'diagnostic' => $diagnostic?->message,
        ], JSON_THROW_ON_ERROR);
        PHP;
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            '-n',
            '-d',
            'extension_dir='.ini_get('extension_dir'),
            '-d',
            'extension=mbstring',
            '-d',
            'zend.multibyte=1',
            '-r',
            $script,
            dirname(__DIR__, 3).'/vendor/autoload.php',
            base64_encode($compiled),
        ],
        $descriptors,
        $pipes,
        options: ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Unable to start the multibyte validation test process.');
    }
    $pipes = validatedProcessPipes($pipes);

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, is_string($stderr) ? $stderr : '')
        ->and(json_decode(is_string($stdout) ? $stdout : '', true, flags: JSON_THROW_ON_ERROR))->toBe([
            'loadedIni' => false,
            'mbstring' => true,
            'zend.multibyte' => '1',
            'diagnostic' => null,
        ]);
});

it('streams compiled templates larger than a pipe buffer to native PHP', function (): void {
    $source = str_repeat("<p>safe content</p>\n", 2_000).'@php break; @endphp';

    $violations = lintCompiledBlade($source);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->message)->toContain("'break' not in the 'loop' or 'switch' context");
});
