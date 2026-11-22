<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReadinessEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_endpoint_reports_database_and_database_queue_storage(): void
    {
        config(['queue.default' => 'database']);

        $this->getJson('/health/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'checks' => [
                    'database' => true,
                    'queue_storage' => true,
                ],
            ]);
    }
}
