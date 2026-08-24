<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

use Forte\Ast\Document\Document;
use Forte\Ast\Node;
use Forte\Sheath\BladeCompiler\Compiler\IsolatedBladeCompiler;
use Forte\Sheath\BladeCompiler\Validation\PhpValidator;
use Throwable;

/** @internal */
final readonly class CompilationAnalyzer
{
    public function __construct(
        private IsolatedBladeCompiler $compiler,
        private PhpValidator $php,
        private PhpRegionAnalyzer $phpRegions,
    ) {}

    /** @return list<CompilationResult> */
    public function analyzeAll(
        Document $document,
        string $path,
        string $phpValidation = PhpValidator::PROCESS,
    ): array {
        $compiled = $this->analyze($document, $path, $phpValidation);
        if ($compiled === null) {
            return [];
        }

        $phpRegion = $this->phpRegions->analyze($document);
        if ($phpRegion === null || $this->sameDiagnosticLocation($compiled, $phpRegion)) {
            return [$compiled];
        }

        return [$compiled, $phpRegion];
    }

    public function analyze(
        Document $document,
        string $path,
        string $phpValidation = PhpValidator::PROCESS,
    ): ?CompilationResult {
        $markers = SourceMarkerMap::forDocument($document);

        try {
            $compiled = $this->compiler->compile($document->source(), $path);
        } catch (Throwable $exception) {
            $diagnostic = new CompilerDiagnostic(
                $this->normalizeMessage($exception->getMessage()),
                max(1, $exception->getLine()),
                DiagnosticKind::BladeCompilation,
            );

            $lastCompiledNode = null;
            $this->compileWithDirectiveMarkers($markers, $path, $lastCompiledNode);

            $node = $lastCompiledNode ?? $this->nodeForCompilerException($markers, $exception);

            return new CompilationResult(
                $diagnostic,
                $node === null ? null : SourceMapping::forNode($node),
            );
        }

        $diagnostic = $this->php->validate($compiled, $phpValidation);
        if ($diagnostic === null) {
            return null;
        }

        $compiledWithMarkers = $this->compileWithMarkers($markers, $path);

        $mappingDiagnostic = null;
        if (is_string($compiledWithMarkers)) {
            try {
                $mappingDiagnostic = $this->php->validate($compiledWithMarkers, $phpValidation);
            } catch (Throwable) {
            }
        }

        $source = $this->sourceMappingFromMarkers(
            $markers,
            $compiledWithMarkers,
            $mappingDiagnostic,
        );

        return new CompilationResult($diagnostic, $source);
    }

    private function sourceMappingFromMarkers(
        SourceMarkerMap $markers,
        ?string $compiled,
        ?CompilerDiagnostic $diagnostic,
    ): ?SourceMapping {
        if ($compiled === null || $diagnostic === null) {
            return null;
        }

        return $markers->mappingForCompiledDiagnostic($compiled, $diagnostic);
    }

    private function compileWithMarkers(
        SourceMarkerMap $markers,
        string $path,
    ): ?string {
        try {
            return $this->compiler->compile($markers->instrumentedSource(), $path);
        } catch (Throwable) {
            // Marker compilation is best-effort diagnostic enrichment. Preserve
            // the original PHP diagnostic when instrumentation fails.
            return null;
        }
    }

    private function compileWithDirectiveMarkers(
        SourceMarkerMap $markers,
        string $path,
        ?Node &$lastCompiledNode,
    ): void {
        try {
            $this->compiler->compileWithDirective(
                $markers->directiveInstrumentedSource(),
                $path,
                $markers->directiveName(),
                function (string $expression) use ($markers, &$lastCompiledNode): string {
                    $marker = $markers->markerFromExpression($expression);
                    if ($marker === null) {
                        return '';
                    }

                    $lastCompiledNode = $markers->nodeForMarker($marker);

                    return $markers->compiledMarker($marker);
                },
            );
        } catch (Throwable) {
            // Marker compilation is best-effort diagnostic enrichment. Preserve
            // the original compiler or PHP diagnostic when instrumentation fails.
        }
    }

    private function nodeForCompilerException(SourceMarkerMap $markers, Throwable $exception): ?Node
    {
        if (preg_match('/@([A-Za-z_][A-Za-z0-9_]*)/', $exception->getMessage(), $match) === 1) {
            $directive = $markers->directiveNamed($match[1]);
            if ($directive !== null) {
                return $directive;
            }
        }

        if (preg_match('/component \[([^]]+)]/i', $exception->getMessage(), $match) === 1) {
            $component = $markers->componentNamed($match[1]);
            if ($component !== null) {
                return $component;
            }
        }

        return null;
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return rtrim($message, '.');
    }

    private function sameDiagnosticLocation(CompilationResult $left, CompilationResult $right): bool
    {
        if ($left->diagnostic->message !== $right->diagnostic->message) {
            return false;
        }

        if ($left->source === null || $right->source === null) {
            return false;
        }

        return $left->source->node->startOffset() === $right->source->node->startOffset()
            && $left->source->node->endOffset() === $right->source->node->endOffset();
    }
}
