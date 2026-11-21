<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Webhooks\MetaWhatsAppWebhookController;
Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'application' => config('app.name')]))->name('api.v1.health');
});
Route::get('/webhooks/meta/whatsapp', [MetaWhatsAppWebhookController::class, 'verify'])->name('webhooks.meta.whatsapp.verify');
Route::post('/webhooks/meta/whatsapp', [MetaWhatsAppWebhookController::class, 'receive'])->name('webhooks.meta.whatsapp.receive');
