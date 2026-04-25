<script>
    import { onDestroy } from 'svelte';
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

    let isUploadingUpdate = false;
    let selectedUpdateFileName = '';
    let selectedUpdateFileSizeLabel = '';
    let selectedDashboardPhotoName = '';
    let selectedDashboardPhotoSizeLabel = '';
    let deletingUpdateIds = [];
    let deletingDashboardPhotoIds = [];
    let uploadElapsedSeconds = 0;
    let uploadTimerId = null;

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

    const formatFileSize = (bytes) => {
        if (!Number.isFinite(bytes) || bytes <= 0) {
            return '';
        }

        const megabytes = bytes / (1024 * 1024);
        return `${megabytes.toFixed(megabytes >= 100 ? 0 : 1)} MB`;
    };

    const formatElapsedTime = (seconds) => {
        const normalizedSeconds = Math.max(0, Math.floor(seconds));
        const hours = Math.floor(normalizedSeconds / 3600);
        const minutes = Math.floor((normalizedSeconds % 3600) / 60);
        const remainingSeconds = normalizedSeconds % 60;

        if (hours > 0) {
            return `${hours}:${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`;
        }

        return `${minutes}:${String(remainingSeconds).padStart(2, '0')}`;
    };

    const stopUploadTimer = () => {
        if (uploadTimerId) {
            window.clearInterval(uploadTimerId);
            uploadTimerId = null;
        }
    };

    const handleUpdatePackageChange = (event) => {
        const file = event.currentTarget?.files?.[0];
        selectedUpdateFileName = file?.name ?? '';
        selectedUpdateFileSizeLabel = formatFileSize(file?.size ?? 0);
    };

    const handleDashboardPhotoChange = (event) => {
        const file = event.currentTarget?.files?.[0];
        selectedDashboardPhotoName = file?.name ?? '';
        selectedDashboardPhotoSizeLabel = formatFileSize(file?.size ?? 0);
    };

    const handleUpdateSubmit = (event) => {
        if (isUploadingUpdate) {
            event.preventDefault();
            return;
        }

        isUploadingUpdate = true;
        uploadElapsedSeconds = 0;
        stopUploadTimer();
        const startedAtMs = Date.now();
        uploadTimerId = window.setInterval(() => {
            uploadElapsedSeconds = Math.floor((Date.now() - startedAtMs) / 1000);
        }, 1000);

        // Let Svelte render the uploading state before the browser starts the full form submit.
        event.preventDefault();
        const form = event.currentTarget;
        requestAnimationFrame(() => form.submit());
    };

    const isDeletingUpdate = (updateId) => deletingUpdateIds.includes(updateId);
    const isDeletingDashboardPhoto = (photoId) => deletingDashboardPhotoIds.includes(photoId);

    const handleUpdateDeleteSubmit = (event, update) => {
        if (isDeletingUpdate(update.id)) {
            event.preventDefault();
            return;
        }

        const confirmed = window.confirm(
            `Delete "${update.title}" (${update.version})? This removes the uploaded file from the admin portal.`
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

    onDestroy(() => {
        stopUploadTimer();
    });
</script>

<svelte:head>
    <title>Admin Dashboard | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName}>
    <section class="stats-grid">
        <StatCard label="Total Licenses" value={stats.totalLicenses ?? 0} hint="Every generated activation key." accent="aqua" />
        <StatCard label="Available" value={stats.availableLicenses ?? 0} hint="Unused licenses waiting for a device." accent="orange" />
        <StatCard label="Active Devices" value={stats.activeDevices ?? 0} hint={`Heartbeat inside ${stats.activeWindowMinutes ?? 10} minutes.`} accent="mint" />
        <StatCard label="Inactive or Expired" value={(stats.inactiveDevices ?? 0) + (stats.expiredLicenses ?? 0)} hint="Needs attention or renewal." accent="rose" />
    </section>

    <section class="dashboard-grid">
        <div class="stack">
            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Create License Key</h2>
                        <p class="card-subtitle">Generate a new 12-digit activator code by choosing a license term.</p>
                    </div>
                    <a href="/admin/licenses/export" class="secondary-button">Export CSV</a>
                </div>

                <form method="POST" action="/admin/licenses">
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
                                        checked={option.value === defaultLicenseDuration}
                                        required
                                    />
                                    <span>{option.label}</span>
                                </label>
                            {/each}
                        </div>
                        <div class="field-help">Expiry is calculated automatically from the license creation date.</div>
                        {#if errors?.duration}
                            <div class="field-error">{errors.duration}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button">Add License Key</button>
                </form>
            </article>

            <article class="table-shell">
                <div class="section-heading">
                    <div>
                        <h2>License Registry</h2>
                        <p class="card-subtitle">License key, creation date, expiry date, device name, and live status monitoring.</p>
                    </div>
                    <span class="chip">{licenses.length} listed</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>License key</th>
                                <th>Creation date</th>
                                <th>Expiry date</th>
                                <th>Device Name</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {#if licenses.length}
                                {#each licenses as license}
                                    <tr>
                                        <td>
                                            <strong class="mono">{license.licenseKey}</strong>
                                            {#if license.appVersion}
                                                <span class="muted">App v{license.appVersion}</span>
                                            {/if}
                                        </td>
                                        <td>{formatDate(license.creationDate, true)}</td>
                                        <td>{formatDate(license.expiryDate)}</td>
                                        <td>
                                            {license.deviceName}
                                        </td>
                                        <td>
                                            <TablePill status={license.status} />
                                            {#if license.lastSeenAt}
                                                <div class="muted">Last seen {formatDate(license.lastSeenAt, true)}</div>
                                            {/if}
                                        </td>
                                    </tr>
                                {/each}
                            {:else}
                                <tr>
                                    <td colspan="5">
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
                        <h2>Upload TimerApp Update</h2>
                        <p class="card-subtitle">Upload a release file so TimerApp clients can detect the newest package.</p>
                    </div>
                </div>

                <form method="POST" action="/admin/updates" enctype="multipart/form-data" on:submit={handleUpdateSubmit}>
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
                        <label for="package">Release file</label>
                        <input
                            id="package"
                            type="file"
                            name="package"
                            accept=".zip,.exe,.msi"
                            required
                            on:change={handleUpdatePackageChange}
                        />
                        <div class="field-help">
                            Large local `.exe` uploads can take several minutes. Keep this page open until you are redirected back to the dashboard.
                        </div>
                        <div class="field-help">
                            Publish time is set automatically from the current Philippine time when the upload is completed.
                        </div>
                        {#if selectedUpdateFileName}
                            <div class="field-help">
                                Selected: {selectedUpdateFileName}{selectedUpdateFileSizeLabel ? ` (${selectedUpdateFileSizeLabel})` : ''}
                            </div>
                        {/if}
                        {#if isUploadingUpdate}
                            <div class="field-help">
                                Uploading update package now. This may take a while on `php artisan serve`.
                            </div>
                            <div class="field-help">
                                Elapsed upload time: <span class="mono">{formatElapsedTime(uploadElapsedSeconds)}</span>
                            </div>
                        {/if}
                        {#if errors?.package}
                            <div class="field-error">{errors.package}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button" disabled={isUploadingUpdate}>
                        {isUploadingUpdate ? `Uploading Update... ${formatElapsedTime(uploadElapsedSeconds)}` : 'Upload Update'}
                    </button>
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
                <span class="chip">{updates.length} upload{updates.length === 1 ? '' : 's'}</span>
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
                            {#if update.description}
                                <div class="support-copy">{update.description}</div>
                            {/if}
                            <div class="support-copy">{formatDate(update.publishedAt, true)}</div>
                        </div>
                    {/each}
                </div>
            {:else}
                <div class="empty-state">No TimerApp update packages have been uploaded yet.</div>
            {/if}
        </article>
    </section>
</AdminLayout>
