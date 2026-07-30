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
  let updateCheck = null;
  let checkingUpdates = false;
  let portalDialog = '';
  let portalMessage = '';
  let portalError = '';
  let accountForm = { currentPasscode: '', newPasscode: '', confirmPasscode: '' };
  let adminPasscodeForm = { newPasscode: '', confirmPasscode: '' };
  let brandingUpload = '';

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

  const fileSize = (bytes) => {
    const size = Number(bytes) || 0;
    if (!size) return '';
    if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`;
    return `${(size / (1024 * 1024)).toFixed(1)} MB`;
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

  const checkUpdates = async () => {
    checkingUpdates = true;
    error = '';
    message = '';
    try {
      updateCheck = await api('/api/admin/updates');
    } catch (err) {
      error = err.message;
    } finally {
      checkingUpdates = false;
    }
  };

  const openPortalDialog = (dialog) => {
    portalDialog = dialog;
    portalMessage = '';
    portalError = '';
    accountForm = { currentPasscode: '', newPasscode: '', confirmPasscode: '' };
  };

  const closePortalDialog = () => {
    portalDialog = '';
    portalMessage = '';
    portalError = '';
  };

  const changePortalPasscode = async () => {
    portalError = '';
    portalMessage = '';
    if (accountForm.newPasscode !== accountForm.confirmPasscode) {
      portalError = 'New passcodes do not match.';
      return;
    }

    try {
      const data = await api('/api/account/passcode', {
        method: 'POST',
        body: JSON.stringify({
          currentPasscode: accountForm.currentPasscode,
          newPasscode: accountForm.newPasscode,
        }),
      });
      accountForm = { currentPasscode: '', newPasscode: '', confirmPasscode: '' };
      portalMessage = data.message || 'Passcode updated.';
    } catch (err) {
      portalError = err.message;
    }
  };

  const setAdminPortalPasscode = async () => {
    error = '';
    message = '';
    if (adminPasscodeForm.newPasscode !== adminPasscodeForm.confirmPasscode) {
      error = 'Portal passcodes do not match.';
      return;
    }

    try {
      const data = await api('/api/admin/portal-passcode', {
        method: 'POST',
        body: JSON.stringify({ newPasscode: adminPasscodeForm.newPasscode }),
      });
      adminPasscodeForm = { newPasscode: '', confirmPasscode: '' };
      settingsForm = { ...(data.settings || settingsForm) };
      message = data.message || 'Portal passcode updated.';
      await refreshPublic();
    } catch (err) {
      error = err.message;
    }
  };

  const fileAsDataUrl = (file) =>
    new Promise((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(reader.result);
      reader.onerror = () => reject(new Error('Could not read the selected image.'));
      reader.readAsDataURL(file);
    });

  const uploadBranding = async (kind, event) => {
    const input = event.currentTarget;
    const file = input.files?.[0];
    if (!file) return;

    brandingUpload = kind;
    error = '';
    message = '';
    try {
      const imageData = await fileAsDataUrl(file);
      const data = await api('/api/admin/branding', {
        method: 'POST',
        body: JSON.stringify({ kind, action: 'upload', imageData }),
      });
      settingsForm = { ...(data.settings || settingsForm) };
      adminStatus = { ...adminStatus, branding: data.branding };
      message = data.message;
      await refreshPublic();
    } catch (err) {
      error = err.message;
    } finally {
      brandingUpload = '';
      input.value = '';
    }
  };

  const resetBranding = async (kind) => {
    brandingUpload = kind;
    error = '';
    message = '';
    try {
      const data = await api('/api/admin/branding', {
        method: 'POST',
        body: JSON.stringify({ kind, action: 'reset' }),
      });
      settingsForm = { ...(data.settings || settingsForm) };
      adminStatus = { ...adminStatus, branding: data.branding };
      message = data.message;
      await refreshPublic();
    } catch (err) {
      error = err.message;
    } finally {
      brandingUpload = '';
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
    <section class="portal-app">
      <div class="connection-bar">
        <span class:connected={Boolean(publicStatus)}></span>
        <strong>{publicStatus?.activeSession ? 'Internet session active' : 'Connected to DTimerFi'}</strong>
        <span>{publicStatus?.stats?.active_sessions ?? 0} active</span>
      </div>

      <header
        class:has-banner={Boolean(publicStatus?.branding?.bannerUrl)}
        class="portal-banner"
        style:background-image={publicStatus?.branding?.bannerUrl ? `url("${publicStatus.branding.bannerUrl}")` : null}
      >
        <div class="brand-lockup">
          <span class={`brand-symbol ${publicStatus?.branding?.logoStyle || 'signal'}`}>
            {#if publicStatus?.branding?.logoStyle === 'custom' && publicStatus?.branding?.logoUrl}
              <img src={publicStatus.branding.logoUrl} alt="" />
            {:else if publicStatus?.branding?.logoStyle === 'monogram'}
              <span>DF</span>
            {:else if publicStatus?.branding?.logoStyle === 'wordmark'}
              <span>DT</span>
            {:else}
              <span>D</span><i></i>
            {/if}
          </span>
          <div>
            <p>{publicStatus?.branding?.name || 'DTimerFi'}</p>
            <span>Time made connected</span>
          </div>
        </div>
        <p class="banner-signature">LOCAL WIFI TIMER</p>
      </header>

      <div class="portal-content">
        <div class="portal-intro">
          <p class="eyebrow">Affordable internet access</p>
          <h1>Stay connected, one moment at a time.</h1>
        </div>

        <div class="client-strip">
          <div>
            <span>Your IP</span>
            <strong>{publicStatus?.clientIp || 'Detecting...'}</strong>
          </div>
          <div>
            <span>Device</span>
            <strong>{publicStatus?.clientMac || 'MAC pending'}</strong>
          </div>
        </div>

        <section class:active={Boolean(publicStatus?.activeSession)} class="time-stage">
          <div class="time-stage-heading">
            <span>Remaining time</span>
            <strong>{publicStatus?.activeSession ? 'ACTIVE' : 'READY'}</strong>
          </div>
          <p>{remainingLabel(publicStatus?.activeSession)}</p>
          <span class="time-caption">
            {publicStatus?.activeSession ? `Ends ${dateTime(publicStatus.activeSession.expires_at)}` : 'Insert a coin at the timer to begin'}
          </span>
        </section>

        <div class="coin-cue">
          <span aria-hidden="true">PHP</span>
          <div>
            <strong>{publicStatus ? `${money(publicStatus.rates.coinAmountMinor, publicStatus.rates.currency)} per drop` : 'Checking rate'}</strong>
            <p>{publicStatus ? `${publicStatus.rates.minutesPerCoin} minutes of access` : 'Please wait'}</p>
          </div>
        </div>

        <nav class="portal-actions" aria-label="Portal actions">
          <button class="portal-action" on:click={() => openPortalDialog('rates')}>
            <span class="action-icon" aria-hidden="true">PHP</span>
            <span><strong>View Rates</strong><small>Time and coin value</small></span>
          </button>
          <button class="portal-action" on:click={() => openPortalDialog('account')}>
            <span class="action-icon account-icon" aria-hidden="true">ID</span>
            <span><strong>Account</strong><small>Device and passcode</small></span>
          </button>
        </nav>

        <footer class="portal-footer">
          <span>{publicStatus?.device || 'DTimer Orange Pi'}</span>
          <a href="/admin" class="admin-link">Admin</a>
        </footer>
      </div>
    </section>

    {#if portalDialog}
      <div class="modal-backdrop">
        <section class="portal-modal" role="dialog" aria-modal="true" aria-labelledby="portal-modal-title">
          <div class="modal-heading">
            <div>
              <p class="eyebrow">{publicStatus?.branding?.name || 'DTimerFi'}</p>
              <h2 id="portal-modal-title">{portalDialog === 'rates' ? 'Internet Rates' : 'Your Account'}</h2>
            </div>
            <button class="modal-close" aria-label="Close" on:click={closePortalDialog}>x</button>
          </div>

          {#if portalDialog === 'rates'}
            <div class="rate-display">
              <span>Standard rate</span>
              <strong>{publicStatus ? money(publicStatus.rates.coinAmountMinor, publicStatus.rates.currency) : '...'}</strong>
              <p>{publicStatus?.rates?.minutesPerCoin || 0} minutes per coin drop</p>
            </div>
            <div class="rate-row">
              <span>Session starts</span>
              <strong>After coin confirmation</strong>
            </div>
            <div class="rate-row">
              <span>Unused time</span>
              <strong>Shown on this portal</strong>
            </div>
          {:else}
            <div class="account-summary">
              <div><span>IP address</span><strong>{publicStatus?.clientIp || 'Detecting...'}</strong></div>
              <div><span>MAC address</span><strong>{publicStatus?.clientMac || 'Not available'}</strong></div>
              <div><span>Session</span><strong>{publicStatus?.activeSession ? 'Active' : 'No active time'}</strong></div>
              <div><span>Remaining</span><strong>{remainingLabel(publicStatus?.activeSession)}</strong></div>
            </div>

            <div class="passcode-form">
              <h3>Change portal passcode</h3>
              {#if !publicStatus?.account?.passcodeConfigured}
                <p class="inline-notice">Ask the administrator to set the first passcode.</p>
              {/if}
              <label>
                Current passcode
                <input bind:value={accountForm.currentPasscode} type="password" autocomplete="current-password" disabled={!publicStatus?.account?.passcodeConfigured} />
              </label>
              <label>
                New passcode
                <input bind:value={accountForm.newPasscode} type="password" autocomplete="new-password" disabled={!publicStatus?.account?.passcodeConfigured} />
              </label>
              <label>
                Confirm new passcode
                <input bind:value={accountForm.confirmPasscode} type="password" autocomplete="new-password" disabled={!publicStatus?.account?.passcodeConfigured} />
              </label>
              <button on:click={changePortalPasscode} disabled={!publicStatus?.account?.passcodeConfigured}>Update Passcode</button>
            </div>

            {#if portalMessage}<div class="inline-feedback success">{portalMessage}</div>{/if}
            {#if portalError}<div class="inline-feedback error">{portalError}</div>{/if}
          {/if}
        </section>
      </div>
    {/if}
  </main>
{:else}
  <main class="admin-shell">
    <header class="topbar">
      <div>
        <p class="eyebrow">DTimerFi</p>
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

      <section class="panel branding-panel">
        <div class="panel-heading">
          <div>
            <h2>Portal Branding</h2>
            <p class="panel-copy">DTimerFi portal identity and access.</p>
          </div>
          <button on:click={saveSettings}>Save Branding</button>
        </div>

        <div class="branding-layout">
          <div class="branding-column">
            <h3>Brand</h3>
            <label>
              Display name
              <input bind:value={settingsForm.portal_brand_name} maxlength="40" />
            </label>

            <fieldset class="logo-fieldset">
              <legend>Logo style</legend>
              <div class="logo-options">
                <label class:selected={settingsForm.portal_logo_style === 'signal'} class="logo-option">
                  <input bind:group={settingsForm.portal_logo_style} type="radio" value="signal" />
                  <span class="mini-mark signal">D<i></i></span>
                  <strong>Signal</strong>
                </label>
                <label class:selected={settingsForm.portal_logo_style === 'monogram'} class="logo-option">
                  <input bind:group={settingsForm.portal_logo_style} type="radio" value="monogram" />
                  <span class="mini-mark">DF</span>
                  <strong>Monogram</strong>
                </label>
                <label class:selected={settingsForm.portal_logo_style === 'wordmark'} class="logo-option">
                  <input bind:group={settingsForm.portal_logo_style} type="radio" value="wordmark" />
                  <span class="mini-mark">DT</span>
                  <strong>Wordmark</strong>
                </label>
                {#if adminStatus.branding?.logoUrl}
                  <label class:selected={settingsForm.portal_logo_style === 'custom'} class="logo-option">
                    <input bind:group={settingsForm.portal_logo_style} type="radio" value="custom" />
                    <span class="mini-mark custom"><img src={adminStatus.branding.logoUrl} alt="" /></span>
                    <strong>Custom</strong>
                  </label>
                {/if}
              </div>
            </fieldset>

            <label class="file-control">
              Custom logo
              <input type="file" accept="image/jpeg,image/png,image/webp" on:change={(event) => uploadBranding('logo', event)} disabled={brandingUpload !== ''} />
            </label>
            <button class="secondary" on:click={() => resetBranding('logo')} disabled={brandingUpload !== ''}>Restore Default Logo</button>
          </div>

          <div class="branding-column">
            <h3>Top Banner</h3>
            <div
              class:has-image={Boolean(adminStatus.branding?.bannerUrl)}
              class="banner-preview"
              style:background-image={adminStatus.branding?.bannerUrl ? `url("${adminStatus.branding.bannerUrl}")` : null}
            >
              <span>{settingsForm.portal_brand_name || 'DTimerFi'}</span>
            </div>
            <label class="file-control">
              Banner image
              <input type="file" accept="image/jpeg,image/png,image/webp" on:change={(event) => uploadBranding('banner', event)} disabled={brandingUpload !== ''} />
            </label>
            <button class="secondary" on:click={() => resetBranding('banner')} disabled={brandingUpload !== ''}>Restore Default Banner</button>
          </div>

          <div class="branding-column">
            <h3>Portal Passcode</h3>
            <div class:configured={settingsForm.portal_passcode_configured} class="passcode-state">
              <span></span>
              {settingsForm.portal_passcode_configured ? 'Passcode configured' : 'Passcode not configured'}
            </div>
            <label>
              New passcode
              <input bind:value={adminPasscodeForm.newPasscode} type="password" autocomplete="new-password" minlength="8" maxlength="63" />
            </label>
            <label>
              Confirm passcode
              <input bind:value={adminPasscodeForm.confirmPasscode} type="password" autocomplete="new-password" minlength="8" maxlength="63" />
            </label>
            <button on:click={setAdminPortalPasscode}>Set Portal Passcode</button>
          </div>
        </div>
      </section>

      <section class="panel updates-panel">
        <div class="panel-heading">
          <div>
            <h2>Software Updates</h2>
            <p class="panel-copy">
              Installed version {updateCheck?.installedVersion || settingsForm.app_version || 'unknown'}
            </p>
          </div>
          <button on:click={checkUpdates} disabled={checkingUpdates}>
            {checkingUpdates ? 'Checking...' : 'Check updates'}
          </button>
        </div>

        {#if updateCheck}
          <div class="source-statuses">
            {#each updateCheck.sources || [] as source}
              <div class="source-status">
                <span class:online={source.ok} class:error-state={!source.ok} class="status-dot"></span>
                <div>
                  <strong>{source.label}</strong>
                  <span>{source.message || `${source.count || 0} release(s) found.`}</span>
                </div>
              </div>
            {/each}
          </div>

          {#if updateCheck.updates?.length}
            <div class="table-wrap">
              <table class="updates-table">
                <thead>
                  <tr>
                    <th>Version</th>
                    <th>Source</th>
                    <th>Release</th>
                    <th>Package</th>
                  </tr>
                </thead>
                <tbody>
                  {#each updateCheck.updates as update (update.id)}
                    <tr>
                      <td>
                        <strong>v{update.version}</strong>
                        {#if update.isNewer === true}
                          <span class="version-state newer">Newer</span>
                        {:else if update.isNewer === false}
                          <span class="version-state">Available</span>
                        {:else}
                          <span class="version-state">Unverified</span>
                        {/if}
                      </td>
                      <td>
                        <strong>{update.sourceLabel}</strong>
                        {#if update.location}<span>{update.location}</span>{/if}
                      </td>
                      <td>
                        <strong>{update.title}</strong>
                        {#if update.description}<span class="release-notes">{update.description}</span>{/if}
                      </td>
                      <td>
                        <strong>{update.fileName || 'Download'}</strong>
                        {#if fileSize(update.fileSize)}<span>{fileSize(update.fileSize)}</span>{/if}
                        {#if update.publishedAt}<span>{dateTime(update.publishedAt)}</span>{/if}
                        {#if update.downloadUrl}
                          <a class="update-link" href={update.downloadUrl} target="_blank" rel="noreferrer">Download</a>
                        {/if}
                      </td>
                    </tr>
                  {/each}
                </tbody>
              </table>
            </div>
          {:else}
            <div class="empty">No update packages were found online or on mounted USB storage.</div>
          {/if}
        {:else}
          <div class="empty">No update check has been run.</div>
        {/if}
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
