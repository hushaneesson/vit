<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards every /vendor/* route (except auth pages). Ensures the request is
 * authenticated on the `client` guard specifically — an admin `web` session
 * never grants access here, and vice versa.
 */
class EnsureClientIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('client')->check()) {
            return redirect()->route('vendor.login');
        }

        $client = Auth::guard('client')->user();

        if ($client->status !== 'active') {
            Auth::guard('client')->logout();

            return redirect()->route('vendor.login')
                ->withErrors(['email' => 'Your account is not active. Please contact your Onboarding Manager.']);
        }

        return $next($request);
    }
}
