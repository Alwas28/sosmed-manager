<?php

namespace App\Http\Controllers\Api;

use App\Enums\ApprovalDecision;
use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Enums\PostType;
use App\Models\ActivityLog;
use App\Models\Content;
use App\Models\ContentPlatform;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AnalyticsController extends ApiController
{
    // -------------------------------------------------------------------------
    // Dashboard (auth only — no gate, mirroring web)
    // -------------------------------------------------------------------------

    public function dashboard(): JsonResponse
    {
        $statuses = ContentStatus::cases();
        $rawStatus = Content::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $statusCounts = [];
        foreach ($statuses as $status) {
            $statusCounts[$status->value] = (int) ($rawStatus[$status->value] ?? 0);
        }

        $platforms = Platform::cases();
        $rawPlatform = ContentPlatform::query()->selectRaw('platform, count(*) as c')->groupBy('platform')->pluck('c', 'platform');
        $platformCounts = [];
        foreach ($platforms as $platform) {
            $platformCounts[$platform->value] = (int) ($rawPlatform[$platform->value] ?? 0);
        }

        $recent = Content::with('creator:id,name,role_id')->with('creator.role:id,name,slug')->latest()->take(6)->get();
        $upcoming = Content::query()->whereNotNull('scheduled_at')->where('scheduled_at', '>=', now())->orderBy('scheduled_at')->take(5)->get();

        return $this->success([
            'total_content' => array_sum($statusCounts),
            'statuses' => collect($statuses)->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'status_counts' => $statusCounts,
            'platforms' => collect($platforms)->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()])->all(),
            'platform_counts' => $platformCounts,
            'max_platform_count' => max(1, ...array_values($platformCounts)),
            'recent_content' => $recent->map(fn (Content $c) => $this->contentSummary($c))->all(),
            'upcoming' => $upcoming->map(fn (Content $c) => $this->contentSummary($c))->all(),
            'media_count' => Media::count(),
            'user_count' => User::count(),
            'role_count' => Role::count(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Laporan (gate report.view)
    // -------------------------------------------------------------------------

    public function reportsOverview(Request $request): JsonResponse
    {
        Gate::authorize('report.view');
        [$from, $to] = $this->reportRange($request);

        $query = Content::query()->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()]);
        $rangeIds = $query->pluck('id');

        $rawStatus = (clone $query)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $statusCounts = [];
        foreach (ContentStatus::cases() as $status) {
            $statusCounts[$status->value] = (int) ($rawStatus[$status->value] ?? 0);
        }

        $rawCategory = (clone $query)->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');
        $categoryCounts = [];
        foreach (ContentCategory::cases() as $category) {
            $categoryCounts[$category->value] = (int) ($rawCategory[$category->value] ?? 0);
        }

        $rawPlatform = ContentPlatform::whereIn('content_id', $rangeIds)->selectRaw('platform, count(*) as c')->groupBy('platform')->pluck('c', 'platform');
        $platformCounts = [];
        foreach (Platform::cases() as $platform) {
            $platformCounts[$platform->value] = (int) ($rawPlatform[$platform->value] ?? 0);
        }

        $topRows = (clone $query)->selectRaw('created_by, count(*) as c')->whereNotNull('created_by')->groupBy('created_by')->orderByDesc('c')->take(5)->get();
        $topContributors = [];
        foreach ($topRows as $row) {
            $user = User::find($row->created_by);
            if (! $user) {
                continue;
            }
            $topContributors[] = [
                'user' => ['id' => $user->id, 'name' => $user->name],
                'count' => (int) $row->c,
            ];
        }

        $total = array_sum($statusCounts);
        $published = $statusCounts[ContentStatus::Published->value] ?? 0;
        $failed = $statusCounts[ContentStatus::Failed->value] ?? 0;

        $items = (clone $query)->with('creator:id,name,role_id')->with('creator.role:id,name,slug')->with('platforms')
            ->latest('created_at')->paginate(min(max((int) $request->integer('per_page', 15), 1), 100));

        return $this->success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'preset' => (string) $request->input('preset', '30d'),
            'total' => $total,
            'published' => $published,
            'failed' => $failed,
            'success_rate' => ($published + $failed) > 0 ? (int) round($published / ($published + $failed) * 100) : null,
            'statuses' => collect(ContentStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
            'status_counts' => $statusCounts,
            'categories' => collect(ContentCategory::cases())->map(fn ($c) => ['value' => $c->value, 'label' => $c->label()])->all(),
            'category_counts' => $categoryCounts,
            'max_category_count' => max(1, ...array_values($categoryCounts)),
            'platforms' => collect(Platform::cases())->map(fn ($p) => ['value' => $p->value, 'label' => $p->label()])->all(),
            'platform_counts' => $platformCounts,
            'max_platform_count' => max(1, ...array_values($platformCounts)),
            'top_contributors' => $topContributors,
            'items' => array_map(fn (Content $c) => $this->contentSummary($c), $items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    public function reportsExport(Request $request): \Illuminate\Http\Response
    {
        Gate::authorize('report.export');
        [$from, $to] = $this->reportRange($request, true);

        $format = strtolower((string) $request->input('format', 'csv'));
        if (! in_array($format, ['csv', 'xls', 'pdf'], true)) {
            $format = 'csv';
        }

        $contents = Content::with(['creator.role', 'platforms', 'approver', 'media'])
            ->whereBetween('created_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->oldest('created_at')->get();

        $typeLabels = static function (Content $content): string {
            return $content->platforms
                ->map(function ($p) {
                    $type = PostType::tryFrom((string) $p->post_type);
                    $platform = Platform::tryFrom((string) $p->platform);

                    return $type && $platform
                        ? $type->label($platform)
                        : (string) $p->post_type;
                })
                ->filter()
                ->unique()
                ->implode(', ');
        };

        // Ringkasan per akun: satu baris untuk setiap akun yang memiliki konten
        // pada rentang tanggal yang dipilih.
        $summaryGroups = $contents->groupBy(
            fn (Content $c) => $c->created_by ?: 'cold-'.$c->id,
        );
        $summaryRows = [];
        foreach ($summaryGroups as $group) {
            $first = $group->first();
            $statuses = $group->countBy(fn (Content $c) => $c->status->value);
            $platforms = $group
                ->flatMap(fn (Content $c) => $c->platforms->pluck('platform'))
                ->filter()
                ->unique()
                ->map(fn ($p) => Platform::tryLabel($p) ?? $p)
                ->implode(', ');
            $categories = $group
                ->map(fn (Content $c) => ($c->category ?? ContentCategory::Umum)->label())
                ->unique()
                ->implode(', ');
            $types = $group
                ->flatMap(fn (Content $c) => $c->platforms)
                ->map(function ($p) {
                    $type = PostType::tryFrom((string) $p->post_type);
                    $platform = Platform::tryFrom((string) $p->platform);

                    return $type && $platform ? $type->label($platform) : (string) $p->post_type;
                })
                ->filter()
                ->unique()
                ->implode(', ');
            $firstAt = $group->min(fn (Content $c) => $c->created_at?->timestamp ?? 0);
            $lastAt = $group->max(fn (Content $c) => $c->created_at?->timestamp ?? 0);
            $summaryRows[] = [
                $first->creator?->name ?? '—',
                $first->creator?->email ?? '—',
                $first->creator?->role?->name ?? '—',
                $group->count(),
                $statuses->get('draft', 0),
                $statuses->get('pending_approval', 0),
                $statuses->get('revision', 0),
                $statuses->get('approved', 0),
                $statuses->get('scheduled', 0),
                $statuses->get('published', 0),
                $statuses->get('failed', 0),
                $group->filter(fn (Content $c) => $c->isLinkPost())->count(),
                $types,
                $platforms,
                $categories,
                $firstAt ? Carbon::createFromTimestamp($firstAt)->format('d-m-Y H:i') : '—',
                $lastAt ? Carbon::createFromTimestamp($lastAt)->format('d-m-Y H:i') : '—',
            ];
        }

        // Detail seluruh konten pada rentang yang sama.
        $detailRows = [];
        foreach ($contents as $content) {
            $detailRows[] = [
                $content->title,
                ($content->category ?? ContentCategory::Umum)->label(),
                $content->status->label(),
                $typeLabels($content),
                $content->platforms->pluck('platform')->map(fn ($p) => Platform::tryLabel($p))->implode(', '),
                $content->creator?->name ?? '—',
                $content->creator?->role?->name ?? '—',
                $content->link_url ?? '—',
                $content->link_title ?? '—',
                $content->link_description ?? '—',
                $content->media->pluck('original_name')->filter()->implode(', '),
                $content->created_at?->format('d-m-Y H:i') ?? '—',
                $content->scheduled_at?->format('d-m-Y H:i') ?? '—',
                $content->published_at?->format('d-m-Y H:i') ?? '—',
                $content->approver?->name ?? '—',
            ];
        }

        $summaryHeader = [
            'Akun', 'Email', 'Role', 'Total Konten', 'Draft', 'Menunggu Approval',
            'Revisi', 'Disetujui', 'Dijadwalkan', 'Dipublikasikan', 'Gagal',
            'Post Link', 'Jenis Post', 'Platform', 'Kategori',
            'Konten Pertama', 'Konten Terakhir',
        ];
        $detailHeader = [
            'Judul', 'Kategori', 'Status', 'Jenis Post', 'Platform', 'Dibuat Oleh',
            'Role', 'URL Link', 'Judul Link', 'Deskripsi Link', 'Media',
            'Tanggal Dibuat', 'Jadwal', 'Dipublikasikan', 'Reviewer',
        ];

        $rows = [
            ['RINGKASAN PER AKUN'],
            $summaryHeader,
            ...$summaryRows,
            [],
            ['DETAIL KONTEN'],
            $detailHeader,
            ...$detailRows,
        ];
        // Baris 0/1 = judul + header ringkasan; 4/5 = judul + header detail.
        $headerIndexes = [0, 1, 4, 5];

        $base = 'laporan-konten-'.$from->format('Ymd').'-'.$to->format('Ymd');
        $period = 'Periode: '.$from->format('d-m-Y').' s/d '.$to->format('d-m-Y');

        if ($format === 'xls') {
            $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>'.$base.'</title>'
                .'<style>table{border-collapse:collapse}th,td{border:1px solid #ccc;padding:4px;font-family:Arial,sans-serif;font-size:10pt;text-align:left}'
                .'.section{background:#eef2ff;font-weight:bold}tr:nth-child(2),tr:nth-child(6){background:#f8fafc}</style>'
                .'</head><body><table>';
            foreach ($rows as $index => $row) {
                $tag = in_array($index, $headerIndexes, true) ? 'th' : 'td';
                $class = in_array($index, $headerIndexes, true) ? ' class="section"' : '';
                $html .= '<tr>';
                foreach ($row as $cell) {
                    $html .= '<'.$tag.$class.'>'.e((string) $cell).'</'.$tag.'>';
                }
                $html .= '</tr>';
            }
            $html .= '</table></body></html>';

            return response($html, 200, [
                'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$base.'.xls"',
                'Cache-Control' => 'no-store',
            ]);
        }

        if ($format === 'pdf') {
            $lines = ['LAPORAN KONTEN', $period, ''];
            $lines[] = 'RINGKASAN PER AKUN';
            foreach ($summaryRows as $row) {
                foreach ($summaryHeader as $index => $label) {
                    $lines[] = $label.': '.mb_substr((string) ($row[$index] ?? ''), 0, 140);
                }
                $lines[] = '';
            }
            $lines[] = 'DETAIL KONTEN';
            foreach ($detailRows as $row) {
                foreach ($detailHeader as $index => $label) {
                    $lines[] = $label.': '.mb_substr((string) ($row[$index] ?? ''), 0, 140);
                }
                $lines[] = '';
            }

            return response($this->buildSimplePdf($lines), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$base.'.pdf"',
                'Cache-Control' => 'no-store',
            ]);
        }

        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$base.'.csv"',
        ]);
    }

    /// PDF sederhana tanpa library: font Helvetica bawaan, satu halaman per 48 baris.
    /// @param array<int, string> $lines
    private function buildSimplePdf(array $lines): string
    {
        $encode = static function (string $text): string {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);

            return addcslashes($converted === false ? '' : $converted, "\\()");
        };

        $chunks = array_chunk($lines, 48);
        if ($chunks === []) {
            $chunks = [['']];
        }
        $pageCount = count($chunks);
        $fontNumber = 3 + 2 * $pageCount;
        $kids = '';
        for ($i = 0; $i < $pageCount; $i++) {
            $kids .= (3 + $i).' 0 R ';
        }

        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids ['.$kids.'] /Count '.$pageCount.' >>',
        ];
        foreach ($chunks as $index => $chunk) {
            $pageNumber = 3 + $index;
            $contentNumber = 3 + $pageCount + $index;
            $objects[$pageNumber] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] '
                .'/Resources << /Font << /F1 '.$fontNumber.' 0 R >> >> /Contents '.$contentNumber.' 0 R >>';

            $stream = "BT\n/F1 10 Tf\n13 TL\n40 800 Td\n";
            foreach ($chunk as $line) {
                $stream .= '('.$encode((string) $line).") Tj\nT*\n";
            }
            $stream .= "ET\n";
            $objects[$contentNumber] = '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream';
        }
        $objects[$fontNumber] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $size = count($objects) + 1;
        $pdf .= 'xref'."\n0 ".$size."\n0000000000 65535 f \n";
        for ($number = 1; $number < $size; $number++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$number] ?? 0);
        }
        $pdf .= 'trailer'."\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";

        return $pdf;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function reportRange(Request $request, bool $defaultsFromQuery = false): array
    {
        if ($request->filled('preset')) {
            $today = Carbon::today();
            [$from, $to] = match ($request->input('preset')) {
                '7d' => [$today->copy()->subDays(6), $today->copy()],
                'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
                'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
                'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
                default => [$today->copy()->subDays(29), $today->copy()],
            };
        } else {
            $fallbackFrom = $defaultsFromQuery ? now()->subDays(29) : Carbon::today()->subDays(29);
            $fallbackTo = $defaultsFromQuery ? now() : Carbon::today();
            $from = $request->filled('from') ? Carbon::parse($request->input('from')) : $fallbackFrom;
            $to = $request->filled('to') ? Carbon::parse($request->input('to')) : $fallbackTo;
        }

        return [$from, $to];
    }

    // -------------------------------------------------------------------------
    // Kinerja pengguna (gate log.view)
    // -------------------------------------------------------------------------

    public function performance(Request $request): JsonResponse
    {
        Gate::authorize('log.view');
        $period = in_array($request->input('period'), ['day', 'week', 'month'], true) ? $request->input('period') : 'month';
        $offset = min(0, (int) $request->integer('offset', 0));
        [$from, $to] = $this->rangeFor($period, $offset);

        $created = Content::whereBetween('created_at', [$from, $to])->whereNotNull('created_by')
            ->selectRaw('created_by as uid, count(*) as c')->groupBy('created_by')->pluck('c', 'uid');

        $theirs = DB::table('content_approvals')
            ->join('contents', 'contents.id', '=', 'content_approvals.content_id')
            ->whereBetween('content_approvals.created_at', [$from, $to])
            ->whereNotNull('contents.created_by')
            ->selectRaw('contents.created_by as uid, content_approvals.decision as decision, count(*) as c')
            ->groupBy('contents.created_by', 'content_approvals.decision')->get();
        $theirsPivot = $this->pivot($theirs, 'decision');

        $reviews = DB::table('content_approvals')->whereBetween('created_at', [$from, $to])
            ->selectRaw('reviewer_id as uid, decision, count(*) as c')->groupBy('reviewer_id', 'decision')->get();
        $reviewsPivot = $this->pivot($reviews, 'decision');

        $publishes = DB::table('activity_logs')->whereBetween('created_at', [$from, $to])
            ->whereIn('action', ['published', 'publish_failed'])->whereNotNull('user_id')
            ->selectRaw('user_id as uid, action, count(*) as c')->groupBy('user_id', 'action')->get();
        $publishPivot = $this->pivot($publishes, 'action');

        $users = User::with('role')->whereNotNull('role_id')->orderBy('name')->get();
        $rows = [];
        foreach ($users as $user) {
            $uid = (string) $user->id;
            $createdCount = (int) ($created[$uid] ?? 0);
            $approvedTheirs = (int) ($theirsPivot[$uid][ApprovalDecision::Approved->value] ?? 0);
            $revisionTheirs = (int) ($theirsPivot[$uid][ApprovalDecision::Revision->value] ?? 0);
            $reviewsApproved = (int) ($reviewsPivot[$uid][ApprovalDecision::Approved->value] ?? 0);
            $reviewsRevision = (int) ($reviewsPivot[$uid][ApprovalDecision::Revision->value] ?? 0);
            $publishDone = (int) ($publishPivot[$uid]['published'] ?? 0);
            $publishFailed = (int) ($publishPivot[$uid]['publish_failed'] ?? 0);
            $good = $approvedTheirs + $reviewsApproved + $publishDone;
            $bad = $revisionTheirs + $reviewsRevision + $publishFailed;
            $worked = $createdCount + $reviewsApproved + $reviewsRevision + $publishDone + $publishFailed;
            if ($user->isAdministrator() && $worked === 0) {
                continue;
            }
            $rows[] = [
                'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name],
                'created' => $createdCount,
                'approved_theirs' => $approvedTheirs,
                'revision_theirs' => $revisionTheirs,
                'reviews_approved' => $reviewsApproved,
                'reviews_revision' => $reviewsRevision,
                'publish_done' => $publishDone,
                'publish_failed' => $publishFailed,
                'worked' => $worked,
                'good' => $good,
                'bad' => $bad,
                'quality_pct' => ($good + $bad) > 0 ? (int) round($good / ($good + $bad) * 100) : null,
            ];
        }

        return $this->success([
            'period' => $period,
            'offset' => $offset,
            'from' => $from->toISOString(),
            'to' => $to->toISOString(),
            'range_label' => $this->rangeLabel($from, $to, $period),
            'total_users' => count($rows),
            'rows' => $rows,
        ]);
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function rangeFor(string $period, int $offset): array
    {
        if ($period === 'day') {
            $day = now()->addDays($offset);

            return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
        }
        if ($period === 'month') {
            $month = now()->startOfMonth()->addMonthsNoOverflow($offset);

            return [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        }
        $week = now()->addWeeks($offset);

        return [$week->copy()->startOfWeek(), $week->copy()->endOfWeek()];
    }

    private function rangeLabel(Carbon $from, Carbon $to, string $period): string
    {
        Carbon::setLocale('id');

        return match ($period) {
            'day' => $from->isoFormat('dddd, D MMMM YYYY'),
            'month' => $from->isoFormat('MMMM YYYY'),
            default => $from->isoFormat('D MMM').' – '.$to->isoFormat('D MMM YYYY'),
        };
    }

    /** @return array<string, array<string, int>> */
    private function pivot($rows, string $keyColumn): array
    {
        $result = [];
        foreach ($rows as $row) {
            $uid = (string) $row->uid;
            $result[$uid][(string) $row->{$keyColumn}] = (int) $row->c;
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Kalender (gate calendar.view)
    // -------------------------------------------------------------------------

    public function calendar(Request $request): JsonResponse
    {
        Gate::authorize('calendar.view');
        $year = (int) $request->integer('year', now()->year);
        $month = (int) $request->integer('month', now()->month);
        $statusFilter = (string) $request->input('status_filter', '');

        $monthStart = Carbon::create($year, $month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $dated = Content::query()
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter))
            ->where(fn ($q) => $q->whereBetween('scheduled_at', [$gridStart, $gridEnd])
                ->orWhereBetween('published_at', [$gridStart, $gridEnd]))
            ->orderBy('scheduled_at')->get();

        $byDate = [];
        foreach ($dated as $content) {
            $date = ($content->published_at ?? $content->scheduled_at)?->format('Y-m-d');
            if ($date === null) {
                continue;
            }
            $byDate[$date][] = $this->contentSummary($content);
        }

        $weeks = [];
        $cursor = $gridStart->copy();
        while ($cursor <= $gridEnd) {
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $key = $cursor->format('Y-m-d');
                $days[] = [
                    'date' => $key,
                    'in_month' => $cursor->month === $month && $cursor->year === $year,
                    'is_today' => $cursor->isToday(),
                    'items' => $byDate[$key] ?? [],
                ];
                $cursor->addDay();
            }
            $weeks[] = $days;
        }

        $unscheduledQuery = Content::query()
            ->whereNull('scheduled_at')->whereNull('published_at')
            ->whereIn('status', array_map(fn ($s) => $s->value, [
                ContentStatus::Draft, ContentStatus::PendingApproval,
                ContentStatus::Revision, ContentStatus::Approved,
            ]))
            ->when($statusFilter !== '', fn ($q) => $q->where('status', $statusFilter));
        $unscheduledTotal = (clone $unscheduledQuery)->count();
        $unscheduled = $unscheduledQuery->latest()->take(10)->get()
            ->map(fn (Content $c) => $this->contentSummary($c))->all();

        $scheduledCount = Content::where('status', ContentStatus::Scheduled->value)
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])->count();
        $publishedCount = Content::where('status', ContentStatus::Published->value)
            ->whereBetween('published_at', [$monthStart, $monthEnd])->count();

        return $this->success([
            'year' => $year,
            'month' => $month,
            'month_label' => $monthStart->isoFormat('MMMM YYYY'),
            'weeks' => $weeks,
            'scheduled_count' => $scheduledCount,
            'published_count' => $publishedCount,
            'unscheduled_total' => $unscheduledTotal,
            'unscheduled' => $unscheduled,
            'statuses' => collect(ContentStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label()])->all(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Log aktivitas agregat (gate log.view)
    // -------------------------------------------------------------------------

    public function logs(Request $request): JsonResponse
    {
        Gate::authorize('log.view');
        $period = in_array($request->input('period'), ['day', 'week', 'month'], true) ? $request->input('period') : 'week';
        $offset = min(0, (int) $request->integer('offset', 0));
        [$from, $to] = $this->rangeFor($period, $offset);
        [$prevFrom, $prevTo] = $this->rangeFor($period, $offset - 1);

        $work = fn (Carbon $start, Carbon $end) => ActivityLog::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('category', '!=', ActivityLog::PRESENCE_CATEGORY);

        $logs = $work($from, $to)->get(['id', 'user_id', 'action', 'category', 'created_at']);
        $total = $logs->count();
        $previousTotal = $work($prevFrom, $prevTo)->count();
        $activeUsers = $logs->whereNotNull('user_id')->pluck('user_id')->unique()->count();

        $buckets = [];
        if ($period === 'day') {
            for ($hour = 0; $hour < 24; $hour++) {
                $count = $logs->filter(fn ($log) => (int) $log->created_at->format('H') === $hour)->count();
                $buckets[] = ['label' => str_pad((string) $hour, 2, '0', STR_PAD_LEFT), 'count' => $count];
            }
        } else {
            $cursor = $from->copy()->startOfDay();
            while ($cursor <= $to->copy()->startOfDay()) {
                $key = $cursor->format('Y-m-d');
                $count = $logs->filter(fn ($log) => $log->created_at->format('Y-m-d') === $key)->count();
                $buckets[] = ['label' => $cursor->isoFormat('D MMM'), 'count' => $count];
                $cursor->addDay();
            }
        }

        $rankRows = $logs->whereNotNull('user_id')->groupBy('user_id')->map(fn ($group) => [
            'user_id' => (int) $group->first()->user_id,
            'count' => $group->count(),
            'categories' => $group->countBy('category')->sortDesc()->take(3)->all(),
        ])->sortByDesc('count')->take(10)->values();
        $rankUsers = User::with('role')->whereIn('id', $rankRows->pluck('user_id'))->get()->keyBy('id');
        $ranking = [];
        foreach ($rankRows as $row) {
            $user = $rankUsers->get($row['user_id']);
            if (! $user) {
                continue;
            }
            $ranking[] = [
                'user' => ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name],
                'count' => $row['count'],
                'categories' => $row['categories'],
            ];
        }

        $itemsQuery = ActivityLog::with('user:id,name,role_id')->with('user.role:id,name,slug')
            ->whereBetween('created_at', [$from, $to]);
        if ($request->filled('user_filter')) {
            $itemsQuery->where('user_id', (int) $request->input('user_filter'));
        }
        if ($request->filled('category_filter')) {
            $itemsQuery->where('category', (string) $request->input('category_filter'));
        }
        $items = $itemsQuery->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(max((int) $request->integer('per_page', 15), 1), 100));

        return $this->success([
            'period' => $period,
            'offset' => $offset,
            'from' => $from->toISOString(),
            'to' => $to->toISOString(),
            'range_label' => $this->rangeLabel($from, $to, $period),
            'total' => $total,
            'delta' => $previousTotal > 0 ? (int) round(($total - $previousTotal) / $previousTotal * 100) : null,
            'active_users' => $activeUsers,
            'average' => $activeUsers > 0 ? round($total / $activeUsers, 1) : 0,
            'buckets' => $buckets,
            'max_bucket' => max(1, ...array_column($buckets, 'count')),
            'ranking' => $ranking,
            'category_counts' => $logs->countBy('category')->sortDesc()->all(),
            'items' => array_map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'action_label' => ActivityLog::labelFor($log->action),
                'category' => $log->category,
                'description' => $log->description,
                'user' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'role' => $log->user->role?->slug,
                ] : null,
                'created_at' => $log->created_at?->toISOString(),
            ], $items->items()),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
            'categories' => ActivityLog::CATEGORIES,
        ]);
    }

    /** @return array<string, mixed> */
    private function contentSummary(Content $content): array
    {
        return [
            'id' => $content->id,
            'title' => $content->title,
            'status' => ['value' => $content->status->value, 'label' => $content->status->label()],
            'category' => ['value' => $content->category?->value, 'label' => $content->category?->label()],
            'author' => $content->creator ? ['id' => $content->creator->id, 'name' => $content->creator->name] : null,
            'scheduled_at' => $content->scheduled_at?->toISOString(),
            'published_at' => $content->published_at?->toISOString(),
            'created_at' => $content->created_at?->toISOString(),
        ];
    }
}
