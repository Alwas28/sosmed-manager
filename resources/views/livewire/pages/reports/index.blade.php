<?php

use App\Enums\ContentCategory;
use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\ContentPlatform;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.admin-layout', ['title' => 'Laporan', 'subtitle' => 'Ringkasan aktivitas konten dalam rentang waktu tertentu.'])] class extends Component
{
    use WithPagination;

    public string $preset = '30d';

    public string $from = '';

    public string $to = '';

    public function mount(): void
    {
        $this->applyPreset('30d');
    }

    public function applyPreset(string $preset): void
    {
        $today = Carbon::today();

        [$from, $to] = match ($preset) {
            '7d' => [$today->copy()->subDays(6), $today->copy()],
            '30d' => [$today->copy()->subDays(29), $today->copy()],
            'this_month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'last_month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->copy()->startOfYear(), $today->copy()->endOfYear()],
            default => [$today->copy()->subDays(29), $today->copy()],
        };

        $this->preset = $preset;
        $this->from = $from->format('Y-m-d');
        $this->to = $to->format('Y-m-d');
        $this->resetPage();
    }

    public function applyCustomRange(): void
    {
        $this->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ], attributes: ['from' => 'dari tanggal', 'to' => 'sampai tanggal']);

        $this->preset = 'custom';
        $this->resetPage();
    }

    protected function rangeQuery(): Builder
    {
        return Content::query()->whereBetween('created_at', [
            Carbon::parse($this->from)->startOfDay(),
            Carbon::parse($this->to)->endOfDay(),
        ]);
    }

    public function with(): array
    {
        $statuses = ContentStatus::cases();
        $rawStatusCounts = $this->rangeQuery()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $statusCounts = [];
        foreach ($statuses as $s) {
            $statusCounts[$s->value] = (int) ($rawStatusCounts[$s->value] ?? 0);
        }

        $categories = ContentCategory::cases();
        $rawCategoryCounts = $this->rangeQuery()->selectRaw('category, count(*) as c')->groupBy('category')->pluck('c', 'category');
        $categoryCounts = [];
        foreach ($categories as $cat) {
            $categoryCounts[$cat->value] = (int) ($rawCategoryCounts[$cat->value] ?? 0);
        }
        $maxCategoryCount = max(1, ...array_values($categoryCounts));

        $platforms = Platform::cases();
        $contentIds = $this->rangeQuery()->pluck('id');
        $rawPlatformCounts = ContentPlatform::query()
            ->whereIn('content_id', $contentIds)
            ->selectRaw('platform, count(*) as c')
            ->groupBy('platform')
            ->pluck('c', 'platform');
        $platformCounts = [];
        foreach ($platforms as $p) {
            $platformCounts[$p->value] = (int) ($rawPlatformCounts[$p->value] ?? 0);
        }
        $maxPlatformCount = max(1, ...array_values($platformCounts));

        $topContributors = $this->rangeQuery()
            ->selectRaw('created_by, count(*) as c')
            ->whereNotNull('created_by')
            ->groupBy('created_by')
            ->orderByDesc('c')
            ->take(5)
            ->get()
            ->map(fn ($row) => ['user' => User::find($row->created_by), 'count' => $row->c])
            ->filter(fn ($row) => $row['user'] !== null)
            ->values();

        $total = array_sum($statusCounts);
        $published = $statusCounts[ContentStatus::Published->value];
        $failed = $statusCounts[ContentStatus::Failed->value];
        $successRate = ($published + $failed) > 0 ? (int) round($published / ($published + $failed) * 100) : null;

        return [
            'total' => $total,
            'published' => $published,
            'failed' => $failed,
            'successRate' => $successRate,
            'statuses' => $statuses,
            'statusCounts' => $statusCounts,
            'categories' => $categories,
            'categoryCounts' => $categoryCounts,
            'maxCategoryCount' => $maxCategoryCount,
            'platforms' => $platforms,
            'platformCounts' => $platformCounts,
            'maxPlatformCount' => $maxPlatformCount,
            'topContributors' => $topContributors,
            'items' => $this->rangeQuery()->with(['creator', 'platforms'])->latest('created_at')->paginate(15),
        ];
    }
}; ?>

