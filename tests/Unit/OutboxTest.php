<?php

namespace Lampwick\Laravel\Tests\Unit;

use Lampwick\Laravel\Agent\Outbox;
use Lampwick\Laravel\Agent\Outcome;
use PHPUnit\Framework\TestCase;

final class OutboxTest extends TestCase
{
    public function test_waits_for_the_flush_interval_then_batches_everything(): void
    {
        $outbox = new Outbox(batchBytes: 1024, bufferBytes: 4096, flushInterval: 1.0);
        $outbox->push('[{"t":"a"}]', 10.0);
        $outbox->push('[{"t":"b"},{"t":"c"}]', 10.5);

        $this->assertFalse($outbox->due(10.9));
        $this->assertEqualsWithDelta(0.1, $outbox->wait(10.9), 0.0001);
        $this->assertTrue($outbox->due(11.0));
        $this->assertSame('[{"t":"a"},{"t":"b"},{"t":"c"}]', $outbox->take(11.0)->body());
        $this->assertFalse($outbox->due(20.0), 'nothing is sent while a batch is in flight');
        $this->assertSame(Outcome::Sent, $outbox->settle(202, null, 11.1));
        $this->assertTrue($outbox->isEmpty());
    }

    public function test_sends_early_when_the_batch_is_full_and_splits_by_size(): void
    {
        $outbox = new Outbox(batchBytes: 30, bufferBytes: 4096, flushInterval: 60.0);
        $outbox->push('[{"t":"aaaaaaaaaaaa"}]', 0.0);
        $this->assertFalse($outbox->due(0.0));
        $outbox->push('[{"t":"bbbbbbbbbbbb"}]', 0.0);
        $this->assertTrue($outbox->due(0.0));

        $this->assertSame('[{"t":"aaaaaaaaaaaa"}]', $outbox->take(0.0)->body());
        $outbox->settle(202, null, 0.0);
        $this->assertSame('[{"t":"bbbbbbbbbbbb"}]', $outbox->take(0.0)->body());
    }

    public function test_ignores_empty_arrays_and_rejects_non_arrays(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0);
        $this->assertFalse($outbox->push(' [ ] ', 0.0));
        $this->assertTrue($outbox->isEmpty());

        $this->expectException(\InvalidArgumentException::class);
        $outbox->push('{"t":"log"}', 0.0);
    }

    public function test_drops_the_oldest_payloads_when_the_buffer_is_full(): void
    {
        $outbox = new Outbox(batchBytes: 1024, bufferBytes: 25, flushInterval: 0.0);
        $outbox->push('[{"t":"1","x":"aaa"}]', 0.0);
        $outbox->push('[{"t":"2","x":"bbb"}]', 0.0);

        $this->assertSame(1, $outbox->droppedPayloads);
        $this->assertSame('[{"t":"2","x":"bbb"}]', $outbox->take(0.0)->body());
    }

    public function test_retries_honouring_retry_after_and_keeps_order(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0);
        $outbox->push('[{"t":"1"}]', 0.0);
        $outbox->take(0.0);
        $outbox->push('[{"t":"2"}]', 0.0);

        $this->assertSame(Outcome::Retry, $outbox->settle(503, 3.0, 100.0));
        $this->assertEqualsWithDelta(103.0, $outbox->backoffUntil(), 0.0001);
        $this->assertFalse($outbox->due(102.9));
        $this->assertFalse($outbox->due(102.9, draining: true), 'draining still honours the backoff');
        $this->assertTrue($outbox->due(103.0));
        $this->assertSame('[{"t":"1"},{"t":"2"}]', $outbox->take(103.0)->body());
    }

    public function test_backs_off_exponentially_without_retry_after(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0, maxBackoff: 60.0);
        $outbox->push('[{"t":"1"}]', 0.0);
        $delays = [];
        $now = 0.0;
        for ($i = 0; $i < 8; $i++) {
            $outbox->take($now);
            $outbox->settle($i % 2 ? 0 : 502, null, $now);
            $delays[] = $outbox->backoffUntil() - $now;
            $now = $outbox->backoffUntil();
        }

        foreach ([1, 2, 4, 8, 16, 32] as $i => $base) {
            $this->assertGreaterThanOrEqual($base, $delays[$i]);
            $this->assertLessThanOrEqual(min(60, $base * 1.25), $delays[$i]);
        }
        $this->assertEqualsWithDelta(60.0, $delays[7], 0.0001);

        $outbox->take($now);
        $outbox->settle(202, null, $now);
        $this->assertSame(0.0, $outbox->backoffUntil());
    }

    public function test_splits_a_batch_that_is_too_large(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0);
        foreach (['1', '2', '3', '4'] as $n) {
            $outbox->push("[{\"t\":\"$n\"}]", 0.0);
        }
        $this->assertCount(4, $outbox->take(0.0)->parts);
        $this->assertSame(Outcome::Split, $outbox->settle(413, null, 0.0));

        $first = $outbox->take(0.0);
        $this->assertSame('[{"t":"1"},{"t":"2"}]', $first->body());
        $outbox->settle(202, null, 0.0);
        $this->assertSame('[{"t":"3"},{"t":"4"}]', $outbox->take(0.0)->body());
    }

    public function test_drops_a_single_payload_that_is_too_large_or_rejected(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0);
        $outbox->push('[{"t":"1"}]', 0.0);
        $outbox->take(0.0);
        $this->assertSame(Outcome::Dropped, $outbox->settle(413, null, 0.0));

        $outbox->push('[{"t":"2"}]', 0.0);
        $outbox->take(0.0);
        $this->assertSame(Outcome::Dropped, $outbox->settle(400, null, 0.0));
        $this->assertSame(2, $outbox->droppedPayloads);
        $this->assertTrue($outbox->isEmpty());
    }

    public function test_pauses_after_an_authentication_error(): void
    {
        $outbox = new Outbox(1024, 4096, 0.0, maxBackoff: 60.0);
        $outbox->push('[{"t":"1"}]', 0.0);
        $outbox->take(0.0);
        $this->assertSame(Outcome::Dropped, $outbox->settle(401, null, 5.0));
        $this->assertSame(65.0, $outbox->backoffUntil());
    }
}
