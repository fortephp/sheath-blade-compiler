<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

use Forte\Ast\Document\Document;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\BladeCompiler\Validation\PhpValidator;

/** @internal */
final readonly class PhpRegionAnalyzer
{
    public function __construct(private PhpValidator $php) {}

    public function analyze(Document $document): ?CompilationResult
    {
        $nodes = $this->phpNodes($document);
        if ($nodes === []) {
            return null;
        }

        $validationSource = $this->validationSource($nodes);
        $diagnostic = $this->php->validate($validationSource['php'], PhpValidator::PARSER);
        if ($diagnostic === null) {
            return null;
        }

        return new CompilationResult(
            $diagnostic,
            $this->mappingForDiagnosticLine(
                $this->diagnosticLine($diagnostic),
                $validationSource['lineOwners'],
            ),
        );
    }

    /** @return list<PhpBlockNode|PhpTagNode> */
    private function phpNodes(Document $document): array
    {
        /** @var list<PhpBlockNode|PhpTagNode> $nodes */
        $nodes = [];
        $document->allOfType(PhpBlockNode::class, true)->each(
            static function (PhpBlockNode $node) use (&$nodes): void {
                $nodes[] = $node;
            },
        );
        $document->allOfType(PhpTagNode::class, true)->each(
            static function (PhpTagNode $node) use (&$nodes): void {
                $nodes[] = $node;
            },
        );

        usort($nodes, static fn (Node $left, Node $right): int => $left->startOffset() <=> $right->startOffset());

        return $nodes;
    }

    /**
     * @param  list<PhpBlockNode|PhpTagNode>  $nodes
     * @return array{php: string, lineOwners: list<array{int, int, PhpBlockNode|PhpTagNode}>}
     */
    private function validationSource(array $nodes): array
    {
        $php = '';
        /** @var list<array{int, int, PhpBlockNode|PhpTagNode}> $lineOwners */
        $lineOwners = [];
        foreach ($nodes as $index => $node) {
            if ($index > 0) {
                $php .= "\nHTML\n";
            }

            $snippet = $node instanceof PhpBlockNode
                ? '<?php'.$node->content().'?>'
                : $node->getDocumentContent();
            $startLine = substr_count($php, "\n") + 1;
            $endLine = $startLine + substr_count($snippet, "\n");
            $lineOwners[] = [$startLine, $endLine, $node];
            $php .= $snippet;
        }

        return ['php' => $php, 'lineOwners' => $lineOwners];
    }

    private function diagnosticLine(CompilerDiagnostic $diagnostic): int
    {
        $diagnosticLine = $diagnostic->compiledLine;
        if (preg_match('/\bUnclosed .+ on line (?<line>\d+)$/', $diagnostic->message, $matches) === 1) {
            $diagnosticLine = (int) $matches['line'];
        }

        return $diagnosticLine;
    }

    /** @param list<array{int, int, PhpBlockNode|PhpTagNode}> $lineOwners */
    private function mappingForDiagnosticLine(int $diagnosticLine, array $lineOwners): SourceMapping
    {
        [$ownerStartLine, , $owner] = $lineOwners[count($lineOwners) - 1];
        foreach ($lineOwners as [$startLine, $endLine, $node]) {
            if ($diagnosticLine < $startLine || $diagnosticLine > $endLine) {
                continue;
            }

            $ownerStartLine = $startLine;
            $owner = $node;

            break;
        }

        $sourceLine = $owner->startLine() + $diagnosticLine - $ownerStartLine;

        return SourceMapping::forLine($owner, $sourceLine);
    }
}
