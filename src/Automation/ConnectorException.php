<?php

namespace GlpiPlugin\Tasksmanager\Automation;

/**
 * Thrown by a connector when a call could not be completed.
 *
 * $permanent = false (default): transient — network error, timeout, 5xx,
 * expired token. The Runner counts an attempt and retries next run.
 * $permanent = true: the remote side rejected the request (4xx on
 * submit, missing config). Retrying would not help, so the job fails.
 */
class ConnectorException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $permanent = false,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
