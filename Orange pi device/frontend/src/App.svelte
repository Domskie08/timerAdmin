<script>
  let view = window.location.pathname.startsWith('/admin') ? 'admin' : 'portal';
  let publicStatus = null;
  let adminStatus = null;
  let csrfToken = '';
  let message = '';
  let error = '';
  let loginForm = { username: 'admin', password: 'admin' };
  let passwordForm = { currentPassword: '', newPassword: '' };
  let sessionForm = { clientIp: '', clientMac: '', minutes: 5, amountMinor: 500 };
  let settingsForm = {};

  const money = (amountMinor, currency = 'PHP') =>
    new Intl.NumberFormat('en-PH', { style: 'currency', currency }).format((Number(amountMinor) || 0) / 100);

  const dateTime = (value) => {
    if (!value) return 'Not set';
    return new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
    }).format(new Date(value));
  };

  const remainingLabel = (session) => {
    if (!session) return 'No active time';
    const seconds = Math.max(0, Math.floor((new Date(session.expires_at).getTime() - Date.now()) / 1000));
    const minutes = Math.floor(seconds / 60);
    const remainder = seconds % 60;
    return `${minutes}m ${remainder.toString().padStart(2, '0')}s`;
  };

  const api = async (path, options = {}) => {
    const response = await fetch(path, {
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        ...(csrfToken ? { 'X-CSRF-Token': csrfToken } : {}),
        ...(options.headers || {}),
      },
      ...options,
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.message || 'Request failed');
    }
    return data;
  };

  const refreshPublic = async () => {
    publicStatus = await api('/api/status');
  };

  const refreshAdmin = async () => {
    try {
      const data = await api('/api/admin/status');
      adminStatus = data;
      csrfToken = data.csrfToken;
      settingsForm = { ...(data.settings || {}) };
      error = '';
    } catch (err) {
      adminStatus = null;
      csrfToken = '';
    }
  };

  const login = async () => {
    error = '';
    message = '';
    try {
      const data = await api('/api/login', {
        method: 'POST',
        body: JSON.stringify(loginForm),
      });
      csrfToken = data.csrfToken;
      await refreshAdmin();
      message = data.admin?.must_change_password ? 'Change the default password to unlock setup.' : 'Signed in.';
    } catch (err) {
      error = err.message;
    }
  };

  const logout = async () => {
    try {
      await api('/api/logout', { method: 'POST', body: '{}' });
    } finally {
      adminStatus = null;
      csrfToken = '';
      message = '';
      error = '';
    }
  };

  const changePassword = async () => {
    error = '';
    message = '';
    try {
      const data = await api('/api/admin/password', {
        method: 'POST',
        body: JSON.stringify(passwordForm),
      });
      passwordForm = { currentPassword: '', newPassword: '' };
      message = data.message || 'Password updated.';
      await refreshAdmin();
    } catch (err) {
      error = err.message;
    }
  };

  const saveSettings = async () => {
    error = '';
    message = '';
    try {
      const data = await api('/api/admin/settings', {
        method: 'POST',
        body: JSON.stringify(settingsForm),
      });
      settingsForm = { ...(data.settings || {}) };
      message = 'Settings saved.';
      await refreshAdmin();
    } catch (err) {
      error = err.message;
    }
  };

  const createSession = async () => {
    error = '';
    message = '';
    try {
      await api('/api/sessions', {
        method: 'POST',
        body: JSON.stringify(sessionForm),
      });
      message = 'Session created.';
      await refreshAdmin();
    } catch (err) {
      error = err.message;
    }
  };

  const sessionAction = async (id, action) => {
    error = '';
    message = '';
    try {
      await api(`/api/sessions/${id}/${action}`, { method: 'POST', body: '{}' });
      message = `Session ${action} complete.`;
      await refreshAdmin();
    } catch (err) {
      error = err.message;
    }
  };

  const runSync = async () => {
    error = '';
    message = '';
    try {
      const data = await api('/api/sync', { method: 'POST', body: '{}' });
      message = data.message || 'Sync complete.';
      await refreshAdmin();
    } catch (err) {
      error = err.message;
    }
  };

  const reconcileFirewall = async () => {
    error = '';
    message = '';
    try {
      const data = await api('/api/firewall/reconcile', { method: 'POST', body: '{}' });
      message = data.firewall?.message || (data.firewall?.enforced ? 'Firewall updated.' : 'Firewall dry run saved.');
    } catch (err) {
      error = err.message;
    }
  };

  refreshPublic();
  if (view === 'admin') refreshAdmin();
  setInterval(() => {
    if (view === 'portal') refreshPublic();
    if (view === 'admin' && adminStatus) refreshAdmin();
  }, 10000);
</script>

