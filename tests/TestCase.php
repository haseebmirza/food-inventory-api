<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'daily_food_inventory_test' || filled(config('database.connections.mysql.url'))) {
            throw new \RuntimeException('Tests require the isolated MySQL daily_food_inventory_test database.');
        }

        return $app;
    }
}
