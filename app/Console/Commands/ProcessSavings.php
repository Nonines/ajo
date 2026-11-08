<?php

namespace App\Console\Commands;

use App\Models\SavingsException;
use App\Models\SavingsPlan;
use App\Support\SavingsAccrual;
use App\Support\SavingsProcessing;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Discovers due plans or performs a bounded backfill of completed application-calendar dates.
 */
class ProcessSavings extends Command
{
    protected $signature = 'savings:process {--plan= : Process one plan} {--from= : First completed accrual date in the application timezone} {--through= : Last completed accrual date in the application timezone}';

    protected $description = 'Discover savings work or process a bounded chronological backfill';

    public function handle(SavingsProcessing $processing, SavingsAccrual $accrual): int
    {
        if ($this->option('plan') === null) {
            if ($this->option('from') !== null || $this->option('through') !== null) {
                $this->error('Specify --plan for dated backfills.');

                return self::FAILURE;
            }
            $processing->discover();

            return self::SUCCESS;
        }
        $plan = SavingsPlan::findOrFail((int) $this->option('plan'));
        if ($this->option('from') !== null) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('from'), config('app.timezone'));
            $through = CarbonImmutable::createFromFormat('!Y-m-d', $this->option('through') ?? $this->option('from'), config('app.timezone'));
            if ($from === null || $through === null || $from->format('Y-m-d') !== $this->option('from') || $through->lessThan($from)
                || $through->format('Y-m-d') !== ($this->option('through') ?? $this->option('from'))
                || $from->diffInDays($through) >= config('savings.processing_days')) {
                $this->error('Use a chronological range of at most '.config('savings.processing_days').' dates.');

                return self::FAILURE;
            }
            for ($date = $from; $date->lessThanOrEqualTo($through); $date = $date->addDay()) {
                if (! $accrual->date($plan, $date->toDateString())) {
                    $this->error('Accrual requires investigation. See the admin savings exceptions.');

                    return self::FAILURE;
                }
            }
        } else {
            $processing->process($plan->id, $this->option('through'));
            if (SavingsException::where('savings_plan_id', $plan->id)->exists()) {
                $this->error('Processing requires investigation. See the admin savings exceptions.');

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
