<script lang="ts">
    let { class: className = '' }: { class?: string } = $props();
</script>

<div class="relative isolate aspect-square [container-type:inline-size] w-full {className}" aria-hidden="true">
    <div class="absolute inset-[8%] rounded-full border border-current/30"></div>
    <div class="absolute inset-[20%] rounded-full border border-dashed border-current/30"></div>
    <div class="absolute top-[13%] left-[11%] -rotate-12 border-2 border-[#18181b] bg-[#fff9ed] px-4 py-2 font-mono text-[clamp(.5rem,2.5cqw,.8rem)] font-bold text-[#18181b] shadow-[3px_3px_0_#18181b]">FOR FUTURE YOU ↗︎</div>
    <div class="absolute top-[31%] left-[14%] w-[72%] rotate-[-9deg] border-2 border-[#18181b] bg-[#ff8066] p-[7%] text-[#18181b] shadow-[7px_7px_0_#18181b]">
        <p class="font-mono text-[clamp(.5rem,2.5cqw,.8rem)] font-bold tracking-widest uppercase">A little intention</p>
        <p class="mt-3 font-display text-[clamp(1.25rem,8cqw,3rem)] leading-none font-black tracking-tighter">A LITTLE<br />TODAY.</p>
    </div>
    <div class="absolute top-[51%] left-[22%] w-[72%] rotate-[6deg] border-2 border-[#18181b] bg-[#d7f75b] p-[7%] text-[#18181b] shadow-[7px_7px_0_#18181b]">
        <p class="font-mono text-[clamp(.5rem,2.5cqw,.8rem)] font-bold tracking-widest uppercase">A bigger possibility</p>
        <p class="mt-3 font-display text-[clamp(1.25rem,8cqw,3rem)] leading-none font-black tracking-tighter">A BIGGER<br />TOMORROW.</p>
    </div>
    <svg viewBox="0 0 100 100" class="absolute top-[15%] right-[9%] size-[19%] text-current" fill="none" aria-hidden="true"><path d="M50 0v100M0 50h100M15 15l70 70M15 85l70-70" stroke="currentColor" stroke-width="5" /></svg>
</div>
