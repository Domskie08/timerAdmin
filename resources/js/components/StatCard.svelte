<script>
    export let label = '';
    export let value = '';
    export let hint = '';
    export let accent = 'aqua';
</script>

<article class="stat-card">
    <div class={`stat-accent accent-${accent}`}></div>
    <div class="stat-label">{label}</div>
    <div class="stat-value">{value}</div>
    {#if hint}
        <div class="stat-hint">{hint}</div>
    {/if}
</article>

