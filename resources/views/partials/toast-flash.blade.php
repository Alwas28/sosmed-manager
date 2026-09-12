{{--
    Fires a global toast for whatever session('status')/session('error') this
    page render carries — set by the component's own action (delete, save,
    submit, approve, dst.) via session()->flash(). Session flash data is
    already available on the SAME response that set it (Laravel flashes for
    "this request + the next"), so this fires correctly both right after a
    same-page Livewire action and after a full-page redirect.

    The actual toast UI lives once in components.admin-layout (a global
    Alpine listener on the "toast" window event) — this partial only ever
    dispatches, never renders any visible markup itself.
--}}
@if (session('status') || session('error'))
    <div
        x-data
        x-init="$dispatch('toast', { type: @js(session('error') ? 'error' : 'success'), message: @js(session('error') ?? session('status')) })"
        style="display:none"
    ></div>
@endif
