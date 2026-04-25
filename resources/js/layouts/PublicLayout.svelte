<script>
    import { Link } from '@inertiajs/svelte';

    export let auth = { user: null };
    export let appName = 'TimerAdmin';
</script>

<div class="page-shell">
    <header class="site-header">
        <Link href="/" class="brand-lockup">
            <div class="brand-mark">TA</div>
            <div>
                <h1 class="brand-title">{appName}</h1>
                <p class="brand-copy">Dark Fusion license command center for TimerApp.</p>
            </div>
        </Link>

        <nav class="site-nav">
            <Link href="/" class="ghost-button">Home</Link>
            <Link href="/support" class="ghost-button">Support</Link>
            {#if auth?.user?.is_admin}
                <Link href="/admin" class="primary-button">Open Admin</Link>
            {:else}
                <Link href="/admin/login" class="primary-button">Admin Login</Link>
            {/if}
        </nav>
    </header>

    <slot />
</div>

