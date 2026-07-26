<script>
    import AdminLayout from '@/layouts/AdminLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let stats = {};
    export let licenseDurations = [];
    export let defaultLicenseDuration = '1_month';
    export let licenses = [];
    export let errors = {};
    export let formState = {};

    let deletingLicenseIds = [];

    const toDateObject = (value) => {
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) {
            const [year, month, day] = value.split('-').map(Number);
            return new Date(year, month - 1, day, 12);
        }

        return new Date(value);
    };

    const formatDate = (value, withTime = false) => {
        if (!value) {
            return 'Not set';
        }

        return new Intl.DateTimeFormat('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            ...(withTime ? { hour: 'numeric', minute: '2-digit' } : {}),
        }).format(toDateObject(value));
    };

    const isDeletingLicense = (licenseId) => deletingLicenseIds.includes(licenseId);

    const handleLicenseDeleteSubmit = (event, license) => {
        if (isDeletingLicense(license.id)) {
            event.preventDefault();
            return;
        }

        const confirmed = window.confirm(
            `Delete license ${license.licenseKey}? This permanently removes the key from the admin registry.`
        );

        if (!confirmed) {
            event.preventDefault();
            return;
        }

        deletingLicenseIds = [...deletingLicenseIds, license.id];
    };

    const showRenewLicenseError = (license) =>
        Number(formState?.renewTargetLicenseId ?? 0) === license.id && Boolean(errors?.renew_license_code);

    const renewalInputValue = (license) =>
        Number(formState?.renewTargetLicenseId ?? 0) === license.id ? formState?.renewLicenseCode ?? '' : '';

    const pcLicenses = licenses.filter((license) => license.productType === 'pc_timer');
    const dtimerWifiLicenses = licenses.filter((license) => license.productType === 'piso_wifi');
</script>

