<?php

namespace Tests\Unit;

use App\Support\RequestTimeLimit;
use Tests\TestCase;

class RequestTimeLimitTest extends TestCase
{
    public function test_extending_never_breaks_a_request_on_hosts_without_set_time_limit(): void
    {
        $before = ini_get('max_execution_time');

        RequestTimeLimit::extendForAiCall(60, 2);

        $this->assertSame($before, ini_get('max_execution_time'));
    }
}
