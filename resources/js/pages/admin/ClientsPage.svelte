<script>
    import AdminLayout from '@/layouts/AdminLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let stats = {};
    export let clients = [];

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
    <title>Client Accounts | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName}>
    <section class="stats-grid">
        <StatCard label="Client Accounts" value={stats.clientAccounts ?? 0} hint="Registered client admins" accent="aqua" />
        <StatCard label="PC Timer" value={stats.pcTimers ?? 0} hint="Claimed PC Timer licenses" accent="mint" />
        <StatCard label="DTimer Machines" value={stats.dtimerMachines ?? 0} hint="Linked and historical machines" accent="orange" />
        <StatCard label="Coin Sales" value={formatMoney(stats.totalSalesAmountMinor ?? 0)} hint={`${stats.pendingRevocations ?? 0} pending revocation${(stats.pendingRevocations ?? 0) === 1 ? '' : 's'}`} accent="rose" />
    </section>

    <article class="table-shell">
        <div class="section-heading">
            <div>
                <h2>Client Accounts</h2>
                <p class="card-subtitle">Client admins, PC Timer devices, DTimer WiFi machines, sales, and revocation status.</p>
            </div>
            <a href="/admin" class="secondary-button">License Dashboard</a>
        </div>

        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Client</th>
                        <th>Admins</th>
                        <th>Devices</th>
                        <th>Licenses</th>
                        <th>Sales</th>
                        <th>Revocations</th>
                    </tr>
                </thead>
                <tbody>
                    {#if clients.length}
                        {#each clients as client}
                            <tr>
                                <td>
                                    <strong>{client.name}</strong>
                                    <span class="muted">{client.contactEmail || 'No contact email'}</span>
                                    <span class="muted">Created {formatDate(client.createdAt, true)}</span>
                                </td>
                                <td>
                                    {#each client.users as user}
                                        <span class="muted">{user.name} · {user.email}</span>
                                    {/each}
                                </td>
                                <td>
                                    <strong>{client.pcTimerLicenseCount} PC Timer</strong>
                                    <span class="muted">{client.machineCount} DTimer machine{client.machineCount === 1 ? '' : 's'}</span>
                                    <span class="muted">{client.onlineMachineCount} DTimer online</span>
                                    {#each client.machines.slice(0, 3) as machine}
                                        <span class="muted">{machine.deviceName || 'DTimer machine'} · {machine.licenseKey || 'No license'}</span>
                                    {/each}
                                </td>
                                <td>
                                    <strong>{client.licenseCount}</strong>
                                    <span class="muted">{client.pcTimerLicenseCount} PC Timer</span>
                                    <span class="muted">{client.dtimerWifiLicenseCount} DTimer WiFi</span>
                                </td>
                                <td>
                                    <strong>{formatMoney(client.salesAmountMinor)}</strong>
                                    <span class="muted">{client.salesCount} event{client.salesCount === 1 ? '' : 's'}</span>
                                </td>
                                <td>
                                    <TablePill status={client.pendingRevocationCount ? 'Pending' : 'Clear'} />
                                    <div class="muted">{client.pendingRevocationCount} pending</div>
                                </td>
                            </tr>
                        {/each}
                    {:else}
                        <tr>
                            <td colspan="6">
                                <div class="empty-state">No client accounts have registered yet.</div>
                            </td>
                        </tr>
                    {/if}
                </tbody>
            </table>
        </div>
    </article>
</AdminLayout>
