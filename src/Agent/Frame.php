<?php

namespace Lampwick\Laravel\Agent;

/**
 * Incremental parser for one frame of the local ingest protocol:
 * `<length>:v1:<token hash>:<body>`, where `<length>` counts everything after the first colon
 * and `<body>` is `PING` or a JSON array of records. One frame per connection.
 */
final class Frame
{
    private const MAX_HEADER = 64;

    private string $buffer = '';

    private ?int $bodyLength = null;

    private int $bodyStart = 0;

    public ?string $hash = null;

    public function __construct(private readonly int $maxFrame) {}

    /**
     * Feeds received bytes. Returns the body once the frame is complete, null while more bytes
     * are needed.
     *
     * @throws FrameException when the bytes cannot be a valid frame
     */
    public function feed(string $bytes): ?string
    {
        $this->buffer .= $bytes;

        if ($this->bodyLength === null) {
            $this->parseHeader();
            if ($this->bodyLength === null) {
                return null;
            }
        }

        $received = strlen($this->buffer) - $this->bodyStart;
        if ($received > $this->bodyLength) {
            throw new FrameException('frame longer than its length prefix');
        }

        return $received === $this->bodyLength ? substr($this->buffer, $this->bodyStart) : null;
    }

    private function parseHeader(): void
    {
        $a = strpos($this->buffer, ':');
        $b = $a === false ? false : strpos($this->buffer, ':', $a + 1);
        $c = $b === false ? false : strpos($this->buffer, ':', $b + 1);

        if ($c === false) {
            if (strlen($this->buffer) >= self::MAX_HEADER) {
                throw new FrameException('missing or oversized header');
            }

            return;
        }
        if ($c >= self::MAX_HEADER) {
            throw new FrameException('header too long');
        }

        $length = substr($this->buffer, 0, $a);
        $version = substr($this->buffer, $a + 1, $b - $a - 1);
        $this->hash = substr($this->buffer, $b + 1, $c - $b - 1);

        if ($length === '' || ! ctype_digit($length)) {
            throw new FrameException('invalid length prefix');
        }
        if ($version !== 'v1') {
            throw new FrameException("unsupported payload version \"{$version}\"");
        }
        if ((int) $length > $this->maxFrame) {
            throw new FrameException("payload of {$length} bytes is larger than the {$this->maxFrame} byte limit");
        }

        $overhead = strlen($version) + 1 + strlen($this->hash) + 1;
        if ((int) $length < $overhead) {
            throw new FrameException('length prefix shorter than header');
        }

        $this->bodyLength = (int) $length - $overhead;
        $this->bodyStart = $c + 1;
    }
}
