<script>
    import { Link } from '@inertiajs/svelte';

    export let flash = {};
    export let csrfToken = '';
    export let appName = 'TimerAdmin';
</script>

<div class="page-shell">
    <header class="admin-header">
        <div class="brand-lockup">
            <div class="brand-mark">TA</div>
            <div>
                <h1 class="brand-title">{appName}</h1>
                <p class="brand-copy">License creation, news publishing, and update delivery.</p>
            </div>
        </div>

        <div class="header-actions">
            <Link href="/" class="ghost-button">View Home</Link>
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
