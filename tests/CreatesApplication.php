<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (config('database.default') === 'mysql' && ! preg_match('/^chargeguard_test_[a-f0-9]{12}$/D', config('database.connections.mysql.database'))) {
            throw new \RuntimeException('Tests refuse to modify a non-isolated MySQL database. Use scripts/test-mysql.php.');
        }

        return $app;
    }
}
