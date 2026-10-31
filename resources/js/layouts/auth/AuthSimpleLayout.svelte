<script lang="ts">
    import { Link } from '@inertiajs/svelte';
    import type { Snippet } from 'svelte';
    import AppLogoIcon from '@/components/AppLogoIcon.svelte';
    import { home } from '@/routes';

    let {
        title = '',
        description = '',
        children,
    }: {
        title?: string;
        description?: string;
        children?: Snippet;
    } = $props();
</script>

<div
    class="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10"
>
    <div class="w-full max-w-md rounded-md border-2 border-foreground bg-card p-6 shadow-[4px_4px_0_var(--foreground)] sm:p-8">
        <div class="flex flex-col gap-8">
            <div class="flex flex-col items-center gap-4">
                <Link
                    href={home()}
                    class="flex items-center gap-2 font-medium"
                >
                    <div
                        class="mb-1 flex h-9 w-9 items-center justify-center rounded-md"
                    >
                        <AppLogoIcon
                            class="size-9 fill-current text-(--foreground) dark:text-white"
                        />
                    </div>
                    <span class="font-display text-3xl font-black">Ajo</span>
                </Link>
                <div class="space-y-2 text-center">
                    <h1 class="font-display text-2xl font-bold">{title}</h1>
                    <p class="text-center text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
            </div>
            {@render children?.()}
        </div>
    </div>
</div>
