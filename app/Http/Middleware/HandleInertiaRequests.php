<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'appName' => config('app.name'),
            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email', 'is_admin', 'client_account_id'),
            ],
            'csrfToken' => csrf_token(),
            'flash' => [
                'success' => fn (): ?string => $request->session()->get('success'),
                'error' => fn (): ?string => $request->session()->get('error'),
            ],
            'formState' => [
                'licenseDuration' => fn (): ?string => $request->session()->getOldInput('duration'),
                'renewLicenseCode' => fn (): ?string => $request->session()->getOldInput('renew_license_code'),
                'renewTargetLicenseId' => fn (): ?string => $request->session()->getOldInput('target_license_id'),
                'updateExternalDownloadUrl' => fn (): ?string => $request->session()->getOldInput('external_download_url'),
            ],
        ]);
    }
}

