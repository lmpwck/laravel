<?php

namespace Lampwick\Laravel\Tests\Contract\App;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ContractCommand extends Command
{
    protected $signature = 'contract:command {--fail} {--password=}';

    protected $description = 'Command of the contract app';

    public function handle(): int
    {
        DB::select('select 1 as command');
        Log::info('contract command ran');

        return $this->option('fail') ? self::FAILURE : self::SUCCESS;
    }
}
