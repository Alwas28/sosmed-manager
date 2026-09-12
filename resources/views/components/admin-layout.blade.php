@props(['title' => 'Dashboard', 'subtitle' => null])
<!DOCTYPE html>
<html lang="id" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title }} — {{ config('app.name', 'Sosmed') }}</title>

    <script>
        (function () {
            try {
                if (localStorage.getItem('simsosmed.theme') === 'light') {
                    document.documentElement.classList.add('light');
                }
                var accent = localStorage.getItem('simsosmed.accent');
                if (accent) {
                    document.documentElement.style.setProperty('--accent', accent);
                }
            } catch (e) {}
        })();
    </script>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    @vite(['resources/css/app.css', 'resources/css/admin.css', 'resources/js/app.js'])
</head>
<body>
    <div class="overlay" id="overlay"></div>

    {{-- Global toast stack — any page/component fires one via
         `$dispatch('toast', { type, message })` (Alpine, from
         partials.toast-flash) or `$this->dispatch('toast', ...)`
         (Livewire component methods); both bubble here the same way. --}}
    <div
        class="toast-stack"
        x-data="{ toasts: [] }"
        x-on:toast.window="
            const id = Date.now() + Math.random();
            toasts.push({ id, type: $event.detail.type === 'error' ? 'error' : 'success', message: $event.detail.message });
            setTimeout(() => { toasts = toasts.filter(t => t.id !== id) }, 5000);
        "
    >
        <template x-for="t in toasts" :key="t.id">
            <div class="toast" :class="t.type === 'error' ? 'toast-error' : 'toast-success'" x-transition>
                <i class="toast-icon fa-solid" :class="t.type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check'"></i>
                <span class="toast-msg" x-text="t.message"></span>
                <button type="button" class="toast-close" aria-label="Tutup" @click="toasts = toasts.filter(x => x.id !== t.id)">&times;</button>
            </div>
        </template>
    </div>

    @include('partials.admin-sidebar')

    <div class="main">
        <header class="topbar">
            <div class="topbar-left">
                <button class="menu-btn" id="openSidebar" type="button" aria-label="Buka menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div>
                    <h1 class="page-title">{{ $title }}</h1>
                    @if ($subtitle)
                        <p class="page-sub">{{ $subtitle }}</p>
                    @endif
                </div>
            </div>

            <div class="topbar-right">
                <button class="toggle" id="themeToggle" type="button" aria-label="Ganti tema terang / gelap">
                    <div class="toggle-knob" id="toggleKnob"><i class="fa-solid fa-moon" id="toggleIcon"></i></div>
                </button>
                <button class="palette-btn" id="paletteBtn" type="button" aria-label="Pilih warna aksen">
                    <i class="fa-solid fa-palette"></i>
                </button>

                <div class="theme-panel" id="themePanel">
                    <div class="theme-panel-title">Warna aksen</div>
                    <div class="swatches" id="swatches"></div>
                    <div class="theme-panel-note">Tema &amp; warna aksen tersimpan otomatis di browser ini.</div>
                </div>
            </div>
        </header>

        <div class="content">
            {{ $slot }}
        </div>
    </div>
</body>
</html>
