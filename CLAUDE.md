# CLAUDE.md — Tasks Manager plugin

## Shared conventions

Conventions for **all** of my GLPI 11 plugins live in [`../GLPI-Shared/`](../GLPI-Shared/CLAUDE.md). Read those rules first when starting any task — they cover versioning, namespacing, hooks, DB API, validation-first workflow, migrations, AJAX endpoints, and the build/release process.

This file only covers what's specific to *this* plugin.

## Status — canonical reference plugin

This is the **published reference implementation** for my GLPI 11 plugin conventions. Patterns in [`../GLPI-Shared/rules/`](../GLPI-Shared/rules/) were extracted from this codebase. When a rule disagrees with the actual code here, **the code wins** and the shared rule needs an update.

Published at [github.com/bacus99/GLPI_Ticket_Tasks_Manager](https://github.com/bacus99/GLPI_Ticket_Tasks_Manager) and listed in the GLPI plugin catalog.

## Project goal

Adds a **workflow engine** to GLPI 11 tickets: define ordered sequences of task templates, apply them to tickets (manually or via GLPI Forms), and let each step automatically hand off to the next as tasks are completed.

## Plugin identity

| Field    | Value                                                          |
|----------|----------------------------------------------------------------|
| Slug     | `tasksmanager` (deployed folder name)                          |
| Repo     | [bacus99/GLPI_Ticket_Tasks_Manager](https://github.com/bacus99/GLPI_Ticket_Tasks_Manager) |
| Composer | `bacus99/glpi-tasksmanager`                                    |
| Namespace| `GlpiPlugin\Tasksmanager\`                                     |

## Architecture

```
tasksmanager/
├── setup.php              Workflow + TaskDashboard + Profile registrations,
│                          ticket/tickettask add/update hooks for auto-advance,
│                          form-destination integration (defensive try/catch)
├── hook.php               Install tables: taskstates, configs, workflows, etc.
├── src/
│   ├── Workflow.php       Workflow model + step-advance engine
│   ├── TaskState.php      Per-task state extension
│   ├── TaskDashboard.php  "Workflow" tab rendered on tickets
│   ├── Profile.php        Rights tab on profiles
│   ├── Config.php
│   └── Form/Destination/
│       ├── WorkflowField.php        GLPI Forms integration
│       └── WorkflowFieldConfig.php
├── front/                 workflow.list.php, workflow.form.php, …
├── ajax/                  workflow + taskstate XHR endpoints
├── public/js/             workflow-refresh.js (auto-reload on Done)
├── public/css/
└── locales/tasksmanager.pot
```

## How the workflow engine works

1. A **Workflow** is an ordered list of GLPI Task Templates.
2. When applied to a ticket, the first template's task is instantiated.
3. The ticket's *assigned tech / group* are set from that task's template.
4. When the user marks the task **Done**, the plugin:
   - finds the next step in the workflow,
   - swaps the ticket's *assigned tech / group* to the next template's values (so the next notification reaches the right team),
   - creates the next task.
5. When the last step is done, the workflow is marked `completed`.

## Plugin-specific notes

- **Defensive form-destination registration** — `plugin_init_tasksmanager()` wraps the `FormDestinationManager::registerPluginCommonITILConfigField()` call in `try { … } catch (\Throwable $e) {}`. During early boot (when `Plugin::getPluginInformation` runs), the form classes or the plugin's autoloader may not be available; an uncaught error here makes GLPI fail to load plugin information.
- **Schema upgrades live in `plugin_tasksmanager_install()`** — each new column or index is gated by `$DB->fieldExists()` / `$DB->indexExists()` so the same function handles both fresh install and upgrade. See [`../GLPI-Shared/rules/glpi-migration.md`](../GLPI-Shared/rules/glpi-migration.md).
- **`workflow-refresh.js`** triggers a full page reload when a task is marked Done, so the Workflow tab and the ticket's assigned group stay in sync with the server state.

## Intentional deviation from `GLPI-Shared/rules/glpi-plugin-api.md`

The shared rule prescribes an explicit `Session::validateCSRF($payload)` call
in every state-changing AJAX endpoint. **This plugin doesn't do that.** GLPI 11's
`CheckCsrfListener` middleware validates the CSRF token before the request
reaches our PHP — it accepts either:

- The `X-Glpi-Csrf-Token` request header (used by our `fetch()` calls in
  `src/TaskDashboard.php` and `front/workflow.form.php`), **or**
- The `_glpi_csrf_token` body field (used by our HTML forms; populated
  client-side from the `meta[property='glpi:csrf_token']` tag).

An explicit `Session::validateCSRF($_POST)` call would *fail* for the
header-based AJAX path because no token is in `$_POST`. So we rely on the
middleware exclusively. If you add a new AJAX endpoint, follow the same
pattern — don't add explicit `validateCSRF` calls.

## Pre-existing conventions

This plugin **pre-dates** the GLPI-Shared central conventions folder. The rules in GLPI-Shared were extracted from here, so by definition this plugin follows them — but if you find a spot that doesn't, prefer fixing this plugin (and updating the rule if it was wrong) over diverging the rule.
