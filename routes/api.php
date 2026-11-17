<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| API ini memakai API key statis dari .env dan sesi web Laravel yang sama
| dengan aplikasi Livewire. Tidak ada perubahan skema database, migration,
| atau seeder.
|
*/

Route::middleware('api.key')->group(function () {
    Route::post('/login', [AuthController::class, 'login'])
        ->name('api.login');

    Route::middleware('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout'])
            ->name('api.logout');
        Route::get('/me', [AuthController::class, 'me'])
            ->name('api.me');

        Route::get('/reference', [ReferenceController::class, 'index'])
            ->name('api.reference');

        Route::get('/contents', [ContentController::class, 'index'])
            ->middleware('can:content.view')
            ->name('api.contents.index');
        Route::post('/contents', [ContentController::class, 'store'])
            ->middleware('can:content.create')
            ->name('api.contents.store');
        Route::get('/contents/{content}', [ContentController::class, 'show'])
            ->middleware('can:content.view')
            ->name('api.contents.show');
        Route::put('/contents/{content}', [ContentController::class, 'update'])
            ->middleware('can:content.edit')
            ->name('api.contents.update');
        Route::delete('/contents/{content}', [ContentController::class, 'destroy'])
            ->middleware('can:content.delete')
            ->name('api.contents.destroy');

        Route::get('/contents/{content}/logs', [ContentController::class, 'logs'])
            ->middleware('can:content.view')
            ->name('api.contents.logs');
        Route::post('/contents/{content}/submit', [ContentController::class, 'submit'])
            ->middleware('can:content.submit')
            ->name('api.contents.submit');
        Route::post('/contents/{content}/approve', [ContentController::class, 'approve'])
            ->middleware('can:approval.approve')
            ->name('api.contents.approve');
        Route::post('/contents/{content}/revision', [ContentController::class, 'requestRevision'])
            ->middleware('can:approval.reject')
            ->name('api.contents.revision');
        Route::post('/contents/{content}/schedule', [ContentController::class, 'schedule'])
            ->middleware('can:schedule.manage')
            ->name('api.contents.schedule');
        Route::post('/contents/{content}/publish', [ContentController::class, 'publish'])
            ->middleware('can:publish.manage')
            ->name('api.contents.publish');

        Route::get('/activity-logs', [ActivityController::class, 'index'])
            ->middleware('can:log.view')
            ->name('api.activity-logs.index');

        Route::get('/reports/activity', [ReportController::class, 'activity'])
            ->middleware('can:report.view')
            ->name('api.reports.activity');
        Route::get('/reports/users', [ReportController::class, 'users'])
            ->middleware('can:report.view')
            ->name('api.reports.users');

        Route::get('/access-control', [AccessControlController::class, 'index'])
            ->middleware('can:access.manage')
            ->name('api.access-control.index');
        Route::put('/access-control/roles/{role}', [AccessControlController::class, 'update'])
            ->middleware('can:access.manage')
            ->name('api.access-control.roles.update');
    });
});
