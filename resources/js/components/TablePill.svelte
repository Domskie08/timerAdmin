<script>
    export let status = 'Frozen';

    const className = `status-${status.toLowerCase().replace(/\s+/g, '-')}`;
</script>

<span class={`table-pill ${className}`}>{status}</span>

