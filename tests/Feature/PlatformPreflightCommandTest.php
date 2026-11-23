<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PlatformPreflightCommandTest extends TestCase
{
    public function test_strict_preflight_passes_when_production_runtime_configuration_is_complete(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:preflight-test-key',
            'app.url' => 'https://platform.example.test',
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'require',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
            'session.secure' => true,
            'services.meta.graph_api_version' => 'v24.0',
            'services.meta.app_id' => 'meta-app-id',
            'services.meta.app_secret' => 'meta-app-secret',
            'services.meta.webhook_verify_token' => 'meta-webhook-token',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.test',
            'mail.from.address' => 'no-reply@example.test',
            'services.groq.api_key' => 'groq-test-key',
            'services.google.client_id' => 'google-client-id',
            'services.google.client_secret' => 'google-client-secret',
            'services.google.redirect_uri' => 'https://platform.example.test/google/callback',
        ]);

        $exitCode = Artisan::call('platform:preflight', ['--strict' => true]);\n        $this->assertSame(0, $exitCode, Artisan::output());
    }

    public function test_strict_preflight_fails_when_production_integrations_are_missing(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:preflight-test-key',
            'app.url' => 'https://platform.example.test',
            'database.default' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'require',
            'queue.default' => 'database',
            'queue.connections.database.after_commit' => true,
            'session.secure' => true,
            'services.meta.graph_api_version' => 'v24.0',
            'services.groq.api_key' => 'groq-test-key',
            'services.google.client_id' => null,
            'services.google.client_secret' => null,
            'services.google.redirect_uri' => null,
        ]);

        $this->artisan('platform:preflight', ['--strict' => true])->assertExitCode(1);
    }
}
