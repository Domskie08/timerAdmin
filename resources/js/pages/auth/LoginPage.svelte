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
    <title>Admin Login | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <div class="login-shell">
        {#if flash?.error}
            <div class="flash flash-error">{flash.error}</div>
        {/if}

        <section class="panel login-panel">
            <p class="eyebrow">Admin Login</p>
            <h2>Sign in to your admin account.</h2>
            <p class="section-copy">
                Super admins open the license console. Client admins open the device portal from the same login.
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
                    <span>Keep this session signed in</span>
                </label>

                <div class="form-actions">
                    <button type="submit" class="primary-button">Login</button>
                    <Link href="/client/register" class="secondary-button">Create Client Account</Link>
                </div>
            </form>

            <p class="footer-note">
                Client admins can create an account first, then use this same login page after registration.
            </p>
        </section>
    </div>
</PublicLayout>
