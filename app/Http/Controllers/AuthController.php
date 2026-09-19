<?php

namespace App\Http\Controllers;

use App\Mail\LoginCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function requestCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']]);
        $email = strtolower(trim($data['email']));
        $key = 'login-email:'.hash('sha256', $email);
        $challenge = (string) Str::uuid();
        $user = User::where('email', $email)->where('active', true)->whereHas('household', fn ($q) => $q->where('active', true))->first();
        if (! RateLimiter::tooManyAttempts($key, 3)) {
            RateLimiter::hit($key, 600);
            if ($user) {
                $code = (string) random_int(100000, 999999);
                DB::transaction(function () use ($user, $challenge, $code) {
                    User::whereKey($user->id)->lockForUpdate()->first();
                    DB::table('login_challenges')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
                    DB::table('login_challenges')->insert([
                        'id' => $challenge, 'user_id' => $user->id, 'code_hash' => Hash::make($code),
                        'expires_at' => now()->addMinutes(10), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
                try {
                    Mail::to($user->email)->send(new LoginCode($code));
                } catch (\Throwable $exception) {
                    DB::table('login_challenges')->where('id', $challenge)->update(['used_at' => now()]);
                    report($exception);
                }
            }
        }
        $request->session()->put('login_challenge', $challenge);

        return redirect()->route('login.verify')->with('status', 'If your email is registered, a sign-in code will arrive shortly.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = DB::transaction(function () use ($request) {
            $challenge = DB::table('login_challenges')->where('id', $request->session()->get('login_challenge', ''))->lockForUpdate()->first();
            if (! $challenge || $challenge->used_at || $challenge->attempts >= 5 || now()->gte($challenge->expires_at)) {
                return null;
            }
            DB::table('login_challenges')->where('id', $challenge->id)->increment('attempts');
            if (! Hash::check($request->string('code')->toString(), $challenge->code_hash)) {
                return null;
            }
            DB::table('login_challenges')->where('id', $challenge->id)->update(['used_at' => now()]);

            return User::whereKey($challenge->user_id)->where('active', true)->whereHas('household', fn ($q) => $q->where('active', true))->first();
        });
        if (! $user) {
            throw ValidationException::withMessages(['code' => 'This code is invalid or expired. Request a new code to try again.']);
        }
        Auth::login($user);
        $request->session()->forget('login_challenge');
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
