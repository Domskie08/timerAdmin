<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $account = $request->user()->clientAccount()->firstOrFail();

        return Inertia::render('client/SettingsPage', [
            'account' => [
                'id' => $account->id,
                'name' => $account->name,
                'contactEmail' => $account->contact_email,
            ],
            'profile' => [
                'name' => $request->user()->name,
                'email' => $request->user()->email,
            ],
        ]);
    }

    public function updatePassword(UpdatePasswordRequest $request): RedirectResponse
    {
        $request->user()->forceFill([
            'password' => $request->string('password')->toString(),
        ])->save();

        return redirect()
            ->route('client.settings')
            ->with('success', 'Password changed successfully.');
    }
}
