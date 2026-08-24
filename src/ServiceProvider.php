<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler;

use Forte\Sheath\SheathManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

final class ServiceProvider extends BaseServiceProvider
{
    /** @var array<string, string> */
    private const PRESET = [
        'blade-valid-php-syntax' => 'off',
        'blade-compiler-valid-output' => 'error',
    ];

    public function boot(SheathManager $sheath): void
    {
        $sheath->discoverRules(__DIR__.'/Rules', __NAMESPACE__.'\\Rules');
        $sheath->registerPreset('blade-compiler', self::PRESET);
    }
}
