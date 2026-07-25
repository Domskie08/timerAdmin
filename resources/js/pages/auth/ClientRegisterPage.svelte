<script>
    import { Link } from '@inertiajs/svelte';
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let csrfToken = '';
    export let errors = {};
</script>

<svelte:head>
    <title>Client Registration | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <div class="login-shell">
        <section class="panel login-panel">
            <p class="eyebrow">Client Admin</p>
            <h2>Create a DTimer WiFi account.</h2>
            <p class="section-copy">Claim purchased license keys and monitor linked Orange Pi machines.</p>

            <form method="POST" action="/client/register">
                <input type="hidden" name="_token" value={csrfToken} />

                <div class="field">
                    <label for="account_name">Client or shop name</label>
                    <input id="account_name" type="text" name="account_name" required autocomplete="organization" />
                    {#if errors?.account_name}
                        <div class="field-error">{errors.account_name}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="name">Admin name</label>
                    <input id="name" type="text" name="name" required autocomplete="name" />
                    {#if errors?.name}
                        <div class="field-error">{errors.name}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input id="email" type="email" name="email" required autocomplete="email" />
                    {#if errors?.email}
                        <div class="field-error">{errors.email}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" type="password" name="password" required autocomplete="new-password" />
                    {#if errors?.password}
                        <div class="field-error">{errors.password}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" />
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-button">Create Client Account</button>
                    <Link href="/admin/login" class="secondary-button">Admin Login</Link>
                </div>
            </form>
        </section>
    </div>
</PublicLayout>
