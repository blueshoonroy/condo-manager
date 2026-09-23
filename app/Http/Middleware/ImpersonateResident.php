<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class ImpersonateResident
{
    public function handle(Request $request, Closure $next): Response
    {
        $target = $request->session()->get('impersonated_user_id');
        if (! $target) {
            return $next($request);
        }
        $administrator = Auth::user();
        if (! $administrator?->is_admin || ! $administrator->active || ! $administrator->household?->active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }
        if ($request->routeIs('impersonation.stop', 'logout')) {
            return $next($request);
        }
        $resident = User::whereKey($target)->where('active', true)->whereHas('household', fn ($query) => $query->where('active', true))->first();
        if (! $resident) {
            $request->session()->forget('impersonated_user_id');

            return redirect()->route('admin', ['tab' => 'residents'])->with('status', 'Resident preview ended because this account is no longer available.');
        }
        abort_unless(in_array($request->method(), ['GET', 'HEAD'], true) && ! $request->routeIs('google.*'), 403, 'Resident preview is read-only. Return to your administrator account to make changes.');

        /** Keep the authenticated session owned by the administrator; substitute the resident only for this request. */
        Auth::setUser($resident);
        try {
            return $next($request);
        } finally {
            Auth::setUser($administrator);
        }
    }
}
