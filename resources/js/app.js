/* =========================================================
   SIM_Sosmed — admin shell interactions
   Theme (dark/light) + accent color are persisted in
   localStorage. Sidebar / dropdown behaviour is delegated
   from `document` so it survives Livewire `wire:navigate`.
   ========================================================= */

const THEME_KEY = 'simsosmed.theme';
const ACCENT_KEY = 'simsosmed.accent';

const ACCENTS = [
    { hex: '#6366F1', name: 'Indigo' },
    { hex: '#8B5CF6', name: 'Violet' },
    { hex: '#10B981', name: 'Emerald' },
    { hex: '#0EA5E9', name: 'Sky' },
    { hex: '#F59E0B', name: 'Amber' },
    { hex: '#F43F5E', name: 'Rose' },
];

function readStore(key) {
    try {
        return localStorage.getItem(key);
    } catch (e) {
        return null;
    }
}

function writeStore(key, value) {
    try {
        localStorage.setItem(key, value);
    } catch (e) {
        /* storage unavailable — ignore */
    }
}

function currentTheme() {
    return document.documentElement.classList.contains('light') ? 'light' : 'dark';
}

/**
 * Re-apply the stored theme + accent to <html>. Needed after a
 * `wire:navigate` visit, which swaps in a fresh server-rendered
 * <html> element and drops the classes we set client-side.
 */
function restoreTheme() {
    document.documentElement.classList.toggle('light', readStore(THEME_KEY) === 'light');
    const accent = readStore(ACCENT_KEY);
    if (accent) {
        document.documentElement.style.setProperty('--accent', accent);
    }
}

function applyTheme(theme) {
    document.documentElement.classList.toggle('light', theme === 'light');
    writeStore(THEME_KEY, theme);
    syncThemeIcon();
}

function syncThemeIcon() {
    const icon = document.getElementById('toggleIcon');
    if (icon) {
        icon.className = currentTheme() === 'light' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
    }
}

function activeAccent() {
    return readStore(ACCENT_KEY) || ACCENTS[0].hex;
}

function applyAccent(hex) {
    document.documentElement.style.setProperty('--accent', hex);
    writeStore(ACCENT_KEY, hex);
    document.querySelectorAll('.swatch').forEach((s) => {
        const on = s.dataset.hex === hex;
        s.classList.toggle('active', on);
        s.innerHTML = on ? '<i class="fa-solid fa-check"></i>' : '';
    });
}

function buildSwatches() {
    const wrap = document.getElementById('swatches');
    if (!wrap || wrap.dataset.ready) return;
    wrap.dataset.ready = '1';

    const active = activeAccent();
    wrap.innerHTML = '';
    ACCENTS.forEach((accent) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'swatch' + (accent.hex === active ? ' active' : '');
        btn.style.background = accent.hex;
        btn.style.color = accent.hex;
        btn.title = accent.name;
        btn.dataset.hex = accent.hex;
        btn.innerHTML = accent.hex === active ? '<i class="fa-solid fa-check"></i>' : '';
        wrap.appendChild(btn);
    });
}

function initChrome() {
    restoreTheme();
    syncThemeIcon();
    buildSwatches();
}

document.addEventListener('DOMContentLoaded', initChrome);
// Fires as early as possible during a wire:navigate visit to avoid a flash.
document.addEventListener('livewire:navigating', restoreTheme);
document.addEventListener('livewire:navigated', initChrome);

document.addEventListener('click', (e) => {
    const target = e.target;

    if (target.closest('#themeToggle')) {
        applyTheme(currentTheme() === 'light' ? 'dark' : 'light');
        return;
    }

    const swatch = target.closest('.swatch');
    if (swatch) {
        applyAccent(swatch.dataset.hex);
        return;
    }

    if (target.closest('#paletteBtn')) {
        e.stopPropagation();
        document.getElementById('themePanel')?.classList.toggle('show');
        return;
    }

    if (target.closest('#openSidebar')) {
        document.getElementById('sidebar')?.classList.add('open');
        document.getElementById('overlay')?.classList.add('show');
        return;
    }

    if (target.closest('#closeSidebar') || target.closest('#overlay')) {
        document.getElementById('sidebar')?.classList.remove('open');
        document.getElementById('overlay')?.classList.remove('show');
        return;
    }

    if (target.closest('#profileBtn')) {
        e.stopPropagation();
        document.getElementById('profileMenu')?.classList.toggle('show');
        document.getElementById('profileBtn')?.classList.toggle('open');
        return;
    }

    if (!target.closest('#themePanel') && !target.closest('#paletteBtn')) {
        document.getElementById('themePanel')?.classList.remove('show');
    }
    if (!target.closest('#profileMenu') && !target.closest('#profileBtn')) {
        document.getElementById('profileMenu')?.classList.remove('show');
        document.getElementById('profileBtn')?.classList.remove('open');
    }
});

// Close the mobile sidebar after navigating to a new page.
document.addEventListener('livewire:navigated', () => {
    document.getElementById('sidebar')?.classList.remove('open');
    document.getElementById('overlay')?.classList.remove('show');
});
