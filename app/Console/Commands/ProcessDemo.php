<?php

namespace App\Console\Commands;

use App\Support\Demo\DemoTimeMachine;
use Illuminate\Console\Command;

/**
 * Continues the persisted demo scheduler at simulated chronological instants.
 */
class ProcessDemo extends Command
{
    protected $signature = 'demo:process';

    protected $description = 'Process current demo work and continue an administrator-requested time advance';

    /**
     * Continues persisted demo work without advancing real provider records.
     */
    public function handle(DemoTimeMachine $machine): int
    {
        $machine->requireDemo();
        $machine->process();

        return self::SUCCESS;
    }
}
