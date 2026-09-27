<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Log Aktivitas', 'subtitle' => 'Siapa mengerjakan apa, dan siapa yang paling sibuk.'])] class extends Component
{
    use WithPagination;

    #[Url]
    public string $period = 'week'; // day | week | month

    /** 0 = periode berjalan, -1 = sebelumnya, dst. */
    #[Url]
    public int $offset = 0;

    #[Url]
    public string $userFilter = '';

    #[Url]
    public string $categoryFilter = '';

    public function setPeriod(string $period): void
    {
        $this->period = in_array($period, ['day', 'week', 'month'], true) ? $period : 'week';
        $this->offset = 0;
        $this->resetPage();
    }

    public function shift(int $step): void
    {
        $this->offset = min(0, $this->offset + $step);
        $this->resetPage();
    }

    public function today(): void
    {
        $this->offset = 0;
        $this->resetPage();
    }

    public function updatedUserFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    /** @return array{0: Carbon, 1: Carbon} */
    protected function rangeFor(int $offset): array
    {
        $now = Carbon::now();

        return match ($this->period) {
            'day' => [
                $now->copy()->addDays($offset)->startOfDay(),
                $now->copy()->addDays($offset)->endOfDay(),
            ],
            'month' => [
                $now->copy()->startOfMonth()->addMonthsNoOverflow($offset)->startOfMonth(),
                $now->copy()->startOfMonth()->addMonthsNoOverflow($offset)->endOfMonth(),
            ],
            default => [
                $now->copy()->addWeeks($offset)->startOfWeek(),
                $now->copy()->addWeeks($offset)->endOfWeek(),
            ],
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

    public function with(): array
    {
        [$from, $to] = $this->rangeFor($this->offset);
        [$prevFrom, $prevTo] = $this->rangeFor($this->offset - 1);

        // Kesibukan = aktivitas kerja; login/logout tidak dihitung.
        $work = fn () => ActivityLog::query()
            ->whereBetween('created_at', [$from, $to])
            ->where('category', '!=', ActivityLog::PRESENCE_CATEGORY);

        $logs = $work()->get(['id', 'user_id', 'action', 'category', 'created_at']);
        $total = $logs->count();
        $previousTotal = ActivityLog::query()
            ->whereBetween('created_at', [$prevFrom, $prevTo])
            ->where('category', '!=', ActivityLog::PRESENCE_CATEGORY)
            ->count();

        // Tren: jam (harian), hari (mingguan/bulanan).
        $buckets = [];

        if ($this->period === 'day') {
            for ($h = 0; $h < 24; $h++) {
                $buckets[$h] = ['label' => sprintf('%02d', $h), 'show' => $h % 3 === 0, 'title' => sprintf('%02d:00–%02d:59', $h, $h), 'count' => 0];
            }
            foreach ($logs as $log) {
                $buckets[$log->created_at->hour]['count']++;
            }
        } else {
            foreach ($from->copy()->locale('id')->daysUntil($to->copy()->startOfDay()) as $day) {
                $isWeek = $this->period === 'week';
                $buckets[$day->format('Y-m-d')] = [
                    'label' => $isWeek ? $day->isoFormat('dd') : $day->format('j'),
                    'show' => $isWeek || $day->day === 1 || $day->day % 5 === 0,
                    'title' => $day->isoFormat('dddd, D MMM'),
                    'count' => 0,
                ];
            }
            foreach ($logs as $log) {
                $key = $log->created_at->format('Y-m-d');
                if (isset($buckets[$key])) {
                    $buckets[$key]['count']++;
                }
            }
        }

        $maxBucket = max(1, ...array_column($buckets, 'count'));
        $peak = collect($buckets)->sortByDesc('count')->first();

        // Papan "paling sibuk".
        $byUser = $logs->whereNotNull('user_id')->groupBy('user_id');
        $ranking = $byUser
            ->map(fn ($rows, $uid) => [
                'user_id' => (int) $uid,
                'count' => $rows->count(),
                'last' => $rows->max('created_at'),
                'categories' => $rows->countBy('category')->sortDesc()->take(3)->all(),
            ])
            ->sortByDesc('count')
            ->take(10)
            ->values();

        $users = User::with('role')->whereIn('id', $ranking->pluck('user_id'))->get()->keyBy('id');
        $ranking = $ranking->filter(fn ($r) => $users->has($r['user_id']))->map(function ($r) use ($users) {
            $r['user'] = $users[$r['user_id']];

            return $r;
        })->values();
        $maxRank = max(1, (int) $ranking->max('count'));

        $categoryCounts = $logs->countBy('category')->sortDesc();

        $items = ActivityLog::with('user')
            ->whereBetween('created_at', [$from, $to])
            ->when($this->userFilter !== '', fn ($q) => $q->where('user_id', (int) $this->userFilter))
            ->when($this->categoryFilter !== '', fn ($q) => $q->where('category', $this->categoryFilter))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(15);

        return [
            'rangeLabel' => $this->rangeLabel($from, $to),
            'from' => $from,
            'to' => $to,
            'total' => $total,
            'delta' => $previousTotal > 0 ? (int) round(($total - $previousTotal) / $previousTotal * 100) : null,
            'previousTotal' => $previousTotal,
            'activeUsers' => $byUser->count(),
            'average' => $byUser->count() > 0 ? round($total / $byUser->count(), 1) : 0,
            'buckets' => $buckets,
            'maxBucket' => $maxBucket,
            'peak' => $peak && $peak['count'] > 0 ? $peak : null,
            'ranking' => $ranking,
            'maxRank' => $maxRank,
            'categoryCounts' => $categoryCounts,
            'items' => $items,
            'allUsers' => User::orderBy('name')->get(['id', 'name']),
            'categories' => ActivityLog::CATEGORIES,
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

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Total Aktivitas</div>
            <div class="stat-value">{{ number_format($total) }}</div>
            <div class="field-hint" style="margin:4px 0 0;">
                @if ($delta === null)
                    {{ $previousTotal === 0 ? 'Periode lalu belum ada aktivitas' : '' }}
                @else
                    <span style="color:var({{ $delta >= 0 ? '--status-posted-text' : '--status-failed-text' }});">
                        <i class="fa-solid {{ $delta >= 0 ? 'fa-arrow-trend-up' : 'fa-arrow-trend-down' }}"></i> {{ abs($delta) }}%
                    </span> dari periode lalu ({{ $previousTotal }})
                @endif
            </div>
        </div>
        <div class="stat">
            <div class="stat-label">Pengguna Aktif</div>
            <div class="stat-value">{{ $activeUsers }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Rata-rata / Pengguna</div>
            <div class="stat-value">{{ $average }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Puncak Aktivitas</div>
            <div class="stat-value" style="font-size:20px;margin-top:10px;">{{ $peak ? $peak['title'] : '—' }}</div>
            @if ($peak)
                <div class="field-hint" style="margin:4px 0 0;">{{ $peak['count'] }} aktivitas</div>
            @endif
        </div>
    </div>

    <div class="dash-grid">
        <div>
            <div class="panel">
                <p class="panel-title"><i class="fa-solid fa-trophy" style="color:#F59E0B;"></i> Paling Sibuk</p>
                <p class="panel-sub">Peringkat pengguna berdasarkan jumlah aktivitas kerja (login &amp; logout tidak dihitung).</p>

                @forelse ($ranking as $i => $row)
                    @php($u = $row['user'])
                    <div class="rank-row">
                        <span class="rank-num {{ $i === 0 ? 'top' : '' }}">{{ $i + 1 }}</span>
                        <span class="avatar">{{ Str::of($u->name)->explode(' ')->filter()->take(2)->map(fn ($w) => Str::substr($w, 0, 1))->implode('') }}</span>
                        <div class="rank-main">
                            <div class="rank-name">
                                {{ $u->name }}
                                <span class="badge">{{ $u->role?->name ?? 'Tanpa role' }}</span>
                            </div>
                            <span class="bar-track" style="display:block;margin:6px 0;"><span class="bar-fill" style="width:{{ (int) round($row['count'] / $maxRank * 100) }}%;"></span></span>
                            <div class="rank-sub">
                                @foreach ($row['categories'] as $cat => $n)
                                    <span class="chip"><i class="{{ \App\Models\ActivityLog::categoryIcon($cat) }}"></i> {{ \App\Models\ActivityLog::categoryLabel($cat) }} {{ $n }}</span>
                                @endforeach
                                <span class="date-cell">terakhir {{ $row['last']->diffForHumans() }}</span>
                            </div>
                        </div>
                        <span class="rank-count">{{ $row['count'] }}</span>
                    </div>
                @empty
                    <div class="dash-empty">Belum ada aktivitas pada periode ini.</div>
                @endforelse
            </div>
        </div>

        <div>
            <div class="panel">
                <p class="panel-title">Tren Aktivitas</p>
                <p class="panel-sub">{{ ['day' => 'Per jam', 'week' => 'Per hari', 'month' => 'Per hari'][$period] }} — {{ $rangeLabel }}</p>
                <div class="trend">
                    @foreach ($buckets as $b)
                        <div class="trend-col" title="{{ $b['title'] }}: {{ $b['count'] }} aktivitas">
                            <div class="trend-plot">
                                <div class="trend-bar {{ $peak && $b['count'] === $peak['count'] ? 'peak' : '' }}" style="height:{{ $b['count'] > 0 ? max(4, (int) round($b['count'] / $maxBucket * 100)) : 0 }}%;"></div>
                            </div>
                            <div class="trend-label">{{ $b['show'] ? $b['label'] : '' }}</div>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="panel">
                <p class="panel-title">Jenis Aktivitas</p>
                @php($maxCat = max(1, (int) $categoryCounts->max()))
                @forelse ($categoryCounts as $cat => $n)
                    <div class="bar-row">
                        <span class="bar-label" style="width:120px;"><i class="{{ \App\Models\ActivityLog::categoryIcon($cat) }}"></i> {{ \App\Models\ActivityLog::categoryLabel($cat) }}</span>
                        <span class="bar-track"><span class="bar-fill" style="width:{{ (int) round($n / $maxCat * 100) }}%;"></span></span>
                        <span class="bar-count">{{ $n }}</span>
                    </div>
                @empty
                    <div class="dash-empty">Belum ada data.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="panel" style="margin-top:16px;">
        <div class="toolbar" style="margin-bottom:12px;">
            <div>
                <p class="panel-title" style="margin:0;">Riwayat Aktivitas</p>
                <p class="panel-sub" style="margin:0;">{{ number_format($items->total()) }} catatan · {{ $rangeLabel }}</p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <select class="select" style="width:auto;" wire:model.live="userFilter">
                    <option value="">Semua pengguna</option>
                    @foreach ($allUsers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
                <select class="select" style="width:auto;" wire:model.live="categoryFilter">
                    <option value="">Semua jenis</option>
                    @foreach ($categories as $slug => [$catLabel])
                        <option value="{{ $slug }}">{{ $catLabel }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pengguna</th>
                        <th>Aktivitas</th>
                        <th>Keterangan</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $log)
                        <tr>
                            <td class="date-cell" style="white-space:nowrap;">
                                {{ $log->created_at->format('d M Y H:i') }}
                                <div class="p-desc">{{ $log->created_at->diffForHumans() }}</div>
                            </td>
                            <td class="title-text">
                                {{ $log->user?->name ?? 'Sistem' }}
                                @if ($log->ip_address)<div class="p-desc">{{ $log->ip_address }}</div>@endif
                            </td>
                            <td>
                                <span class="chip"><i class="{{ \App\Models\ActivityLog::categoryIcon($log->category) }}"></i> {{ $log->actionLabel() }}</span>
                            </td>
                            <td class="date-cell" style="max-width:380px;white-space:normal;">{{ $log->description ?: '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="dash-empty">Tidak ada aktivitas untuk filter ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:16px;">{{ $items->links() }}</div>
    </div>
</div>
