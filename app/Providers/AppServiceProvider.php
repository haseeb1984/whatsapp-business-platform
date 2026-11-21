<?php

namespace App\Providers;

use App\Contracts\AiProviderInterface;
use App\Services\AI\GroqProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AiProviderInterface::class, GroqProvider::class);
    }

    public function boot(): void
    {
    }
}
