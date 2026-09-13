<?php

namespace App\Http\Controllers;

use App\Rules\IdentityNumber;
use App\Support\IdentityProof;

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
            // Optional here, unlike at checkout: the profile is not itself a
            // donation, and the requirement belongs to the INR payment.
            'id_type' => ['nullable', \Illuminate\Validation\Rule::in(array_keys(IdentityProof::TYPES))],
            'id_number' => [
                'nullable', 'string', 'max:32',
                new IdentityNumber($request->input('id_type')),
            ],
        ], [], [
            'id_type' => 'identity proof',
            'id_number' => 'identity number',
        ]);

        $data['id_number'] = filled($data['id_number'] ?? null)
            ? IdentityProof::normalise($data['id_number'])
            : null;

        if (blank($data['id_number'])) {
            $data['id_type'] = null;
        }

        $request->user()->update($data);

        return back()->with('status', 'Details saved.');
    }
}
