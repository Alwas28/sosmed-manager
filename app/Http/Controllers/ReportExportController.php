<?php

namespace App\Http\Controllers;

use App\Enums\ContentCategory;
use App\Enums\Platform;
use App\Models\Content;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV export for the Laporan page — same date-range filter, streamed so it never has to hold the whole file in memory. */
class ReportExportController extends Controller
{
    public function export(Request $request): StreamedResponse
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')) : now()->subDays(29);
        $to = $request->filled('to') ? Carbon::parse($request->string('to')) : now();

        $contents = Content::query()
            ->with(['creator', 'platforms'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->oldest('created_at')
            ->get();

        $filename = 'laporan-konten-'.$from->format('Ymd').'-'.$to->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($contents) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Judul', 'Kategori', 'Status', 'Platform', 'Dibuat Oleh', 'Tanggal Dibuat', 'Jadwal', 'Dipublikasikan']);

            foreach ($contents as $content) {
                fputcsv($out, [
                    $content->title,
                    ($content->category ?? ContentCategory::Umum)->label(),
                    $content->status->label(),
                    $content->platforms->pluck('platform')->map(fn ($p) => Platform::tryLabel($p))->implode(', '),
                    $content->creator?->name ?? '—',
                    $content->created_at->format('d-m-Y H:i'),
                    $content->scheduled_at?->format('d-m-Y H:i') ?? '—',
                    $content->published_at?->format('d-m-Y H:i') ?? '—',
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
