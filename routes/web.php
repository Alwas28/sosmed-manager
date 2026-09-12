<?php

use App\Http\Controllers\BufferController;
use App\Support\AdminMenu;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::get('/', fn () => Auth::check()
    ? redirect()->route('dashboard')
    : redirect()->route('login'));

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard is the universal landing page — available to every signed-in user.
    Volt::route('dashboard', 'pages.dashboard')->name('dashboard');

    Volt::route('kalender', 'pages.calendar.index')->middleware('can:calendar.view')->name('calendar');

    Route::view('profile', 'profile')->name('profile');

    Route::post('logout', function (Request $request) {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');

    // Halaman nyata untuk manajemen akses.
    Volt::route('pengguna', 'pages.users.index')
        ->middleware('can:user.view')
        ->name('users.index');

    Volt::route('role-akses', 'pages.roles.index')
        ->middleware('can:role.view')
        ->name('roles.index');

    Volt::route('akses-kontrol', 'pages.access-control.index')
        ->middleware('can:access.manage')
        ->name('access-control.index');

    // Distribusi — akun social media (read-only, data dari Buffer) & integrasi Buffer.
    Volt::route('social-media', 'pages.social-accounts.index')
        ->middleware('can:social.view')
        ->name('social.accounts');

    Volt::route('buffer', 'pages.buffer.index')
        ->middleware('can:buffer.manage')
        ->name('buffer');

    Route::middleware('can:buffer.manage')->prefix('buffer')->name('buffer.')->group(function () {
        Route::get('connect', [BufferController::class, 'connect'])->name('connect');
        Route::post('connect-token', [BufferController::class, 'connectToken'])->name('connect-token');
        Route::get('callback', [BufferController::class, 'callback'])->name('callback');
        Route::post('sync', [BufferController::class, 'sync'])->name('sync');
        Route::delete('disconnect', [BufferController::class, 'disconnect'])->name('disconnect');
    });

    // Modul Konten — satu komponen daftar, difilter per status lewat nama route.
    Route::middleware('can:content.view')->group(function () {
        Volt::route('konten', 'pages.contents.index')->name('konten.index');
        Volt::route('konten/draft', 'pages.contents.index')->name('konten.draft');
        Volt::route('konten/menunggu-approval', 'pages.contents.index')->name('konten.approval');
        Volt::route('konten/revisi', 'pages.contents.index')->name('konten.revision');
        Volt::route('konten/disetujui', 'pages.contents.index')->name('konten.approved');
        Volt::route('konten/terjadwal', 'pages.contents.index')->name('konten.scheduled');
        Volt::route('konten/dipublikasikan', 'pages.contents.index')->name('konten.published');
        Volt::route('konten/gagal', 'pages.contents.index')->name('konten.failed');
    });
    Volt::route('konten/buat', 'pages.contents.form')->middleware('can:content.create')->name('konten.create');
    Volt::route('konten/{content}/ubah', 'pages.contents.form')->middleware('can:content.edit')->name('konten.edit');
    Volt::route('konten/{content}', 'pages.contents.show')->middleware('can:content.view')->name('konten.show');

    // Modul Media
    Volt::route('media', 'pages.media.index')->middleware('can:media.view')->name('media');

    // Approval workflow
    Volt::route('approval', 'pages.approval.queue')->middleware('can:approval.view')->name('approval.queue');

    // Pengaturan — Integrasi AI
    Volt::route('pengaturan/ai', 'pages.settings.ai')->middleware('can:ai.manage')->name('ai.settings');
    Volt::route('pengaturan/generate-gambar', 'pages.settings.image-ai')->middleware('can:ai.manage')->name('image-ai.settings');
    Volt::route('pengaturan/template-postingan', 'pages.settings.post-template')->middleware('can:ai.manage')->name('post-template.settings');

    /*
     | Modul SIM_Sosmed lain — masih placeholder (mengikuti roadmap Blueprint).
     | Route, izin, judul, dan menunya semua berasal dari App\Support\AdminMenu
     | supaya sidebar & proteksi route tidak pernah berbeda. Route yang sudah
     | didefinisikan di atas dilewati otomatis.
     */
    Route::getRoutes()->refreshNameLookups();

    foreach (AdminMenu::sections() as $items) {
        foreach ($items as $item) {
            if (Route::has($item['route'])) {
                continue;
            }

            $route = Route::view($item['uri'], 'admin.placeholder', ['title' => $item['label']])
                ->name($item['route']);

            if ($item['permission'] !== null) {
                $route->middleware("can:{$item['permission']}");
            }
        }
    }
});

require __DIR__.'/auth.php';
