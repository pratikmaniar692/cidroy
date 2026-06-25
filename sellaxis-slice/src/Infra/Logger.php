<?php

declare(strict_types=1);

namespace Forgeline\Infra;

/**
 * Structured logging, as required by the brief. Every log line is a single
 * JSON object on its own line (so it's trivially greppable/parseable by any
 * log aggregator) with a consistent shape: timestamp, level, event, and a
 * context bag. We deliberately log the *outcome* of every decision this
 * system makes about a tricky case (held, quarantined, discarded_stale,
 * etc.) -- per Part B's own finding that silent failures are the most
 * dangerous kind; nothing in this codebase fails silently.
 */
final class Logger
{
    public static function log(string $level, string $event, array $context = []): void
    {
        $line = [
            'ts' => (new \DateTimeImmutable())->format('c'),
            'level' => $level,
            'event' => $event,
        ] + $context;

        // STDOUT is only defined as a constant under the CLI SAPI -- it
        // does not exist under PHP's built-in dev server (cli-server SAPI)
        // or most web server SAPIs (fpm, apache2handler), so referencing
        // the constant directly would fatal-error on every single log
        // call outside of plain CLI scripts. php://stdout is the portable
        // way to write the same stream under every SAPI, including the
        // console commands (PollSellaxis, ProcessOutbox, Seed) that DO run
        // under plain CLI -- so this one stream target correctly serves
        // both the HTTP service and the console commands.
        static $stream = null;
        if ($stream === null) {
            $stream = fopen('php://stdout', 'w');
        }
        fwrite($stream, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
    }

    public static function info(string $event, array $context = []): void
    {
        self::log('info', $event, $context);
    }

    public static function warn(string $event, array $context = []): void
    {
        self::log('warn', $event, $context);
    }

    public static function error(string $event, array $context = []): void
    {
        self::log('error', $event, $context);
    }
}
