<?php

namespace Massif\ResponsiveImages\Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use Massif\ResponsiveImages\ServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            \Statamic\Providers\StatamicServiceProvider::class,
            ServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('statamic.editions.pro', true);
    }
}
