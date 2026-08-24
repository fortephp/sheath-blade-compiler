<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Rules;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Node;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\BladeCompiler\Analysis\CompilationAnalyzer;
use Forte\Sheath\BladeCompiler\Analysis\CompilationResult;
use Forte\Sheath\BladeCompiler\Analysis\DiagnosticKind;
use Forte\Sheath\BladeCompiler\Compiler\CompilerFingerprint;
use Forte\Sheath\BladeCompiler\Validation\PhpValidator;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Contracts\SharesRuleState;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\View\Compilers\BladeCompiler;

#[RequiresPackage('laravel/framework', '^12.0 || ^13.0')]
final class ValidCompiledOutputRule extends AbstractRule implements ProvidesCacheContext, SharesRuleState
{
    protected array $options = [
        'phpValidation' => PhpValidator::PROCESS,
        'cacheIdentity' => '',
    ];

    protected array $optionRules = [
        'phpValidation' => 'one-of:process,parser',
    ];

    public function __construct(
        private readonly CompilationAnalyzer $analyzer,
        private readonly BladeCompiler $compiler,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return 'blade-compiler-valid-output';
    }

    public function getDescription(): string
    {
        return 'Blade templates must compile to valid PHP.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ConfigurationException
     */
    public function cacheContext(array $options): array
    {
        $cacheIdentity = $options['cacheIdentity'] ?? '';
        if (! is_string($cacheIdentity)) {
            throw ConfigurationException::invalidRuleOption(
                $this->getId(),
                'cacheIdentity',
                'a string',
            );
        }

        return CompilerFingerprint::make(
            $this->compiler,
            $cacheIdentity,
        );
    }

    public function check(Document $document, RuleContext $context): void
    {
        $results = $this->analyzer->analyzeAll(
            $document,
            $context->getFilePath(),
            $this->phpValidation(),
        );

        foreach ($results as $result) {
            $this->report($document, $context, $result);
        }
    }

    private function report(Document $document, RuleContext $context, CompilationResult $result): void
    {
        $message = $this->message(
            $result->diagnostic->kind,
            $result->diagnostic->message,
            $result->source?->node,
        );
        $range = $result->source?->preciseRange();
        if ($range !== null) {
            $context->reportAt(
                Position::fromOffset($document, $range[0]),
                Position::fromOffset($document, $range[1]),
                $message,
            );

            return;
        }
        if ($result->source !== null) {
            $context->report($result->source->node, $message);

            return;
        }

        $end = min(1, strlen($document->source()));
        $context->reportAt(
            Position::fromOffset($document, 0),
            Position::fromOffset($document, $end),
            $message,
        );
    }

    private function phpValidation(): string
    {
        return $this->getOption('phpValidation') === PhpValidator::PARSER
            ? PhpValidator::PARSER
            : PhpValidator::PROCESS;
    }

    private function message(DiagnosticKind $kind, string $detail, ?Node $node): string
    {
        if ($node instanceof EchoNode) {
            $delimiter = $kind === DiagnosticKind::PhpSyntax
                ? $this->earlyEchoDelimiter($node)
                : null;

            if ($delimiter !== null) {
                return "Echo ends at the first `{$delimiter}`, before the expression is complete.";
            }
        }

        if ($node instanceof DirectiveNode && $detail === 'Undefined array key 1') {
            return match ($node->nameText()) {
                'pushif' => '@pushIf expects a condition and stack name.',
                'inject' => '@inject expects a variable name and service.',
                default => $this->sentence($detail),
            };
        }

        return $this->sentence($detail);
    }

    private function sentence(string $detail): string
    {
        return preg_match('/[.!?]\z/u', $detail) === 1 ? $detail : $detail.'.';
    }

    private function earlyEchoDelimiter(EchoNode $node): ?string
    {
        $delimiter = $node->isRaw() ? '!!}' : ($node->isEscaped() ? '}}' : null);
        if ($delimiter === null) {
            return null;
        }

        $source = $node->getDocumentContent();
        if (! str_ends_with($source, $delimiter)) {
            return null;
        }

        return str_contains(substr($source, 0, -strlen($delimiter)), $delimiter)
            ? $delimiter
            : null;
    }
}
