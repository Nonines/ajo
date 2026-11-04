<script module lang="ts">
    import { dashboard } from '@/routes';

    export const layout = {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    };
</script>

<script lang="ts">
    import { Link, page } from '@inertiajs/svelte';
    import AppHead from '@/components/AppHead.svelte';
    import { Button } from '@/components/ui/button';
    import { index as wallet } from '@/routes/wallet';
    import { edit as editProfile } from '@/routes/profile';
    import { edit as editSecurity } from '@/routes/security';
</script>

<AppHead title="Your account" />

<div class="flex flex-1 flex-col gap-8 p-6 sm:p-10">
    <section class="rounded-md border-2 border-foreground bg-primary p-6 text-primary-foreground shadow-[4px_4px_0_var(--foreground)] sm:p-10">
        <p class="mb-4 font-mono text-xs font-bold tracking-widest uppercase">Your next chapter</p>
        <h1 class="font-display text-4xl font-black tracking-tight sm:text-5xl">Welcome, {page.props.auth.user.name}.</h1>
        <p class="mt-4 max-w-xl leading-relaxed">Your account is ready. Take a moment to make it yours and review your security settings.</p>
    </section>
    <Button variant="outline" asChild>
        {#snippet children(props)}<Link {...props} href={wallet()}>View your wallet and transactions</Link>{/snippet}
    </Button>
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="flex flex-col items-start gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <span class="font-mono text-sm text-muted-foreground">01 / THE BASICS</span>
            <h2 class="font-display text-2xl font-bold">Make yourself at home.</h2>
            <p class="text-muted-foreground">Keep your name and email address up to date.</p>
            <Button variant="outline" asChild>
                {#snippet children(props)}<Link {...props} href={editProfile()}>Review your profile</Link>{/snippet}
            </Button>
        </section>
        <section class="flex flex-col items-start gap-4 rounded-md border-2 border-foreground bg-card p-6">
            <span class="font-mono text-sm text-muted-foreground">02 / PEACE OF MIND</span>
            <h2 class="font-display text-2xl font-bold">A stronger front door.</h2>
            <p class="text-muted-foreground">Manage your password, two-factor authentication, and passkeys.</p>
            <Button variant="outline" asChild>
                {#snippet children(props)}<Link {...props} href={editSecurity()}>Review account security</Link>{/snippet}
            </Button>
        </section>
    </div>
    <p class="text-xs text-muted-foreground">Ajo is a fictional savings platform. Payments are test-mode only.</p>
</div>
