<?php

/**
 * WP Bulk Plugin Updater
 *
 * A script to automate WordPress plugin updates using WP-CLI with
 * automatic Git commits and push capability.
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Script constants
define('SCRIPT_VERSION', '1.0.0');
define('LOG_DIR', __DIR__ . '/logs');
define('UPDATED_PLUGINS_LOG', LOG_DIR . '/updated_plugins.json');
define('MANUAL_UPDATES_LOG', LOG_DIR . '/manual_updates_required.json');

// Command line options
$options = getopt('', ['dry-run', 'no-push', 'branch::', 'help']);

// Display help if requested
if (isset($options['help'])) {
    displayHelp();
    exit(0);
}

// Parse options
$dryRun = isset($options['dry-run']);
$noPush = isset($options['no-push']);
$branch = isset($options['branch']) ? $options['branch'] : null;

// Welcome message
echo "\n";
echo "WP Bulk Plugin Updater v" . SCRIPT_VERSION . "\n";
echo "----------------------------------------\n\n";

// Environment checks
if (!checkEnvironment()) {
    exit(1);
}

// Initialize logs
initializeLogs();

// Main execution
try {
    // Get list of plugins that need updates
    echo "Checking for plugin updates... this may take a moment\n";
    $pluginsToUpdate = getPluginsNeedingUpdates();

    if (empty($pluginsToUpdate)) {
        echo "✓ No plugins need updating.\n";
        exit(0);
    }

    echo "Found " . count($pluginsToUpdate) . " plugin(s) that need updates.\n\n";

    if ($dryRun) {
        echo "DRY RUN: The following plugins would be checked for updates:\n";
        foreach ($pluginsToUpdate as $plugin) {
            echo "- {$plugin['name']} - v{$plugin['version']} → v{$plugin['update_version']}\n";
        }
        echo "\nNo changes were made (dry run).\n";
        exit(0);
    }

    // Process each plugin
    $updated = [];
    $manualUpdateRequired = [];

    foreach ($pluginsToUpdate as $plugin) {
        echo "Processing: {$plugin['name']}...\n";

        if (canUpdateAutomatically($plugin)) {
            if (updatePlugin($plugin)) {
                $updated[] = $plugin;
            } else {
                // If update failed, add to manual update list
                $plugin['reason'] = 'Update failed';
                $manualUpdateRequired[] = $plugin;
            }
        } else {
            $plugin['reason'] = 'Premium plugin requires manual update';
            $manualUpdateRequired[] = $plugin;
            echo "  ⚠ Cannot update automatically. Added to manual update list.\n";
        }
    }

    // Save logs
    saveUpdatedPluginsLog($updated);
    saveManualUpdatesLog($manualUpdateRequired);

    // Push changes if requested
    if (!$noPush && !empty($updated)) {
        pushChangesToGitHub($branch);
    }

    // Display summary
    displaySummary($updated, $manualUpdateRequired);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}

/**
 * Display help text
 */
function displayHelp()
{
    echo "WP Bulk Plugin Updater\n";
    echo "---------------------\n";
    echo "Usage: php update_plugins.php [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run       Show what would be updated without making changes\n";
    echo "  --no-push       Update plugins and commit but don't push to GitHub\n";
    echo "  --branch=BRANCH Set the branch to push to (default: current branch)\n";
    echo "  --help          Display this help message\n";
}

/**
 * Check if the environment meets requirements
 */
