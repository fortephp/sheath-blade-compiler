<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Analysis;

use Forte\Ast\Node;

/** @internal */
final readonly class SourceMapping
{
    private function __construct(
        public Node $node,
        public ?int $startOffset,
        public ?int $endOffset,
    ) {}

    public static function forNode(Node $node): self
    {
        return new self($node, null, null);
    }

    public static function forRange(Node $node, int $startOffset, int $endOffset): self
    {
        return new self($node, $startOffset, $endOffset);
    }

    public static function forLine(Node $node, int $line): self
    {
        if ($line < $node->startLine() || $line > $node->endLine()) {
            return self::forNode($node);
        }

        $document = $node->getDocument();
        $start = max(
            $node->startOffset(),
            $document->getOffsetFromPosition($line, 1),
        );
        $end = min(
            $node->endOffset(),
            $document->getOffsetFromPosition($line, 1) + strlen($document->getLine($line)),
        );
        $source = $document->source();

        while ($start < $end && str_contains(" \t", $source[$start])) {
            $start++;
        }
        while ($end > $start && str_contains(" \t", $source[$end - 1])) {
            $end--;
        }

        return $start < $end
            ? self::forRange($node, $start, $end)
            : self::forNode($node);
    }

    /** @return array{int, int}|null */
    public function preciseRange(): ?array
    {
        if ($this->startOffset === null || $this->endOffset === null) {
            return null;
        }

        return [$this->startOffset, $this->endOffset];
    }
}
