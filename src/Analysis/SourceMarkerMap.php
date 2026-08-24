<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Ast\VerbatimNode;

/** @internal */
final class SourceMarkerMap
{
    /** @var array<string, Node> */
    private array $nodes = [];

    private ?DirectiveNode $lastUnclosedDirective = null;

    private function __construct(
        private readonly string $source,
        private readonly string $nonce,
        private readonly string $directiveName,
    ) {}

    public static function forDocument(Document $document): self
    {
        $nonce = substr(hash('xxh128', $document->source()), 0, 12);
        $instance = new self($document->source(), $nonce, '__sheathCompilerMarker_'.$nonce);
        $instance->collectNodes($document);

        return $instance;
    }

    public function instrumentedSource(): string
    {
        return $this->sourceWithMarkers(static fn (Node $node): bool => true, false);
    }

    public function directiveInstrumentedSource(): string
    {
        return $this->sourceWithMarkers(
            static fn (Node $node): bool => $node instanceof DirectiveNode,
            true,
        );
    }

    public function mappingForCompiledDiagnostic(
        string $compiledWithMarkers,
        CompilerDiagnostic $diagnostic,
    ): ?SourceMapping {
        $unclosedDirective = $this->unclosedDirectiveFor($diagnostic);
        if ($unclosedDirective !== null) {
            return SourceMapping::forNode($unclosedDirective);
        }

        $marker = $this->nearestMarkerBeforeDiagnostic($compiledWithMarkers, $diagnostic->compiledLine);
        if ($marker === null) {
            return null;
        }

        return $this->mappingForNode(
            $marker['node'],
            $compiledWithMarkers,
            $diagnostic->compiledLine,
            $marker['line'],
            $marker['endOffset'],
        );
    }

    private function unclosedDirectiveFor(CompilerDiagnostic $diagnostic): ?DirectiveNode
    {
        if (! str_contains(strtolower($diagnostic->message), 'unexpected end of file')) {
            return null;
        }

        return $this->lastUnclosedDirective;
    }

    /** @return array{node: Node, line: int, endOffset: int}|null */
    private function nearestMarkerBeforeDiagnostic(string $compiled, int $diagnosticLine): ?array
    {
        $pattern = '/(?:<!--|\/\*)__SHEATH_BC_'.preg_quote($this->nonce, '/').'_(?<marker>n[0-9a-z]+)__(?:-->|\*\/)/';
        $matched = preg_match_all($pattern, $compiled, $matches, PREG_OFFSET_CAPTURE);
        if (! is_int($matched) || $matched === 0) {
            return null;
        }

        $nearest = null;
        $line = 1;
        $scannedOffset = 0;
        foreach ($matches[0] as $index => $match) {
            $offset = $match[1];
            $line += substr_count($compiled, "\n", $scannedOffset, $offset - $scannedOffset);
            $scannedOffset = $offset;

            if ($line > $diagnosticLine) {
                break;
            }

            $marker = $matches['marker'][$index][0] ?? null;
            if (is_string($marker) && isset($this->nodes[$marker])) {
                $nearest = [
                    'node' => $this->nodes[$marker],
                    'line' => $line,
                    'endOffset' => $offset + strlen($match[0]),
                ];
            }
        }

        return $nearest;
    }

    private function mappingForNode(
        Node $node,
        string $compiledWithMarkers,
        int $compiledDiagnosticLine,
        int $compiledMarkerLine,
        int $compiledMarkerEnd,
    ): SourceMapping {
        $phpNode = $this->multilinePhpNode($node);
        if ($phpNode === null) {
            return SourceMapping::forNode($node);
        }

        $expected = $this->expectedCompiledPhp($phpNode);
        if ($expected === null || ! $this->compiledPhpFollowsMarker(
            $compiledWithMarkers,
            $compiledMarkerEnd,
            $expected,
        )) {
            return SourceMapping::forNode($node);
        }

        $sourceLine = $phpNode->startLine()
            + $compiledDiagnosticLine
            - ($compiledMarkerLine + 1);

        return SourceMapping::forLine($phpNode, $sourceLine);
    }

    private function multilinePhpNode(Node $node): PhpBlockNode|PhpTagNode|null
    {
        if (! $node instanceof PhpBlockNode && ! $node instanceof PhpTagNode) {
            return null;
        }

        return $node->startLine() === $node->endLine() ? null : $node;
    }

