<script>
    import ClientLayout from '@/layouts/ClientLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let account = {};
    export let stats = {};
    export let machines = [];
    export let recentSales = [];

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
    <title>Client Dashboard | {appName}</title>
</svelte:head>

<ClientLayout {flash} {csrfToken} {appName} current="dashboard">
    <section class="section-heading page-heading">
        <div>
            <h2>{account.name || 'Client Account'}</h2>
            <p class="card-subtitle">{account.contactEmail || 'DTimer WiFi client admin'}</p>
        </div>
    </section>

    <section class="stats-grid">
        <StatCard label="Machines" value={stats.machines ?? 0} hint={`${stats.onlineMachines ?? 0} online now`} accent="aqua" />
        <StatCard label="Licenses" value={stats.linkedLicenses ?? 0} hint="Linked DTimer WiFi licenses" accent="mint" />
        <StatCard label="Today Sales" value={formatMoney(stats.todaySalesAmountMinor ?? 0)} hint={`${stats.todaySalesCount ?? 0} coin sale event${(stats.todaySalesCount ?? 0) === 1 ? '' : 's'}`} accent="orange" />
        <StatCard label="All Sales" value={formatMoney(stats.totalSalesAmountMinor ?? 0)} hint={`Heartbeat window ${stats.activeWindowMinutes ?? 10} minutes`} accent="rose" />
    </section>

    <section class="dashboard-grid">
        <article class="table-shell">
            <div class="section-heading">
                <h2>DTimer WiFi Machines</h2>
                <a href="/client/dtimer-wifi" class="secondary-button">Open DTimer WiFi</a>
            </div>

            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Machine</th>
                            <th>Status</th>
                            <th>Users</th>
                            <th>License</th>
                            <th>Last seen</th>
                        </tr>
                    </thead>
                    <tbody>
                        {#if machines.length}
                            {#each machines as machine}
                                <tr>
                                    <td>
                                        <strong>{machine.deviceName || 'DTimer machine'}</strong>
                                        <span class="muted mono">{machine.macAddress}</span>
                                    </td>
                                    <td>
                                        <TablePill status={machine.statusLabel} />
                                        <div class="muted">{machine.wifiStatus || 'WiFi not reported'} / {machine.timerStatus || 'Timer not reported'}</div>
                                    </td>
                                    <td>
                                        <strong>{machine.connectedUsers ?? 0}</strong>
                                        <span class="muted">{machine.activeSessions ?? 0} active session{(machine.activeSessions ?? 0) === 1 ? '' : 's'}</span>
                                    </td>
                                    <td>
                                        <strong class="mono">{machine.licenseKey || 'Not linked'}</strong>
                                        <span class="muted">{machine.expiresAt ? `Expires ${formatDate(machine.expiresAt)}` : 'No active expiry'}</span>
                                    </td>
                                    <td>{machine.lastSeenAt ? formatDate(machine.lastSeenAt, true) : 'Not seen yet'}</td>
                                </tr>
                            {/each}
                        {:else}
                            <tr>
                                <td colspan="5">
                                    <div class="empty-state">No DTimer WiFi machines have linked to this account.</div>
                                </td>
                            </tr>
                        {/if}
                    </tbody>
                </table>
            </div>
        </article>

        <article class="panel">
            <div class="section-heading">
                <h2>Recent Coin Sales</h2>
                <a href="/client/licensing" class="ghost-button">Licensing</a>
            </div>

            {#if recentSales.length}
                <div class="list-rail">
                    {#each recentSales as sale}
                        <div class="list-item">
                            <header>
                                <strong>{formatMoney(sale.amountMinor)}</strong>
                                <span class="pill-tag">{sale.machineName}</span>
                            </header>
                            <div class="support-copy">{formatDate(sale.occurredAt, true)} · {sale.pulseCount ?? 0} pulse{(sale.pulseCount ?? 0) === 1 ? '' : 's'}</div>
                            {#if sale.userSlot || sale.sessionId}
                                <div class="support-copy">{sale.userSlot || 'User'} {sale.sessionId ? `· ${sale.sessionId}` : ''}</div>
                            {/if}
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="empty-state">No coin sales have been received yet.</div>
            {/if}
        </article>
    </section>
</ClientLayout>
