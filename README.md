# WP Bulk Plugin Updater

A WordPress utility script that automates the process of updating plugins with automatic Git commits and push capability.

## Features

- Automatically detects plugins that need updates
- Creates a separate Git commit for each plugin update
- Identifies premium plugins that require manual updates
- Maintains logs of updated plugins and those requiring manual updates
- Provides a dry-run option to preview changes
- Generates a detailed summary after completing updates

## Requirements

- PHP 7.4 or higher
- WordPress installation
- WP-CLI installed and configured
- Git installed and a valid Git repository
- Internet access for plugin updates

## Installation

1. Copy the `wp-bulk-plugin-updater` directory into your WordPress root or `wp-content` directory
2. Ensure the directory is accessible from your WordPress installation

## Usage

Navigate to your WordPress installation directory in the terminal and run:

```bash
cd wp-content
php wp-bulk-plugin-updater/update_plugins.php [options]
```

### Options

- `--dry-run`: Show what would be updated without making changes
- `--no-push`: Update plugins and commit but don't push to GitHub
- `--branch=BRANCH`: Set the branch to push to (default: current branch)
- `--help`: Display help information

### Examples

Run with default settings (update and push):
```bash
php wp-bulk-plugin-updater/update_plugins.php
```

Perform a dry run to preview changes:
```bash
php wp-bulk-plugin-updater/update_plugins.php --dry-run
```

Update plugins and commit but don't push:
```bash
php wp-bulk-plugin-updater/update_plugins.php --no-push
```

Update plugins and push to a specific branch:
```bash
php wp-bulk-plugin-updater/update_plugins.php --branch=develop
```

## Output Files

The script creates two JSON log files in the `logs` directory:

- `updated_plugins.json`: List of plugins that were successfully updated
- `manual_updates_required.json`: List of plugins that require manual updates

## Error Handling

The script includes comprehensive error handling to:

- Verify the environment meets all requirements
- Handle update failures gracefully
- Record errors in the appropriate log files
- Provide clear error messages with suggested actions

## License

This project is licensed under the MIT License - see the LICENSE file for details.