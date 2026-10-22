<?php

use App\Enums\ApprovalDecision;
use App\Models\Content;
use App\Models\ContentApproval;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Kinerja Pengguna', 'subtitle' => 'Siapa paling produktif & paling sedikit mengerjakan tugas, per role.'])] class extends Component
{
    public string $period = 'month'; // day | week | month

    public int $offset = 0;

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['day', 'week', 'month'], true) ? $period : 'month';
        $this->offset = 0;
    }

    public function shift(int $step): void
    {
        $this->offset = min(0, $this->offset + $step);
    }

    public function today(): void
    {
        $this->offset = 0;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function rangeFor(int $offset): array
    {
        $now = Carbon::now();

        return match ($this->period) {
            'day' => [$now->copy()->addDays($offset)->startOfDay(), $now->copy()->addDays($offset)->endOfDay()],
            'month' => [
                $now->copy()->startOfMonth()->addMonthsNoOverflow($offset)->startOfMonth(),
                $now->copy()->startOfMonth()->addMonthsNoOverflow($offset)->endOfMonth(),
            ],
            default => [$now->copy()->addWeeks($offset)->startOfWeek(), $now->copy()->addWeeks($offset)->endOfWeek()],
        };
    }

    protected function rangeLabel(Carbon $from, Carbon $to): string
    {
        $from = $from->copy()->locale('id');
        $to = $to->copy()->locale('id');

        return match ($this->period) {
            'day' => $from->isoFormat('dddd, D MMMM YYYY'),
            'month' => $from->isoFormat('MMMM YYYY'),
            default => $from->isoFormat('D MMM').' – '.$to->isoFormat('D MMM YYYY'),
        };
    }

    /**
     * Satu baris skor per pengguna, dari data yang sudah ada (tidak ada tabel baru):
     *  - "created"        = konten yang mereka buat (sebagai Content Creator)
     *  - "approved/revision" (dari review atas konten MEREKA) = sinyal kualitas konten mereka
     *  - "reviewsApproved/reviewsRevision" (sebagai Reviewer) = keputusan yang MEREKA ambil
     *  - "publishDone/publishFailed" (sebagai Publisher) = eksekusi publikasi yang MEREKA lakukan
     *
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function scoreRows(Carbon $from, Carbon $to): \Illuminate\Support\Collection
    {
        $created = Content::query()
            ->whereBetween('created_at', [$from, $to])
            ->whereNotNull('created_by')
            ->selectRaw('created_by as uid, count(*) as c')
            ->groupBy('created_by')
            ->pluck('c', 'uid');

        // Plain query builder (not the Eloquent models) for these grouped aggregates —
        // Eloquent would otherwise cast the raw-selected `decision` column to the
        // ApprovalDecision enum, which can't be used as an array key below.
        $onTheirContent = \Illuminate\Support\Facades\DB::table('content_approvals')
            ->join('contents', 'contents.id', '=', 'content_approvals.content_id')
            ->whereBetween('content_approvals.created_at', [$from, $to])
            ->whereNotNull('contents.created_by')
            ->selectRaw('contents.created_by as uid, content_approvals.decision as decision, count(*) as c')
            ->groupBy('contents.created_by', 'content_approvals.decision')
            ->get();

        $asReviewer = \Illuminate\Support\Facades\DB::table('content_approvals')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('reviewer_id as uid, decision, count(*) as c')
            ->groupBy('reviewer_id', 'decision')
            ->get();

        $asPublisher = \Illuminate\Support\Facades\DB::table('activity_logs')
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('action', ['published', 'publish_failed'])
            ->whereNotNull('user_id')
            ->selectRaw('user_id as uid, action, count(*) as c')
            ->groupBy('user_id', 'action')
            ->get();

        $pivot = fn ($rows, string $keyCol) => $rows->groupBy('uid')->map(
            fn ($g) => $g->mapWithKeys(fn ($r) => [(string) $r->{$keyCol} => (int) $r->c])
        );

        $onTheirContentByUid = $pivot($onTheirContent, 'decision');
        $asReviewerByUid = $pivot($asReviewer, 'decision');
        $asPublisherByUid = $pivot($asPublisher, 'action');

        $users = User::with('role')->whereNotNull('role_id')->orderBy('name')->get();

        return $users->map(function (User $u) use ($created, $onTheirContentByUid, $asReviewerByUid, $asPublisherByUid) {
            $approvedTheirs = (int) ($onTheirContentByUid[$u->id][ApprovalDecision::Approved->value] ?? 0);
            $revisionTheirs = (int) ($onTheirContentByUid[$u->id][ApprovalDecision::Revision->value] ?? 0);
            $reviewsApproved = (int) ($asReviewerByUid[$u->id][ApprovalDecision::Approved->value] ?? 0);
            $reviewsRevision = (int) ($asReviewerByUid[$u->id][ApprovalDecision::Revision->value] ?? 0);
            $publishDone = (int) ($asPublisherByUid[$u->id]['published'] ?? 0);
            $publishFailed = (int) ($asPublisherByUid[$u->id]['publish_failed'] ?? 0);

            $good = $approvedTheirs + $reviewsApproved + $publishDone;
            $bad = $revisionTheirs + $reviewsRevision + $publishFailed;
            $createdCount = (int) ($created[$u->id] ?? 0);

            return [
                'user' => $u,
                'created' => $createdCount,
                'approvedTheirs' => $approvedTheirs,
                'revisionTheirs' => $revisionTheirs,
                'reviewsApproved' => $reviewsApproved,
                'reviewsRevision' => $reviewsRevision,
                'publishDone' => $publishDone,
                'publishFailed' => $publishFailed,
                'worked' => $createdCount + $reviewsApproved + $reviewsRevision + $publishDone + $publishFailed,
                'good' => $good,
                'bad' => $bad,
                'qualityPct' => ($good + $bad) > 0 ? (int) round($good / ($good + $bad) * 100) : null,
            ];
        })->reject(fn ($row) => $row['user']->isAdministrator() && $row['worked'] === 0);
    }

    protected function qualityBadge(?int $pct): array
    {
        return match (true) {
            $pct === null => ['Belum ada data', 'badge'],
            $pct >= 80 => ['Baik', 'badge badge-accent'],
            $pct >= 50 => ['Cukup', 'badge badge-lock'],
            default => ['Perlu Perhatian', 'badge badge-danger'],
        };
    }

    public function with(): array
    {
        [$from, $to] = $this->rangeFor($this->offset);

        $rows = $this->scoreRows($from, $to);
        $byRole = $rows->groupBy(fn ($r) => $r['user']->role?->name ?? 'Tanpa role')
            ->map(fn ($g) => $g->sortByDesc('worked')->values())
            ->sortKeys();

        $mostActive = $rows->sortByDesc('worked')->first();
        $leastActive = $rows->filter(fn ($r) => $r['worked'] > 0)->sortBy('worked')->first();

        return [
            'rangeLabel' => $this->rangeLabel($from, $to),
            'byRole' => $byRole,
            'totalUsers' => $rows->count(),
            'mostActive' => $mostActive,
            'leastActive' => $leastActive,
        ];
    }
}; ?>

<div>
    <div class="toolbar">
        <div class="role-tabs" style="margin-bottom:0;">
            @foreach (['day' => 'Harian', 'week' => 'Mingguan', 'month' => 'Bulanan'] as $key => $label)
                <button type="button" class="role-tab {{ $period === $key ? 'active' : '' }}" wire:click="setPeriod('{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>

        <div class="period-nav">
            <button type="button" class="btn btn-sm" wire:click="shift(-1)" title="Periode sebelumnya"><i class="fa-solid fa-chevron-left"></i></button>
            <span class="period-label">{{ $rangeLabel }}</span>
            <button type="button" class="btn btn-sm" wire:click="shift(1)" @disabled($offset >= 0) title="Periode berikutnya"><i class="fa-solid fa-chevron-right"></i></button>
            @if ($offset !== 0)
                <button type="button" class="btn btn-sm btn-ghost" wire:click="today">
                    {{ ['day' => 'Hari ini', 'week' => 'Minggu ini', 'month' => 'Bulan ini'][$period] }}
                </button>
            @endif
        </div>
    </div>

    <div class="panel" style="margin-bottom:16px;">
        <p class="field-hint" style="margin:0;">
            <i class="fa-solid fa-circle-info"></i>
            <strong>Dikerjakan</strong> = jumlah tugas yang disentuh (membuat konten, memproses approval, mempublikasikan).
            <strong>Skor Kualitas</strong> = porsi hasil baik (disetujui / publikasi sukses) dibanding hasil bermasalah (revisi / publikasi gagal) — kosong kalau belum ada hasil sama sekali pada periode ini.
        </p>
    </div>

    @if ($mostActive || $leastActive)
        <div class="stat-grid">
            @if ($mostActive)
                <div class="stat">
                    <div class="stat-label"><i class="fa-solid fa-trophy" style="color:#F59E0B;"></i> Paling Produktif</div>
                    <div class="stat-value" style="font-size:18px;">{{ $mostActive['user']->name }}</div>
                    <div class="field-hint" style="margin:4px 0 0;">{{ $mostActive['worked'] }} tugas dikerjakan · {{ $mostActive['user']->role?->name }}</div>
                </div>
            @endif
            @if ($leastActive)
                <div class="stat">
                    <div class="stat-label"><i class="fa-solid fa-battery-quarter" style="color:var(--text-muted);"></i> Paling Sedikit</div>
                    <div class="stat-value" style="font-size:18px;">{{ $leastActive['user']->name }}</div>
                    <div class="field-hint" style="margin:4px 0 0;">{{ $leastActive['worked'] }} tugas dikerjakan · {{ $leastActive['user']->role?->name }}</div>
                </div>
            @endif
            <div class="stat">
                <div class="stat-label">Pengguna Aktif Tercakup</div>
                <div class="stat-value">{{ $totalUsers }}</div>
            </div>
        </div>
    @endif

    @forelse ($byRole as $roleName => $rows)
        <div class="panel">
            <p class="panel-title">{{ $roleName }} <span class="badge">{{ $rows->count() }} pengguna</span></p>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Pengguna</th>
                            <th style="text-align:right;">Dibuat</th>
                            <th style="text-align:right;">Direview</th>
                            <th style="text-align:right;">Dipublikasikan</th>
                            <th style="text-align:right;">Dikerjakan</th>
                            <th>Skor Kualitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php([$qLabel, $qClass] = $this->qualityBadge($row['qualityPct']))
                            <tr>
                                <td class="title-text">{{ $row['user']->name }}</td>
                                <td style="text-align:right;" class="date-cell">
                                    {{ $row['created'] }}
                                    @if ($row['revisionTheirs'] > 0)
                                        <span class="p-desc">({{ $row['revisionTheirs'] }} direvisi)</span>
                                    @endif
                                </td>
                                <td style="text-align:right;" class="date-cell">
                                    {{ $row['reviewsApproved'] + $row['reviewsRevision'] }}
                                    @if ($row['reviewsApproved'] + $row['reviewsRevision'] > 0)
                                        <span class="p-desc">({{ $row['reviewsApproved'] }} setuju / {{ $row['reviewsRevision'] }} revisi)</span>
                                    @endif
                                </td>
                                <td style="text-align:right;" class="date-cell">
                                    {{ $row['publishDone'] }}
                                    @if ($row['publishFailed'] > 0)
                                        <span class="p-desc">({{ $row['publishFailed'] }} gagal)</span>
                                    @endif
                                </td>
                                <td style="text-align:right;font-weight:600;">{{ $row['worked'] }}</td>
                                <td>
                                    <span class="{{ $qClass }}">{{ $qLabel }}{{ $row['qualityPct'] !== null ? " ({$row['qualityPct']}%)" : '' }}</span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @empty
        <div class="panel">
            <div class="dash-empty">Belum ada aktivitas tercatat pada periode ini.</div>
        </div>
    @endforelse
</div>
