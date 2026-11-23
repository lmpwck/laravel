<?php

namespace Lampwick\Laravel;

use Lampwick\Agent\Redactor;
use Laravel\Nightwatch\Core;

/**
 * For your own code: send a custom event, something a user did that you name, like
 * `checkout.completed` or `payment.paid`. Lampwick lists it under **Events**, takes it as a step
 * in funnels, user paths and retention, and an event alert can notify the team when it comes in.
 *
 * The event goes to the local agent with the other records, so it never waits on the network:
 * while the current request, job or command is sampled it joins its records (and shows in its
 * trace) and is sent when the execution ends; when it is not sampled, it is sent on its own right
 * away, so sampling never loses an event. Never throws.
 */
final class Lampwick
{
    /** Longest event name, in characters (lampwick's `events::MAX_NAME`). */
    public const MAX_NAME = 100;

    /** Properties per event (`events::MAX_PROPS`). */
    public const MAX_PROPS = 20;

    /** Longest property name (`events::MAX_PROP_KEY`). */
    public const MAX_PROP_KEY = 40;

    /** Longest string property value, in bytes (`events::MAX_PROP_VALUE`). */
    public const MAX_PROP_VALUE = 200;

    /** @var array<string, true> names already reported as invalid, so the log gets one line each */
    private static array $warned = [];

    /**
     * Sends a custom event. `$props` are up to 20 strings, numbers and booleans (other values are
     * left out; sensitive names, such as `token` or `iban`, are sent as `[redacted]`). `$user` is
     * your app's user id; without one, the signed-in user's.
     *
     * Returns false when nothing is sent: monitoring is off, or the name is invalid (1 to 100
     * letters, digits, spaces and `. _ - : /`; the log says so once per name). When the agent is
     * down, the event is lost like the other records.
     *
     * @param  array<string, mixed>  $props
     */
    public static function event(string $name, array $props = [], int|string|null $user = null): bool
    {
        try {
            return self::send($name, $props, $user);
        } catch (\Throwable $e) {
            error_log('lampwick: could not send the event "'.mb_substr($name, 0, self::MAX_NAME).'" ('.$e->getMessage().')');

            return false;
        }
    }

    /** @param array<string, mixed> $props */
    private static function send(string $name, array $props, int|string|null $user): bool
    {
        $name = trim($name);
        if (! self::validName($name)) {
            if (! isset(self::$warned[$name]) && count(self::$warned) < 100) {
                self::$warned[$name] = true;
                error_log('lampwick: not sending the event "'.mb_substr($name, 0, self::MAX_NAME).'": a name is 1 to '.self::MAX_NAME.' letters, digits, spaces and . _ - : /');
            }

            return false;
        }

        $core = app(Core::class);
        if (! $core->enabled()) {
            return false;
        }

        $state = $core->executionState;
        $record = [
            'v' => 1,
            't' => 'event',
            'timestamp' => microtime(true),
            'deploy' => $state->deploy,
            'server' => $state->server,
            'name' => $name,
            'user' => $user === null ? $state->user->id() : (string) $user,
        ];
        if (($props = self::props($props)) !== []) {
            $record['props'] = $props;
        }

        if ($core->sampling()) {
            // In the execution's trace, sent with its records (lampwick shows it in the timeline).
            $record += [
                'trace_id' => $state->trace,
                'execution_source' => $state->source,
                'execution_id' => $state->id(),
                'execution_preview' => $state->executionPreview(),
                'execution_stage' => $state->stage,
            ];
            $core->ingest->write($record);
        } else {
            // The execution's records are not sent: this one goes on its own, without pointing at them.
            $core->ingest->writeNow($record);
        }

        return true;
    }

    /** As lampwick checks it (`events::valid_name`). */
    private static function validName(string $name): bool
    {
        return $name !== ''
            && mb_strlen($name) <= self::MAX_NAME
            && preg_match('~^[\p{L}\p{N} ._\-:/]+$~u', $name) === 1
            && ! str_contains($name, '  ');
    }

    /**
     * @param  array<mixed>  $props
     * @return array<string, string|int|float|bool>
     */
    private static function props(array $props): array
    {
        $out = [];
        foreach ($props as $key => $value) {
            if (count($out) === self::MAX_PROPS) {
                break;
            }
            $key = trim((string) $key);
            if ($key === '' || strlen($key) > self::MAX_PROP_KEY || preg_match('~^[A-Za-z0-9_.\-]+$~', $key) !== 1) {
                continue;
            }
            $value = match (true) {
                $value instanceof \BackedEnum => $value->value,
                $value instanceof \UnitEnum => $value->name,
                $value instanceof \Stringable => (string) $value,
                default => $value,
            };
            if (is_float($value) && ! is_finite($value)) {
                continue;
            }
            if (is_string($value)) {
                $value = mb_strcut(Redactor::text($value), 0, self::MAX_PROP_VALUE);
            } elseif (! is_int($value) && ! is_float($value) && ! is_bool($value)) {
                continue;
            }
            $out[$key] = Redactor::isSensitive($key) ? Redactor::REDACTED : $value;
        }

        return $out;
    }
}
