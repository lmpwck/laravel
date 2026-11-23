<?php

namespace Lampwick\Laravel\Tests\Feature;

use Lampwick\Agent\Testing\Contract;
use Lampwick\Laravel\Lampwick;
use Lampwick\Laravel\LampwickServiceProvider;
use Laravel\Nightwatch\Core;
use Laravel\Nightwatch\NightwatchServiceProvider;
use Orchestra\Testbench\TestCase;

/** `Lampwick::event()`: what it sends, and that sampling never loses an event. */
final class EventTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $lampwick = ['token' => 'uk_event_test', 'agent' => ['listen' => '127.0.0.1:2499']];

    protected function getPackageProviders($app): array
    {
        return [LampwickServiceProvider::class, NightwatchServiceProvider::class];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);
        $app['config']->set('lampwick', $this->lampwick);
    }

    protected function setUp(): void
    {
        parent::setUp();
        CapturedFrames::$frames = [];
        if (! in_array('lampwick-capture', stream_get_wrappers(), true)) {
            stream_wrapper_register('lampwick-capture', CapturedFrames::class);
        }
        $this->core()->ingest->streamFactory = static fn () => fopen('lampwick-capture://agent', 'r+');
    }

    public function test_a_sampled_execution_takes_the_event_into_its_trace(): void
    {
        $this->core()->sample(1.0);

        $this->assertTrue(Lampwick::event('payment.paid', ['amount' => 49], user: 7));

        $this->assertSame([], CapturedFrames::$frames, 'sent with the execution, when it ends');
        [$event] = $this->buffered();
        $this->assertSame([], Contract::violations($event));
        $this->assertSame(['event', 'payment.paid', ['amount' => 49], '7'], [$event['t'], $event['name'], $event['props'], $event['user']]);
        $this->assertSame(json_decode(json_encode($this->core()->executionState->trace)), $event['trace_id']);
    }

    public function test_an_execution_that_is_not_sampled_sends_the_event_right_away_on_its_own(): void
    {
        $this->core()->dontSample();

        $this->assertTrue(Lampwick::event('payment.paid', ['amount' => 49]));

        $this->assertSame([], $this->buffered());
        $this->assertCount(1, CapturedFrames::$frames);
        [$event] = json_decode(explode(':', CapturedFrames::$frames[0], 4)[3], true);
        $this->assertSame(['event', 'payment.paid', ['amount' => 49]], [$event['t'], $event['name'], $event['props']]);
        $this->assertArrayNotHasKey('trace_id', $event, 'the trace is not sent, so the event does not point at it');
        $this->assertArrayNotHasKey('execution_id', $event);
    }

    public function test_properties_are_cleaned_as_lampwick_stores_them(): void
    {
        $this->core()->sample(1.0);
        $props = [
            'plan' => Plan::Pro,
            'label' => new class implements \Stringable
            {
                public function __toString(): string
                {
                    return 'gift';
                }
            },
            'note' => str_repeat('é', 150),
            'iban' => 'NL91ABNA0417164300',
            'ok' => true,
            'nan' => NAN,
            'list' => [1, 2],
            'nothing' => null,
            'bad key' => 1,
            str_repeat('k', 41) => 1,
        ];
        for ($i = 0; $i < 30; $i++) {
            $props["p{$i}"] = $i;
        }

        Lampwick::event('checkout.completed', $props);

        [$event] = $this->buffered();
        $this->assertSame(['plan' => 'pro', 'label' => 'gift', 'note' => str_repeat('é', 100), 'iban' => '[redacted]', 'ok' => true], array_slice($event['props'], 0, 5));
        $this->assertCount(Lampwick::MAX_PROPS, $event['props']);
    }

    public function test_an_invalid_name_sends_nothing(): void
    {
        $this->core()->sample(1.0);

        foreach (['', '   ', '<script>', 'two  spaces', str_repeat('a', 101)] as $name) {
            $this->assertFalse(Lampwick::event($name), $name);
        }
        $this->assertTrue(Lampwick::event(' Invite sent: team/42 '));

        $this->assertSame(['Invite sent: team/42'], array_column($this->buffered(), 'name'));
    }

    public function test_nothing_is_sent_when_monitoring_is_off(): void
    {
        $this->lampwick['enabled'] = false;
        $this->refreshApplication();

        $this->assertFalse(Lampwick::event('payment.paid'));
        $this->assertSame([], CapturedFrames::$frames);
    }

    private function core(): Core
    {
        return $this->app->make(Core::class);
    }

    /** @return list<array<string, mixed>> the records waiting for the execution to end */
    private function buffered(): array
    {
        return json_decode($this->core()->ingest->buffer->pull('h')->rawPayload() ?: '[]', true) ?? [];
    }
}

enum Plan: string
{
    case Pro = 'pro';
}

/** A stand-in agent: keeps each frame written to it and answers `2:OK`. */
final class CapturedFrames
{
    /** @var list<string> */
    public static array $frames = [];

    /** @var resource|null */
    public $context;

    private string $written = '';

    private bool $answered = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->written .= $data;

        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        if ($this->answered) {
            return '';
        }
        $this->answered = true;
        self::$frames[] = $this->written;

        return '2:OK';
    }

    public function stream_eof(): bool
    {
        return $this->answered;
    }

    public function stream_set_option(int $option, int $arg1, ?int $arg2): bool
    {
        return true;
    }

    public function stream_close(): void {}
}
