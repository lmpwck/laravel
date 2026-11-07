<?php

namespace Lampwick\Laravel\Tests\Contract\App;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $id) {}

    public function handle(): void
    {
        DB::select('select 2 as job');
        Log::info('contract job ran', ['id' => $this->id]);
    }
}
