<?php

namespace Lampwick\Laravel\Agent;

/**
 * Bounded FIFO of record payloads waiting to be sent, plus the retry/backoff state.
 *
 * Payloads are kept as the inner part of a JSON array (`{...},{...}`), so batches are built by
 * joining strings: nothing is decoded again. At most one batch is in flight at a time, which
 * keeps memory bounded and records in order.
 */
final class Outbox
{
    /** @var list<string> */
    private array $queue = [];

    private int $bytes = 0;

    private ?float $oldest = null;

    private ?Batch $inFlight = null;

    private float $notBefore = 0.0;

    private int $failures = 0;

    /** Lowered batch size after a 413, until a batch is accepted again. */
    private ?int $limit = null;

    public int $droppedPayloads = 0;

    public int $droppedBytes = 0;

    public function __construct(
        private readonly int $batchBytes,
        private readonly int $bufferBytes,
        private readonly float $flushInterval,
        private readonly float $maxBackoff = 60.0,
    ) {}

    /**
     * Queues a payload. Returns false when the payload is empty (nothing queued).
     *
     * @param  string  $json  a JSON array of records
     */
    public function push(string $json, float $now): bool
    {
        $inner = self::inner($json);
        if ($inner === '') {
            return false;
        }

        $this->queue[] = $inner;
        $this->bytes += strlen($inner);
        $this->oldest ??= $now;

        // Over budget: drop the oldest payloads (never the one in flight; it is not queued).
        while ($this->bytes > $this->bufferBytes && count($this->queue) > 1) {
            $dropped = array_shift($this->queue);
            $this->bytes -= strlen($dropped);
            $this->droppedPayloads++;
            $this->droppedBytes += strlen($dropped);
        }

        return true;
    }

    /** Whether a batch should be sent now. `$draining` sends whatever is queued without waiting. */
    public function due(float $now, bool $draining = false): bool
    {
        if ($this->inFlight !== null || $this->queue === [] || $now < $this->notBefore) {
            return false;
        }

        return $draining
            || $this->bytes >= $this->batchBytes
            || $now - ($this->oldest ?? $now) >= $this->flushInterval;
    }

    /** Seconds until `due()` may become true without new input, or null when nothing is queued. */
    public function wait(float $now): ?float
    {
        if ($this->inFlight !== null || $this->queue === []) {
            return null;
        }

        $at = max($this->notBefore, ($this->oldest ?? $now) + $this->flushInterval);

        return max(0.0, $at - $now);
    }

    /** Takes the next batch off the queue; it stays in flight until `settle()`. */
    public function take(float $now): Batch
    {
        $limit = min($this->batchBytes, $this->limit ?? PHP_INT_MAX);
        $parts = [];
        $size = 0;
        while ($this->queue !== []) {
            $next = strlen($this->queue[0]);
            if ($parts !== [] && $size + $next + 1 > $limit) {
                break;
            }
            $parts[] = array_shift($this->queue);
            $size += $next + 1;
        }
        $this->bytes -= array_sum(array_map('strlen', $parts));
        $this->oldest = $this->queue === [] ? null : $now;

        return $this->inFlight = new Batch($parts);
    }

    /**
     * Records the outcome of the batch in flight.
     *
     * @param  int  $status  HTTP status, or 0 when the request failed before a response
     * @param  float|null  $retryAfter  seconds from the `Retry-After` header
     */
    public function settle(int $status, ?float $retryAfter, float $now): Outcome
    {
        $batch = $this->inFlight ?? throw new \LogicException('no batch in flight');
        $this->inFlight = null;

        if ($status >= 200 && $status < 300) {
            $this->failures = 0;
            $this->notBefore = 0.0;
            $this->limit = null;

            return Outcome::Sent;
        }

        if ($status === 413) {
            if (count($batch->parts) > 1) {
                // Too large for lampwick: send it again in smaller batches.
                $this->limit = max(1, intdiv($batch->bytes() + count($batch->parts), 2));
                $this->requeue($batch->parts, $now);

                return Outcome::Split;
            }
            $this->countDropped($batch);

            return Outcome::Dropped;
        }

        if ($status === 0 || $status === 408 || $status === 429 || $status >= 500) {
            $this->requeue($batch->parts, $now);
            $this->failures++;
            $delay = $retryAfter ?? min($this->maxBackoff, 2 ** min($this->failures - 1, 10));
            $jitter = $retryAfter === null ? $delay * (mt_rand(0, 250) / 1000) : 0.0;
            $this->notBefore = $now + min($this->maxBackoff, $delay + $jitter);

            return Outcome::Retry;
        }

        // 400, 401, 403, 404, 411, ...: retrying the same request will not help.
        $this->countDropped($batch);
        if ($status === 401 || $status === 403 || $status === 404) {
            // Misconfiguration; don't hammer lampwick while it lasts.
            $this->notBefore = $now + $this->maxBackoff;
        }

        return Outcome::Dropped;
    }

    public function backoffUntil(): float
    {
        return $this->notBefore;
    }

    public function bytes(): int
    {
        return $this->bytes;
    }

    public function isEmpty(): bool
    {
        return $this->queue === [] && $this->inFlight === null;
    }

    public function inFlight(): bool
    {
        return $this->inFlight !== null;
    }

    /** @param list<string> $parts */
    private function requeue(array $parts, float $now): void
    {
        $this->queue = [...$parts, ...$this->queue];
        $this->bytes += array_sum(array_map('strlen', $parts));
        $this->oldest = $now - $this->flushInterval; // send again as soon as the backoff allows
    }

    private function countDropped(Batch $batch): void
    {
        $this->droppedPayloads += count($batch->parts);
        $this->droppedBytes += $batch->bytes();
    }

    /** Strips the brackets of a JSON array; returns '' for an empty array. */
    public static function inner(string $json): string
    {
        $json = trim($json);
        if (strlen($json) < 2 || $json[0] !== '[' || $json[-1] !== ']') {
            throw new \InvalidArgumentException('payload is not a JSON array');
        }

        return trim(substr($json, 1, -1));
    }
}
