<script>
    import { Link } from '@inertiajs/svelte';

    export let flash = {};
    export let csrfToken = '';
    export let appName = 'TimerAdmin';
    export let current = 'dashboard';

    const navClass = (key) => key === current ? 'ghost-button nav-active' : 'ghost-button';
</script>

<div class="page-shell">
    <header class="admin-header">
        <div class="brand-lockup">
            <div class="brand-mark">TD</div>
            <div>
                <h1 class="brand-title">Timer Devices</h1>
                <p class="brand-copy">Client device console for {appName}.</p>
            </div>
        </div>

        <div class="header-actions">
            <Link href="/client" class={navClass('dashboard')}>Overview</Link>
            <Link href="/client/pc-timer" class={navClass('pc-timer')}>PC Timer</Link>
            <Link href="/client/dtimer-wifi" class={navClass('dtimer-wifi')}>DTimer WiFi</Link>
            <Link href="/client/licensing" class={navClass('licensing')}>Licensing</Link>
            <form method="POST" action="/logout">
                <input type="hidden" name="_token" value={csrfToken} />
                <button type="submit" class="secondary-button">Logout</button>
            </form>
        </div>
    </header>

    {#if flash?.success}
        <div class="flash flash-success">{flash.success}</div>
    {/if}

    {#if flash?.error}
        <div class="flash flash-error">{flash.error}</div>
    {/if}

    <main class="admin-main">
        <slot />
    </main>
</div>
