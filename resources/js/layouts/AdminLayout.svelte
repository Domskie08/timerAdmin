<script>
    import { Link } from '@inertiajs/svelte';

    export let flash = {};
    export let csrfToken = '';
    export let appName = 'TimerAdmin';

    export let current = 'licenses';

    const navClass = (key) => `ghost-button${current === key ? ' nav-active' : ''}`;
</script>

<div class="page-shell">
    <header class="admin-header">
        <div class="brand-lockup">
            <div class="brand-mark">TA</div>
            <div>
                <h1 class="brand-title">{appName}</h1>
                <p class="brand-copy">License registry, client accounts, and setup tools.</p>
            </div>
        </div>

        <div class="header-actions">
            <Link href="/" class="ghost-button">View Home</Link>
            <Link href="/admin" class={navClass('licenses')}>Licenses</Link>
            <Link href="/admin/clients" class={navClass('clients')}>Clients</Link>
            <Link href="/admin/setup" class={navClass('setup')}>Setup</Link>
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
