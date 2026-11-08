<x-filament-panels::page>
    <div wire:poll.2s="process">
        <x-filament::section heading="Demo clock">
            <dl>
                <dt>Current date (Africa/Lagos)</dt>
                <dd>{{ \Carbon\CarbonImmutable::parse($demo['current_at'], 'UTC')->setTimezone(config('app.timezone'))->format('j F Y, H:i') }}</dd>
                @if ($demo['target_at'])
                    <dt>Advancing toward</dt>
                    <dd>{{ \Carbon\CarbonImmutable::parse($demo['target_at'], 'UTC')->setTimezone(config('app.timezone'))->format('j F Y, H:i') }}</dd>
                @else
                    <dt>Status</dt>
                    <dd>Ready</dd>
                @endif
            </dl>
            <p>This date applies to every account. Interest, maturity, and review deadlines use this clock.</p>
            @if ($demo['failure'])
                <p role="alert">{{ $demo['failure'] }} Resolve the issue, then select Resume processing.</p>
            @endif
        </x-filament::section>
        <x-filament::section heading="Explore the demo">
            @if ($demo['seeded_at'])
                <p>400 days of history are ready. Sign in as {{ config('demo.customer_email') }} with the configured demo customer password.</p>
                <p>Inspect completed plans and their statements, then advance 30 days to watch another plan mature.</p>
            @else
                <p>Seed the demo history before advancing time to explore completed plans and a plan close to maturity.</p>
            @endif
            <p>Use Payment Simulations to create deposits and withdrawals and choose their outcomes. Ordinary staff review still applies to withdrawals.</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
