<?php
use Illuminate\\Support\\Facades\\Route;
Route::prefix('v1')->group(function (): void {
    Route::get('/health', fn () => response()->json(['status' => 'ok', 'application' => config('app.name')]))->name('api.v1.health');
});
// Meta webhook routes are added in the WhatsApp integration milestone.