<div>
    <div class="panel">
        <div class="toolbar" style="margin-bottom:0;">
            <div class="quick-actions">
                <button type="button" class="btn btn-sm {{ $preset === '7d' ? 'btn-primary' : '' }}" wire:click="applyPreset('7d')">7 Hari Terakhir</button>
                <button type="button" class="btn btn-sm {{ $preset === '30d' ? 'btn-primary' : '' }}" wire:click="applyPreset('30d')">30 Hari Terakhir</button>
                <button type="button" class="btn btn-sm {{ $preset === 'this_month' ? 'btn-primary' : '' }}" wire:click="applyPreset('this_month')">Bulan Ini</button>
                <button type="button" class="btn btn-sm {{ $preset === 'last_month' ? 'btn-primary' : '' }}" wire:click="applyPreset('last_month')">Bulan Lalu</button>
                <button type="button" class="btn btn-sm {{ $preset === 'this_year' ? 'btn-primary' : '' }}" wire:click="applyPreset('this_year')">Tahun Ini</button>
            </div>

            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <input type="date" class="input" style="width:auto;" wire:model="from">
                <span class="field-hint" style="margin:0;">s/d</span>
                <input type="date" class="input" style="width:auto;" wire:model="to">
                <button type="button" class="btn btn-sm" wire:click="applyCustomRange"><i class="fa-solid fa-filter"></i> Terapkan</button>
                @can('report.export')
                    <a class="btn btn-sm btn-primary" href="{{ route('reports.export', ['from' => $from, 'to' => $to]) }}">
                        <i class="fa-solid fa-download"></i> Unduh CSV
                    </a>
                @endcan
            </div>
        </div>
        @error('from')<div class="field-error">{{ $message }}</div>@enderror
        @error('to')<div class="field-error">{{ $message }}</div>@enderror
        <p class="field-hint" style="margin-top:10px;margin-bottom:0;">
            Menampilkan konten yang <strong>dibuat</strong> antara {{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} — {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}.
        </p>
    </div>

    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Total Konten</div>
            <div class="stat-value">{{ $total }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Dipublikasikan</div>
            <div class="stat-value" style="color:var(--status-posted-text);">{{ $published }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Gagal</div>
            <div class="stat-value" style="color:var(--status-failed-text);">{{ $failed }}</div>
        </div>
        <div class="stat">
            <div class="stat-label">Tingkat Publikasi Berhasil</div>
            <div class="stat-value">{{ $successRate === null ? '—' : $successRate.'%' }}</div>
        </div>
    </div>

    <div class="dash-grid">
        <div>
            <div class="panel">
                <p class="panel-title">Berdasarkan Status</p>
                @if ($total > 0)
                    @foreach ($statuses as $s)
                        @php($pill = $s->pill())
                        <div class="bar-row">
                            <span class="bar-label">{{ $s->label() }}</span>
                            <span class="bar-track"><span class="bar-fill" style="width:{{ $total > 0 ? round($statusCounts[$s->value] / $total * 100) : 0 }}%;background:var({{ $pill[1] }});"></span></span>
                            <span class="bar-count">{{ $statusCounts[$s->value] }}</span>
                        </div>
                    @endforeach
                @else
                    <div class="dash-empty">Tidak ada konten pada rentang ini.</div>
                @endif
            </div>

            <div class="panel">
                <p class="panel-title">Berdasarkan Platform</p>
                @if ($total > 0)
                    @foreach ($platforms as $p)
                        <div class="bar-row">
                            <span class="bar-label"><i class="{{ $p->icon() }}"></i> {{ $p->label() }}</span>
                            <span class="bar-track"><span class="bar-fill" style="width:{{ (int) round($platformCounts[$p->value] / $maxPlatformCount * 100) }}%;"></span></span>
                            <span class="bar-count">{{ $platformCounts[$p->value] }}</span>
                        </div>
                    @endforeach
                @else
                    <div class="dash-empty">Tidak ada konten pada rentang ini.</div>
                @endif
            </div>
        </div>

        <div>
            <div class="panel">
                <p class="panel-title">Berdasarkan Kategori</p>
                @if ($total > 0)
                    @foreach ($categories as $cat)
                        <div class="bar-row">
                            <span class="bar-label"><i class="{{ $cat->icon() }}"></i> {{ $cat->label() }}</span>
                            <span class="bar-track"><span class="bar-fill" style="width:{{ (int) round($categoryCounts[$cat->value] / $maxCategoryCount * 100) }}%;"></span></span>
                            <span class="bar-count">{{ $categoryCounts[$cat->value] }}</span>
                        </div>
                    @endforeach
                @else
                    <div class="dash-empty">Tidak ada konten pada rentang ini.</div>
                @endif
            </div>

            <div class="panel">
                <p class="panel-title">Kontributor Teraktif</p>
                @forelse ($topContributors as $row)
                    <div class="dash-list-item">
                        <span class="title-text">{{ $row['user']->name }}</span>
                        <span class="badge badge-accent">{{ $row['count'] }} konten</span>
                    </div>
                @empty
                    <div class="dash-empty">Tidak ada konten pada rentang ini.</div>
                @endforelse
            </div>
        </div>
    </div>

    <div class="panel">
        <p class="panel-title">Rincian Konten</p>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Judul</th>
                        <th>Kategori</th>
                        <th>Platform</th>
                        <th>Status</th>
                        <th>Dibuat oleh</th>
                        <th>Tanggal Dibuat</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($items as $content)
                        @php($pill = $content->status->pill())
                        @php($cat = $content->category ?? \App\Enums\ContentCategory::Umum)
                        <tr>
                            <td>
                                <a class="title-text" href="{{ route('konten.show', $content) }}" wire:navigate style="color:var(--accent);text-decoration:none;">{{ $content->title }}</a>
                            </td>
                            <td><span class="chip"><i class="{{ $cat->icon() }}"></i> {{ $cat->label() }}</span></td>
                            <td>
                                <div class="chip-row">
                                    @forelse ($content->platforms as $p)
                                        <span class="chip"><i class="{{ \App\Enums\Platform::tryIcon($p->platform) }}"></i> {{ \App\Enums\Platform::tryLabel($p->platform) }}</span>
                                    @empty
                                        <span class="chip">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td><span class="pill" style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});">{{ $content->status->label() }}</span></td>
                            <td class="date-cell">{{ $content->creator?->name ?? '—' }}</td>
                            <td class="date-cell">{{ $content->created_at->format('d M Y H:i') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="text-align:center;padding:40px;color:var(--text-muted);">Tidak ada konten pada rentang ini.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="margin-top:16px;">{{ $items->links() }}</div>
    </div>
</div>
