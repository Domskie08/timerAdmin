<script>
    import ClientLayout from '@/layouts/ClientLayout.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let errors = {};
    export let account = {};
    export let profile = {};
</script>

<svelte:head>
    <title>Settings | {appName}</title>
</svelte:head>

<ClientLayout {flash} {csrfToken} {appName} current="settings">
    <section class="section-heading page-heading">
        <div>
            <h2>Settings</h2>
            <p class="card-subtitle">{account.name || 'Client Account'}{account.contactEmail ? ` / ${account.contactEmail}` : ''}</p>
        </div>
    </section>

    <section class="dashboard-grid">
        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Change Password</h2>
                    <p class="card-subtitle">Update the password for this client admin login.</p>
                </div>
            </div>

            <form method="POST" action="/client/settings/password">
                <input type="hidden" name="_token" value={csrfToken} />
                <input type="hidden" name="_method" value="PUT" />

                <div class="field">
                    <label for="current_password">Current password</label>
                    <input id="current_password" type="password" name="current_password" autocomplete="current-password" required />
                    {#if errors?.current_password}
                        <div class="field-error">{errors.current_password}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="password">New password</label>
                    <input id="password" type="password" name="password" autocomplete="new-password" required />
                    {#if errors?.password}
                        <div class="field-error">{errors.password}</div>
                    {/if}
                </div>

                <div class="field">
                    <label for="password_confirmation">Confirm new password</label>
                    <input id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" required />
                </div>

                <button type="submit" class="primary-button">Change Password</button>
            </form>
        </article>

        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Account</h2>
                    <p class="card-subtitle">Client admin profile and account details.</p>
                </div>
            </div>

            <div class="metric-grid">
                <div class="metric">
                    <strong>{profile.name || 'Client Admin'}</strong>
                    <span>{profile.email || 'No email'}</span>
                </div>
                <div class="metric">
                    <strong>{account.name || 'Client Account'}</strong>
                    <span>{account.contactEmail || 'No contact email'}</span>
                </div>
            </div>
        </article>
    </section>
</ClientLayout>
