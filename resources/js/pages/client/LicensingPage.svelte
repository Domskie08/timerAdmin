<script>
    import ClientLayout from '@/layouts/ClientLayout.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let errors = {};
    export let licenses = [];

    const formatDate = (value, withTime = false) => {
        if (!value) {
            return 'Not set';
        }

        return new Intl.DateTimeFormat('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
        }).format(new Date(value));
    };
</script>

<svelte:head>
    <title>Client Licensing | {appName}</title>
</svelte:head>

<ClientLayout {flash} {csrfToken} {appName} current="licensing">
    <section class="dashboard-grid">
        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Claim License</h2>
                    <p class="card-subtitle">Add purchased PC Timer and DTimer WiFi license keys to this client account.</p>
                </div>
            </div>

            <div class="form-grid">
                <form method="POST" action="/client/licenses/claim">
                    <input type="hidden" name="_token" value={csrfToken} />
                    <input type="hidden" name="product_type" value="pc_timer" />

                    <div class="field">
                        <label for="pc_license_key">PC Timer license key</label>
                        <input id="pc_license_key" type="text" name="license_key" inputmode="numeric" maxlength="12" required />
                        {#if errors?.license_key}
                            <div class="field-error">{errors.license_key}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button">Claim PC Timer</button>
                </form>

                <form method="POST" action="/client/licenses/claim">
                    <input type="hidden" name="_token" value={csrfToken} />
                    <input type="hidden" name="product_type" value="piso_wifi" />

                    <div class="field">
                        <label for="dtimer_license_key">DTimer WiFi license key</label>
                        <input id="dtimer_license_key" type="text" name="license_key" inputmode="numeric" maxlength="12" required />
                        {#if errors?.license_key}
                            <div class="field-error">{errors.license_key}</div>
                        {/if}
                    </div>

                    <button type="submit" class="secondary-button">Claim DTimer WiFi</button>
                </form>
            </div>
        </article>

        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Revocation Timing</h2>
                    <p class="card-subtitle">Client revocation requests unlink machines after 30 days.</p>
                </div>
            </div>
            <div class="metric-grid">
                <div class="metric">
                    <strong>30 days</strong>
                    <span>Pending period before unlink</span>
                </div>
                <div class="metric">
                    <strong>MAC match</strong>
                    <span>SD-card recovery can relink</span>
                </div>
            </div>
        </article>
    </section>

    <article class="table-shell">
        <div class="section-heading">
            <div>
                <h2>Licenses</h2>
                <p class="card-subtitle">Claimed licenses, linked machines, and pending revocations.</p>
            </div>
            <span class="chip">{licenses.length} license{licenses.length === 1 ? '' : 's'}</span>
        </div>

        <div class="table-wrap">
            <table class="license-registry-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>License</th>
                        <th>Status</th>
                        <th>Device</th>
                        <th>Revocation</th>
                        <th class="action-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {#if licenses.length}
                        {#each licenses as license}
                            <tr>
                                <td>
                                    <TablePill status={license.productTypeLabel} />
                                </td>
                                <td>
                                    <strong class="mono">{license.licenseKey}</strong>
                                    <span class="muted">{license.durationLabel}</span>
                                    <span class="muted secret-value">{license.deviceSecret}</span>
                                </td>
                                <td>
                                    <TablePill status={license.status} />
                                    <div class="muted">{license.provisionStatus}</div>
                                    <div class="muted">{license.isLifetime ? 'Lifetime' : license.expiryDate ? `Expires ${formatDate(license.expiryDate)}` : 'Starts after activation'}</div>
                                </td>
                                <td>
                                    {#if license.productType === 'piso_wifi' && license.machine}
                                        <strong>{license.machine.deviceName || 'DTimer machine'}</strong>
                                        <span class="muted mono">{license.machine.macAddress}</span>
                                        <span class="muted">{license.machine.lastSeenAt ? `Last seen ${formatDate(license.machine.lastSeenAt, true)}` : 'Not seen yet'}</span>
                                    {:else if license.productType === 'pc_timer'}
                                        <strong>{license.deviceName || 'Not activated yet'}</strong>
                                        <span class="muted mono">{license.deviceId || 'No device ID'}</span>
                                        <span class="muted">{license.lastSeenAt ? `Last seen ${formatDate(license.lastSeenAt, true)}` : 'Not seen yet'}</span>
                                    {:else}
                                        <strong>Not linked yet</strong>
                                        <span class="muted">Waiting for Orange Pi machine link</span>
                                    {/if}
                                </td>
                                <td>
                                    {#if license.productType === 'pc_timer'}
                                        <span class="muted">Not available for PC Timer</span>
                                    {:else if license.pendingRevocation}
                                        <TablePill status="Pending" />
                                        <span class="muted">Requested {formatDate(license.pendingRevocation.requestedAt, true)}</span>
                                        <span class="muted">Unlinks {formatDate(license.pendingRevocation.effectiveAt, true)}</span>
                                    {:else}
                                        <span class="muted">No pending revocation</span>
                                    {/if}
                                </td>
                                <td class="action-column">
                                    {#if license.productType === 'piso_wifi' && license.machine && !license.pendingRevocation}
                                        <form method="POST" action={`/client/licenses/${license.id}/revocations`} class="inline-action-form renew-license-form">
                                            <input type="hidden" name="_token" value={csrfToken} />
                                            <input class="renew-license-input" type="text" name="reason" placeholder="Reason" maxlength="255" />
                                            {#if errors?.reason}
                                                <div class="field-error">{errors.reason}</div>
                                            {/if}
                                            <button type="submit" class="danger-button">Request Revocation</button>
                                        </form>
                                    {:else if license.productType === 'piso_wifi' && license.pendingRevocation}
                                        <span class="muted">Waiting for 30-day unlink</span>
                                    {:else if license.productType === 'pc_timer'}
                                        <span class="muted">No revocation flow</span>
                                    {:else}
                                        <span class="muted">No machine to revoke</span>
                                    {/if}
                                </td>
                            </tr>
                        {/each}
                    {:else}
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">No licenses have been claimed by this account.</div>
                            </td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </article>
</ClientLayout>
