<?php

use App\Models\BufferConnection;
use App\Models\SocialChannel;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Akun Social Media', 'subtitle' => 'Akun yang terhubung melalui Buffer.'])] class extends Component
{
    public function with(): array
    {
        return [
            'connection' => BufferConnection::current(),
            'groups' => SocialChannel::query()
                ->orderBy('service')
                ->orderBy('username')
                ->get()
                ->groupBy('service'),
        ];
    }
}; ?>

<div>
    @if (! $connection)
        <div class="panel" style="text-align:center;padding:48px 20px;">
            <div style="font-size:30px;color:var(--text-muted);margin-bottom:12px;"><i class="fa-solid fa-plug-circle-xmark"></i></div>
            <h2 class="panel-title">Buffer belum terhubung</h2>
            <p class="panel-sub">Hubungkan Buffer dulu untuk menarik daftar akun social media.</p>
            @can('buffer.manage')
                <a class="btn btn-primary btn-sm" href="{{ route('buffer') }}" wire:navigate>Ke Integrasi Buffer</a>
            @endcan
        </div>
    @elseif ($groups->isEmpty())
        <div class="panel">
            <p class="panel-sub" style="margin:0;">Belum ada channel tersinkron. Buka <strong>Integrasi Buffer</strong> dan klik <em>Sinkron Channel</em>.</p>
        </div>
    @else
        @foreach ($groups as $service => $channels)
            <div class="panel">
                <h2 class="panel-title">
                    <i class="{{ $channels->first()->iconClass() }}"></i> {{ $channels->first()->serviceLabel() }}
                    <span class="badge">{{ $channels->count() }}</span>
                </h2>
                <div class="table-wrap" style="margin-top:8px;">
                    <table>
                        <thead>
                            <tr><th>Akun</th><th>Tipe</th><th>Zona Waktu</th><th>Buffer Profile ID</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($channels as $channel)
                                <tr>
                                    <td class="title-text">{{ $channel->username ?? $channel->display_name ?? '—' }}</td>
                                    <td class="date-cell">{{ $channel->service_type ?? '—' }}</td>
                                    <td class="date-cell">{{ $channel->timezone ?? '—' }}</td>
                                    <td class="date-cell"><code>{{ $channel->buffer_profile_id }}</code></td>
                                    <td><span class="badge {{ $channel->is_active ? 'badge-accent' : '' }}">{{ $channel->is_active ? 'aktif' : 'nonaktif' }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endforeach
    @endif
</div>