    private function compiledPhpFollowsMarker(string $compiled, int $markerEnd, string $expected): bool
    {
        if (($compiled[$markerEnd] ?? null) !== "\n") {
            return false;
        }

        return substr($compiled, $markerEnd + 1, strlen($expected)) === $expected;
    }

    private function expectedCompiledPhp(PhpBlockNode|PhpTagNode $node): ?string
    {
        if (! $node->hasClose()) {
            return null;
        }

        return $node instanceof PhpBlockNode
            ? '<?php'.$node->content().'?>'
            : $node->getDocumentContent();
    }

    /**
     * @param  callable(Node): bool  $include
     */
    private function sourceWithMarkers(callable $include, bool $asDirective): string
    {
        $insertions = [];

        foreach ($this->nodes as $marker => $node) {
            if (! $include($node)) {
                continue;
            }

            $footerOffset = $this->footerMarkerOffsetFor($node, $asDirective);
            $insertions[] = [
                'offset' => $footerOffset ?? $node->startOffset(),
                'marker' => match (true) {
                    $asDirective => "@{$this->directiveName}('{$marker}')",
                    $footerOffset !== null => $this->serializedPhpMarker($marker),
                    default => $this->serializedMarker($marker),
                },
            ];
        }

        usort($insertions, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

        $chunks = [];
        $sourceOffset = 0;
        foreach ($insertions as $insertion) {
            $chunks[] = substr($this->source, $sourceOffset, $insertion['offset'] - $sourceOffset);
            $chunks[] = $insertion['marker'];
            $sourceOffset = $insertion['offset'];
        }
        $chunks[] = substr($this->source, $sourceOffset);

        return implode('', $chunks);
    }

    private function footerMarkerOffsetFor(Node $node, bool $asDirective): ?int
    {
        if ($asDirective || ! $node instanceof DirectiveNode) {
            return null;
        }

        return $this->footerMarkerOffset($node);
    }

    public function directiveName(): string
    {
        return $this->directiveName;
    }

    public function markerFromExpression(string $expression): ?string
    {
        if (preg_match("/^\\s*'(?<marker>n[0-9a-z]+)'\\s*$/", $expression, $match) !== 1) {
            return null;
        }

        return isset($this->nodes[$match['marker']]) ? $match['marker'] : null;
    }

    public function nodeForMarker(string $marker): ?Node
    {
        return $this->nodes[$marker] ?? null;
    }

    public function compiledMarker(string $marker): string
    {
        return $this->serializedMarker($marker);
    }

    public function directiveNamed(string $name): ?DirectiveNode
    {
        $match = null;

        foreach ($this->nodes as $node) {
            if ($node instanceof DirectiveNode && strcasecmp($node->nameText(), $name) === 0) {
                if ($match !== null) {
                    return null;
                }

                $match = $node;
            }
        }

        return $match;
    }

    public function componentNamed(string $name): ?ComponentNode
    {
        $match = null;

        foreach ($this->nodes as $node) {
            if ($node instanceof ComponentNode && strcasecmp($node->getComponentName(), $name) === 0) {
                if ($match !== null) {
                    return null;
                }

                $match = $node;
            }
        }

        return $match;
    }

    private function collectNodes(Document $document): void
    {
        $seenOffsets = [];
        $ignoredClosingDirectiveRanges = $this->ignoredClosingDirectiveRanges($document);

        foreach ($document->getBlockDirectives() as $block) {
            $start = $block->startDirective();
            if ($start === null) {
                continue;
            }

            $this->rememberIfBlockIsUnclosed(
                $start,
                $block->endDirective(),
                $ignoredClosingDirectiveRanges,
            );
        }

        foreach (array_keys($document->getNodes()) as $index) {
            $node = $document->getNode($index);

            if (! $this->canReceiveMarker($node)) {
                continue;
            }

            if ($node->startOffset() < 0 || isset($seenOffsets[$node->startOffset()])) {
                continue;
            }

            $seenOffsets[$node->startOffset()] = true;
            // Prefix markers because PHP coerces numeric-string array keys to integers.
            $this->nodes['n'.base_convert((string) $node->index(), 10, 36)] = $node;
        }
    }

    /** @param list<array{int, int}> $ignoredRanges */
    private function rememberIfBlockIsUnclosed(
        DirectiveNode $start,
        ?DirectiveNode $end,
        array $ignoredRanges,
    ): void {
        if ($end !== null && $this->isCompleteLaravelDirective($end)) {
            return;
        }

        if ($this->containsVisibleDirective(
            '@end'.$start->nameText(),
            $start->endOffset(),
            $ignoredRanges,
        )) {
            return;
        }

        if (! $this->startsAfterLastUnclosedDirective($start)) {
            return;
        }

        $this->lastUnclosedDirective = $start;
    }

    private function startsAfterLastUnclosedDirective(DirectiveNode $directive): bool
    {
        return $this->lastUnclosedDirective === null
            || $directive->startOffset() > $this->lastUnclosedDirective->startOffset();
    }

    private function canReceiveMarker(Node $node): bool
    {
        return $node instanceof EchoNode
            || $node instanceof DirectiveNode
            || $node instanceof ComponentNode
            || $node instanceof PhpBlockNode
            || $node instanceof PhpTagNode;
    }

    private function serializedMarker(string $marker): string
    {
        return "\n<!--__SHEATH_BC_{$this->nonce}_{$marker}__-->\n";
    }

    private function serializedPhpMarker(string $marker): string
    {
        return "/*__SHEATH_BC_{$this->nonce}_{$marker}__*/";
    }

    private function footerMarkerOffset(DirectiveNode $node): ?int
    {
        if (! $this->isFooterCompiledDirective($node)) {
            return null;
        }

        $arguments = $node->arguments();
        if (! is_string($arguments) || ! str_starts_with($arguments, '(')) {
            return null;
        }

        $offset = $node->startOffset()
            + 1
            + strlen($node->nameText())
            + strlen($node->whitespaceBetweenNameAndArgs() ?? '');

        return ($this->source[$offset] ?? null) === '(' ? $offset + 1 : null;
    }

    private function isFooterCompiledDirective(DirectiveNode $node): bool
    {
        return $node->isDirectiveNamed('extends') || $node->isDirectiveNamed('extendsFirst');
    }

    /** @return list<array{int, int}> */
    private function ignoredClosingDirectiveRanges(Document $document): array
    {
        $ranges = [];

        foreach (array_keys($document->getNodes()) as $index) {
            $node = $document->getNode($index);
            if ($this->hidesBladeDirectives($node)) {
                $ranges[] = [$node->startOffset(), $node->endOffset()];
            }
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        return $ranges;
    }

    private function hidesBladeDirectives(Node $node): bool
    {
        return $node instanceof BladeCommentNode
            || $node instanceof VerbatimNode
            || $node instanceof PhpBlockNode
            || $node instanceof PhpTagNode;
    }

    /** @param list<array{int, int}> $ignoredRanges */
    private function containsVisibleDirective(string $directive, int $offset, array $ignoredRanges): bool
    {
        while (($match = stripos($this->source, $directive, $offset)) !== false) {
            $afterDirective = $match + strlen($directive);

            if ($this->isVisibleStandaloneDirective($match, $afterDirective, $ignoredRanges)) {
                return true;
            }

            $offset = $afterDirective;
        }

        return false;
    }

    /** @param list<array{int, int}> $ignoredRanges */
    private function isVisibleStandaloneDirective(
        int $offset,
        int $afterDirective,
        array $ignoredRanges,
    ): bool {
        return ! $this->offsetIsInRanges($offset, $ignoredRanges)
            && ! $this->continuesLaravelDirectiveName($afterDirective);
    }

    private function isCompleteLaravelDirective(DirectiveNode $directive): bool
    {
        return ! $this->continuesLaravelDirectiveName(
            $directive->startOffset() + 1 + strlen($directive->nameText()),
        );
    }

    private function continuesLaravelDirectiveName(int $offset): bool
    {
        if ($this->isDirectiveNameCharacter($this->source[$offset] ?? null)) {
            return true;
        }

        return ($this->source[$offset] ?? null) === ':'
            && ($this->source[$offset + 1] ?? null) === ':'
            && $this->isDirectiveNameCharacter($this->source[$offset + 2] ?? null);
    }

    private function isDirectiveNameCharacter(?string $character): bool
    {
        return $character !== null && preg_match('/[A-Za-z0-9_]/', $character) === 1;
    }

    /** @param list<array{int, int}> $ranges */
    private function offsetIsInRanges(int $offset, array $ranges): bool
    {
        $low = 0;
        $high = count($ranges) - 1;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            [$start, $end] = $ranges[$middle];

            if ($offset < $start) {
                $high = $middle - 1;
            } elseif ($offset >= $end) {
                $low = $middle + 1;
            } else {
                return true;
            }
        }

        return false;
    }
}
