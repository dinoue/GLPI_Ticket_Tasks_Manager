<?php

namespace GlpiPlugin\Tasksmanager\Automation;

use GlpiPlugin\Tasksmanager\Workflow;

/**
 * Mapping — resolves the value specs in an automation_config against a
 * ticket.
 *
 * A value spec is one of:
 *   "form:26"                 answer to form question 26 (case-preserved)
 *   "ticket:id" / "ticket:name" / "ticket:entities_id"
 *   "ticket:entity"           the ticket entity's full name
 *   "GLPI-{ticket:id}-{form:26}"  string with placeholders
 *   any other scalar          literal
 *   {"value": …}              literal (escape hatch, e.g. a literal "form:1")
 *   {"from": <spec>,          source, then optionally:
 *    "map": {"label": out},     lookup (exact, then case-insensitive)
 *    "default": …,              used when empty or unmapped
 *    "required": true,          empty after all of the above → error
 *    "type": "int|float|bool|string",
 *    "min": n, "max": n}        numeric bounds (after type cast)
 *
 * Errors throw MappingException: the Runner fails the job before
 * anything is sent, so a bad mapping never reaches the remote system.
 */
class Mapping
{
    /** Top-level config keys that are not value specs. */
    private const RESERVED = ['connector', 'inputs', 'failure_groups_id', 'timeout_minutes', 'success_followup_private'];

    /**
     * Resolve the `inputs` map. Keys resolving to null / '' are omitted
     * (optional form questions left blank) unless marked required.
     */
    public static function resolveInputs(array $inputs, int $tickets_id): array
    {
        $out = [];
        foreach ($inputs as $key => $spec) {
            $value = self::resolve($spec, $tickets_id, (string)$key);
            if ($value === null || $value === '') {
                continue;
            }
            $out[(string)$key] = $value;
        }
        return $out;
    }

    /**
     * Resolve every non-reserved top-level key (catalog_item_id,
     * project_id, deployment_name, …) so any of them can come from the
     * form or a lookup table.
     */
    public static function resolveConfig(array $cfg, int $tickets_id): array
    {
        foreach ($cfg as $key => $spec) {
            if (in_array($key, self::RESERVED, true)) {
                continue;
            }
            $cfg[$key] = self::resolve($spec, $tickets_id, (string)$key);
        }
        return $cfg;
    }

    /**
     * @throws MappingException
     */
    public static function resolve(mixed $spec, int $tickets_id, string $key): mixed
    {
        if (!is_array($spec)) {
            return self::resolveSource($spec, $tickets_id);
        }
        if (array_is_list($spec)) {
            return $spec; // literal list (e.g. tags)
        }
        if (array_key_exists('value', $spec)) {
            return $spec['value'];
        }

        $value = self::resolveSource($spec['from'] ?? null, $tickets_id);
        $empty = ($value === null || $value === '');

        if (!$empty && isset($spec['map']) && is_array($spec['map'])) {
            $mapped = self::lookup($spec['map'], (string)$value);
            if ($mapped === null) {
                if (!array_key_exists('default', $spec)) {
                    throw new MappingException(sprintf('%s: no mapping for "%s"', $key, (string)$value));
                }
                $value = $spec['default'];
            } else {
                $value = $mapped;
            }
        } elseif ($empty && array_key_exists('default', $spec)) {
            $value = $spec['default'];
        }

        if ($value === null || $value === '') {
            if (!empty($spec['required'])) {
                throw new MappingException(sprintf('%s: required value is empty', $key));
            }
            return null;
        }

        return self::cast($value, $spec, $key);
    }

    private static function resolveSource(mixed $source, int $tickets_id): mixed
    {
        if (!is_string($source)) {
            return $source;
        }
        if (preg_match('/^form:(\d+)$/', $source, $m)) {
            return Workflow::getFormAnswerRaw($tickets_id, (int)$m[1]);
        }
        if (preg_match('/^ticket:(\w+)$/', $source, $m)) {
            return self::ticketField($tickets_id, $m[1]);
        }
        if (str_contains($source, '{')) {
            return preg_replace_callback(
                '/\{(form:\d+|ticket:\w+)\}/',
                fn ($m) => (string)(self::resolveSource($m[1], $tickets_id) ?? ''),
                $source
            );
        }
        return $source;
    }

    private static function ticketField(int $tickets_id, string $field): ?string
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB($tickets_id)) {
            return null;
        }
        if ($field === 'entity') {
            return (string)\Dropdown::getDropdownName('glpi_entities', (int)$ticket->fields['entities_id']);
        }
        $raw = $ticket->fields[$field] ?? null;
        return $raw === null ? null : (string)$raw;
    }

    private static function lookup(array $map, string $value): mixed
    {
        if (array_key_exists($value, $map)) {
            return $map[$value];
        }
        $needle = mb_strtolower(trim($value));
        foreach ($map as $k => $v) {
            if (mb_strtolower(trim((string)$k)) === $needle) {
                return $v;
            }
        }
        return null;
    }

    /**
     * @throws MappingException
     */
    private static function cast(mixed $value, array $spec, string $key): mixed
    {
        switch ($spec['type'] ?? null) {
            case 'int':
            case 'float':
                if (!is_numeric($value)) {
                    throw new MappingException(sprintf('%s: "%s" is not a number', $key, (string)$value));
                }
                $value = $spec['type'] === 'int' ? (int)$value : (float)$value;
                if (isset($spec['min']) && $value < $spec['min']) {
                    throw new MappingException(sprintf('%s: %s is below the minimum %s', $key, $value, $spec['min']));
                }
                if (isset($spec['max']) && $value > $spec['max']) {
                    throw new MappingException(sprintf('%s: %s is above the maximum %s', $key, $value, $spec['max']));
                }
                return $value;
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'string':
                return (string)$value;
        }
        return $value;
    }
}
