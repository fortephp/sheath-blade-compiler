<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Compiler;

use Closure;
use Illuminate\View\Compilers\BladeCompiler;
use ReflectionException;
use ReflectionFunction;
use ReflectionObject;
use ReflectionProperty;

/** @internal */
final readonly class IsolatedBladeCompiler
{
    /** @var list<string> */
    private const CALLBACK_PROPERTIES = [
        'customDirectives',
        'extensions',
        'prepareStringsForCompilationUsing',
        'precompilers',
    ];

    public function __construct(private BladeCompiler $compiler) {}

    /**
     * @throws ReflectionException
     */
    public function compile(string $source, string $path): string
    {
        return $this->compileString($this->compiler($path), $source);
    }

    /**
     * @throws ReflectionException
     */
    public function compileWithDirective(
        string $source,
        string $path,
        string $name,
        Closure $handler,
    ): string {
        $compiler = $this->compiler($path);
        $compiler->directive($name, $handler);

        return $this->compileString($compiler, $source);
    }

    private function compiler(string $path): BladeCompiler
    {
        $compiler = clone $this->compiler;
        $this->rebindCompilerCallbacks($compiler);
        $compiler->setPath($path);

        return $compiler;
    }

    private function rebindCompilerCallbacks(BladeCompiler $compiler): void
    {
        $reflection = new ReflectionObject($compiler);

        foreach (self::CALLBACK_PROPERTIES as $propertyName) {
            if (! $reflection->hasProperty($propertyName)) {
                continue;
            }

            $property = $reflection->getProperty($propertyName);
            $callbacks = $property->getValue($compiler);
            if (! is_array($callbacks)) {
                continue;
            }

            foreach ($callbacks as $name => $callback) {
                $callbacks[$name] = $this->rebindCallback($callback, $compiler);
            }

            $property->setValue($compiler, $callbacks);
        }
    }

    /**
     * @throws ReflectionException
     */
    private function rebindCallback(mixed $callback, BladeCompiler $compiler): mixed
    {
        $arrayCallback = $this->compilerArrayCallback($callback);
        if ($arrayCallback !== null) {
            $arrayCallback[0] = $compiler;

            return $arrayCallback;
        }

        if ($callback === $this->compiler) {
            return $compiler;
        }

        if (! $callback instanceof Closure) {
            return $callback;
        }

        $reflection = new ReflectionFunction($callback);
        if ($reflection->getClosureThis() !== $this->compiler) {
            return $callback;
        }

        return $callback->bindTo(
            $compiler,
            $reflection->getClosureScopeClass()?->getName(),
        );
    }

    /** @return array<mixed>|null */
    private function compilerArrayCallback(mixed $callback): ?array
    {
        if (! is_array($callback) || ! array_key_exists(0, $callback)) {
            return null;
        }

        return $callback[0] === $this->compiler ? $callback : null;
    }

    /**
     * @throws ReflectionException
     */
    private function compileString(BladeCompiler $compiler, string $source): string
    {
        $componentHashStack = $this->componentHashStackProperty($compiler);
        $originalComponentHashStack = $componentHashStack->getValue();

        try {
            return $compiler->compileString($source);
        } finally {
            $componentHashStack->setValue(null, $originalComponentHashStack);
        }
    }

    /**
     * @throws ReflectionException
     */
    private function componentHashStackProperty(BladeCompiler $compiler): ReflectionProperty
    {
        return (new ReflectionObject($compiler))->getProperty('componentHashStack');
    }
}
