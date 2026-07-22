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
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function createLogin(): Response
    {
        return Inertia::render('auth/ClientLoginPage');
    }

    /**
     * @throws ValidationException
     */
    public function storeLogin(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['nullable', 'boolean'],
        ]);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
        ], $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => 'The provided credentials do not match a client admin account.',
            ]);
        }

        $request->session()->regenerate();

        if ($request->user()?->is_admin || ! $request->user()?->client_account_id) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => 'This login is restricted to client admin accounts.',
            ]);
        }

        return redirect()
            ->intended(route('client.dashboard'))
            ->with('success', 'Welcome back. Your DTimer WiFi account is ready.');
    }

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
            ->with('success', 'Client admin account created. Claim a license to start linking DTimer WiFi machines.');
    }
}
