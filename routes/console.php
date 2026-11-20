<?php

use App\Services\Content\ContentApprovalService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('content:auto-publish', function () {
    $count = app(ContentApprovalService::class)->publishDue();
    $this->info($count === 0
        ? 'Tidak ada konten yang jatuh tempo untuk publikasi otomatis.'
        : $count.' konten dipublikasikan otomatis.');
})->purpose('Publikasikan konten yang melewati batas 10 menit Pimpinan Redaksi');

Schedule::command('content:auto-publish')
    ->everyMinute()
    ->withoutOverlapping();
