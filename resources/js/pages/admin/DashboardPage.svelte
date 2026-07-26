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
    export let news = [];
    export let updates = [];
    export let dashboardPhotos = [];
    export let errors = {};
    export let formState = {};

    let selectedDashboardPhotoName = '';
    let selectedDashboardPhotoSizeLabel = '';
    let deletingLicenseIds = [];
    let deletingUpdateIds = [];
    let deletingDashboardPhotoIds = [];

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

    const formatFileSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '';
        }

        const megabytes = bytes / (1024 * 1024);
        return `${megabytes.toFixed(megabytes >= 100 ? 0 : 1)} MB`;
    };

    const handleDashboardPhotoChange = (event) => {
        const file = event.currentTarget?.files?.[0];
        selectedDashboardPhotoName = file?.name ?? '';
        selectedDashboardPhotoSizeLabel = formatFileSize(file?.size ?? 0);
    };

    const isDeletingLicense = (licenseId) => deletingLicenseIds.includes(licenseId);
    const isDeletingUpdate = (updateId) => deletingUpdateIds.includes(updateId);
    const isDeletingDashboardPhoto = (photoId) => deletingDashboardPhotoIds.includes(photoId);

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

    const handleUpdateDeleteSubmit = (event, update) => {
        if (isDeletingUpdate(update.id)) {
            event.preventDefault();
            return;
        }

        const confirmed = window.confirm(
            `Delete "${update.title}" (${update.version})? This removes the release from the admin portal.`
        );

        if (!confirmed) {
            event.preventDefault();
            return;
        }

        deletingUpdateIds = [...deletingUpdateIds, update.id];
    };

    const handleDashboardPhotoDeleteSubmit = (event, photo) => {
        if (isDeletingDashboardPhoto(photo.id)) {
            event.preventDefault();
            return;
        }

        const confirmed = window.confirm(
            `Delete "${photo.title || photo.imageName}" from the public dashboard carousel?`
        );

        if (!confirmed) {
            event.preventDefault();
            return;
        }

        deletingDashboardPhotoIds = [...deletingDashboardPhotoIds, photo.id];
    };

    const showRenewLicenseError = (license) =>
        Number(formState?.renewTargetLicenseId ?? 0) === license.id && Boolean(errors?.renew_license_code);

    const renewalInputValue = (license) =>
        Number(formState?.renewTargetLicenseId ?? 0) === license.id ? formState?.renewLicenseCode ?? '' : '';

    const pcLicenses = licenses.filter((license) => license.productType === 'pc_timer');
    const dtimerWifiLicenses = licenses.filter((license) => license.productType === 'piso_wifi');

</script>

