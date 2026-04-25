<script>
    import { onDestroy, onMount } from 'svelte';
    import { Link } from '@inertiajs/svelte';
    import PublicLayout from '@/layouts/PublicLayout.svelte';

    export let appName = 'TimerAdmin';
    export let auth = { user: null };
    export let news = [];
    export let latestUpdate = null;
    export let dashboardPhotos = [];

    let selectedPhotoIndex = 0;
    let photoTimerId = null;

    $: activePhoto = dashboardPhotos[selectedPhotoIndex] ?? null;

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

    const stopPhotoTimer = () => {
        if (photoTimerId) {
            window.clearInterval(photoTimerId);
            photoTimerId = null;
        }
    };

    const startPhotoTimer = () => {
        stopPhotoTimer();

        if (dashboardPhotos.length <= 1) {
            return;
        }

        photoTimerId = window.setInterval(() => {
            selectedPhotoIndex = (selectedPhotoIndex + 1) % dashboardPhotos.length;
        }, 5000);
    };

    const movePhoto = (direction) => {
        if (dashboardPhotos.length <= 1) {
            return;
        }

        selectedPhotoIndex = (selectedPhotoIndex + direction + dashboardPhotos.length) % dashboardPhotos.length;
        startPhotoTimer();
    };

    const selectPhoto = (index) => {
        selectedPhotoIndex = index;
        startPhotoTimer();
    };

    onMount(() => {
        startPhotoTimer();
    });

    onDestroy(() => {
        stopPhotoTimer();
    });
</script>

<svelte:head>
    <title>Home | {appName}</title>
</svelte:head>

<PublicLayout {auth} {appName}>
    <section class="hero-grid home-hero-grid">
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
        </article>

        <aside class="panel dashboard-carousel-panel">
            <div class="section-heading">
                <h2 class="card-title">Dashboard</h2>
                <span class="chip">{dashboardPhotos.length} photo{dashboardPhotos.length === 1 ? '' : 's'}</span>
            </div>

            {#if activePhoto}
                <div class="carousel-frame">
                    <img src={activePhoto.imageUrl} alt={activePhoto.title || 'Dashboard photo'} />
                    {#if activePhoto.title}
                        <div class="carousel-caption">{activePhoto.title}</div>
                    {/if}
                </div>

                {#if dashboardPhotos.length > 1}
                    <div class="carousel-controls">
                        <button type="button" class="ghost-button carousel-button" on:click={() => movePhoto(-1)} aria-label="Previous photo">&lt;</button>
                        <div class="carousel-dots" aria-label="Dashboard photo selector">
                            {#each dashboardPhotos as photo, index}
                                <button
                                    type="button"
                                    class:active={index === selectedPhotoIndex}
                                    on:click={() => selectPhoto(index)}
                                    aria-label={`Show ${photo.title || `photo ${index + 1}`}`}
                                ></button>
                            {/each}
                        </div>
                        <button type="button" class="ghost-button carousel-button" on:click={() => movePhoto(1)} aria-label="Next photo">&gt;</button>
                    </div>
                {/if}
            {:else}
                <div class="empty-state carousel-empty">Upload dashboard photos from the admin page to show them here.</div>
            {/if}
        </aside>
    </section>

    <section class="home-summary-grid">
        <article class="panel compact-update-panel">
            <div class="section-heading">
                <h2 class="card-title">Latest TimerApp Update</h2>
                {#if latestUpdate}
                    <span class="chip">v{latestUpdate.version}</span>
                {/if}
            </div>

            {#if latestUpdate}
                <div class="update-callout compact-update">
                    <strong>{latestUpdate.title}</strong>
                    <p class="section-copy">{latestUpdate.description || 'An update package is ready for TimerApp clients.'}</p>
                    <div class="tag-row">
                        <span class="pill-tag">{latestUpdate.fileName}</span>
                        <span class="pill-tag">{formatDate(latestUpdate.publishedAt, true)}</span>
                    </div>
                    <div class="tag-row">
                        <a class="secondary-button compact-action" href={latestUpdate.downloadUrl}>Download Latest</a>
                        <Link href="/support" class="ghost-button compact-action">All Versions</Link>
                    </div>
                </div>
            {:else}
                <div class="empty-state">No update has been uploaded yet.</div>
            {/if}
        </article>

        <article id="news" class="panel">
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
        </article>
    </section>
</PublicLayout>
