<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

/** @internal */
final readonly class CompilerDiagnostic
{
    public function __construct(
        public string $message,
        public int $compiledLine,
        public DiagnosticKind $kind,
    ) {}
}
