<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::before(function (User $user, string $ability) {
            // Administrator lolos setiap pemeriksaan.
            if ($user->isAdministrator()) {
                return true;
            }

            // Ability ber-titik (mis. "content.view", "role.manage") diperlakukan
            // sebagai permission slug dan diselesaikan lewat role pengguna.
            if (str_contains($ability, '.')) {
                return $user->hasPermission($ability);
            }

            return null;
        });
    }
}
