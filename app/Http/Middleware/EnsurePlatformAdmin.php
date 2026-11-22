<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $email = mb_strtolower((string) $request->user()?->email);
        $allowedEmails = config('platform.admin_emails', []);

        abort_unless($email !== '' && in_array($email, $allowedEmails, true), 403);

        return $next($request);
    }
}
