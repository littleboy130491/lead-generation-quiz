<?php

namespace Tests;

use Database\Seeders\AdminRoleSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (app()->runningUnitTests() && Schema::hasTable('permissions')) {
            $this->seed(AdminRoleSeeder::class);
        }
    }
}
