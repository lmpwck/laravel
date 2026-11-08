<?php

namespace Lampwick\Laravel\Logging;

use Illuminate\Log\Logger;
use Lampwick\Agent\Redactor;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;

/**
 * Log channel tap of the `lampwick` channel: replaces the values of sensitive context and extra
 * keys (passwords, tokens, ...; see Lampwick\Agent\Redactor) before the record is sent.
 */
final class RedactLogContext
{
    public function __invoke(Logger $logger): void
    {
        $monolog = $logger->getLogger();
        if ($monolog instanceof Monolog) {
            $monolog->pushProcessor(static fn (LogRecord $record): LogRecord => $record->with(
                message: Redactor::text($record->message),
                context: Redactor::array(self::normalize($record->context)),
                extra: Redactor::array(self::normalize($record->extra)),
            ));
        }
    }

    /** Objects become arrays or strings first, so their secrets are seen too. */
    private static function normalize(mixed $value, int $depth = 0): mixed
    {
        return match (true) {
            $depth > 5 => '[…]',
            $value instanceof \Throwable => ['class' => $value::class, 'message' => Redactor::text($value->getMessage()), 'code' => $value->getCode(), 'file' => $value->getFile().':'.$value->getLine()],
            $value instanceof \JsonSerializable => self::normalize($value->jsonSerialize(), $depth + 1),
            $value instanceof \Illuminate\Contracts\Support\Arrayable => self::normalize($value->toArray(), $depth + 1),
            $value instanceof \Stringable => (string) $value,
            $value instanceof \DateTimeInterface, $value instanceof \UnitEnum, ! is_object($value) && ! is_array($value) => $value,
            is_object($value) => '['.$value::class.']',
            default => array_map(fn ($v) => self::normalize($v, $depth + 1), $value),
        };
    }
}
