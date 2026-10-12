<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createApplication()
    {
        return require __DIR__.'/../bootstrap/app.php';
    }
}
