<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    
    public function test_readiness_returns_degraded_without_exposing_database_exception_details(): void
    {
        DB::shouldReceive('connection')->once()->andThrow(new \RuntimeException('database credentials must stay private'));

        $this->getJson('/health/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'degraded',
                'checks' => [
                    'database' => false,
                    'queue_storage' => false,
                ],
            ]);
    }

}
}
