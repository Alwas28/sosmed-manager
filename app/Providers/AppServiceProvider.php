<?php

namespace App\Providers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
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
        Event::listen(Login::class, fn (Login $event) => ActivityLog::record('login', 'Masuk ke sistem.', userId: $event->user->getAuthIdentifier()));
        Event::listen(Logout::class, function (Logout $event) {
            if ($event->user) {
                ActivityLog::record('logout', 'Keluar dari sistem.', userId: $event->user->getAuthIdentifier());
            }
        });

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
