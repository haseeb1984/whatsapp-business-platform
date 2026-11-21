<?php
namespace Tests\Feature;
use Tests\TestCase;
class HealthEndpointTest extends TestCase
{
    public function test_framework_health_endpoint_is_available(): void {
        $this->get('/up')->assertOk();
    }
    public function test_api_health_endpoint_returns_json(): void {
        $this->getJson('/api/v1/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