function checkEnvironment()
{
    $isValid = true;

    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        echo "ERROR: PHP 7.4 or higher is required. Current version: " . PHP_VERSION . "\n";
        $isValid = false;
    } else {
        echo "✓ PHP version OK: " . PHP_VERSION . "\n";
    }

    // Check if WP-CLI is installed
    $wpCliResult = runCommand('wp --info');
    if (!$wpCliResult['success']) {
        echo "ERROR: WP-CLI is not installed or not available in PATH.\n";
        echo "Please install WP-CLI: https://wp-cli.org/#installing\n";
        $isValid = false;
    } else {
        echo "✓ WP-CLI is installed\n";
    }

    // Check if this is a WordPress installation
    $wpVersionResult = runCommand('wp core version');
    if (!$wpVersionResult['success']) {
        echo "ERROR: This does not appear to be a valid WordPress installation.\n";
        echo "Make sure you're running this script from within a WordPress site.\n";
        $isValid = false;
    } else {
        echo "✓ WordPress installation detected: v" . trim($wpVersionResult['output']) . "\n";
    }

    // Check if Git is installed
    $gitVersionResult = runCommand('git --version');
    if (!$gitVersionResult['success']) {
        echo "ERROR: Git is not installed or not available in PATH.\n";
        $isValid = false;
    } else {
        echo "✓ Git is installed: " . trim($gitVersionResult['output']) . "\n";
    }

    // Check if current directory is in a Git repository
    $gitStatusResult = runCommand('git status');
    if (!$gitStatusResult['success']) {
        echo "ERROR: Current directory is not in a Git repository.\n";
        $isValid = false;
    } else {
        echo "✓ Git repository detected\n";
    }

    return $isValid;
}

/**
 * Initialize log files
 */
function initializeLogs()
{
    // Create logs directory if it doesn't exist
    if (!is_dir(LOG_DIR)) {
        mkdir(LOG_DIR, 0755, true);
    }

    // Create empty log files if they don't exist
    if (!file_exists(UPDATED_PLUGINS_LOG)) {
        file_put_contents(UPDATED_PLUGINS_LOG, json_encode([]));
    }

    if (!file_exists(MANUAL_UPDATES_LOG)) {
        file_put_contents(MANUAL_UPDATES_LOG, json_encode([]));
    }
}

/**
 * Get list of plugins that need updates
 */
function getPluginsNeedingUpdates()
{
    $result = runCommand('wp plugin list --update=available --format=json');

    if (!$result['success']) {
        throw new Exception("Failed to get list of plugins: " . $result['output']);
    }

    $plugins = json_decode($result['output'], true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Failed to parse plugin list: " . json_last_error_msg());
    }

    // Get more detailed update information
    echo "Getting detailed update information...\n";
    foreach ($plugins as $key => $plugin) {
        echo "  • Checking {$plugin['name']}...\r";

        // Get update version information using wp plugin update --dry-run
        $detailResult = runCommand("wp plugin update {$plugin['name']} --dry-run --format=json");
        if ($detailResult['success']) {
            $updateDetails = json_decode($detailResult['output'], true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($updateDetails) && !empty($updateDetails)) {
                // Extract update version from details
                $updateInfo = reset($updateDetails); // Get first element
                if (isset($updateInfo['new_version'])) {
                    $plugins[$key]['update_version'] = $updateInfo['new_version'];
                } else {
                    $plugins[$key]['update_version'] = 'Unknown';
                }
            } else {
                $plugins[$key]['update_version'] = 'Unknown';
            }
        } else {
            $plugins[$key]['update_version'] = 'Unknown';
        }
    }
    echo "\n"; // Clear the line after progress

    return $plugins;
}

/**
 * Check if a plugin can be updated automatically
 */