<svelte:head>
    <title>License Dashboard | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName} current="licenses">
    <section class="stats-grid">
        <StatCard label="Total Licenses" value={stats.totalLicenses ?? 0} hint="Every generated activation key." accent="aqua" />
        <StatCard label="PC TimerApp" value={stats.pcLicenses ?? 0} hint="Desktop timer licenses." accent="mint" />
        <StatCard label="DTimer WiFi" value={stats.pisoWifiLicenses ?? 0} hint="Orange Pi device licenses." accent="orange" />
        <StatCard label="Active Devices" value={stats.activeDevices ?? 0} hint={`Heartbeat inside ${stats.activeWindowMinutes ?? 10} minutes. ${stats.expiredLicenses ?? 0} expired license${(stats.expiredLicenses ?? 0) === 1 ? '' : 's'} in the system.`} accent="rose" />
    </section>

    <section class="split-grid">
        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Create PC License</h2>
                    <p class="card-subtitle">Generate a TimerApp desktop license for PC timer clients.</p>
                </div>
            </div>

            <form method="POST" action="/admin/licenses/pc">
                <input type="hidden" name="_token" value={csrfToken} />

                <div class="field">
                    <span>License term</span>
                    <div class="duration-picker">
                        {#each licenseDurations as option}
                            <label class="duration-option">
                                <input
                                    type="radio"
                                    name="duration"
                                    value={option.value}
                                    checked={option.value === (formState?.licenseDuration || defaultLicenseDuration)}
                                    required
                                />
                                <span>{option.label}</span>
                            </label>
                        {/each}
                    </div>
                    <div class="field-help">Use this for the regular PC TimerApp license flow.</div>
                    <div class="field-help">Status checks, COM detection, heartbeats, and app startup do not consume license time.</div>
                    {#if errors?.duration}
                        <div class="field-error">{errors.duration}</div>
                    {/if}
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-button">Add PC License</button>
                    <a href="/admin/licenses/pc/export" class="secondary-button">Export PC DTimer App</a>
                </div>
            </form>
        </article>

        <article class="panel">
            <div class="section-heading">
                <div>
                    <h2>Create DTimer WiFi License</h2>
                    <p class="card-subtitle">Generate a DTimer WiFi license for Orange Pi machines and client admins.</p>
                </div>
            </div>

            <form method="POST" action="/admin/licenses/pisowifi">
                <input type="hidden" name="_token" value={csrfToken} />

                <div class="metric-grid license-lifetime-grid">
                    <div class="metric">
                        <strong>Lifetime</strong>
                        <span>No expiration date for DTimer WiFi devices</span>
                    </div>
                    <div class="metric">
                        <strong>Revocable</strong>
                        <span>Client revocation and admin delete stay available</span>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-button">Add DTimer WiFi License</button>
                    <a href="/admin/licenses/dtimer-wifi/export" class="secondary-button">Export DTimer WiFi</a>
                </div>
            </form>
        </article>
    </section>

    <article class="table-shell license-registry-shell">
        <div class="section-heading">
            <div>
                <h2>License Registry</h2>
                <p class="card-subtitle">Track frozen vs active licenses, provisioned devices, and the real expiry date that begins only after activation.</p>
            </div>
            <span class="chip">{pcLicenses.length} PC / {dtimerWifiLicenses.length} DTimer WiFi</span>
        </div>

        <div class="table-wrap">
            <table class="license-registry-table">
                <thead>
                    <tr>
                        <th>License key</th>
                        <th>Type</th>
                        <th>Device secret</th>
                        <th>Term</th>
                        <th>Expiry date</th>
                        <th>Device</th>
                        <th>Client</th>
                        <th>Provision status</th>
                        <th>License status</th>
                        <th class="action-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    {#if licenses.length}
                        {#each licenses as license}
                            <tr>
                                <td data-label="License key">
                                    <strong class="mono">{license.licenseKey}</strong>
                                    {#if license.appVersion}
                                        <span class="muted">App v{license.appVersion}</span>
                                    {/if}
                                </td>
                                <td data-label="Type">
                                    <TablePill status={license.productTypeLabel} />
                                </td>
                                <td data-label="Device secret">
                                    <strong class="mono secret-value">{license.deviceSecret}</strong>
                                    <span class="muted">Auto-generated when the license is created.</span>
                                </td>
                                <td data-label="Term">
                                    <strong>{license.durationLabel}</strong>
                                    <span class="muted">Created {formatDate(license.creationDate, true)}</span>
                                    {#if license.isConsumedForRenewal && license.consumedAt}
                                        <span class="muted">Consumed {formatDate(license.consumedAt, true)}</span>
                                    {/if}
                                    {#if license.renewalHistory?.length}
                                        {#each license.renewalHistory as renewal}
                                            <span class="muted">
                                                Renewed by {renewal.licenseKey} for {renewal.durationLabel} on {formatDate(renewal.consumedAt, true)}
                                            </span>
                                        {/each}
                                    {/if}
                                </td>
                                <td data-label="Expiry date">{license.isLifetime ? 'Lifetime' : license.expiryDate ? formatDate(license.expiryDate) : 'Starts after activation'}</td>
                                <td data-label="Device">
                                    <strong class="mono">{license.deviceId || 'Not linked yet'}</strong>
                                    <span class="muted">{license.deviceName}</span>
                                    {#if license.productType !== 'pc_timer' && license.machineId && license.machineId !== license.deviceId}
                                        <span class="muted">Machine ID {license.machineId}</span>
                                    {/if}
                                    {#if license.isConsumedForRenewal && license.consumedForLicenseKey}
                                        <span class="muted">Consumed for renewal of {license.consumedForLicenseKey}</span>
                                    {/if}
                                </td>
                                <td data-label="Client">
                                    <strong>{license.clientAccountName || 'Unclaimed'}</strong>
                                    {#if license.clientAccountId}
                                        <span class="muted">Client account #{license.clientAccountId}</span>
                                    {:else}
                                        <span class="muted">Available for client claim</span>
                                    {/if}
                                </td>
                                <td data-label="Provision status">
                                    <TablePill status={license.provisionStatus} />
                                </td>
                                <td data-label="License status">
                                    <TablePill status={license.licenseStatus} />
                                    {#if license.activatedAt}
                                        <div class="muted">Activated {formatDate(license.activatedAt, true)}</div>
                                    {/if}
                                    {#if license.lastSeenAt}
                                        <div class="muted">Last seen {formatDate(license.lastSeenAt, true)}</div>
                                    {:else if !license.activatedAt}
                                        <div class="muted">Waiting for Settings activation.</div>
                                    {/if}
                                </td>
                                <td class="action-column" data-label="Actions">
                                    <div class="license-action-stack">
                                        {#if license.canRenew}
                                            <form
                                                method="POST"
                                                action={`/admin/licenses/${license.id}/renew`}
                                                class="inline-action-form renew-license-form"
                                            >
                                                <input type="hidden" name="_token" value={csrfToken} />
                                                <input type="hidden" name="target_license_id" value={license.id} />
                                                <input
                                                    class="renew-license-input"
                                                    type="text"
                                                    name="renew_license_code"
                                                    placeholder="Renew license code"
                                                    inputmode="numeric"
                                                    maxlength="12"
                                                    value={renewalInputValue(license)}
                                                    required
                                                />
                                                {#if showRenewLicenseError(license)}
                                                    <div class="field-error">{errors.renew_license_code}</div>
                                                {/if}
                                                <button type="submit" class="secondary-button">Renew</button>
                                            </form>
                                        {/if}

                                        <form
                                            method="POST"
                                            action={`/admin/licenses/${license.id}`}
                                            class="inline-action-form"
                                            on:submit={(event) => handleLicenseDeleteSubmit(event, license)}
                                        >
                                            <input type="hidden" name="_token" value={csrfToken} />
                                            <input type="hidden" name="_method" value="DELETE" />
                                            <button type="submit" class="danger-button" disabled={isDeletingLicense(license.id)}>
                                                {isDeletingLicense(license.id) ? 'Deleting...' : 'Delete'}
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        {/each}
                    {:else}
                        <tr>
                            <td colspan="10">
                                <div class="empty-state">No licenses yet. Create the first one using the form above.</div>
                            </td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </article>
</AdminLayout>
