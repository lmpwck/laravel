<?php

namespace Lampwick\Laravel\Tests\Contract\App;

use Illuminate\Auth\GenericUser;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * The small Laravel app of the contract test (tests/Contract/ContractTest.php): a route, a
 * command, a queued job and a scheduled task that together produce every record type. Only
 * loaded by `vendor/bin/testbench` and the testbench web server, through testbench.yaml, and
 * only when CONTRACT_APP=1.
 */
final class ContractAppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! env('CONTRACT_APP')) {
            return;
        }

        $this->commands([ContractCommand::class]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->call(fn () => DB::select('select 3 as scheduled'))->name('contract-task')->everyMinute();
        });

        Route::get('/contract/{id}', function (string $id) {
            Auth::setUser(new GenericUser(['id' => 42, 'name' => 'Ada Lovelace', 'email' => 'ada@example.com']));
            DB::select('select ? as id', [$id]);
            Cache::get('contract-key');
            Cache::put('contract-key', 'value', 60);
            Http::fake(['example.com/*' => Http::response('pong', 200), 'hooks.slack.com/*' => Http::response('ok', 200)]);
            Http::get('https://example.com/ping?access_token=SECRET-outgoing');
            Mail::to('ada@example.com')->send(new ContractMail);
            Notification::route('mail', 'ada@example.com')->notify(new ContractNotification);
            ContractJob::dispatch($id);
            Log::warning('contract log', ['id' => $id, 'password' => 'SECRET-log', 'user' => ['api_token' => 'SECRET-nested']]);
            report(new \RuntimeException('handled in the contract app'));

            return response()->json(['ok' => true]);
        })->name('contract.show');

        Route::get('/reset/{token}', function (string $token) {
            Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);
            Http::post('https://hooks.slack.com/services/T0001/B0002/SECRET-slack', ['text' => 'hi']);
            Log::info('calling https://api.example.com/v1?api_key=SECRET-logurl');
            report(new \RuntimeException('callback to https://deploy:SECRET-exception@ci.example.com/hook failed'));

            return response()->noContent();
        })->name('contract.reset');

        // Signed-URL style parameters: `hash` and `nonce` are secrets in a route.
        Route::get('/email/{id}/{hash}/{nonce}', fn () => response()->noContent())->name('contract.email');

        Route::get('/contract-fail', function () {
            throw new \LogicException('unhandled in the contract app');
        });
    }
}
