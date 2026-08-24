<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

/** @internal */
enum DiagnosticKind: string
{
    case BladeCompilation = 'blade-compilation';
    case PhpSyntax = 'php-syntax';
    case PhpCompilation = 'php-compilation';
}
