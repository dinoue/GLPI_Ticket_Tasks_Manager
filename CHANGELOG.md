# Changelog

All notable changes to **Tasks Manager** are documented here.
This project follows [Semantic Versioning](https://semver.org/).

## [1.4.0] — 2026-05-15

### Added
- **Skip / Restart current step (admin actions).** Two new buttons on the
  ticket's Workflow tab let an admin force-advance past a stuck step
  ("Skip step") or re-create the current step's task ("Restart step").
  Both mark any lingering TicketTask as Done first, then either advance
  `current_step` (and create the next step's task) or re-instantiate the
  current step. New audit events: `step_skipped`, `step_restarted`.
  New AJAX actions: `skip_current_step`, `restart_current_step`. Requires
  `plugin_tasksmanager_workflows : UPDATE`.

- **Visual workflow builder (vertical flowchart).** The workflow editor's
  step list is now a vertical card-based flowchart with chevron connectors
  between steps and a drag handle on each card. Drag-reorder uses
  **SortableJS** (bundled in GLPI 11 at `public/lib/sortablejs.js`),
  replacing the up/down arrow buttons. The legacy table layout is gone.
  Each step card shows the number badge, the clickable task-template name,
  and the expandable template-comment editor.

### Changed
- Removed `tmMoveStep` JS helper (replaced by SortableJS).
- CSS additions for `.tm-flow*` classes scoped to the builder.

## [1.3.19] — 2026-05-15

### Changed
- **AJAX response contract aligned with `GLPI-Shared/rules/glpi-plugin-api.md`.**
  All plugin endpoints (`ajax/workflow.php`, `ajax/taskstate.php`) now return
  `{ ok: bool, error?: string, data?: object }` instead of the previous
  `{ success: bool, message: string }`. Error responses additionally use
  proper HTTP status codes (400 missing/invalid input, 403 forbidden,
  404 not found, 500 server error) so transport-level tooling can react
  appropriately. JS callers in `src/TaskDashboard.php`,
  `front/workflow.form.php`, and `public/js/tasksmanager.js` updated to
  read the new shape.
- Composer package name corrected to `bacus99/glpi-tasksmanager` (matching
  the shared `bacus99/glpi-<plugin-slug>` convention).
- `CLAUDE.md` updated to reflect the corrected composer name and document
  the intentional reliance on `CheckCsrfListener` (no explicit
  `Session::validateCSRF` calls in AJAX endpoints).
- `.glpiignore` now excludes `CLAUDE.md` so the dev guide isn't shipped
  in release tarballs.

## [1.3.18] — 2026-05-15

### Added
- **Tasks Manager rights tab on GLPI Profiles**
  (Administration → Profiles → *profile* → **Tasks Manager**). Lets admins
  grant per-profile rights instead of relying on the catch-all `config`
  right. New right: `plugin_tasksmanager_workflows` with granular
  Read / Update / Create / Delete / Purge permissions, rendered via
  GLPI's native `displayRightsChoiceMatrix`.
- Install/upgrade automatically declares the right and grants full
  permissions to the super-admin profile (id 4) so the installer can
  use the plugin out of the box.
- Uninstall removes the right from every profile.

### Changed
- All workflow-management permission checks (workflow list / form /
  AJAX endpoints / TaskDashboard "Create a workflow" link) switched
  from `Session::checkRight('config', …)` to
  `Session::checkRight('plugin_tasksmanager_workflows', …)`.

## [1.3.17] — 2026-05-15

### Changed
- The "description" textarea on each workflow step is now bound to the
  **task template's `comment` field**, not a per-step value. Editing it
  updates `glpi_tasktemplates.comment` via `TaskTemplate->update()` and
  applies to every workflow that uses the same template. New AJAX action:
  `update_template_comment`. The textarea label is now "Add / Edit
  template comment" with a small hint about its scope.
- `Workflow::applyStep` no longer overrides the new task's content with
  the step's description — task content always comes from the template's
  `content` field, so behavior matches GLPI's own task-template flow.

### Notes
- The `glpi_plugin_tasksmanager_workflow_steps.description` column added
  in 1.3.15 is now unused but left in place to keep the upgrade idempotent.

## [1.3.16] — 2026-05-15

### Added
- Task-template name in the workflow editor steps table is now a clickable
  link that opens the GLPI task template's edit form in a new tab. Saves a
  context-switch when reviewing what each step actually does.

## [1.3.15] — 2026-05-15

### Added
- **Per-step description.** Each workflow step now has an optional
  description field (rich text, stored as TEXT on
  `glpi_plugin_tasksmanager_workflow_steps.description`). When set, it
  overrides the task template's body when the step instantiates — so you
  can reuse a single task template across workflows and supply
  step-specific runbook instructions per workflow. Idempotent upgrade
  migration adds the column to existing installs.
- Inline expand/collapse description editor on the workflow form, saved
  via XHR on blur. New AJAX action: `update_step_description`.

### Changed
- Plugin's workflow panel now overrides `--tblr-secondary` to `#d6dee8`
  (lighter blue-gray for the "Pending" badge) and `--tblr-success` to
  `#57f06f` (brighter green for the "Done" badge). Scoped to
  `.tasksmanager-workflow-panel` so global GLPI badges are unaffected.

## [1.3.14] — 2026-05-15

### Added
- **Clone workflow.** A "Duplicate" button on the workflow list page (next to
  Edit and Delete) creates a copy of the workflow's metadata and all its
  steps under a new "(copy)" name, then opens the new workflow's editor.
- **Audit log.** Every workflow event (apply, step start, complete, remove)
  is now recorded with the user, timestamp, and JSON details payload. A new
  "History" card on the Workflow tab shows the last 12 events for the
  ticket. New table `glpi_plugin_tasksmanager_workflow_events` (created on
  install/upgrade).

## [1.3.13] — 2026-05-15

### Added
- Per-workflow checkbox **"Assign the ticket to each step's task team"**
  (default on, backward-compatible). When unchecked, advancing the workflow
  only sets the new task's tech/group from the template — the ticket's own
  assignment is left untouched. Adds `assign_ticket_to_task` to
  `glpi_plugin_tasksmanager_workflows` with an idempotent upgrade migration.

## [1.3.12] — 2026-05-15

### Fixed
- Workflow tab status badges (Done / In progress / Pending) now render with
  white text even on GLPI 11 themes that set `--tblr-badge-color` to a dark
  colour. The plugin's CSS is now registered via `ADD_CSS` (it wasn't being
  loaded at all before) and contains a scoped override.

## [1.3.11] — 2026-05-15

### Fixed
- Task template values of `-1` for `users_id_tech` / `groups_id_tech` (GLPI's
  "no specific user/group" placeholder) no longer cause a MySQL
  "Out of range value" error on the `INT UNSIGNED` column. They are now
  treated as empty for both the new task input and the ticket actor swap.

## [1.3.10] — 2026-05-15

### Fixed
- Editing an existing followup/answer in the timeline no longer reloads the
  page mid-edit. Auto-refresh now triggers from a server-set response header
  (`X-TM-Workflow-Advanced: 1`) that's only sent when our hook actually
  advances a workflow step. The previous URL-pattern matcher fired on any
  POST to `/ajax/timeline.php`, which caught followup edits as well.

## [1.3.9] — 2026-05-15

### Changed
- Auto-refresh logic now matches GLPI 11's actual save path: any successful
  POST/PUT/PATCH to `/ajax/timeline.php` (or any `tickettask` URL) on a
  ticket page schedules a reload. The previous `state=2` body check was
  too strict — GLPI sends task saves as multipart FormData where the body
  matcher could fail to read the state value reliably.
- Form-submit fallback added: full-page POST submits to a save URL with
  `state=2` set a sessionStorage flag that forces a reload on next page.

## [1.3.8] — 2026-05-15

### Changed
- `workflow-refresh.js` rewritten to be more aggressive: broader URL
  pattern list (now includes `timeline`, `common.tabs`, etc.) and a DOM
  fallback that observes the timeline for a task switching to Done.
- Verbose tracing available via `localStorage.setItem('tm_debug','1')` —
  every relevant XHR / fetch is logged to the browser console.

## [1.3.7] — 2026-05-15

### Fixed
- `workflow-refresh.js` now lives under `public/js/` so GLPI 11's static
  asset serving can find it. Previously the file was at top-level `js/`
  and returned 404 in the browser, meaning the page never auto-reloaded
  after a task was marked Done.

## [1.3.6] — 2026-05-14

### Fixed
- Ticket sidebar now reflects the new step's assigned group. Previous versions
  changed the assignment via direct inserts into `glpi_groups_tickets` /
  `glpi_users_tickets`, which bypassed GLPI 11's actor cache and left the
  sidebar showing the old group even though the underlying tables were
  correct. Actor swap now goes through `Ticket::update(['_actors' => …])`
  while preserving requesters and observers untouched.

## [1.3.5] — 2026-05-14

### Fixed
- Restored `js/workflow-refresh.js` (the file was missing from the build, so
  the ticket page didn't reload after a task was marked Done — the workflow
  *had* swapped the ticket's assigned group on the server, but the user was
  still looking at the pre-change sidebar).

## [1.3.4] — 2026-05-14

### Changed
- Version bump to trigger the database upgrade path on existing installs
  (adds `groups_id_completion` to `glpi_plugin_tasksmanager_workflows`).

## [1.3.3] — 2026-05-14

### Added
- Completion banner on the Workflow tab: once the last step is done the panel
  shows "Workflow *name* completed." with a timestamp, instead of falling
  back to a bare "No active workflow" line.
- Workflow definition now accepts an optional **completion group**. When the
  last step is marked done, the ticket's ASSIGN group is replaced with this
  one so the closing team (typically L2/L3) inherits the ticket.
- Wrench icon on **Setup → Plugins** now opens the workflow list directly
  instead of the generic config page.

### Changed
- Author is now Christian Bernard only.

### Fixed
- Step counter on the Workflow tab no longer shows inflated numbers like
  "11 / 4". Caused by `iterator_to_array` preserving the GLPI row IDs as
  array keys; now re-keyed with `array_values()` so `$i` is positional.

## [1.3.2] — 2026-05-14

### Added
- Auto-refresh of the ticket page when a workflow task is marked Done, so the
  user always sees the new step's task and refreshed actors.
- Defensive guards around the GLPI Forms destination registration so the
  plugin no longer fails to load when boot order delays the form classes.

### Changed
- The ticket's *assigned tech / group* is now swapped **before** the next
  step's TicketTask is created, so the "new task" notification reaches the
  new step's team — not the previous one.

### Fixed
- Double-fire prevention when a Save replays a task that's already Done.
- New workflow tasks are now created with state **To do** (1) instead of
  **Information** (0), so they appear in the user's task list.

## [1.3.1] — earlier

- Initial public release of the workflow engine.
- Workflow builder under **Tools**, per-ticket **Workflow** tab.
- GLPI Forms destination integration via `FormDestinationManager`.
