<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\DonorAccountCreated;
use App\Rules\IdentityNumber;
use App\Support\IdentityProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Admin-managed accounts: a donor who gave by bank transfer, or one whose
 * email changed and whose history needs a home.
 */
class UserController extends Controller
{
    public function create(): View
    {
        return view('admin.user-form', ['user' => new User]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = new User;
        $user->fill($this->profileAttributes($data));
        // Unguessable and never shown; the donor sets their own through the
        // emailed link, exactly as with an account created after a guest gift.
        $user->password = filled($data['password'] ?? null) ? $data['password'] : Str::password(32);
        $user->save();

        if ($request->boolean('is_admin')) {
            $user->promoteToAdmin();
        }

        $this->inviteIfRequested($request, $user);

        return redirect()->route('admin.users.show', $user)->with('status', 'Account created.');
    }

    public function edit(User $user): View
    {
        return view('admin.user-form', ['user' => $user]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        $user->fill($this->profileAttributes($data));

        if (filled($data['password'] ?? null)) {
            $user->password = $data['password'];
        }

        $user->save();

        // An admin cannot demote themselves: it would lock them out mid-request
        // and could leave the trust with no admin at all.
        if ($request->boolean('is_admin')) {
            $user->promoteToAdmin();
        } elseif ($user->isNot($request->user())) {
            $user->revokeAdmin();
        }

        $this->inviteIfRequested($request, $user);

        return redirect()->route('admin.users.show', $user)->with('status', 'Account updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?User $user = null): array
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            // Optional, unlike the donor's own profile: an admin often knows no
            // more than a name and an address to email.
            'phone' => ['nullable', 'string', 'max:20'],
            'address_line1' => ['nullable', 'string', 'max:255'],
            'address_line2' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'size:2'],
            'id_type' => ['nullable', Rule::in(array_keys(IdentityProof::TYPES))],
            'id_number' => ['nullable', 'string', 'max:32', new IdentityNumber($request->input('id_type'))],
            'is_admin' => ['nullable', 'boolean'],
            'send_invite' => ['nullable', 'boolean'],
        ], [], [
            'id_type' => 'identity proof',
            'id_number' => 'identity number',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function profileAttributes(array $data): array
    {
        $attributes = collect($data)->only([
            'name', 'email', 'phone', 'address_line1', 'address_line2',
            'city', 'state', 'postal_code', 'country',
        ])->all();

        $attributes['id_number'] = filled($data['id_number'] ?? null)
            ? IdentityProof::normalise($data['id_number'])
            : null;
        $attributes['id_type'] = $attributes['id_number'] ? $data['id_type'] : null;

        return $attributes;
    }

    private function inviteIfRequested(Request $request, User $user): void
    {
        if ($request->boolean('send_invite')) {
            $user->notify(new DonorAccountCreated(Password::broker()->createToken($user)));
        }
    }
}
