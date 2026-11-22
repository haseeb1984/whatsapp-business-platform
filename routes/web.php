<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\BusinessProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Platform\DashboardController as PlatformDashboardController;
use App\Http\Controllers\BusinessHoursController;
use App\Http\Controllers\BusinessHolidayController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\StaffWorkingHoursController;
use App\Http\Controllers\FaqController;
use App\Http\Controllers\WhatsAppConnectionController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\BookingPolicyController;
use App\Http\Controllers\StaffAvailabilityExceptionController;
use App\Http\Controllers\GoogleCalendarConnectionController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::get('/forgot-password', [AuthController::class, 'showForgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [AuthController::class, 'showResetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1')->name('password.update');
});

Route::get('/', function (Request $request) {
    if (! $request->user()) {
        return redirect()->route('login');
    }

    $tenantId = $request->session()->get('current_tenant_id');
    $hasActiveMembership = is_string($tenantId) && $request->user()->tenants()
        ->wherePivot('status', 'active')
        ->where('tenants.status', 'active')
        ->where('tenants.id', $tenantId)
        ->exists();

    if ($hasActiveMembership) {
        return redirect()->route('dashboard');
    }

    $request->session()->forget('current_tenant_id');

    return redirect()->route('workspaces.index');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/email/verify', [EmailVerificationController::class, 'notice'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:10,1'])
        ->name('verification.verify');
    Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')
        ->name('verification.send');

    Route::get('/workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('/workspaces/switch', [WorkspaceController::class, 'switch'])->middleware('throttle:20,1')->name('workspaces.switch');
    Route::middleware(['verified', 'platform.admin', 'audit'])->group(function (): void {
        Route::get('/platform', [PlatformDashboardController::class, 'index'])->name('platform.dashboard');
        Route::get('/platform/tenants/export', [PlatformDashboardController::class, 'exportTenants'])->name('platform.tenants.export');
    });
});

