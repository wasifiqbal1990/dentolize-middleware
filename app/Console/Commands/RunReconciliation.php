<?php

namespace App\Console\Commands;

use App\Sync\Reconciliation\ReconciliationService;
use Illuminate\Console\Command;

class RunReconciliation extends Command
{
    protected $signature = 'whisper:reconcile';

    protected $description = 'Run the local Whisper reconciliation worker.';

    public function handle(ReconciliationService $reconciliation): int
    {
        $result = $reconciliation->run();

        $this->components->info(sprintf(
            'Reconciliation complete: pushed=%d healed=%d still_failing=%d',
            $result['pushed'],
            $result['healed'],
            $result['still_failing'],
        ));

        return self::SUCCESS;
    }
}
