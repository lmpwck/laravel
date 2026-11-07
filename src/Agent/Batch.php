<?php

namespace Lampwick\Laravel\Agent;

/** A set of queued payloads sent as one HTTP request. */
final class Batch
{
    /** @param list<string> $parts inner parts of JSON arrays */
    public function __construct(public readonly array $parts) {}

    public function body(): string
    {
        return '['.implode(',', $this->parts).']';
    }

    public function bytes(): int
    {
        return array_sum(array_map('strlen', $this->parts));
    }
}
