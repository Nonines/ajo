<?php

namespace Database\Seeders;

use App\Support\DemoHistory;
use Illuminate\Database\Seeder;

/**
 * Run independently so DatabaseSeeder's muted model events cannot suppress the audit trail.
 */
class DemoHistorySeeder extends Seeder
{
    public function run(): void
    {
        app(DemoHistory::class)->seed();
        $this->command?->info('Demo history ready. Customer: '.config('demo.customer_email').'. Use DEMO_CUSTOMER_PASSWORD to sign in.');
    }
}
