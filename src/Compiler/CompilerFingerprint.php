<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Compiler;

use Forte\Sheath\BladeCompiler\Validation\PhpValidator;
use Illuminate\View\Compilers\BladeCompiler;
use Random\RandomException;

/** @internal */
final class CompilerFingerprint
{
    /**
     * @return array<string, mixed>
     *
     * @throws RandomException
     */
    public static function make(BladeCompiler $compiler, string $cacheIdentity = ''): array
    {
        return [
            'php' => PHP_VERSION_ID,
            'binary' => PHP_BINARY,
            'parser' => PhpValidator::parserConfiguration(),
            'compiler' => $compiler::class,
            'application' => $cacheIdentity === ''
                ? ['invocation' => bin2hex(random_bytes(16))]
                : ['provided' => hash('xxh128', $cacheIdentity)],
        ];
    }
}
