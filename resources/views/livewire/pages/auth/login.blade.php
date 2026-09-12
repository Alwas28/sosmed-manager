<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
    }
}; ?>

<div class="auth-card">
    <div class="auth-brand">
        <div class="brand-icon"><i class="fa-solid fa-share-nodes"></i></div>
        <div>
            <h1 class="auth-title">{{ config('app.name', 'Sosmed') }}</h1>
            <p class="auth-sub">Social Media Manager</p>
        </div>
    </div>

    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login">
        <div class="field">
            <label class="field-label" for="email">Email</label>
            <input wire:model="form.email" id="email" type="email" name="email" class="input"
                   required autofocus autocomplete="username">
            @error('form.email')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <div class="field">
            <label class="field-label" for="password">Kata Sandi</label>
            <input wire:model="form.password" id="password" type="password" name="password" class="input"
                   required autocomplete="current-password">
            @error('form.password')
                <div class="field-error">{{ $message }}</div>
            @enderror
        </div>

        <label class="auth-remember">
            <input wire:model="form.remember" type="checkbox" name="remember">
            <span>Ingat saya</span>
        </label>

        <button type="submit" class="btn btn-primary" style="width:100%;" wire:loading.attr="disabled">
            <span wire:loading.remove wire:target="login">Masuk</span>
            <span wire:loading wire:target="login">Memproses…</span>
        </button>

        @if (Route::has('password.request'))
            <div style="text-align:center;margin-top:16px;">
                <a class="auth-link" href="{{ route('password.request') }}" wire:navigate>Lupa kata sandi?</a>
            </div>
        @endif
    </form>
</div>
