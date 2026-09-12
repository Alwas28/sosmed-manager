<?php

use App\Enums\ContentStatus;
use App\Enums\Platform;
use App\Models\Content;
use App\Models\ContentPlatform;
use App\Models\Media;
use App\Models\Role;
use App\Models\User;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Dashboard', 'subtitle' => 'Ringkasan aktivitas social media manager.'])] class extends Component
{
    public function with(): array
    {
        $statuses = ContentStatus::cases();

        $rawStatusCounts = Content::query()->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
        $statusCounts = [];
        foreach ($statuses as $s) {
            $statusCounts[$s->value] = (int) ($rawStatusCounts[$s->value] ?? 0);
        }

        $platforms = Platform::cases();
        $rawPlatformCounts = ContentPlatform::query()->selectRaw('platform, count(*) as c')->groupBy('platform')->pluck('c', 'platform');
        $platformCounts = [];
        foreach ($platforms as $p) {
            $platformCounts[$p->value] = (int) ($rawPlatformCounts[$p->value] ?? 0);
        }
        $maxPlatformCount = max(1, ...array_values($platformCounts));

        return [
            'totalContent' => array_sum($statusCounts),
            'statuses' => $statuses,
            'statusCounts' => $statusCounts,
            'platforms' => $platforms,
            'platformCounts' => $platformCounts,
            'maxPlatformCount' => $maxPlatformCount,
            'recentContent' => Content::with('creator')->latest()->take(6)->get(),
            'upcoming' => Content::query()
                ->whereNotNull('scheduled_at')
                ->where('scheduled_at', '>=', now())
                ->orderBy('scheduled_at')
                ->take(5)
                ->get(),
            'mediaCount' => Media::count(),
            'userCount' => User::count(),
            'roleCount' => Role::count(),
        ];
    }
}; ?>

<div>
    <div class="stat-grid">
        <div class="stat">
            <div class="stat-label">Total Konten</div>
            <div class="stat-value">{{ $totalContent }}</div>
        </div>
        @foreach ($statuses as $s)
            @php($pill = $s->pill())
            @can('content.view')
                <a class="stat stat-link" href="{{ route($s->route()) }}" wire:navigate>
                    <div class="stat-label">{{ $s->label() }}</div>
                    <div class="stat-value" style="color:var({{ $pill[1] }});">{{ $statusCounts[$s->value] }}</div>
                </a>
            @else
                <div class="stat">
                    <div class="stat-label">{{ $s->label() }}</div>
                    <div class="stat-value" style="color:var({{ $pill[1] }});">{{ $statusCounts[$s->value] }}</div>
                </div>
            @endcan
        @endforeach
    </div>

    <div class="panel">
        <p class="panel-title">Aksi Cepat</p>
        <div class="quick-actions">
            @can('content.create')
                <a class="btn btn-primary btn-sm" href="{{ route('konten.create') }}" wire:navigate><i class="fa-solid fa-plus"></i> Konten Baru</a>
            @endcan
            @can('calendar.view')
                <a class="btn btn-sm" href="{{ route('calendar') }}" wire:navigate><i class="fa-solid fa-calendar-days"></i> Kalender Konten</a>
            @endcan
            @can('approval.view')
                <a class="btn btn-sm" href="{{ route('approval.queue') }}" wire:navigate>
                    <i class="fa-solid fa-list-check"></i> Antrian Approval
                    @if ($statusCounts[\App\Enums\ContentStatus::PendingApproval->value] > 0)
                        <span class="badge badge-danger" style="margin-left:4px;">{{ $statusCounts[\App\Enums\ContentStatus::PendingApproval->value] }}</span>
                    @endif
                </a>
            @endcan
            @can('media.view')
                <a class="btn btn-sm" href="{{ route('media') }}" wire:navigate><i class="fa-solid fa-photo-film"></i> Pustaka Media ({{ $mediaCount }})</a>
            @endcan
        </div>
    </div>

    <div class="dash-grid">
        <div>
            @can('content.view')
                <div class="panel">
                    <p class="panel-title">Konten Terbaru</p>
                    <p class="panel-sub">6 konten yang terakhir dibuat/diubah.</p>

                    @forelse ($recentContent as $content)
                        @php($pill = $content->status->pill())
                        <div class="dash-list-item">
                            <span class="pill" style="background:var({{ $pill[0] }});color:var({{ $pill[1] }});">{{ $content->status->label() }}</span>
                            <a class="title-text" href="{{ route('konten.show', $content) }}" wire:navigate style="color:var(--accent);text-decoration:none;">{{ $content->title }}</a>
                            <span class="date-cell">{{ $content->creator?->name ?? '—' }} · {{ $content->updated_at->diffForHumans() }}</span>
                        </div>
                    @empty
                        <div class="dash-empty">
                            Belum ada konten.
                            @can('content.create')
                                <a href="{{ route('konten.create') }}" wire:navigate style="color:var(--accent);">Buat yang pertama</a>.
                            @endcan
                        </div>
                    @endforelse
                </div>

                <div class="panel">
                    <p class="panel-title">Akan Terbit</p>
                    <p class="panel-sub">Konten dengan jadwal publikasi terdekat.</p>

                    @forelse ($upcoming as $content)
                        <div class="dash-list-item">
                            <i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>
                            <a class="title-text" href="{{ route('konten.show', $content) }}" wire:navigate style="color:var(--accent);text-decoration:none;">{{ $content->title }}</a>
                            <span class="date-cell">{{ $content->scheduled_at->format('d M Y H:i') }}</span>
                        </div>
                    @empty
                        <div class="dash-empty">
                            Belum ada konten terjadwal.
                            @can('calendar.view')
                                <a href="{{ route('calendar') }}" wire:navigate style="color:var(--accent);">Lihat Kalender Konten</a>.
                            @endcan
                        </div>
                    @endforelse
                </div>
            @endcan
        </div>

        <div>
            @can('content.view')
                <div class="panel">
                    <p class="panel-title">Distribusi Platform</p>
                    <p class="panel-sub">Jumlah konten per platform (dari seluruh konten).</p>

                    @if ($totalContent > 0)
                        @foreach ($platforms as $p)
                            <div class="bar-row">
                                <span class="bar-label"><i class="{{ $p->icon() }}"></i> {{ $p->label() }}</span>
                                <span class="bar-track"><span class="bar-fill" style="width:{{ (int) round($platformCounts[$p->value] / $maxPlatformCount * 100) }}%;"></span></span>
                                <span class="bar-count">{{ $platformCounts[$p->value] }}</span>
                            </div>
                        @endforeach
                    @else
                        <div class="dash-empty">Belum ada data — buat konten dan pilih platformnya.</div>
                    @endif
                </div>
            @endcan

            <div class="panel">
                <p class="panel-title">Selamat datang, {{ auth()->user()->name }} 👋</p>
                <p class="panel-sub">
                    Anda masuk sebagai <strong>{{ auth()->user()->role?->name ?? 'Tanpa role' }}</strong>.
                </p>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                    <div class="stat" style="padding:12px 14px;">
                        <div class="stat-label">Total Pengguna</div>
                        <div class="stat-value" style="font-size:20px;">{{ $userCount }}</div>
                    </div>
                    <div class="stat" style="padding:12px 14px;">
                        <div class="stat-label">Total Role</div>
                        <div class="stat-value" style="font-size:20px;">{{ $roleCount }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
