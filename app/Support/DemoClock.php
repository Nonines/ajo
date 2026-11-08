<?php

namespace App\Support;

use App\Contracts\Clock;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reads the persisted demo instant afresh, including in long-lived processes.
 */
class DemoClock implements Clock
{
    public function now(): CarbonImmutable
    {
        if (! app()->environment('demo')) {
            throw new DomainException('The demo clock requires APP_ENV=demo.');
        }
        $instant = DB::table('demo_state')->where('id', 1)->value('current_at');
        if ($instant === null) {
            return DB::transaction(function (): CarbonImmutable {
                $instant = DB::table('demo_state')->where('id', 1)->value('current_at');
                if ($instant !== null) {
                    return CarbonImmutable::parse($instant, 'UTC');
                }
                if (DB::table('journals')->exists() || DB::table('activity_log')->exists()) {
                    throw new DomainException('Initialize demo mode on an empty financial and audit history.');
                }
                $start = config('demo.start_at');
                $date = $start === null || $start === ''
                    ? (new SystemClock)->now()->setTimezone(config('app.timezone'))->startOfDay()->subDays(config('demo.history_days'))->addMinutes(5)->utc()
                    : CarbonImmutable::parse($start, 'UTC')->utc()->startOfMinute();
                DB::table('demo_state')->insert(['id' => 1, 'current_at' => $date, 'history_starts_at' => $date]);

                return $date;
            });
        }

        return CarbonImmutable::parse($instant, 'UTC');
    }
}
