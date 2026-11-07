<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'dev' => \App\Support\DevMode::enabled(),
            'admin' => fn () => $request->is(config('admin.path'), config('admin.path').'/*') && $request->user()
                ? ['name' => $request->user()->name, 'email' => $request->user()->email, 'path' => config('admin.path')]
                : null,
            'flash' => [
                'admin_flash' => fn () => $request->session()->get('admin_flash'),
                'admin_test' => fn () => $request->session()->get('admin_test'),
                'admin_token' => fn () => $request->session()->get('admin_token'),
                'dev_message' => fn () => $request->session()->get('dev_message'),
                'registration' => fn () => $request->session()->get('registration'),
            ],
        ]);
    }
}
