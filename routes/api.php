<?php

use App\Http\Controllers\Api\AccessControlController;
use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContentController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\SettingsController;
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
        Route::put('/users/{user}/active', [UsersController::class, 'updateActive'])
            ->middleware('can:user.manage')
            ->name('api.users.active.update');
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

        // Profil (auth only, seperti web)
        Route::get('/profile', [ProfileController::class, 'show'])->name('api.profile.show');
        Route::put('/profile', [ProfileController::class, 'updateInformation'])->name('api.profile.update');
        Route::put('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('api.profile.avatar');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('api.profile.password');
        Route::post('/profile/resend-verification', [ProfileController::class, 'resendVerification'])->name('api.profile.verify');
        Route::delete('/profile', [ProfileController::class, 'destroy'])->name('api.profile.destroy');

        // Pengaturan (AI / image AI / template)
        Route::get('/settings/ai', [SettingsController::class, 'aiGet'])->middleware('can:ai.manage')->name('api.settings.ai');
        Route::put('/settings/ai', [SettingsController::class, 'aiSave'])->middleware('can:ai.manage')->name('api.settings.ai.save');
        Route::post('/settings/ai/test', [SettingsController::class, 'aiTest'])->middleware('can:ai.manage')->name('api.settings.ai.test');
        Route::get('/settings/image-ai', [SettingsController::class, 'imageIndex'])->middleware('can:image_ai.manage')->name('api.settings.image');
        Route::post('/settings/image-ai', [SettingsController::class, 'imageStore'])->middleware('can:image_ai.manage')->name('api.settings.image.store');
        Route::put('/settings/image-ai/{image}', [SettingsController::class, 'imageUpdate'])->middleware('can:image_ai.manage')->name('api.settings.image.update');
        Route::post('/settings/image-ai/{image}/toggle', [SettingsController::class, 'imageToggle'])->middleware('can:image_ai.manage')->name('api.settings.image.toggle');
        Route::delete('/settings/image-ai/{image}', [SettingsController::class, 'imageDestroy'])->middleware('can:image_ai.manage')->name('api.settings.image.destroy');
        Route::get('/settings/template', [SettingsController::class, 'templateGet'])->middleware('can:template.manage')->name('api.settings.template');
        Route::put('/settings/template', [SettingsController::class, 'templateSave'])->middleware('can:template.manage')->name('api.settings.template.save');
        Route::delete('/settings/template/logo', [SettingsController::class, 'templateRemoveLogo'])->middleware('can:template.manage')->name('api.settings.template.logo');
        Route::delete('/settings/template/font', [SettingsController::class, 'templateRemoveFont'])->middleware('can:template.manage')->name('api.settings.template.font');
        Route::get('/profile/preferences', [SettingsController::class, 'preferencesGet'])->name('api.profile.preferences');
        Route::put('/profile/preferences', [SettingsController::class, 'preferencesSave'])->name('api.profile.preferences.save');
        Route::get('/settings/general', [SettingsController::class, 'generalGet'])->middleware('can:template.manage')->name('api.settings.general');
        Route::put('/settings/general', [SettingsController::class, 'generalSave'])->middleware('can:template.manage')->name('api.settings.general.save');
        Route::get('/settings/workflow', [SettingsController::class, 'workflowGet'])->middleware('can:template.manage')->name('api.settings.workflow');
        Route::put('/settings/workflow', [SettingsController::class, 'workflowSave'])->middleware('can:template.manage')->name('api.settings.workflow.save');

        // Analitik, laporan, kalender, kinerja, log
        Route::get('/dashboard', [AnalyticsController::class, 'dashboard'])->name('api.dashboard');
        Route::get('/reports/overview', [AnalyticsController::class, 'reportsOverview'])->middleware('can:report.view')->name('api.reports.overview');
        Route::get('/reports/export', [AnalyticsController::class, 'reportsExport'])->middleware('can:report.export')->name('api.reports.export');
        Route::get('/performance', [AnalyticsController::class, 'performance'])->middleware('can:log.view')->name('api.performance');
        Route::get('/calendar', [AnalyticsController::class, 'calendar'])->middleware('can:calendar.view')->name('api.calendar');
        Route::get('/logs', [AnalyticsController::class, 'logs'])->middleware('can:log.view')->name('api.logs');
    });
});
