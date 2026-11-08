<?php

namespace Lampwick\Laravel\Tests\Feature\Fixtures;

// Shapes a future instrumentation version might give Ingest::$streamFactory.

final class Untyped
{
    public $streamFactory;
}

final class ClosureTyped
{
    public ?\Closure $streamFactory = null;
}

final class PrivateProperty
{
    private $streamFactory; // @phpstan-ignore property.unused
}

final class ReadOnlyProperty
{
    public function __construct(public readonly mixed $streamFactory = null) {}
}

final class NarrowlyTyped
{
    public ?\Laravel\Nightwatch\SocketStreamFactory $streamFactory = null;
}

final class StaticProperty
{
    public static $streamFactory;
}
