<?php

namespace GlpiPlugin\Tasksmanager\Automation;

/**
 * ConnectorInterface — one outbound automation system (Aria today, AWX or
 * others later). Connectors are stateless request builders: the Runner
 * owns the job row, retries and ticket side effects.
 *
 * Both calls run from the automationjobs cron, never inside a user
 * request. Transport problems (timeout, 5xx, bad token) must throw
 * ConnectorException with $permanent = false so the Runner retries up to
 * its attempt cap; a request the remote side rejects outright throws
 * with $permanent = true and fails the job immediately.
 */
interface ConnectorInterface
{
    /**
     * Short machine name stored in automation_jobs.connector ("aria").
     */
    public function getName(): string;

    /**
     * Send the request. Returns the external id to poll with later
     * (for Aria: the deployment id).
     *
     * @param array $inputs Resolved request inputs (form answers already mapped)
     * @param array $cfg    The step's automation_config with every value
     *                      spec already resolved
     *
     * @throws ConnectorException
     */
    public function submit(array $inputs, array $cfg): string;

    /**
     * Check progress of a submitted request.
     *
     * @throws ConnectorException
     */
    public function poll(string $id, array $cfg): Result;

    /**
     * Log in with the stored credentials (never a cached session) and make
     * one read-only call. Returns a short human summary for the settings
     * page ("Connected — 12 catalog items visible").
     *
     * @throws ConnectorException
     */
    public function testConnection(): string;
}
