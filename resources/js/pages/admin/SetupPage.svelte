<script>
    import AdminLayout from '@/layouts/AdminLayout.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let news = [];
    export let updates = [];
    export let dashboardPhotos = [];
    export let errors = {};
    export let formState = {};

    let selectedDashboardPhotoName = '';
    let selectedDashboardPhotoSizeLabel = '';
    let deletingUpdateIds = [];
    let deletingDashboardPhotoIds = [];

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

    const handleDashboardPhotoChange = (event) => {
        const file = event.currentTarget?.files?.[0];
        selectedDashboardPhotoName = file?.name ?? '';
        selectedDashboardPhotoSizeLabel = formatFileSize(file?.size ?? 0);
    };

    const isDeletingUpdate = (updateId) => deletingUpdateIds.includes(updateId);
    const isDeletingDashboardPhoto = (photoId) => deletingDashboardPhotoIds.includes(photoId);

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
</script>

<svelte:head>
    <title>Admin Setup | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName} current="setup">
    <section class="section-heading page-heading">
        <div>
            <h2>Setup</h2>
            <p class="card-subtitle">Admin account, TimerApp releases, home news, and dashboard photos.</p>
        </div>
        <a href="/admin" class="secondary-button">Back to Admin</a>
    </section>

    <section class="dashboard-grid">
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
                        <div class="field-help">Set the Drive file to anyone with the link can view so visitors can download it.</div>
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
        </div>

        <div class="stack">
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
        </div>
    </section>
</AdminLayout>
