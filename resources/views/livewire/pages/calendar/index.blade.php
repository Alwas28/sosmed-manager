<?php

use App\Enums\ContentStatus;
use App\Models\Content;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Kalender Konten', 'subtitle' => 'Jadwal & publikasi konten dalam tampilan bulanan.'])] class extends Component
{
    private const array MONTHS = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    public int $year;

    public int $month; // 1-12

    public string $statusFilter = '';

    public function mount(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    public function prevMonth(): void
    {
        $cursor = Carbon::create($this->year, $this->month, 1)->subMonthNoOverflow();
        $this->year = $cursor->year;
        $this->month = $cursor->month;
    }

    public function nextMonth(): void
    {
        $cursor = Carbon::create($this->year, $this->month, 1)->addMonthNoOverflow();
        $this->year = $cursor->year;
        $this->month = $cursor->month;
    }

    public function goToday(): void
    {
        $this->year = (int) now()->year;
        $this->month = (int) now()->month;
    }

    /** Base query for content that has a date to place on the grid — a schedule or an actual publish. */
    protected function datedQuery(Carbon $from, Carbon $to): Builder
    {
        return Content::query()
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter))
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('scheduled_at', [$from, $to])
                    ->orWhereBetween('published_at', [$from, $to]);
            });
    }

    public function with(): array
    {
        $monthStart = Carbon::create($this->year, $this->month, 1)->startOfDay();
        $monthEnd = $monthStart->copy()->endOfMonth()->endOfDay();

        // Grid always fills whole weeks (Senin–Minggu) so the layout never
        // jumps between 4/5/6 rows as the user pages between months.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        $byDay = [];
        foreach ($this->datedQuery($gridStart, $gridEnd)->orderBy('scheduled_at')->get() as $content) {
            $date = ($content->scheduled_at ?? $content->published_at)?->format('Y-m-d');
            if ($date !== null) {
                $byDay[$date][] = $content;
            }
        }

        $weeks = [];
        $cursor = $gridStart->copy();
        while ($cursor->lte($gridEnd)) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $week[] = [
                    'date' => $cursor->copy(),
                    'inMonth' => $cursor->month === $this->month && $cursor->year === $this->year,
                    'isToday' => $cursor->isToday(),
                    'items' => $byDay[$cursor->format('Y-m-d')] ?? [],
                ];
                $cursor->addDay();
            }
            $weeks[] = $week;
        }

        // Content with no schedule/publish date yet has nowhere to sit on
        // the grid — surfaced separately instead of silently disappearing.
        $unscheduledStatuses = [ContentStatus::Draft, ContentStatus::PendingApproval, ContentStatus::Revision, ContentStatus::Approved];
        $unscheduledBase = Content::query()
            ->whereNull('scheduled_at')
            ->whereNull('published_at')
            ->whereIn('status', array_map(fn ($s) => $s->value, $unscheduledStatuses))
            ->when($this->statusFilter !== '', fn ($q) => $q->where('status', $this->statusFilter));

        return [
            'weeks' => $weeks,
            'monthLabel' => self::MONTHS[$this->month].' '.$this->year,
            'scheduledCount' => $this->datedQuery($monthStart, $monthEnd)->where('status', ContentStatus::Scheduled->value)->count(),
            'publishedCount' => $this->datedQuery($monthStart, $monthEnd)->where('status', ContentStatus::Published->value)->count(),
            'unscheduledTotal' => (clone $unscheduledBase)->count(),
            'unscheduled' => (clone $unscheduledBase)->latest()->take(10)->get(),
            'allStatuses' => ContentStatus::cases(),
        ];
    }
}; ?>

<div>
    <div class="stat-grid" style="margin-bottom:20px;">
        <div class="stat">
            <div class="stat-label">Terjadwal Bulan Ini</div>
            <div class="stat-value">{{ $scheduledCount }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Dipublikasikan Bulan Ini</div>
            <div class="stat-value">{{ $publishedCount }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Belum Dijadwalkan</div>
            <div class="stat-value">{{ $unscheduledTotal }}</div>
        </div>
    </div>

    <div class="panel">
        <div class="cal-header">
            <h2 class="cal-title">{{ $monthLabel }}</h2>
            <div class="cal-nav">
                <select class="select" style="width:auto;" wire:model.live="statusFilter">
                    <option value="">Semua Status</option>
                    @foreach ($allStatuses as $s)
                        <option value="{{ $s->value }}">{{ $s->label() }}</option>
                    @endforeach
                </select>
                <button type="button" class="btn btn-sm" wire:click="prevMonth"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" class="btn btn-sm" wire:click="goToday">Hari Ini</button>
                <button type="button" class="btn btn-sm" wire:click="nextMonth"><i class="fa-solid fa-chevron-right"></i></button>
                <a class="btn btn-sm btn-primary" href="{{ route('konten.create') }}" wire:navigate>
                    <i class="fa-solid fa-plus"></i> Konten Baru
                </a>
            </div>
        </div>

        <div class="cal-legend">
            @foreach ($allStatuses as $s)
                @php($pill = $s->pill())
                <span><span class="cal-legend-dot" style="background:var({{ $pill[0] }});"></span>{{ $s->label() }}</span>
            @endforeach
        </div>

        <div class="cal-grid-wrap">
            <div class="cal-grid">
                <div class="cal-dow">Sen</div>
                <div class="cal-dow">Sel</div>
                <div class="cal-dow">Rab</div>
                <div class="cal-dow">Kam</div>
                <div class="cal-dow">Jum</div>
                <div class="cal-dow">Sab</div>
                <div class="cal-dow">Min</div>

                @foreach ($weeks as $week)
                    @foreach ($week as $day)
                        <div class="cal-day {{ $day['inMonth'] ? '' : 'is-outside' }} {{ $day['isToday'] ? 'is-today' : '' }}">
                            <div class="cal-daynum">{{ $day['date']->day }}</div>
                            @foreach (collect($day['items'])->take(3) as $content)
                                @php($pill = $content->status->pill())
                                <a href="{{ route('konten.show', $content) }}" wire:navigate class="cal-item"
                                   style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});"
                                   title="{{ $content->title }} — {{ $content->status->label() }}">
                                    {{ $content->title }}
                                </a>
                            @endforeach
                            @if (count($day['items']) > 3)
                                <span class="cal-more">+{{ count($day['items']) - 3 }} lainnya</span>
                            @endif
                        </div>
                    @endforeach
                @endforeach
            </div>
        </div>
    </div>

    <div class="panel">
        <p class="panel-title">Belum Dijadwalkan ({{ $unscheduledTotal }})</p>
        <p class="panel-sub">Konten yang belum punya tanggal terjadwal/publikasi, jadi belum muncul di kalender di atas.</p>

        @forelse ($unscheduled as $content)
            @php($pill = $content->status->pill())
            <div class="cal-unscheduled-item">
                <span class="pill" style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});">{{ $content->status->label() }}</span>
                <a href="{{ route('konten.show', $content) }}" wire:navigate style="color:var(--accent);text-decoration:none;flex:1;">
                    {{ $content->title }}
                </a>
                <span class="date-cell">{{ $content->created_at->format('d M Y') }}</span>
            </div>
        @empty
            <p class="field-hint">Semua konten sudah punya tanggal, atau belum ada konten sama sekali.</p>
        @endforelse

        @if ($unscheduledTotal > $unscheduled->count())
            <a href="{{ route('konten.index') }}" wire:navigate class="btn btn-sm" style="margin-top:12px;">
                Lihat Semua Konten <i class="fa-solid fa-arrow-right"></i>
            </a>
        @endif
    </div>
</div>
