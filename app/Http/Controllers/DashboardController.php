<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        return view('dashboard', [
            'user' => $user,
            'activeSubscriptions' => $user->activeSubscriptions()->with('plan')->get(),
            'recentTransactions' => $user->transactions()->latest()->limit(5)->get(),
            'totalGiven' => $user->transactions()->paid()->sum('amount'),
            'currency' => config('payments.default_currency'),
        ]);
    }

    public function profile(Request $request)
    {
        return view('profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'address_line1' => ['required', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['required', 'string', 'max:20'],
            'country' => ['required', 'string', 'size:2'],
            'pan' => ['nullable', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]$/'],
        ], [
            'pan.regex' => 'A PAN looks like ABCDE1234F.',
        ]);

        $request->user()->update($data);

        return back()->with('status', 'Details saved.');
    }
}
