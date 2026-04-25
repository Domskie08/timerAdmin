<script>
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let updates = [];

    const formatDate = (value, withTime = false) => {
        if (!value) {
            return 'Available now';
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
</script>

<svelte:head>
    <title>Support | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <section class="panel support-hero">
        <p class="eyebrow">Support</p>
        <h1 class="support-title">TimerApp Downloads</h1>
        <p class="section-copy">
            Download any published TimerApp version uploaded by the admin team.
        </p>
    </section>

    <section class="panel">
        <div class="section-heading">
            <h2>Available Versions</h2>
            <span class="chip">{updates.length} version{updates.length === 1 ? '' : 's'}</span>
        </div>

        {#if updates.length}
            <div class="list-rail">
                {#each updates as update}
                    <article class="support-version-card">
                        <div>
                            <div class="version-heading">
                                <h3>{update.title}</h3>
                                <span class="pill-tag">v{update.version}</span>
                            </div>
                            {#if update.description}
                                <p class="section-copy">{update.description}</p>
                            {/if}
                            <div class="tag-row">
                                <span class="pill-tag">{update.fileName}</span>
                                {#if formatFileSize(update.fileSize)}
                                    <span class="pill-tag">{formatFileSize(update.fileSize)}</span>
                                {/if}
                                <span class="pill-tag">{formatDate(update.publishedAt, true)}</span>
                            </div>
                        </div>
                        <a class="primary-button" href={update.downloadUrl}>Download</a>
                    </article>
                {/each}
            </div>
        {:else}
            <div class="empty-state">No TimerApp downloads are available yet.</div>
        {/if}
    </section>
</PublicLayout>
