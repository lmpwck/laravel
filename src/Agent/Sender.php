<?php

namespace Lampwick\Laravel\Agent;

/** Sends one batch body at a time without blocking. */
interface Sender
{
    public function start(string $body): void;

    public function busy(): bool;

    /** Advances the request; returns its response once it finished. */
    public function poll(): ?Response;

    public function endpoint(): string;
}
