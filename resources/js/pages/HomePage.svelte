<script>
    import { Link } from '@inertiajs/svelte';
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let news = [];
    export let latestUpdate = null;

    const formatDate = (value, withTime = false) => {
        if (!value) {
            return 'Just now';
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
    <title>Home | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <section class="hero-grid">
        <article class="hero-panel">
            <p class="eyebrow">Dark Fusion Dashboard</p>
            <h1 class="hero-title">Licenses, device health, and TimerApp updates in one admin console.</h1>
            <p class="hero-copy">
                Create unique 12-digit activator codes, monitor whether the linked device is active,
                export license records, and publish public news updates from a single Laravel + Svelte workspace.
            </p>

            <div class="hero-actions">
                {#if auth?.user?.is_admin}
                    <Link href="/admin" class="primary-button">Open Dashboard</Link>
                {:else}
                    <Link href="/admin/login" class="primary-button">Admin Login</Link>
                {/if}
                <a href="#news" class="secondary-button">View News</a>
            </div>

            <div class="hero-metrics">
                <div class="metric">
                    <strong>12 Digits</strong>
                    <span>Every new activator code is generated as a unique 12-digit key.</span>
                </div>
                <div class="metric">
                    <strong>CSV Export</strong>
                    <span>Creation date, expiry date, device name, and status are export-ready.</span>
                </div>
                <div class="metric">
                    <strong>Live Update Feed</strong>
                    <span>TimerApp can detect the latest uploaded update package automatically.</span>
                </div>
            </div>
        </article>

        <aside class="panel stack">
            <div>
                <div class="section-heading">
                    <h2 class="card-title">Latest TimerApp Update</h2>
                    {#if latestUpdate}
                        <span class="chip">v{latestUpdate.version}</span>
                    {/if}
                </div>

                {#if latestUpdate}
                    <div class="update-callout">
                        <strong>{latestUpdate.title}</strong>
                        <p class="section-copy">{latestUpdate.description || 'An update package is ready for TimerApp clients.'}</p>
                        <div class="tag-row">
                            <span class="pill-tag">{latestUpdate.fileName}</span>
                            <span class="pill-tag">{formatDate(latestUpdate.publishedAt, true)}</span>
                        </div>
                    </div>
                {:else}
                    <div class="empty-state">No update has been uploaded yet.</div>
                {/if}
            </div>

            <div>
                <div class="section-heading">
                    <h2 class="card-title">Public News Feed</h2>
                </div>
                <p class="section-copy">
                    Visitors can open this homepage without logging in and read the latest announcements posted by the admin team.
                </p>
            </div>
        </aside>
    </section>

    <section id="news" class="panel">
        <div class="section-heading">
            <h2>Latest News</h2>
            <span class="chip">{news.length} post{news.length === 1 ? '' : 's'}</span>
        </div>

        {#if news.length}
            <div class="list-rail">
                {#each news as item}
                    <article class="news-card">
                        <header>
                            <h3>{item.title}</h3>
                            <div class="tag-row">
                                {#if item.isPinned}
                                    <span class="pill-tag">Pinned</span>
                                {/if}
                                <span class="pill-tag">{formatDate(item.publishedAt, true)}</span>
                            </div>
                        </header>
                        <p class="section-copy">{item.body}</p>
                    </article>
                {/each}
            </div>
        {:else}
            <div class="empty-state">No news has been posted yet.</div>
        {/if}
    </section>
</PublicLayout>
