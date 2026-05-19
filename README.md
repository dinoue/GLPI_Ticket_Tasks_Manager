# Tasks Manager — GLPI 11 Plugin

[![GLPI](https://img.shields.io/badge/GLPI-11.0.x-blue.svg)](https://glpi-project.org/)
[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.1-777BB4.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-green.svg)](LICENSE)

Adds a **workflow engine** to GLPI tickets: define ordered sequences of task templates, apply them to tickets (manually or via GLPI Forms), and let each step automatically hand off to the next as tasks are completed.

## Features

- **Named workflows** as ordered sequences of GLPI task templates.
- **Manual application** via a dedicated *Workflow* tab on every Ticket.
- **Form integration** — pick a workflow as part of a GLPI Form's ticket destination, so submissions are routed through your workflow from day one.
- **Automatic step advance** — when a step's task is marked Done (checkbox or status dropdown), the next step's task is created automatically on the same ticket.
- **Per-step routing** — the new task's *and* the ticket's assigned group/technician come from the task template, so notifications reach the right team at every stage.
- **GLPI 11 native** — CSRF-compliant via `CheckCsrfListener`, uses the native form-destination API, PSR-4 autoloaded.

## Requirements

| Component     | Version            |
|---------------|--------------------|
| GLPI          | 11.0.0 – 11.0.x    |
| PHP           | ≥ 8.1              |
| MySQL/MariaDB | 8.0+ / 10.5+       |

## Installation

1. Download the latest release tarball from the [Releases page](https://github.com/bacus99/GLPI_Ticket_Tasks_Manager/releases) and extract it into `glpi/plugins/tasksmanager/` — or clone this repository there directly.
2. In GLPI, go to **Setup → Plugins**.
3. Find **Tasks Manager** in the list, click **Install**, then **Enable**.
4. Create your workflows under **Tools → Workflows** (build them from existing GLPI Task Templates).
5. Apply a workflow to a ticket from its **Workflow** tab, or set it as the default for a GLPI Form destination.

## How it works

1. A workflow is an ordered list of GLPI **Task Templates**.
2. When applied to a ticket, the first template's task is instantiated.
3. The ticket's *assigned tech / group* are set from that task's template.
4. When the user marks the task **Done**, the plugin:
   - finds the next step,
   - swaps the ticket's *assigned tech / group* to the next template's values (so the next notification reaches the right team),
   - creates the next task.
5. When the last step is done, the workflow is marked `completed`.

## Directory structure

```
tasksmanager/
├── setup.php                        Plugin registration, hooks, JS injection
├── hook.php                         Install/uninstall, event callbacks
├── composer.json                    PSR-4 autoload
├── plugin.xml                       GLPI catalog descriptor
├── logo.svg                         Plugin logo
├── README.md
├── LICENSE
├── CHANGELOG.md
├── src/
│   ├── Workflow.php                 Workflow model + step engine
│   ├── TaskState.php                Per-task state extension
│   ├── TaskDashboard.php            Ticket "Workflow" tab
│   ├── Config.php                   Plugin configuration
│   └── Form/Destination/
│       ├── WorkflowField.php        Form destination integration
│       └── WorkflowFieldConfig.php
├── front/                           Form pages (workflow.list, workflow.form, …)
├── ajax/                            XHR endpoints (workflow, taskstate)
├── js/
│   └── workflow-refresh.js          Auto-reload after a task is marked Done
└── locales/
    └── tasksmanager.pot             Translation template (en_GB, fr_FR)
```

## Building a release

```powershell
.\build.ps1                     # uses version from setup.php
.\build.ps1 -Version 1.3.12      # or override
```

Produces `dist/glpi-tasksmanager-<VERSION>.tar.bz2`, excluding everything
listed in `.glpiignore` (git metadata, build artifacts, IDE files, etc.).

## Publishing to the GLPI plugin catalog

1. **Replace the LICENSE stub** with the full GPL-3.0 text:
   ```powershell
   Invoke-WebRequest https://www.gnu.org/licenses/gpl-3.0.txt -OutFile LICENSE
   ```
2. **Push to GitHub** under `github.com/bacus99/GLPI_Ticket_Tasks_Manager` (must be public).
3. **Tag and release** the build:
   ```bash
   git tag -a 1.3.12 -m "Release 1.3.12"
   git push --tags
   gh release create 1.3.12 dist/glpi-tasksmanager-1.3.12.tar.bz2 \
       --title "1.3.12" --notes-from-tag
   ```
4. **Verify** that the URLs in `plugin.xml` all resolve (logo, homepage,
   issues, readme, and most importantly the `download_url`).
5. **Submit** the plugin to the [GLPI plugin catalog](https://plugins.glpi-project.org/)
   by sending a Pull Request to
   [pluginsGLPI/data](https://github.com/pluginsGLPI/data) adding your
   `plugin.xml` URL to `xml/plugins.json`.

## License

[GPL-3.0-or-later](LICENSE)

## Author

Christian Bernard
