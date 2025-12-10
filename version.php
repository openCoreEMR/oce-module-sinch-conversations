<?php

/**
 * Package version data for the OpenCoreEmr Sinch Conversations Module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

// Default version when not in a git repository
// This is automatically updated by release-please
const DEFAULT_VERSION = '0.1.1'; // x-release-please-version

// Calculate and unpack version information into global variables that OpenEMR expects
[$v_major, $v_minor, $v_patch, $v_tag, $v_database] = (function (
    string $defaultVersion
): array {
    /**
     * Execute a git command in the module directory
     *
     * @param array<string> $gitArgs Git command arguments as array
     * @return string Command output trimmed, or empty string on failure
     */
    $executeGitCommand = function (array $gitArgs): string {
        $output = [];
        $return_code = 0;

        // Build command with properly escaped arguments
        $escapedArgs = array_map('escapeshellarg', $gitArgs);
        $command = sprintf(
            'git -C %s %s 2>/dev/null',
            escapeshellarg(__DIR__),
            implode(' ', $escapedArgs)
        );

        exec($command, $output, $return_code);

        // Return empty string if git command failed
        return $return_code === 0 ? trim(implode("\n", $output)) : '';
    };

    $git_dir = __DIR__ . '/.git';
    if (is_dir($git_dir) || is_file($git_dir)) {
        // We're in a git repository - use git describe for version
        $git_describe = $executeGitCommand(['describe', '--tags', '--always', '--dirty']);

        if (!empty($git_describe)) {
            // Parse git describe output (e.g., "v1.0.0-5-gabc1234-dirty" or "abc1234-dirty")
            // Format: [tag]-[commits since tag]-g[short hash][-dirty]
            if (preg_match('/^v?(\d+)\.(\d+)\.(\d+)/', $git_describe, $matches)) {
                // Has a version tag - use it via array destructuring (skip index 0)
                [, $v_major, $v_minor, $v_patch] = $matches;

                // Add commit count and hash if there are commits after the tag
                if (preg_match('/-(\d+)-g([0-9a-f]+)(-dirty)?$/', $git_describe, $extra)) {
                    // Unpack with default for optional dirty flag
                    [, $commits, $hash, $dirty] = $extra + [3 => null];
                    $v_patch .= '-dev+' . $hash;
                    if ($dirty !== null) {
                        $v_patch .= '.dirty';
                    }
                } elseif (str_ends_with($git_describe, '-dirty')) {
                    $v_patch .= '-dirty';
                }
            } else {
                // No version tag - use commit hash
                $branch = $executeGitCommand(['rev-parse', '--abbrev-ref', 'HEAD']) ?: 'unknown';
                $v_major = $branch;
                $v_minor = $git_describe;
                $v_patch = '';
            }

            return [$v_major, $v_minor, $v_patch, '', 1];
        }
    }

    // Not in a git repository or git command failed - parse and use default version
    if (preg_match('/^(\d+)\.(\d+)\.(\d+)/', $defaultVersion, $matches)) {
        [, $v_major, $v_minor, $v_patch] = $matches;
        return [$v_major, $v_minor, $v_patch, '', 1];
    }

    // Fallback if DEFAULT_VERSION is malformed
    return ['1', '0', '0', '', 1];
})(DEFAULT_VERSION);
