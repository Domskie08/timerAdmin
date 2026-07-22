<script>
    import { Link } from '@inertiajs/svelte';
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let csrfToken = '';
    export let errors = {};
    export let flash = {};
</script>

<svelte:head>
    <title>Client Login | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <div class="login-shell">
        {#if flash?.error}
            <div class="flash flash-error">{flash.error}</div>
        {/if}

        <section class="panel login-panel">
            <p class="eyebrow">Client Admin</p>
            <h2>Sign in to DTimer WiFi.</h2>
            <p class="section-copy">View machines, coin sales, licensing, and revocation requests for your account.</p>

            <form method="POST" action="/client/login">
                <input type="hidden" name="_token" value={csrfToken} />

                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" required autocomplete="email" />
                    {#if errors?.email}
                        <div class="field-error">{errors.email}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="current-password" />
                    {#if errors?.password}
                        <div class="field-error">{errors.password}</div>
                    {/if}
                </div>

                <label class="field-inline">
                    <input type="checkbox" name="remember" value="1" />
                    <span>Keep this client session signed in</span>
                </label>

                <div class="form-actions">
                    <button type="submit" class="primary-button">Enter Client Portal</button>
                    <Link href="/client/register" class="secondary-button">Create Account</Link>
                </div>
            </form>
        </section>
    </div>
</PublicLayout>
