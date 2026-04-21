<script>
    import AdminLayout from '@/layouts/AdminLayout.svelte';
    import StatCard from '@/components/StatCard.svelte';
    import TablePill from '@/components/TablePill.svelte';

    export let appName = 'TimerAdmin';
    export let csrfToken = '';
    export let flash = {};
    export let stats = {};
    export let licenses = [];
    export let news = [];
    export let updates = [];
    export let errors = {};

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
    <title>Admin Dashboard | {appName}</title>
</svelte:head>

<AdminLayout {flash} {csrfToken} {appName}>
    <section class="stats-grid">
        <StatCard label="Total Licenses" value={stats.totalLicenses ?? 0} hint="Every generated activation key." accent="aqua" />
        <StatCard label="Available" value={stats.availableLicenses ?? 0} hint="Unused licenses waiting for a PC." accent="orange" />
        <StatCard label="Active Devices" value={stats.activeDevices ?? 0} hint={`Heartbeat inside ${stats.activeWindowMinutes ?? 10} minutes.`} accent="mint" />
        <StatCard label="Inactive or Expired" value={(stats.inactiveDevices ?? 0) + (stats.expiredLicenses ?? 0)} hint="Needs attention or renewal." accent="rose" />
    </section>

    <section class="dashboard-grid">
        <div class="stack">
            <article class="panel">
                <div class="section-heading">
                    <div>
                        <h2>Create License Key</h2>
                        <p class="card-subtitle">Generate a new 12-digit activator code by choosing an expiry date.</p>
                    </div>
                    <a href="/admin/licenses/export" class="secondary-button">Export CSV</a>
                </div>

                <form method="POST" action="/admin/licenses">
                    <input type="hidden" name="_token" value={csrfToken} />

                    <div class="field">
                        <label for="expires_at">Expiry date</label>
                        <input id="expires_at" type="date" name="expires_at" required />
                        {#if errors?.expires_at}
                            <div class="field-error">{errors.expires_at}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button">Add License Key</button>
                </form>
            </article>

            <article class="table-shell">
                <div class="section-heading">
                    <div>
                        <h2>License Registry</h2>
                        <p class="card-subtitle">License key, creation date, expiry date, PC name, and live status monitoring.</p>
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
                                <th>PC name</th>
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
                                        <td>{license.pcName}</td>
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

                <form method="POST" action="/admin/updates" enctype="multipart/form-data">
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
                        <label for="published_at">Publish at</label>
                        <input id="published_at" type="datetime-local" name="published_at" />
                    </div>

                    <div class="field">
                        <label for="package">Release file</label>
                        <input id="package" type="file" name="package" accept=".zip,.exe,.msi" required />
                        {#if errors?.package}
                            <div class="field-error">{errors.package}</div>
                        {/if}
                    </div>

                    <button type="submit" class="primary-button">Upload Update</button>
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
                    {#each updates.slice(0, 5) as update}
                        <div class="list-item">
                            <header>
                                <strong>{update.title}</strong>
                                {#if update.isActive}
                                    <span class="pill-tag">Current Live Release</span>
                                {/if}
                            </header>
                            <div class="support-copy">Version {update.version} - {update.fileName}</div>
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
