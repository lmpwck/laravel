<?php

namespace Lampwick\Laravel\Agent;

final class Response
{
    /**
     * @param  int  $status  HTTP status, or 0 when no response was received
     * @param  float|null  $retryAfter  seconds, from the `Retry-After` header
     */
    public function __construct(
        public readonly int $status,
        public readonly ?float $retryAfter = null,
        public readonly ?string $error = null,
    ) {}
}
