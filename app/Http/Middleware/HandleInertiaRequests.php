<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                    'role' => $user->role,
                    'role_label' => $user->roleLabel(),
                ] : null,
                'abilities' => [
                    'view_batches' => $user?->can('view-batches') ?? false,
                    'view_simulation' => $user?->can('view-simulation') ?? false,
                    'view_planning' => $user?->can('view-planning') ?? false,
                    'manage_planning' => $user?->can('manage-planning') ?? false,
                    'upload_csv' => $user?->can('upload-csv') ?? false,
                ],
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'importReport' => fn () => $request->session()->get('importReport'),
            ],
        ];
    }
}
