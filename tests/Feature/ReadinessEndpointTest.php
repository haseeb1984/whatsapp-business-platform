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
    }

    public function test_responses_include_baseline_browser_security_headers(): void
    {
        $this->getJson('/health/ready')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_hsts_is_only_sent_for_secure_production_requests(): void
    {
        config(['app.env' => 'production']);
        app()->detectEnvironment(fn () => 'production');
        $middleware = new \App\Http\Middleware\SecurityHeaders();

        $secureRequest = \Mockery::mock(\Illuminate\Http\Request::class);
        $secureRequest->shouldReceive('isSecure')->once()->andReturn(true);
        $secureResponse = $middleware->handle($secureRequest, fn () => response('ok'));
        $this->assertSame('max-age=31536000', $secureResponse->headers->get('Strict-Transport-Security'));

        $httpRequest = \Mockery::mock(\Illuminate\Http\Request::class);
        $httpRequest->shouldReceive('isSecure')->once()->andReturn(false);
        $httpResponse = $middleware->handle($httpRequest, fn () => response('ok'));
        $this->assertNull($httpResponse->headers->get('Strict-Transport-Security'));
    }

    public function test_readiness_returns_degraded_without_exposing_database_exception_details(): void
    {
        DB::partialMock()->shouldReceive('connection')->once()->andThrow(new \RuntimeException('database credentials must stay private'));

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
