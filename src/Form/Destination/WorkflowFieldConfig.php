<?php

namespace GlpiPlugin\Tasksmanager\Form\Destination;

use Glpi\Form\Destination\CommonITILField\SimpleValueConfig;

/**
 * Stores the selected workflow ID for a form destination field.
 * Value is the workflows_id as a string (empty string = no workflow).
 */
final class WorkflowFieldConfig extends SimpleValueConfig
{
}
