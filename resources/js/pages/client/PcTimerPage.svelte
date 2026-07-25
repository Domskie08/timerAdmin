<script>
    import ClientLayout from '@/layouts/ClientLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let stats = {};
    export let licenses = [];

    const formatMoney = (amountMinor) =>
        new Intl.NumberFormat('en-PH', {
            style: 'currency',
            currency: 'PHP',
        }).format((Number(amountMinor) || 0) / 100);

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
    <title>PC Timer | {appName}</title>
</svelte:head>

<ClientLayout {flash} {csrfToken} {appName} current="pc-timer">
    <section class="stats-grid">
        <StatCard label="PC Timer Devices" value={stats.totalDevices ?? 0} hint={`${stats.onlineDevices ?? 0} online`} accent="aqua" />
        <StatCard label="Today Sales" value={formatMoney(stats.todaySalesAmountMinor ?? 0)} hint="PC Timer only" accent="mint" />
        <StatCard label="Coin Sales" value={formatMoney(stats.totalSalesAmountMinor ?? 0)} hint="All PC Timer devices" accent="orange" />
        <StatCard label="Online Window" value={`${stats.activeWindowMinutes ?? 10}m`} hint="Last heartbeat threshold" accent="rose" />
    </section>

    <article class="table-shell">
        <div class="section-heading">
            <div>
                <h2>PC Timer Devices</h2>
                <p class="card-subtitle">License-bound timer devices, expiration, app version, and coin sales.</p>
            </div>
            <span class="chip">{licenses.length} device{licenses.length === 1 ? '' : 's'}</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Device</th>
                        <th>License</th>
                        <th>Sales</th>
                        <th>App</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    {#if licenses.length}
                        {#each licenses as license}
                            <tr>
                                <td>
                                    <strong>{license.deviceName || 'Not activated yet'}</strong>
                                    <span class="muted mono">{license.deviceId || 'No device ID'}</span>
                                    <TablePill status={license.provisionStatus} />
                                </td>
                                <td>
                                    <strong class="mono">{license.licenseKey}</strong>
                                    <TablePill status={license.status} />
                                    <span class="muted">{license.durationLabel}</span>
                                    <span class="muted">{license.expiresAt ? `Expires ${formatDate(license.expiresAt)}` : 'Starts after activation'}</span>
                                </td>
                                <td>
                                    <strong>{formatMoney(license.salesAmountMinor)}</strong>
                                    <span class="muted">{license.salesCount ?? 0} event{(license.salesCount ?? 0) === 1 ? '' : 's'} &middot; {license.pulseCount ?? 0} pulse{(license.pulseCount ?? 0) === 1 ? '' : 's'}</span>
                                    <span class="muted">Today {formatMoney(license.todaySalesAmountMinor)} / {license.todaySalesCount ?? 0} event{(license.todaySalesCount ?? 0) === 1 ? '' : 's'}</span>
                                </td>
                                <td>
                                    <span class="muted">App {license.appVersion || 'not reported'}</span>
                                    <span class="muted">Activated {license.activatedAt ? formatDate(license.activatedAt, true) : 'not yet'}</span>
                                </td>
                                <td>{license.lastSeenAt ? formatDate(license.lastSeenAt, true) : 'Not seen yet'}</td>
                            </tr>
                        {/each}
                    {:else}
                        <tr>
                            <td colspan="5">
                                <div class="empty-state">No PC Timer licenses have been claimed by this account.</div>
                            </td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </article>

    {#if licenses.some((license) => license.recentSales?.length)}
        <section class="split-grid">
            {#each licenses.filter((license) => license.recentSales?.length) as license}
                <article class="panel">
                    <div class="section-heading">
                        <h3>{license.deviceName || license.licenseKey}</h3>
                        <span class="chip">{license.recentSales.length} recent</span>
                    </div>
                    <div class="list-rail">
                        {#each license.recentSales as sale}
                            <div class="list-item">
                                <header>
                                    <strong>{formatMoney(sale.amountMinor)}</strong>
                                    <span class="pill-tag">{formatDate(sale.occurredAt, true)}</span>
                                </header>
                                <div class="support-copy">{sale.localEventId} &middot; {sale.pulseCount ?? 0} pulse{(sale.pulseCount ?? 0) === 1 ? '' : 's'}</div>
                            </div>
                        {/each}
                    </div>
                </article>
            {/each}
        </section>
    {/if}
</ClientLayout>
