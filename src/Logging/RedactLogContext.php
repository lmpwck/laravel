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
                context: Redactor::array($record->context),
                extra: Redactor::array($record->extra),
            ));
        }
    }
}