{#if view === 'portal'}
  <main class="portal-shell">
    <section class="portal-panel">
      <div>
        <p class="eyebrow">DTimer WiFi</p>
        <h1>{publicStatus?.device || 'Orange Pi Portal'}</h1>
        <p class="copy">Your device is connected to the local portal. Internet access starts after a paid time session is created by the coin/timer controller.</p>
      </div>

      <div class="time-display">
        <span>Remaining Time</span>
        <strong>{remainingLabel(publicStatus?.activeSession)}</strong>
      </div>

      <div class="portal-grid">
        <div>
          <span class="label">Your IP</span>
          <strong>{publicStatus?.clientIp || 'Detecting...'}</strong>
        </div>
        <div>
          <span class="label">Rate</span>
          <strong>{publicStatus ? `${publicStatus.rates.minutesPerCoin} min / ${money(publicStatus.rates.coinAmountMinor, publicStatus.rates.currency)}` : 'Loading...'}</strong>
        </div>
      </div>

      <a href="/admin" class="admin-link">Admin</a>
    </section>
  </main>
{:else}
  <main class="admin-shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">DTimer WiFi</p>
        <h1>Orange Pi Admin</h1>
      </div>
      {#if adminStatus}
        <button class="secondary" on:click={logout}>Logout</button>
      {/if}
    </header>

    {#if message}
      <div class="flash success">{message}</div>
    {/if}
    {#if error}
      <div class="flash error">{error}</div>
    {/if}

    {#if !adminStatus}
      <section class="panel login-panel">
        <h2>Sign in</h2>
        <label>
          Username
          <input bind:value={loginForm.username} autocomplete="username" />
        </label>
        <label>
          Password
          <input bind:value={loginForm.password} type="password" autocomplete="current-password" />
        </label>
        <button on:click={login}>Login</button>
      </section>
    {:else if adminStatus.admin?.must_change_password}
      <section class="panel login-panel">
        <h2>Change Default Password</h2>
        <p class="copy">Setup is locked until the default admin password is changed.</p>
        <label>
          Current password
          <input bind:value={passwordForm.currentPassword} type="password" autocomplete="current-password" />
        </label>
        <label>
          New password
          <input bind:value={passwordForm.newPassword} type="password" autocomplete="new-password" />
        </label>
        <button on:click={changePassword}>Update Password</button>
      </section>
    {:else}
      <section class="stats">
        <div class="stat"><span>Active</span><strong>{adminStatus.stats?.active_sessions ?? 0}</strong></div>
        <div class="stat"><span>Paused</span><strong>{adminStatus.stats?.paused_sessions ?? 0}</strong></div>
        <div class="stat"><span>Today Sales</span><strong>{money(adminStatus.stats?.today_sales_amount_minor ?? 0, settingsForm.currency || 'PHP')}</strong></div>
        <div class="stat"><span>Pending Sync</span><strong>{adminStatus.stats?.pending_sync ?? 0}</strong></div>
      </section>

      <section class="grid-two">
        <article class="panel">
          <h2>Create Paid Session</h2>
          <label>
            Client IP
            <input bind:value={sessionForm.clientIp} placeholder="192.168.8.23" />
          </label>
          <label>
            Client MAC
            <input bind:value={sessionForm.clientMac} placeholder="Optional" />
          </label>
          <div class="form-row">
            <label>
              Minutes
              <input bind:value={sessionForm.minutes} type="number" min="1" max="1440" />
            </label>
            <label>
              Amount
              <input bind:value={sessionForm.amountMinor} type="number" min="0" />
            </label>
          </div>
          <button on:click={createSession}>Grant Time</button>
        </article>

        <article class="panel">
          <h2>Device Settings</h2>
          <label>
            Device name
            <input bind:value={settingsForm.device_name} />
          </label>
          <label>
            TimerAdmin URL
            <input bind:value={settingsForm.timeradmin_base_url} placeholder="https://your-timeradmin.com" />
          </label>
          <label>
            License key
            <input bind:value={settingsForm.license_key} maxlength="12" />
          </label>
          <label>
            Device secret
            <input bind:value={settingsForm.device_secret} placeholder={settingsForm.device_secret_configured ? 'Already configured' : '64-character secret'} />
          </label>
          <label>
            MAC address
            <input bind:value={settingsForm.mac_address} placeholder="AA:BB:CC:11:22:33" />
          </label>
          <div class="form-row">
            <label>
              WAN
              <input bind:value={settingsForm.wan_interface} />
            </label>
            <label>
              Customer WiFi
              <input bind:value={settingsForm.customer_interface} />
            </label>
          </div>
          <div class="form-row">
            <label>
              Minutes per coin
              <input bind:value={settingsForm.rate_minutes_per_coin} type="number" min="1" />
            </label>
            <label>
              Coin amount
              <input bind:value={settingsForm.coin_amount_minor} type="number" min="1" />
            </label>
          </div>
          <label class="toggle">
            <input
              checked={settingsForm.network_enforcement_enabled === '1'}
              type="checkbox"
              on:change={(event) => (settingsForm.network_enforcement_enabled = event.currentTarget.checked ? '1' : '0')}
            />
            Enable firewall enforcement
          </label>
          <button on:click={saveSettings}>Save Settings</button>
        </article>
      </section>

      <section class="panel">
        <div class="panel-heading">
          <h2>Sessions</h2>
          <div class="actions">
            <button class="secondary" on:click={runSync}>Sync</button>
            <button class="secondary" on:click={reconcileFirewall}>Firewall</button>
          </div>
        </div>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Client</th>
                <th>Status</th>
                <th>Paid</th>
                <th>Expires</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              {#each adminStatus.sessions || [] as session}
                <tr>
                  <td>
                    <strong>{session.client_ip}</strong>
                    <span>{session.client_mac || 'No MAC'}</span>
                  </td>
                  <td>{session.status}</td>
                  <td>{money(session.amount_minor, settingsForm.currency || 'PHP')}</td>
                  <td>{dateTime(session.expires_at)}</td>
                  <td class="row-actions">
                    {#if session.status === 'active'}
                      <button class="secondary" on:click={() => sessionAction(session.id, 'pause')}>Pause</button>
                    {/if}
                    {#if session.status === 'paused'}
                      <button class="secondary" on:click={() => sessionAction(session.id, 'resume')}>Resume</button>
                    {/if}
                    {#if session.status === 'active' || session.status === 'paused'}
                      <button class="danger" on:click={() => sessionAction(session.id, 'block')}>Block</button>
                    {/if}
                  </td>
                </tr>
              {:else}
                <tr>
                  <td colspan="5" class="empty">No sessions yet.</td>
                </tr>
              {/each}
            </tbody>
          </table>
        </div>
      </section>
    {/if}
  </main>
{/if}
