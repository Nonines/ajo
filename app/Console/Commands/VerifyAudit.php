<?php

namespace App\Console\Commands;

use App\Support\AuditTrail;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Checks retained chain evidence and reports the limits of verification after archive expiry.
 */
#[Signature('audit:verify')]
#[Description('Verify retained audit evidence and its chain head')]
class VerifyAudit extends Command
{
    public function handle(AuditTrail $audit): int
    {
        try {
            $sequence = $audit->verify();
            $this->info('Verified through audit event '.$sequence.'.');
            $floor = DB::table('audit_head')->where('id', 1)->sole()->floor_sequence;
            $expired = DB::table('audit_archives')->whereNotNull('purged_at')->count();
            $this->info('Online evidence begins after event '.$floor.'. Expired archive boundaries: '.$expired.'.');
            if ($expired > 0) {
                $this->warn('Expired archive contents are unavailable; only their signed boundary metadata remains.');
            }
            $this->warn('There is no external trusted checkpoint; an authentic historical rollback cannot be disproved.');

            return self::SUCCESS;
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
