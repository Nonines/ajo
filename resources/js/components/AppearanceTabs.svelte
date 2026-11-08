<script lang="ts">
    import Monitor from '@lucide/svelte/icons/monitor';
    import Moon from '@lucide/svelte/icons/moon';
    import Sun from '@lucide/svelte/icons/sun';
    import type { Component, SvelteComponent } from 'svelte';
    import { themeState } from '@/lib/theme.svelte';
    import type { Appearance } from '@/types';

    const { appearance, updateAppearance } = themeState();

    type IconComponent =
        | Component<{ class?: string }>
        | (new (...args: any[]) => SvelteComponent<{ class?: string }>);

    const tabs: { value: Appearance; Icon: IconComponent; label: string }[] = [
        { value: 'light', Icon: Sun, label: 'Light' },
        { value: 'dark', Icon: Moon, label: 'Dark' },
        { value: 'system', Icon: Monitor, label: 'System' },
    ];

    function handleAppearanceChange(value: Appearance) {
        updateAppearance(value);
    }
</script>

<div
    class="inline-flex max-w-full flex-wrap gap-1 rounded-sm border border-foreground/20 bg-muted p-1"
>
    {#each tabs as { value, Icon, label } (value)}
        <button
            type="button"
            aria-pressed={appearance.value === value}
            onclick={() => handleAppearanceChange(value)}
            class="flex min-h-11 items-center rounded-sm px-3.5 py-2 transition-colors {appearance.value ===
            value
                ? 'bg-primary font-semibold text-primary-foreground'
                : 'text-muted-foreground hover:bg-accent hover:text-foreground'}"
        >
            <Icon class="-ml-1 h-4 w-4" />
            <span class="ml-1.5 text-sm">{label}</span>
        </button>
    {/each}
</div>
