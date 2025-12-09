<?php

/**
 * Central accessor for PHP session
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * Provides centralized access to PHP session.
 * This class serves as a single point of abstraction for session access,
 * making it easier to test and refactor in the future.
 */
class SessionAccessor
{
    /**
     * Get a value from session
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a value in session
     */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Check if a key exists in session
     */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a key from session
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Get a string value from session
     */
    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);
        return is_string($value) ? $value : (string)$value;
    }

    /**
     * Set a flash message (retrieved once then cleared)
     */
    public function setFlash(string $key, string $message): void
    {
        $this->set($key, $message);
    }

    /**
     * Get and remove a flash message
     */
    public function getFlash(string $key): ?string
    {
        $message = $this->get($key);
        if ($message !== null) {
            $this->remove($key);
        }
        return is_string($message) ? $message : null;
    }
}
