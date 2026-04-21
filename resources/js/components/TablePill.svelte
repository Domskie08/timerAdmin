<script>
    export let status = 'Available';

    const className = `status-${status.toLowerCase()}`;
</script>

<span class={`table-pill ${className}`}>{status}</span>

