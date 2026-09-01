<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Notifications\ClientOtpNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Client (vendor-side) authentication. Clients never have a password and
 * log in for every session using a freshly emailed one-time code (OTP).
 */
class ClientAuthController extends Controller
{
    public function showLoginForm()
    {
        return view('vendor.auth.login');
    }

    /**
     * Step 1: client submits their email address, we email them a 6-digit
     * OTP if an active client record exists for that address.
     */
    public function sendOtp(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $client = Client::where('email', $request->email)
            ->whereIn('status', ['active', 'invited'])
            ->first();

        // Always respond the same way whether or not the email matched, to
        // avoid leaking which addresses are registered.
        if ($client) {
            if ($client->status === 'invited') {
                $client->forceFill([
                    'status' => 'active',
                    'activated_at' => $client->activated_at ?? now(),
                    'invited_at' => $client->invited_at ?? now(),
                ])->save();
            }

            $code = $client->generateOtp();
            $client->notify(new ClientOtpNotification($code));
        }

        return redirect()
            ->route('vendor.login.otp', ['email' => $request->email])
            ->with('status', 'If that email address is registered, a login code has been sent.');
    }

    public function showOtpForm(Request $request)
    {
        return view('vendor.auth.otp', ['email' => $request->query('email')]);
    }

    /**
     * Step 2: client submits the OTP they received; on success we log them
     * in on the dedicated `client` guard (never the admin `web` guard).
     */
    public function verifyOtp(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'string'],
        ]);

        $client = Client::where('email', $request->email)
            ->whereIn('status', ['active', 'invited'])
            ->where('otp_code', $request->code)
            ->first();

        if (! $client || ! $client->otp_expires_at || $client->otp_expires_at->isPast()) {
            throw ValidationException::withMessages([
                'code' => 'That code is invalid or has expired. Please request a new one.',
            ]);
        }

        $client->forceFill([
            'status' => 'active',
            'activated_at' => $client->activated_at ?? now(),
            'invited_at' => $client->invited_at ?? now(),
            'otp_code' => null,
            'otp_expires_at' => null,
            'last_login_at' => now(),
        ])->save();

        Auth::guard('client')->login($client);
        $request->session()->regenerate();

        return redirect()->route('vendor.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('client')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.login');
    }
}
