<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

/** @internal */
final readonly class CompilationResult
{
    public function __construct(
        public CompilerDiagnostic $diagnostic,
        public ?SourceMapping $source,
    ) {}
}
