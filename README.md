# Tasks Manager - GLPI Plugin

Enhance ticket task workflow management in GLPI with extended states, priorities, due dates, progress tracking, and a visual task board.

## Features

- **Extended task statuses**: Pending, In Progress, Blocked, In Review, Done
- **Priority levels**: Very low through Critical (6 levels)
- **Due date tracking** per task
- **Progress percentage** (0-100%) with visual progress bars
- **User and Group assignment** per task state
- **Task board tab** on Ticket form for at-a-glance workflow overview
- **Global dashboard** under Helpdesk menu for cross-ticket task tracking
- **AJAX status updates** from the board UI
- **GLPI Search engine** integration for filtering/sorting task states
- **Automatic sync**: native task completion auto-updates plugin status

## Requirements

| Component   | Version       |
|-------------|---------------|
| GLPI        | 11.0.0–11.0.x |
| PHP         | >= 8.1        |
| MySQL/MariaDB | 8.0+ / 10.5+ |

## Installation

1. Download or clone this repository into `glpi/plugins/tasksmanager/`
2. Navigate to **Setup > Plugins** in GLPI
3. Find "Tasks Manager" and click **Install**
4. Click **Enable**
5. Configure under **Setup > Plugins > Tasks Manager** (wrench icon)

## Directory Structure

```
tasksmanager/
├── setup.php              # Plugin registration & hooks
├── hook.php               # Install/uninstall & event callbacks
├── composer.json           # PSR-4 autoloading
├── README.md
├── src/
│   ├── TaskState.php       # Core model (CommonDBTM)
│   ├── TaskDashboard.php   # Tab on Ticket + menu entry
│   └── Config.php          # Plugin configuration
├── front/
│   ├── config.form.php     # Settings page
│   ├── taskstate.form.php  # TaskState CRUD
│   └── dashboard.php       # Global overview
├── ajax/
│   └── taskstate.php       # XHR endpoint
├── public/
│   ├── css/tasksmanager.css
│   └── js/tasksmanager.js
├── locales/
│   └── tasksmanager.pot    # Translation template
└── templates/              # Twig templates (future)
```

## License

GPL-3.0-or-later
