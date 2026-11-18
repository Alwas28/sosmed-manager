<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\RolesController;
use App\Http\Controllers\Api\UsersController;
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
        Route::post('/contents/{content}/withdraw', [ContentController::class, 'withdraw'])
            ->middleware('can:content.submit')
            ->name('api.contents.withdraw');
        Route::post('/contents/{content}/cancel-schedule', [ContentController::class, 'cancelSchedule'])
            ->middleware('can:schedule.manage')
            ->name('api.contents.cancel-schedule');

        Route::get('/media', [MediaController::class, 'index'])
            ->middleware('can:media.view')
            ->name('api.media.index');
        Route::post('/media', [MediaController::class, 'store'])
            ->middleware('can:media.upload')
            ->name('api.media.store');
        Route::delete('/media/{media}', [MediaController::class, 'destroy'])
            ->middleware('can:media.delete')
            ->name('api.media.destroy');

        Route::get('/social-accounts', [IntegrationController::class, 'social'])
            ->middleware('can:social.view')
            ->name('api.social-accounts.index');
        Route::get('/buffer/status', [IntegrationController::class, 'bufferStatus'])
            ->middleware('can:buffer.manage')
            ->name('api.buffer.status');
        Route::get('/buffer/connect-url', [IntegrationController::class, 'bufferConnectUrl'])
            ->middleware('can:buffer.manage')
            ->name('api.buffer.connect-url');
        Route::post('/buffer/connect-token', [IntegrationController::class, 'bufferConnectToken'])
            ->middleware('can:buffer.manage')
            ->name('api.buffer.connect-token');
        Route::post('/buffer/sync', [IntegrationController::class, 'bufferSync'])
            ->middleware('can:buffer.manage')
            ->name('api.buffer.sync');
        Route::post('/buffer/disconnect', [IntegrationController::class, 'bufferDisconnect'])
            ->middleware('can:buffer.manage')
            ->name('api.buffer.disconnect');

        Route::get('/activity-logs', [ActivityController::class, 'index'])
            ->middleware('can:log.view')
            ->name('api.activity-logs.index');

        Route::get('/reports/activity', [ReportController::class, 'activity'])
            ->middleware('can:report.view')
            ->name('api.reports.activity');
        Route::get('/reports/users', [ReportController::class, 'users'])
            ->middleware('can:report.view')
            ->name('api.reports.users');

        Route::get('/users', [UsersController::class, 'index'])
            ->middleware('can:user.view')
            ->name('api.users.index');
        Route::put('/users/{user}/role', [UsersController::class, 'updateRole'])
            ->middleware('can:user.manage')
            ->name('api.users.role.update');
        Route::put('/users/{user}/platforms', [UsersController::class, 'updatePlatforms'])
            ->middleware('can:user.platform')
            ->name('api.users.platforms.update');

        Route::get('/roles', [RolesController::class, 'index'])
            ->middleware('can:role.view')
            ->name('api.roles.index');
        Route::post('/roles', [RolesController::class, 'store'])
            ->middleware('can:role.manage')
            ->name('api.roles.store');
        Route::put('/roles/{role}', [RolesController::class, 'update'])
            ->middleware('can:role.manage')
            ->name('api.roles.update');
        Route::delete('/roles/{role}', [RolesController::class, 'destroy'])
            ->middleware('can:role.manage')
            ->name('api.roles.destroy');

        Route::get('/access-control', [AccessControlController::class, 'index'])
            ->middleware('can:access.manage')
            ->name('api.access-control.index');
        Route::put('/access-control/roles/{role}', [AccessControlController::class, 'update'])
            ->middleware('can:access.manage')
            ->name('api.access-control.roles.update');
    });
});
