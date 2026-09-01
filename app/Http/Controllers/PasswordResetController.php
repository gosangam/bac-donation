<?php

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

class PasswordResetController extends Controller
{
    public function request()
    {
        return view('auth.forgot-password');
    }

    public function email(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        // Always the same answer, whether or not the address is registered:
        // a different response here is an account-enumeration oracle.
        return back()->with('status',
            'If that email is registered, a reset link is on its way.');
    }

    public function reset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function update(Request $request)
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            return back()->withInput($request->only('email'))
                ->withErrors(['email' => __($status)]);
        }

        // Signing them in here is safe: setting a password with a valid,
        // single-use token proves control of the mailbox.
        $user = \App\Models\User::where('email', $request->email)->first();
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->is_admin ? 'admin.index' : 'dashboard')
            ->with('status', 'Password set. Welcome.');
    }
}
