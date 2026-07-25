<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/LoginPage');
    }

    /**
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
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
                'email' => 'The provided credentials do not match an admin or client account.',
            ]);
        }

        $request->session()->regenerate();

        if ($request->user()?->is_admin) {
            return redirect()
                ->route('admin.dashboard')
                ->with('success', 'Welcome back. The license console is ready.');
        }

        if ($request->user()?->client_account_id) {
            return redirect()
                ->route('client.dashboard')
                ->with('success', 'Welcome back. Your client device console is ready.');
        }

        Auth::logout();

        throw ValidationException::withMessages([
            'email' => 'This login is restricted to admin and client admin accounts.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('home')
            ->with('success', 'You have been signed out.');
    }
}