function canUpdateAutomatically($plugin)
{
    // Try to get update info
    $checkResult = runCommand("wp plugin update {$plugin['name']} --dry-run");

    // Check for specific error patterns that indicate a premium plugin
    if (!$checkResult['success']) {
        $output = strtolower($checkResult['output']);

        // Common error messages for plugins that can't be updated via WP-CLI
        $errorPatterns = [
            'premium plugin',
            'requires a license',
            'update failed',
            'Error: This plugin does not support direct installation',
            'purchase',
            'license key',
            'subscription',
            'connection error',
            'authentication'
        ];

        foreach ($errorPatterns as $pattern) {
            if (strpos($output, strtolower($pattern)) !== false) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Update a plugin and commit the change
 */
function updatePlugin($plugin)
{
    echo "  • Updating {$plugin['name']}...\n";

    // Update the plugin without the verbose flag
    $command = "wp plugin update {$plugin['name']} --format=json";

    // Run the command in real-time with output streaming
    $descriptorspec = [
        0 => ["pipe", "r"],  // stdin
        1 => ["pipe", "w"],  // stdout
        2 => ["pipe", "w"]   // stderr
    ];

    echo "    Starting update process";
    $process = proc_open($command, $descriptorspec, $pipes);

    if (is_resource($process)) {
        // Close stdin
        fclose($pipes[0]);

        // Read and output stdout with progress indicator
        $dots = 0;
        $output = "";
        while (!feof($pipes[1])) {
            $char = fgetc($pipes[1]);
            if ($char !== false) {
                $output .= $char;
                // Add a progress dot every half second
                if ($dots < 30) {
                    echo ".";
                    $dots++;
                    usleep(100000); // 0.1 second delay
                }
            }
        }
        echo "\n";
        fclose($pipes[1]);

        // Read stderr
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        // Close process and get exit code
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            echo "  ✗ Update failed\n";
            if ($stderr) {
                echo "    Error: " . $stderr . "\n";
            } elseif ($output) {
                echo "    Output: " . $output . "\n";
            }
            return false;
        }

        // Extract new version from output if possible
        $updateData = json_decode($output, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($updateData) && !empty($updateData)) {
            $firstPlugin = reset($updateData);
            if (isset($firstPlugin['new_version'])) {
                $plugin['update_version'] = $firstPlugin['new_version'];
            }
        }

        // Format and display the captured output (use non-JSON version for display)
        $readableOutput = runCommand("wp plugin update {$plugin['name']}");
        if ($readableOutput['success'] && !empty($readableOutput['output'])) {
            echo "    " . str_replace("\n", "\n    ", trim($readableOutput['output'])) . "\n";
        } elseif (trim($output)) {
            // Fall back to JSON output if readable version fails
            echo "    " . str_replace("\n", "\n    ", trim($output)) . "\n";
        }
    } else {
        echo "  ✗ Failed to start update process\n";
        return false;
    }

    echo "  ✓ Plugin updated successfully\n";

    // Check for and clear Git index lock if it exists
    $gitDir = runCommand("git rev-parse --git-dir");
    if ($gitDir['success']) {
        $indexLockPath = trim($gitDir['output']) . "/index.lock";
        if (file_exists($indexLockPath)) {
            echo "  ! Git index lock detected. Attempting to clear...\n";
            if (unlink($indexLockPath)) {
                echo "  ✓ Git index lock cleared\n";
            } else {
                echo "  ✗ Could not clear Git index lock. Commit may fail.\n";
            }
        }
    }

    // Create a commit with progress indicators
    echo "  • Preparing Git commit...\n";
    echo "    - Adding files to Git staging";

    // Run git add with progress indicator
    $addCommand = "git add -A";
    $addProcess = proc_open($addCommand, [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ], $pipes);

    if (is_resource($addProcess)) {
        fclose($pipes[0]);

        // Show progress while git add runs
        $dots = 0;
        while (!feof($pipes[1])) {
            fgetc($pipes[1]);
            if ($dots < 10) {
                echo ".";
                $dots++;
                usleep(100000);
            }
        }
        echo "\n";
        fclose($pipes[1]);

        $addError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $addExitCode = proc_close($addProcess);

        if ($addExitCode !== 0) {
            echo "    ✗ Git add failed: " . $addError . "\n";
            echo "  ⚠ Plugin was updated but changes are not committed\n";
            return true;
        }
    }

    echo "    - Creating commit";
    $commitMessage = "Updated {$plugin['name']} plugin to version {$plugin['update_version']}";
    $commitCommand = "git commit -m \"$commitMessage\"";

    $commitProcess = proc_open($commitCommand, [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"]
    ], $pipes);

    if (is_resource($commitProcess)) {
        fclose($pipes[0]);

        // Show progress while commit runs
        $dots = 0;
        $output = "";
        while (!feof($pipes[1])) {
            $char = fgetc($pipes[1]);
            if ($char !== false) {
                $output .= $char;
                if ($dots < 10) {
                    echo ".";
                    $dots++;
                    usleep(100000);
                }
            }
        }
        echo "\n";
        fclose($pipes[1]);

        $commitError = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $commitExitCode = proc_close($commitProcess);

        if ($commitExitCode !== 0) {
            // Check for specific Git errors
            if (strpos($commitError, 'index.lock') !== false) {
                echo "  ⚠ Git index lock error detected. Please resolve manually:\n";
                echo "    Run: rm -f .git/index.lock\n";
                echo "    Then: git add -A && git commit -m \"Updated {$plugin['name']} plugin\"\n";
            } else {
                echo "  ⚠ Failed to create commit: " . ($commitError ?: $output) . "\n";
            }
            echo "  ⚠ Plugin was updated but changes are not committed\n";
            return true; // Still return true because the plugin was updated
        }

        // Show commit output
        if (trim($output)) {
            echo "    " . str_replace("\n", "\n    ", trim($output)) . "\n";
        }
    } else {
        echo "  ⚠ Failed to start commit process\n";
        echo "  ⚠ Plugin was updated but changes are not committed\n";
        return true;
    }

    echo "  ✓ Changes committed\n";
    return true;
}

/**
 * Push changes to GitHub
 */
function pushChangesToGitHub($branch = null)
{
    echo "\nPushing changes to GitHub...\n";

    $pushCommand = "git push origin";
    if ($branch) {
        $pushCommand .= " $branch";
    }

    $pushResult = runCommand($pushCommand);

    if (!$pushResult['success']) {
        echo "✗ Failed to push changes: " . $pushResult['output'] . "\n";
        return false;
    }

    echo "✓ Changes pushed successfully\n";
    return true;
}

/**
 * Save updated plugins log
 */
function saveUpdatedPluginsLog($plugins)
{
    $logData = [];

    if (file_exists(UPDATED_PLUGINS_LOG)) {
        $logData = json_decode(file_get_contents(UPDATED_PLUGINS_LOG), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logData = [];
        }
    }

    $timestamp = date('Y-m-d H:i:s');

    foreach ($plugins as $plugin) {
        $logData[] = [
            'name' => $plugin['name'],
            'version' => $plugin['version'],
            'new_version' => $plugin['update_version'],
            'timestamp' => $timestamp
        ];
    }

    file_put_contents(UPDATED_PLUGINS_LOG, json_encode($logData, JSON_PRETTY_PRINT));
}

/**
 * Save manual updates log
 */
function saveManualUpdatesLog($plugins)
{
    $logData = [];

    if (file_exists(MANUAL_UPDATES_LOG)) {
        $logData = json_decode(file_get_contents(MANUAL_UPDATES_LOG), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $logData = [];
        }
    }

    $timestamp = date('Y-m-d H:i:s');

    foreach ($plugins as $plugin) {
        $logData[] = [
            'name' => $plugin['name'],
            'version' => $plugin['version'],
            'new_version' => $plugin['update_version'],
            'reason' => $plugin['reason'],
            'timestamp' => $timestamp
        ];
    }

    file_put_contents(MANUAL_UPDATES_LOG, json_encode($logData, JSON_PRETTY_PRINT));
}

/**
 * Run a shell command safely
 */
function runCommand($command)
{
    $output = [];
    $returnCode = 0;

    exec($command . " 2>&1", $output, $returnCode);

    return [
        'success' => $returnCode === 0,
        'output' => implode("\n", $output),
        'code' => $returnCode
    ];
}

/**
 * Display a summary of the update operations
 */
function displaySummary($updated, $manualUpdateRequired)
{
    echo "\n----------------------------------------\n";
    echo "SUMMARY\n";
    echo "----------------------------------------\n\n";

    echo "✓ Total plugins processed: " . (count($updated) + count($manualUpdateRequired)) . "\n";
    echo "  • Updated automatically: " . count($updated) . "\n";
    echo "  • Requiring manual updates: " . count($manualUpdateRequired) . "\n\n";

    if (!empty($updated)) {
        echo "PLUGINS UPDATED:\n";
        foreach ($updated as $plugin) {
            echo "  ✓ {$plugin['name']}: v{$plugin['version']} → v{$plugin['update_version']}\n";
        }
        echo "\n";
    }

    if (!empty($manualUpdateRequired)) {
        echo "PLUGINS REQUIRING MANUAL UPDATE:\n";
        foreach ($manualUpdateRequired as $plugin) {
            echo "  ⚠ {$plugin['name']}: v{$plugin['version']} → v{$plugin['update_version']}\n";
            echo "     Reason: {$plugin['reason']}\n";
        }
        echo "\n";
    }

    echo "Logs saved to:\n";
    echo "  • " . UPDATED_PLUGINS_LOG . "\n";
    echo "  • " . MANUAL_UPDATES_LOG . "\n";
}
