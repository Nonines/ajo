<script lang="ts">
    let {
        class: className = '',
        ...rest
    }: {
        class?: string;
        [key: string]: unknown;
    } = $props();
</script>

<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" class={className} aria-hidden="true" {...rest}>
    <g stroke="currentColor" stroke-width="2.5" stroke-linejoin="round">
        <path fill="#FF8066" d="M4 28h30v14H4z" />
        <path fill="#D7F75B" d="M10 11h30v14H10z" />
    </g>
</svg>
