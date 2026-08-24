<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Validation;

use Forte\Sheath\BladeCompiler\Analysis\CompilerDiagnostic;
use Forte\Sheath\BladeCompiler\Analysis\DiagnosticKind;
use ParseError;

/** @internal */
final readonly class PhpValidator
{
    /** @var list<string> */
    private const PARSER_INI_SETTINGS = [
        'short_open_tag',
        'zend.multibyte',
        'zend.script_encoding',
        'zend.detect_unicode',
        'zend.assertions',
        'mbstring.internal_encoding',
    ];

    public const PROCESS = 'process';

    public const PARSER = 'parser';

    public function __construct(private string $phpBinary = PHP_BINARY) {}

    public function validate(string $compiled, string $validation = self::PROCESS): ?CompilerDiagnostic
    {
        [$diagnostic, $hasPhpCode] = $this->parse($compiled);

        if ($diagnostic !== null) {
            return $diagnostic;
        }

        if ($validation === self::PARSER || ! $hasPhpCode) {
            return null;
        }

        return $this->nativeLint($compiled);
    }

    /** @return array<string, mixed> */
    public static function parserConfiguration(): array
    {
        $settings = [];
        foreach (self::PARSER_INI_SETTINGS as $name) {
            $value = ini_get($name);
            $settings[$name] = is_string($value) ? $value : null;
        }

        return [
            'loadedIni' => php_ini_loaded_file() ?: null,
            'scannedIni' => php_ini_scanned_files() ?: null,
            'mbstring' => extension_loaded('mbstring'),
            'extensionDir' => ini_get('extension_dir') ?: null,
            'settings' => $settings,
        ];
    }

    /** @return array{?CompilerDiagnostic, bool} */
    private function parse(string $compiled): array
    {
        try {
            $tokens = token_get_all($compiled, TOKEN_PARSE);
        } catch (ParseError $error) {
            return [
                new CompilerDiagnostic(
                    $this->normalizeMessage($error->getMessage()),
                    max(1, $error->getLine()),
                    DiagnosticKind::PhpSyntax,
                ),
                false,
            ];
        }

        foreach ($tokens as $token) {
            if ($this->isExecutablePhpToken($token)) {
                return [null, true];
            }
        }

        return [null, false];
    }

    /** @param string|array{int, string, int} $token */
    private function isExecutablePhpToken(string|array $token): bool
    {
        if (is_string($token)) {
            return true;
        }

        return ! in_array($token[0], [
            T_INLINE_HTML,
            T_OPEN_TAG,
            T_CLOSE_TAG,
            T_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
        ], true);
    }

    private function nativeLint(string $compiled): ?CompilerDiagnostic
    {
        if (! function_exists('proc_open')) {
            throw new PhpValidationException(
                'PHP process validation requires proc_open. Use phpValidation=parser when process creation is unavailable.'
            );
        }

        return $this->runNativeLint($compiled);
    }

    private function runNativeLint(string $compiled): ?CompilerDiagnostic
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = @proc_open(
            $this->nativeCommand(),
            $descriptors,
            $pipes,
            options: ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new PhpValidationException("Unable to start PHP process validation with [{$this->phpBinary}].");
        }
        $pipes = $this->validatedPipes($pipes);

        try {
            $length = strlen($compiled);
            $offset = 0;
            $inputComplete = true;
            while ($offset < $length) {
                // Windows anonymous pipes can reject a second small write even
                // while the child is healthy. A blocking write of the complete
                // remainder lets PHP drain stdin as needed on every platform.
                $written = @fwrite($pipes[0], substr($compiled, $offset));
                if (! is_int($written) || $written === 0) {
                    $inputComplete = false;

                    break;
                }

                $offset += $written;
            }
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
        }

        $exitCode = proc_close($process);
        if (! $inputComplete) {
            throw new PhpValidationException('PHP process validation did not accept the complete compiled template.');
        }

        $output = trim(implode("\n", array_filter([$stderr, $stdout], is_string(...))));
        if ($this->nativeLintSucceeded($exitCode, $output)) {
            return null;
        }

        if (preg_match('/(?:Parse|Fatal) error:\s*(.*?)\s+in\s+Standard input code\s+on line\s+(\d+)/is', $output, $match) === 1) {
            return new CompilerDiagnostic(
                $this->normalizeMessage($match[1]),
                max(1, (int) $match[2]),
                DiagnosticKind::PhpCompilation,
            );
        }

        $message = $this->normalizeMessage($output);

        throw new PhpValidationException($message === ''
            ? 'PHP process validation failed without an error message.'
            : "PHP process validation failed: {$message}.");
    }

    private function nativeLintSucceeded(int $exitCode, string $output): bool
    {
        return $exitCode === 0
            || preg_match('/^No syntax errors detected in Standard input code\.?$/i', $output) === 1;
    }

    private function normalizeMessage(string $message): string
    {
        $message = preg_replace('/\s+/', ' ', trim($message)) ?? trim($message);

        return rtrim($message, '.');
    }

    /** @return array{resource, resource, resource} */
    private function validatedPipes(mixed $pipes): array
    {
        if (! is_array($pipes)) {
            throw new PhpValidationException('PHP process validation did not provide its standard streams.');
        }

        return [
            $this->validatedPipe($pipes[0] ?? null),
            $this->validatedPipe($pipes[1] ?? null),
            $this->validatedPipe($pipes[2] ?? null),
        ];
    }

    /** @return resource */
    private function validatedPipe(mixed $pipe)
    {
        if (! is_resource($pipe)) {
            throw new PhpValidationException('PHP process validation did not provide its standard streams.');
        }

        return $pipe;
    }

    /** @return list<string> */
    private function nativeCommand(): array
    {
        $command = [$this->phpBinary];
        $loadedIni = php_ini_loaded_file();
        $scannedIni = php_ini_scanned_files();

        if (is_string($loadedIni)) {
            $command[] = '-c';
            $command[] = $loadedIni;
        } elseif ($scannedIni === false) {
            $command[] = '-n';
        }

        $command[] = '-d';
        $command[] = 'display_errors=1';
        foreach (self::PARSER_INI_SETTINGS as $name) {
            $value = ini_get($name);
            if (! is_string($value)) {
                continue;
            }

            $command[] = '-d';
            $command[] = $name.'='.$value;
        }

        if ($this->mustLoadMbstringForNativeParser()) {
            $extensionDirectory = ini_get('extension_dir');
            if (is_string($extensionDirectory) && $extensionDirectory !== '') {
                $command[] = '-d';
                $command[] = 'extension_dir='.$extensionDirectory;
            }

            // mbstring may have been loaded by a CLI -d flag rather than the
            // configured ini files. Loading it again is harmless when the ini
            // already provides it and preserves the parent parser otherwise.
            $command[] = '-d';
            $command[] = 'extension=mbstring';
        }

        $command[] = '-l';

        return $command;
    }

    private function mustLoadMbstringForNativeParser(): bool
    {
        return extension_loaded('mbstring')
            && filter_var(ini_get('zend.multibyte'), FILTER_VALIDATE_BOOL);
    }
}
