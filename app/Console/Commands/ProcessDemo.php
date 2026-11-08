<?php

namespace App\Console\Commands;

use App\Support\DemoTimeMachine;
use Illuminate\Console\Command;

class ProcessDemo extends Command
{
    protected $signature = 'demo:process';

    protected $description = 'Process current demo work and continue an administrator-requested time advance';

    public function handle(DemoTimeMachine $machine): int
    {
        $machine->requireDemo();
        $machine->process();

        return self::SUCCESS;
    }
}