Route::middleware(['auth', 'verified', 'tenant'])->group(function (): void {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::middleware(['tenant.role:business_admin', 'audit'])->group(function (): void {
        Route::get('/business/profile', [BusinessProfileController::class, 'edit'])->name('business.profile.edit');
        Route::put('/business/profile', [BusinessProfileController::class, 'update'])->name('business.profile.update');
        Route::get('/business/hours', [BusinessHoursController::class, 'edit'])->name('business.hours.edit');
        Route::put('/business/hours', [BusinessHoursController::class, 'update'])->name('business.hours.update');
        Route::get('/business/holidays', [BusinessHolidayController::class, 'index'])->name('business.holidays.index');
        Route::post('/business/holidays', [BusinessHolidayController::class, 'store'])->name('business.holidays.store');
        Route::put('/business/holidays/{holiday}', [BusinessHolidayController::class, 'update'])->name('business.holidays.update');
        Route::delete('/business/holidays/{holiday}', [BusinessHolidayController::class, 'destroy'])->name('business.holidays.destroy');
        Route::get('/business/services', [ServiceController::class, 'index'])->name('business.services.index');
        Route::post('/business/services', [ServiceController::class, 'store'])->name('business.services.store');
        Route::put('/business/services/{service}', [ServiceController::class, 'update'])->name('business.services.update');
        Route::delete('/business/services/{service}', [ServiceController::class, 'destroy'])->name('business.services.destroy');
        Route::get('/business/staff', [StaffController::class, 'index'])->name('business.staff.index');
        Route::post('/business/staff', [StaffController::class, 'store'])->name('business.staff.store');
        Route::put('/business/staff/{staff}', [StaffController::class, 'update'])->name('business.staff.update');
        Route::delete('/business/staff/{staff}', [StaffController::class, 'destroy'])->name('business.staff.destroy');
        Route::get('/business/staff/{staff}/hours', [StaffWorkingHoursController::class, 'edit'])->name('business.staff.hours.edit');
        Route::put('/business/staff/{staff}/hours', [StaffWorkingHoursController::class, 'update'])->name('business.staff.hours.update');
        Route::get('/business/faqs', [FaqController::class, 'index'])->name('business.faqs.index');
        Route::post('/business/faqs', [FaqController::class, 'store'])->name('business.faqs.store');
        Route::put('/business/faqs/{faq}', [FaqController::class, 'update'])->name('business.faqs.update');
        Route::delete('/business/faqs/{faq}', [FaqController::class, 'destroy'])->name('business.faqs.destroy');
        Route::get('/business/calendar', [GoogleCalendarConnectionController::class, 'index'])->name('business.calendar.index');
        Route::get('/business/calendar/calendars', [GoogleCalendarConnectionController::class, 'calendars'])->name('business.calendar.calendars');
        Route::put('/business/calendar/default', [GoogleCalendarConnectionController::class, 'updateDefaultCalendar'])->name('business.calendar.default.update');
        Route::put('/business/calendar/staff/{staff}', [GoogleCalendarConnectionController::class, 'updateStaffCalendar'])->name('business.calendar.staff.update');
        Route::get('/business/calendar/connect', [GoogleCalendarConnectionController::class, 'connect'])->middleware('throttle:5,1')->name('business.calendar.connect');
        Route::get('/business/calendar/callback', [GoogleCalendarConnectionController::class, 'callback'])->middleware('throttle:10,1')->name('business.calendar.callback');
        Route::delete('/business/calendar', [GoogleCalendarConnectionController::class, 'disconnect'])->name('business.calendar.disconnect');
        Route::get('/business/whatsapp', [WhatsAppConnectionController::class, 'index'])->name('business.whatsapp.index');
        Route::post('/business/whatsapp', [WhatsAppConnectionController::class, 'store'])->name('business.whatsapp.store');
        Route::put('/business/whatsapp/{connection}', [WhatsAppConnectionController::class, 'update'])->name('business.whatsapp.update');
        Route::post('/business/whatsapp/{connection}/verify', [WhatsAppConnectionController::class, 'verify'])->middleware('throttle:5,1')->name('business.whatsapp.verify');
        Route::post('/business/whatsapp/{connection}/templates/sync', [WhatsAppConnectionController::class, 'syncTemplates'])->middleware('throttle:5,1')->name('business.whatsapp.templates.sync');
        Route::delete('/business/whatsapp/{connection}', [WhatsAppConnectionController::class, 'destroy'])->name('business.whatsapp.destroy');
    });
    Route::middleware(['tenant.role:business_admin,staff', 'audit'])->group(function (): void {
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [ConversationController::class, 'show'])->name('conversations.show');
        Route::post('/conversations/{conversation}/take', [ConversationController::class, 'take'])->name('conversations.take');
        Route::post('/conversations/{conversation}/release', [ConversationController::class, 'release'])->name('conversations.release');
        Route::post('/conversations/{conversation}/handover', [ConversationController::class, 'requestHandover'])->name('conversations.handover');
        Route::post('/conversations/{conversation}/reply', [ConversationController::class, 'reply'])->middleware('throttle:60,1')->name('conversations.reply');
        Route::post('/conversations/{conversation}/notes', [ConversationController::class, 'addNote'])->middleware('throttle:30,1')->name('conversations.notes.store');
        Route::get('/business/appointments', [AppointmentController::class, 'index'])->name('business.appointments.index');
        Route::get('/business/appointments/availability', [AppointmentController::class, 'availability'])->name('business.appointments.availability');
        Route::post('/business/appointments/holds', [AppointmentController::class, 'createHold'])->middleware('throttle:30,1')->name('business.appointments.holds.store');
        Route::post('/business/appointments/{appointment}/cancel-hold', [AppointmentController::class, 'cancelHold'])->middleware('throttle:30,1')->name('business.appointments.holds.cancel');
        Route::middleware('tenant.role:business_admin')->group(function (): void {
            Route::put('/business/appointments/policy', [BookingPolicyController::class, 'update'])->name('business.appointments.policy.update');
            Route::post('/business/appointments/{appointment}/confirm-calendar', [AppointmentController::class, 'confirmCalendar'])->middleware('throttle:10,1')->name('business.appointments.confirm-calendar');
            Route::post('/business/appointments/{appointment}/cancel', [AppointmentController::class, 'cancelConfirmed'])->name('business.appointments.cancel');
            Route::post('/business/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('throttle:10,1')->name('business.appointments.reschedule');
            Route::get('/business/staff/{staff}/exceptions', [StaffAvailabilityExceptionController::class, 'index'])->name('business.staff.exceptions.index');
            Route::post('/business/staff/{staff}/exceptions', [StaffAvailabilityExceptionController::class, 'store'])->name('business.staff.exceptions.store');
            Route::put('/business/staff/{staff}/exceptions/{exception}', [StaffAvailabilityExceptionController::class, 'update'])->name('business.staff.exceptions.update');
            Route::delete('/business/staff/{staff}/exceptions/{exception}', [StaffAvailabilityExceptionController::class, 'destroy'])->name('business.staff.exceptions.destroy');
            Route::put('/conversations/{conversation}/assign', [ConversationController::class, 'assign'])->name('conversations.assign');
            Route::post('/conversations/{conversation}/close', [ConversationController::class, 'close'])->name('conversations.close');
            Route::post('/conversations/{conversation}/reopen', [ConversationController::class, 'reopen'])->name('conversations.reopen');
        });
    });
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
});
