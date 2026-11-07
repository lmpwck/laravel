<?php

namespace Lampwick\Laravel\Tests\Unit;

use Lampwick\Laravel\Agent\Agent;
use Lampwick\Laravel\Agent\Frame;
use Lampwick\Laravel\Agent\FrameException;
use Lampwick\Laravel\Tests\FakeIngest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    public function test_parses_a_frame_fed_in_pieces(): void
    {
        $bytes = FakeIngest::frame('[{"t":"log","message":"héllo"}]');
        $frame = new Frame(1024);

        foreach (str_split(substr($bytes, 0, -1), 3) as $piece) {
            $this->assertNull($frame->feed($piece));
        }
        $this->assertSame('[{"t":"log","message":"héllo"}]', $frame->feed(substr($bytes, -1)));
        $this->assertSame(Agent::tokenHash(FakeIngest::TOKEN), $frame->hash);
    }

    public function test_parses_ping(): void
    {
        $this->assertSame('PING', (new Frame(1024))->feed(FakeIngest::frame('PING')));
    }

    public function test_token_hash_matches_lampwick(): void
    {
        // Same vector as lampwick's `token_hash_matches_php` test.
        $this->assertSame(7, strlen(Agent::tokenHash('abc')));
        $this->assertSame(substr(hash('xxh128', 'abc'), 0, 7), Agent::tokenHash('abc'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFrames(): iterable
    {
        yield 'no header' => [str_repeat('x', 80), 'missing or oversized header'];
        yield 'bad length' => ['1x:v1:abcdefg:[]', 'invalid length prefix'];
        yield 'unknown version' => ['12:v9:abcdefg:[]', 'unsupported payload version "v9"'];
        yield 'too large' => ['999999:v1:abcdefg:[', 'larger than the 1024 byte limit'];
        yield 'longer than prefix' => ['12:v1:abcdefg:[]]]', 'frame longer than its length prefix'];
        yield 'shorter header' => ['3:v1:abcdefg:', 'length prefix shorter than header'];
    }

    #[DataProvider('invalidFrames')]
    public function test_rejects_invalid_frames(string $bytes, string $message): void
    {
        $this->expectException(FrameException::class);
        $this->expectExceptionMessage($message);
        (new Frame(1024))->feed($bytes);
    }
}
