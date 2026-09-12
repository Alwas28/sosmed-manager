<?php

use App\Models\BufferConnection;
use App\Services\Buffer\BufferAuthService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.admin-layout', ['title' => 'Integrasi Buffer', 'subtitle' => 'Hubungkan SIM_Sosmed ke Buffer sebagai gateway publikasi.'])] class extends Component
{
    public function with(): array
    {
        $auth = app(BufferAuthService::class);
        $connection = BufferConnection::current();

        return [
            'configured' => $auth->configured(),
            'supportsOAuth' => $auth->supportsOAuth(),
            'supportsStaticToken' => $auth->supportsStaticToken(),
            'redirectUri' => $auth->redirectUri(),
            'connection' => $connection,
            'channels' => $connection
                ? $connection->channels()->orderBy('service')->orderBy('username')->get()
                : new Collection,
        ];
    }
}; ?>

<div>
    @include('partials.toast-flash')

    <div class="panel">
        <h2 class="panel-title">Alur</h2>
        <p class="panel-sub" style="margin-bottom:0;">
            SIM_Sosmed &rarr; Buffer API &rarr; Social Media. SIM_Sosmed hanya menyimpan token Buffer
            (terenkripsi) — bukan password akun Facebook/Instagram/LinkedIn.
        </p>
    </div>

    @if (! $configured)
        <div class="panel">
            <h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation"></i> Kredensial belum diatur</h2>
            <p class="panel-sub">Di file <code>.env</code>, isi salah satu:</p>
            <ul class="panel-sub" style="margin:0 0 12px;padding-left:18px;">
                <li><strong>Opsi cepat</strong> — <code>BUFFER_ACCESS_TOKEN</code> (Access Token dari halaman aplikasi Buffer).</li>
                <li><strong>Opsi OAuth</strong> — <code>BUFFER_CLIENT_ID</code> + <code>BUFFER_CLIENT_SECRET</code>, lalu daftarkan Callback URL di bawah.</li>
            </ul>
            <p class="panel-sub" style="margin-bottom:0;">
                Setelah mengisi, jalankan <code>php artisan config:clear</code>.<br>
                Callback URL: <code>{{ $redirectUri }}</code>
            </p>
        </div>
    @elseif (! $connection)
        <div class="panel">
            <h2 class="panel-title">Belum terhubung</h2>
            @if ($supportsStaticToken)
                <p class="panel-sub">Access token terdeteksi di <code>.env</code> — hubungkan langsung:</p>
                <form method="POST" action="{{ route('buffer.connect-token') }}" style="margin-bottom:@if($supportsOAuth)16px @else 0 @endif;">
                    @csrf
                    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-bolt"></i> Hubungkan dengan Access Token</button>
                </form>
            @endif

            @if ($supportsOAuth)
                <p class="panel-sub">Atau lewat otorisasi OAuth Buffer:</p>
                <a class="btn @if($supportsStaticToken)btn-sm @else btn-primary @endif" href="{{ route('buffer.connect') }}">
                    <i class="fa-solid fa-plug"></i> Hubungkan via OAuth
                </a>
                <p class="field-hint" style="margin-top:10px;">Callback URL: <code>{{ $redirectUri }}</code></p>
            @endif
        </div>
    @else
        <div class="panel">
            <div class="toolbar" style="margin-bottom:12px;">
                <div>
                    <h2 class="panel-title" style="margin-bottom:2px;">
                        <span class="badge badge-accent">Terhubung</span>
                        {{ $connection->name ?? 'Akun Buffer' }}
                    </h2>
                    <p class="panel-sub" style="margin:0;">
                        Buffer user: <code>{{ $connection->buffer_user_id ?? '—' }}</code>
                        · Organisasi: <code>{{ $connection->organization_id ?? '—' }}</code>
                        @if ($connection->connectedBy)· oleh {{ $connection->connectedBy->name }}@endif
                        @if ($connection->last_synced_at)· sinkron {{ $connection->last_synced_at->diffForHumans() }}@endif
                    </p>
                </div>
                <div style="display:flex;gap:8px;">
                    <form method="POST" action="{{ route('buffer.sync') }}">
                        @csrf
                        <button class="btn btn-sm" type="submit"><i class="fa-solid fa-arrows-rotate"></i> Sinkron Channel</button>
                    </form>
                    <form method="POST" action="{{ route('buffer.disconnect') }}"
                          onsubmit="return confirm('Putuskan koneksi Buffer? Channel yang tersimpan akan dihapus.')">
                        @csrf
                        @method('DELETE')
                        <button class="btn btn-sm btn-danger" type="submit"><i class="fa-solid fa-link-slash"></i> Putuskan</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2 class="panel-title">Channel Social Media ({{ $channels->count() }})</h2>
            @if ($channels->isEmpty())
                <p class="panel-sub" style="margin-bottom:0;">Belum ada channel. Tambahkan akun di Buffer, lalu klik <strong>Sinkron Channel</strong>.</p>
            @else
                <div class="table-wrap" style="margin-top:6px;">
                    <table>
                        <thead>
                            <tr><th>Platform</th><th>Akun</th><th>Tipe</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($channels as $channel)
                                <tr>
                                    <td><i class="{{ $channel->iconClass() }}"></i> {{ $channel->serviceLabel() }}</td>
                                    <td class="title-text">{{ $channel->username ?? $channel->display_name ?? $channel->buffer_profile_id }}</td>
                                    <td class="date-cell">{{ $channel->service_type ?? '—' }}</td>
                                    <td>
                                        <span class="badge {{ $channel->is_active ? 'badge-accent' : '' }}">
                                            {{ $channel->is_active ? 'aktif' : 'nonaktif' }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
