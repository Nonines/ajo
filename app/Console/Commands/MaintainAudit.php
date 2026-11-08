<?php

namespace App\Console\Commands;

use App\Support\AuditArchives;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Archives verified evidence and purges expired files in separate maintenance steps.
 */
#[Signature('audit:maintain')]
#[Description('Verify and archive audit prefixes, then purge expired archive files during maintenance')]
class MaintainAudit extends Command
{
    public function handle(AuditArchives $archives): int
    {
        $count = $archives->maintain();
        $archives->purgeFiles();
        $this->info('Archived '.$count.' audit events.');

        return self::SUCCESS;
    }
}
