{{--
    Replaces `wire:confirm` (the browser's plain `window.confirm()` popup)
    with a styled modal that matches the rest of the admin UI. Wrap the
    trigger element in the slot — its own markup/classes/icon are untouched,
    only its click behaviour changes: clicking it just opens the modal, and
    `action` (a raw `$wire...` JS expression) only runs once the user
    confirms.

    Usage:
        <x-confirm-button message="Hapus konten “{{ $content->title }}”?" action="$wire.delete({{ $content->id }})">
            <button type="button" class="btn btn-sm btn-danger"><i class="fa-solid fa-trash"></i> Hapus</button>
        </x-confirm-button>
--}}
@props([
    'message',
    'action',
    'title' => 'Konfirmasi',
    'confirmLabel' => 'Ya, Lanjutkan',
    'confirmClass' => 'btn-danger',
    'icon' => 'fa-solid fa-triangle-exclamation',
    'iconColor' => 'var(--status-failed-text)',
])

<span x-data="{ open: false }" style="display:contents;">
    <span style="display:contents;cursor:pointer;" @click="open = true">
        {{ $slot }}
    </span>

    <template x-teleport="body">
        <div x-show="open" x-cloak class="modal-overlay confirm-overlay" @click.self="open = false">
            <div class="modal confirm-modal">
                <div class="modal-head">
                    <h3><i class="{{ $icon }}" style="color:{{ $iconColor }};"></i> {{ $title }}</h3>
                    <button type="button" class="modal-x" @click="open = false" aria-label="Tutup">&times;</button>
                </div>
                <p>{{ $message }}</p>
                <div class="modal-foot">
                    <button type="button" class="btn btn-sm btn-ghost" @click="open = false">Batal</button>
                    <button type="button" class="btn btn-sm {{ $confirmClass }}" @click="open = false; {!! $action !!}">{{ $confirmLabel }}</button>
                </div>
            </div>
        </div>
    </template>
</span>
