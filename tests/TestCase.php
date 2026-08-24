<?php

declare(strict_types=1);

namespace Forte\Sheath\BladeCompiler\Tests;

use Forte\Sheath\BladeCompiler\ServiceProvider;
use Forte\Sheath\ServiceProvider as SheathServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            SheathServiceProvider::class,
            ServiceProvider::class,
        ];
    }
}