<svelte:head>
    <title>Admin Dashboard | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName}>
    <section class="stats-grid">
        <StatCard label="Total Licenses" value={stats.totalLicenses ?? 0} hint="Every generated activation key." accent="aqua" />
        <StatCard label="PC TimerApp" value={stats.pcLicenses ?? 0} hint="Desktop timer licenses." accent="mint" />
        <StatCard label="DTimer WiFi" value={stats.pisoWifiLicenses ?? 0} hint="Orange Pi device licenses." accent="orange" />
        <StatCard label="Active Devices" value={stats.activeDevices ?? 0} hint={`Heartbeat inside ${stats.activeWindowMinutes ?? 10} minutes. ${stats.expiredLicenses ?? 0} expired license${(stats.expiredLicenses ?? 0) === 1 ? '' : 's'} in the system.`} accent="rose" />
    </section>

    <section class="dashboard-grid admin-dashboard-grid">
        <div class="stack">
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

                    <button type="submit" class="primary-button">Add PC License</button>
                </form>
            </article>

            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Create DTimer WiFi License</h2>
                        <p class="card-subtitle">Generate a DTimer WiFi license for Orange Pi machines and client admins.</p>
                    </div>
                    <div class="form-actions">
                        <a href="/admin/licenses/pc/export" class="secondary-button">Export PC DTimer App</a>
                        <a href="/admin/licenses/dtimer-wifi/export" class="secondary-button">Export DTimer WiFi</a>
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

                    <button type="submit" class="primary-button">Add DTimer WiFi License</button>
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
        </div>

        <div class="stack">
            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Publish TimerApp Update</h2>
                        <p class="card-subtitle">Publish a Google Drive download link so TimerApp clients can detect the newest release.</p>
                    </div>
                </div>

                <form method="POST" action="/admin/updates">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div class="field">
                        <label for="version">Version</label>
                        <input id="version" type="text" name="version" placeholder="1.2.0" required />
                    </div>

                    <div class="field">
                        <label for="title">Release title</label>
                        <input id="title" type="text" name="title" placeholder="TimerApp 1.2.0" required />
                    </div>

                    <div class="field">
                        <label for="description">Release notes</label>
                        <textarea id="description" name="description" placeholder="What changed in this build?"></textarea>
                    </div>

                    <div class="field">
                        <label for="external_download_url">Google Drive download URL</label>
                        <input
                            id="external_download_url"
                            type="url"
                            name="external_download_url"
                            placeholder="https://drive.google.com/file/d/.../view?usp=sharing"
                            value={formState?.updateExternalDownloadUrl ?? ''}
                            required
                        />
                        <div class="field-help">
                            Set the Drive file to anyone with the link can view so visitors can download it.
                        </div>
                        {#if errors?.external_download_url}
                            <div class="field-error">{errors.external_download_url}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button">Publish Update</button>
                </form>
            </article>

            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Publish Home News</h2>
                        <p class="card-subtitle">Anything posted here becomes visible on the public home page.</p>
                    </div>
                </div>

                <form method="POST" action="/admin/news">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div class="field">
                        <label for="news_title">Headline</label>
                        <input id="news_title" type="text" name="title" required />
                    </div>

                    <div class="field">
                        <label for="news_body">Announcement</label>
                        <textarea id="news_body" name="body" required></textarea>
                    </div>

                    <div class="field">
                        <label for="news_published_at">Publish at</label>
                        <input id="news_published_at" type="datetime-local" name="published_at" />
                    </div>

                    <label class="field-inline">
                        <input type="checkbox" name="is_pinned" value="1" />
                        <span>Pin this post to the top of the home page</span>
                    </label>

                    <button type="submit" class="secondary-button">Post News</button>
                </form>
            </article>

            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Dashboard Photos</h2>
                        <p class="card-subtitle">Upload images for the public home dashboard carousel.</p>
                    </div>
                    <span class="chip">{dashboardPhotos.length} photo{dashboardPhotos.length === 1 ? '' : 's'}</span>
                </div>

                <form method="POST" action="/admin/dashboard-photos" enctype="multipart/form-data">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div class="field">
                        <label for="photo_title">Photo title</label>
                        <input id="photo_title" type="text" name="photo_title" placeholder="Dashboard overview" />
                        {#if errors?.photo_title}
                            <div class="field-error">{errors.photo_title}</div>
                        {/if}
                    </div>

                    <div class="field">
                        <label for="photo">Photo</label>
                        <input
                            id="photo"
                            type="file"
                            name="photo"
                            accept="image/png,image/jpeg,image/webp"
                            required
                            on:change={handleDashboardPhotoChange}
                        />
                        <div class="field-help">Use JPG, PNG, or WebP images up to 5 MB.</div>
                        {#if selectedDashboardPhotoName}
                            <div class="field-help">
                                Selected: {selectedDashboardPhotoName}{selectedDashboardPhotoSizeLabel ? ` (${selectedDashboardPhotoSizeLabel})` : ''}
                            </div>
                        {/if}
                        {#if errors?.photo}
                            <div class="field-error">{errors.photo}</div>
                        {/if}
                    </div>

                    <button type="submit" class="secondary-button">Add Photo</button>
                </form>

                {#if dashboardPhotos.length}
                    <div class="photo-admin-list">
                        {#each dashboardPhotos as photo}
                            <div class="photo-admin-item">
                                <img src={photo.imageUrl} alt={photo.title || photo.imageName} />
                                <div>
                                    <strong>{photo.title || photo.imageName}</strong>
                                    <div class="support-copy">{photo.imageName}</div>
                                </div>
                                <form
                                    method="POST"
                                    action={`/admin/dashboard-photos/${photo.id}`}
                                    class="inline-action-form"
                                    on:submit={(event) => handleDashboardPhotoDeleteSubmit(event, photo)}
                                >
                                    <input type="hidden" name="_token" value={csrfToken} />
                                    <input type="hidden" name="_method" value="DELETE" />
                                    <button type="submit" class="danger-button" disabled={isDeletingDashboardPhoto(photo.id)}>
                                        {isDeletingDashboardPhoto(photo.id) ? 'Deleting...' : 'Delete'}
                                    </button>
                                </form>
                            </div>
                        {/each}
                    </div>
                {/if}
            </article>

            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Change Password</h2>
                        <p class="card-subtitle">Update the password for the signed-in admin account.</p>
                    </div>
                </div>

                <form method="POST" action="/admin/password">
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

                    <button type="submit" class="secondary-button">Change Password</button>
                </form>
            </article>
        </div>
    </section>

    <section class="split-grid">
        <article class="panel">
            <div class="section-heading">
                <h3>Latest News Posts</h3>
                <span class="chip">{news.length} saved</span>
            </div>

            {#if news.length}
                <div class="list-rail">
                    {#each news.slice(0, 5) as item}
                        <div class="list-item">
                            <header>
                                <strong>{item.title}</strong>
                                <span class="pill-tag">{formatDate(item.publishedAt, true)}</span>
                            </header>
                            <div class="support-copy">{item.body}</div>
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="empty-state">No news has been published yet.</div>
            {/if}
        </article>

        <article class="panel">
            <div class="section-heading">
                <h3>Recent App Updates</h3>
                <span class="chip">{updates.length} release{updates.length === 1 ? '' : 's'}</span>
            </div>

            {#if updates.length}
                <div class="list-rail">
                    {#each updates as update}
                        <div class="list-item">
                            <header>
                                <div>
                                    <strong>{update.title}</strong>
                                    <div class="tag-row">
                                        {#if update.isActive}
                                            <span class="pill-tag">Current Live Release</span>
                                        {/if}
                                        <span class="pill-tag">Version {update.version}</span>
                                    </div>
                                </div>
                                <form
                                    method="POST"
                                    action={`/admin/updates/${update.id}`}
                                    class="inline-action-form"
                                    on:submit={(event) => handleUpdateDeleteSubmit(event, update)}
                                >
                                    <input type="hidden" name="_token" value={csrfToken} />
                                    <input type="hidden" name="_method" value="DELETE" />
                                    <button type="submit" class="danger-button" disabled={isDeletingUpdate(update.id)}>
                                        {isDeletingUpdate(update.id) ? 'Deleting...' : 'Delete'}
                                    </button>
                                </form>
                            </header>
                            <div class="support-copy">{update.fileName}</div>
                            {#if update.externalDownloadUrl}
                                <div class="support-copy">Google Drive download link attached.</div>
                            {/if}
                            {#if update.description}
                                <div class="support-copy">{update.description}</div>
                            {/if}
                            <div class="support-copy">{formatDate(update.publishedAt, true)}</div>
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="empty-state">No TimerApp updates have been published yet.</div>
            {/if}
        </article>
    </section>
</AdminLayout>
