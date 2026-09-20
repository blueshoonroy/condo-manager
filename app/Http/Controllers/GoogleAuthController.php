<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(config('services.google.client_id') && config('services.google.client_secret'), 503, 'Google sign-in is not configured yet.');
        $request->session()->put('google_login', ['expires' => now()->addMinutes(10)->timestamp, 'link_user' => $request->user()?->id]);

        return Socialite::driver('google')->with(['prompt' => 'select_account'])->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $flow = $request->session()->pull('google_login');
        try {
            if (! $flow || $flow['expires'] < now()->timestamp || $request->has('error')) {
                throw new \RuntimeException;
            }
            // Socialite checks the session's one-time OAuth state before exchanging the code.
            $google = Socialite::driver('google')->user();
            $email = strtolower((string) $google->getEmail());
            $verified = ($google->user['email_verified'] ?? false) === true;
            if (! $verified || ! $google->getId()) {
                throw new \RuntimeException;
            }
            $user = DB::transaction(function () use ($email, $google, $flow, $request) {
                $user = User::where('email', $email)->where('active', true)->whereHas('household', fn ($q) => $q->where('active', true))->lockForUpdate()->first();
                if (! $user || ($flow['link_user'] && $flow['link_user'] !== $request->user()?->id) || ($flow['link_user'] && $flow['link_user'] !== $user->id)) {
                    throw new \RuntimeException;
                }
                if ($user->google_id && $user->google_id !== (string) $google->getId()) {
                    throw new \RuntimeException;
                }
                $authoritative = str_ends_with($email, '@gmail.com') || ! empty($google->user['hd']);
                if (! $user->google_id && ! $flow['link_user'] && ! $authoritative) {
                    throw new \RuntimeException;
                }
                if (! $user->google_id) {
                    $user->forceFill(['google_id' => (string) $google->getId()])->save();
                    Audit::record('resident.google_linked', 'user:'.$user->id, [], $user->id);
                }

                return $user;
            });
        } catch (\Throwable) {
            return redirect()->route('login')->withErrors(['google' => 'Google sign-in could not be completed. Use your registered resident email. For a non-Gmail account outside Google Workspace, sign in with a code first, then choose Link Google.']);
        }
        Auth::login($user);
        $request->session()->forget('login_challenge');
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
