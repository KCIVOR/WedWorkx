<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety stop: the tests rebuild the database from scratch, so they must never run against the real one.
     * A cached config (php artisan config:cache) ignores phpunit.xml and points at the real database: this refuses
     * to start before anything touches it.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $database = $app['config']->get('database.connections.mysql.database');
        if ($database !== 'wedworks_chatbot_test') {
            throw new RuntimeException("Refusing to run the tests against the database '{$database}'. If the config is cached, run: php artisan config:clear");
        }

        return $app;
    }
}
