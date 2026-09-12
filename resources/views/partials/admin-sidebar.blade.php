@php($authUser = auth()->user())
@php($initials = $authUser
    ? \Illuminate\Support\Str::of($authUser->name)->explode(' ')->filter()->map(fn ($p) => mb_substr($p, 0, 1))->take(2)->implode('')
    : '?')
@php($pendingApprovalCount = $authUser?->hasPermission('approval.view')
    ? \App\Models\Content::where('status', \App\Enums\ContentStatus::PendingApproval->value)->count()
    : 0)
@php($revisionCount = $authUser?->hasPermission('content.view')
    ? \App\Models\Content::where('status', \App\Enums\ContentStatus::Revision->value)
        ->when(! $authUser->hasPermission('approval.view'), fn ($q) => $q->where('created_by', $authUser->id))
        ->count()
    : 0)

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <div class="brand-left">
            <div class="brand-icon"><i class="fa-solid fa-share-nodes"></i></div>
            <div>
                <div class="brand-name">{{ config('app.name', 'Sosmed') }}</div>
                <div class="brand-sub">Social Media Manager</div>
            </div>
        </div>
        <button class="close-btn" id="closeSidebar" type="button" aria-label="Tutup menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <nav class="menu">
        @foreach (\App\Support\AdminMenu::visibleSections($authUser) as $section => $items)
            <div class="nav-section">
                <div class="nav-section-label">{{ $section }}</div>
                @foreach ($items as $item)
                    <a class="nav-item {{ request()->routeIs($item['route']) ? 'active' : '' }}"
                       href="{{ route($item['route']) }}" wire:navigate>
                        <i class="icon fa-solid {{ $item['icon'] }}"></i>
                        <span class="label">{{ $item['label'] }}</span>
                        @if ($item['route'] === 'approval.queue' && $pendingApprovalCount > 0)
                            <span class="badge badge-accent">{{ $pendingApprovalCount }}</span>
                        @elseif ($item['route'] === 'konten.revision' && $revisionCount > 0)
                            <span class="badge badge-danger">{{ $revisionCount }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>

    <div class="sidebar-footer">
        <div class="profile-menu" id="profileMenu">
            <a href="{{ route('profile') }}" wire:navigate><i class="fa-solid fa-user"></i> Profil</a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="logout"><i class="fa-solid fa-arrow-right-from-bracket"></i> Keluar</button>
            </form>
        </div>
        <button class="profile-btn" id="profileBtn" type="button">
            <div class="avatar">{{ $initials }}</div>
            <div class="footer-text">
                <div class="footer-name">{{ $authUser?->name }}</div>
                <div class="footer-role">{{ $authUser?->role?->name ?? 'Tanpa role' }}</div>
            </div>
            <i class="fa-solid fa-chevron-up profile-chevron"></i>
        </button>
    </div>
</aside>
