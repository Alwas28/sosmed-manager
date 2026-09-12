<x-admin-layout title="Profil" subtitle="Kelola informasi akun Anda.">
    <div style="max-width:640px;">
        <div class="panel">
            <livewire:profile.update-profile-information-form />
        </div>

        <div class="panel">
            <livewire:profile.update-password-form />
        </div>

        <div class="panel">
            <livewire:profile.delete-user-form />
        </div>
    </div>
</x-admin-layout>
