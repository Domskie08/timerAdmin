<script>
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let csrfToken = '';
    export let errors = {};
    export let flash = {};
</script>

<svelte:head>
    <title>Admin Login | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <div class="login-shell">
        {#if flash?.error}
            <div class="flash flash-error">{flash.error}</div>
        {/if}

        <section class="panel login-panel">
            <p class="eyebrow">Admin Access</p>
            <h2>Sign in to manage licenses and TimerApp updates.</h2>
            <p class="section-copy">
                Only admin accounts can create new license keys, upload release files, and publish news to the homepage.
            </p>

            <form method="POST" action="/admin/login">
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
                    <span>Keep this admin session signed in</span>
                </label>

                <button type="submit" class="primary-button">Enter Dashboard</button>
            </form>

            <p class="footer-note">
                The seeded admin credentials are documented in the project README so they can be changed during setup.
            </p>
        </section>
    </div>
</PublicLayout>
