<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->active && ! $user->is_admin && $user->household?->active, 422, 'Choose an active resident account.');
        Audit::record('impersonation.started', 'user:'.$user->id, [], $request->user()->id);
        $request->session()->forget(['google_login', 'state', 'plaid_link_token', 'plaid_link_expires', 'plaid_link_update']);
        $request->session()->put('impersonated_user_id', $user->id);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_admin, 403);
        if ($target = $request->session()->pull('impersonated_user_id')) {
            Audit::record('impersonation.ended', 'user:'.$target, [], $request->user()->id);
        }
        $request->session()->regenerate();

        return redirect()->route('admin', ['tab' => 'residents'])->with('status', 'Back to your administrator account.');
    }
}
