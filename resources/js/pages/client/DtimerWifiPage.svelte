<script>
    import ClientLayout from '@/layouts/ClientLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let stats = {};
    export let machines = [];

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
    <title>DTimer WiFi | {appName}</title>
</svelte:head>

<ClientLayout {flash} {csrfToken} {appName} current="dtimer-wifi">
    <section class="stats-grid">
        <StatCard label="Machines" value={stats.totalMachines ?? 0} hint={`${stats.onlineMachines ?? 0} online`} accent="aqua" />
        <StatCard label="Connected Users" value={stats.connectedUsers ?? 0} hint={`${stats.activeSessions ?? 0} active internet session${(stats.activeSessions ?? 0) === 1 ? '' : 's'}`} accent="mint" />
        <StatCard label="Coin Sales" value={formatMoney(stats.totalSalesAmountMinor ?? 0)} hint="All linked machines" accent="orange" />
        <StatCard label="Online Window" value={`${stats.activeWindowMinutes ?? 10}m`} hint="Last heartbeat threshold" accent="rose" />
    </section>

    <article class="table-shell">
        <div class="section-heading">
            <div>
                <h2>DTimer WiFi Machines</h2>
                <p class="card-subtitle">Orange Pi machine status, internet-control activity, and coin sales.</p>
            </div>
            <span class="chip">{machines.length} machine{machines.length === 1 ? '' : 's'}</span>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Machine</th>
                        <th>WiFi / Timer</th>
                        <th>Users</th>
                        <th>Sales</th>
                        <th>License</th>
                        <th>Versions</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    {#if machines.length}
                        {#each machines as machine}
                            <tr>
                                <td>
                                    <strong>{machine.deviceName || 'DTimer machine'}</strong>
                                    <span class="muted mono">{machine.deviceId || 'No device ID'}</span>
                                    <span class="muted mono">{machine.macAddress}</span>
                                </td>
                                <td>
                                    <TablePill status={machine.statusLabel} />
                                    <span class="muted">WiFi {machine.wifiStatus || 'not reported'}</span>
                                    <span class="muted">Timer {machine.timerStatus || 'not reported'}</span>
                                </td>
                                <td>
                                    <strong>{machine.connectedUsers ?? 0} connected</strong>
                                    <span class="muted">{machine.activeSessions ?? 0} active session{(machine.activeSessions ?? 0) === 1 ? '' : 's'}</span>
                                </td>
                                <td>
                                    <strong>{formatMoney(machine.salesAmountMinor)}</strong>
                                    <span class="muted">{machine.salesCount ?? 0} event{(machine.salesCount ?? 0) === 1 ? '' : 's'} · {machine.pulseCount ?? 0} pulse{(machine.pulseCount ?? 0) === 1 ? '' : 's'}</span>
                                    <span class="muted">Today {formatMoney(machine.todaySalesAmountMinor)} / {machine.todaySalesCount ?? 0} event{(machine.todaySalesCount ?? 0) === 1 ? '' : 's'}</span>
                                </td>
                                <td>
                                    <strong class="mono">{machine.licenseKey || 'Not linked'}</strong>
                                    {#if machine.licenseStatus}
                                        <TablePill status={machine.licenseStatus} />
                                    {/if}
                                    {#if machine.pendingRevocation}
                                        <span class="muted">Unlinks {formatDate(machine.pendingRevocation.effectiveAt, true)}</span>
                                    {:else}
                                        <span class="muted">{machine.expiresAt ? `Expires ${formatDate(machine.expiresAt)}` : 'No active expiry'}</span>
                                    {/if}
                                </td>
                                <td>
                                    <span class="muted">App {machine.appVersion || 'not reported'}</span>
                                    <span class="muted">Firmware {machine.firmwareVersion || 'not reported'}</span>
                                </td>
                                <td>{machine.lastSeenAt ? formatDate(machine.lastSeenAt, true) : 'Not seen yet'}</td>
                            </tr>
                        {/each}
                    {:else}
                        <tr>
                            <td colspan="7">
                                <div class="empty-state">No DTimer WiFi machines have linked to this account.</div>
                            </td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </article>

    {#if machines.some((machine) => machine.recentSales?.length)}
        <section class="split-grid">
            {#each machines.filter((machine) => machine.recentSales?.length) as machine}
                <article class="panel">
                    <div class="section-heading">
                        <h3>{machine.deviceName || 'DTimer machine'}</h3>
                        <span class="chip">{machine.recentSales.length} recent</span>
                    </div>
                    <div class="list-rail">
                        {#each machine.recentSales as sale}
                            <div class="list-item">
                                <header>
                                    <strong>{formatMoney(sale.amountMinor)}</strong>
                                    <span class="pill-tag">{formatDate(sale.occurredAt, true)}</span>
                                </header>
                                <div class="support-copy">{sale.localEventId} · {sale.pulseCount ?? 0} pulse{(sale.pulseCount ?? 0) === 1 ? '' : 's'}</div>
                            </div>
                        {/each}
                    </div>
                </article>
            {/each}
        </section>
    {/if}
</ClientLayout>
