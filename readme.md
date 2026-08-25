# Sheath Blade Compiler

Catch PHP errors in compiled Blade templates before they reach production.

This [Sheath](https://github.com/fortephp/sheath) plugin compiles your Blade views and checks the generated PHP for syntax and compile-time errors. It supports your application's custom directives and components without rendering views or executing their PHP.

## Installation

```bash
composer require --dev fortephp/sheath-blade-compiler
```

Laravel discovers the package automatically. Add its preset to `config/sheath.php`:

```php
'preset' => ['recommended', 'blade-compiler'],
```

Then run Sheath normally:

```bash
php artisan sheath:lint
```

You can also try the plugin without changing your config:

```bash
php artisan sheath:lint --preset=blade-compiler
```

## What it catches

- malformed Blade directives and echoes;
- invalid PHP emitted by custom directives or compiler hooks;
- component compilation failures;
- PHP compile-time errors such as illegal `break` statements and duplicate imports;
- syntax errors inside `@php` blocks and native PHP tags.

Errors are reported in your Blade view rather than generated PHP, so they are easier to find and fix.

By default, the plugin starts a separate PHP process to check each compiled view. This catches compile-time errors that a simpler in-process parser can miss. If your environment does not allow PHP processes, use parser mode instead:

```php
'rules' => [
    'blade-compiler-valid-output' => ['error', [
        'phpValidation' => 'parser',
    ]],
],
```

## Caching

The rule reuses results across `--cache` runs by default. Template changes invalidate their own cached results automatically. If you change application-level Blade compiler behavior, such as custom directives, precompilers, or component registrations, delete `.sheath-cache` before the next run.

You can also supply a deployment or build revision with `cacheIdentity`. Changing it invalidates cached compiler results without exposing the original value in the compiler fingerprint:

```php
'rules' => [
    'blade-compiler-valid-output' => ['error', [
        'cacheIdentity' => env('APP_BUILD_ID', ''),
    ]],
],
```

Set `cacheAcrossRuns` to `false` if the application changes compiler behavior at runtime and cached results must never be reused between command invocations:

```php
'rules' => [
    'blade-compiler-valid-output' => ['error', [
        'cacheAcrossRuns' => false,
    ]],
],
```

## Requirements

- PHP 8.2 or newer
- Laravel 12 or 13
- Sheath 1.x

## License

MIT. See [license.md](license.md).
