# Changelog

All notable changes to **Tasks Manager** are documented here.
This project follows [Semantic Versioning](https://semver.org/).

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
