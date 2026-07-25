<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function createRegister(): Response
    {
        return Inertia::render('auth/ClientRegisterPage');
    }

    public function storeRegister(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'account_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = DB::transaction(function () use ($validated): User {
            $account = ClientAccount::query()->create([
                'name' => $validated['account_name'],
                'contact_email' => $validated['email'],
            ]);

            return User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'is_admin' => false,
                'client_account_id' => $account->id,
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()
            ->route('client.dashboard')
            ->with('success', 'Client admin account created. Claim a license to start linking devices.');
    }
}
