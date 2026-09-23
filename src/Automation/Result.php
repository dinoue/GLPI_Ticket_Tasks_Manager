<?php

namespace GlpiPlugin\Tasksmanager\Automation;

/**
 * Result — outcome of one ConnectorInterface::poll() call.
 *
 *   pending   — still running, poll again on a later cron run
 *   succeeded — done; $details feed the success follow-up
 *   failed    — definitive failure; $message is shown to the technician
 */
final class Result
{
    public const PENDING   = 'pending';
    public const SUCCEEDED = 'succeeded';
    public const FAILED    = 'failed';

    /**
     * @param array  $details Label => value pairs for the ticket follow-up
     *                        (e.g. ['VM name' => 'SRV01', 'IP' => '10.0.0.5'])
     * @param array  $raw     Trimmed remote response, stored on the job row
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message = '',
        public readonly array $details = [],
        public readonly array $raw = []
    ) {
    }

    public static function pending(string $message = '', array $raw = []): self
    {
        return new self(self::PENDING, $message, [], $raw);
    }

    public static function succeeded(array $details = [], string $message = '', array $raw = []): self
    {
        return new self(self::SUCCEEDED, $message, $details, $raw);
    }

    public static function failed(string $message, array $raw = []): self
    {
        return new self(self::FAILED, $message, [], $raw);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function isSucceeded(): bool
    {
        return $this->status === self::SUCCEEDED;
    }
}
