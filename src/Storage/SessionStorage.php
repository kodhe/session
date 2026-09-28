<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Storage;

use Kodhe\Framework\Session\Contracts\StorageInterface;

/**
 * Session Storage - Default implementation using $_SESSION
 * 
 * @package Kodhe\Framework\Session\Storage
 */
class SessionStorage implements StorageInterface
{
    /**
     * @var array Reference to $_SESSION
     */
    protected $session;

    /**
     * Constructor
     * 
     * @param array|null $sessionData Session data reference (defaults to $_SESSION)
     */
    public function __construct(?array &$sessionData = null)
    {
        if ($sessionData === null) {
            if (!isset($_SESSION)) {
                $_SESSION = [];
            }
            $this->session =& $_SESSION;
        } else {
            $this->session =& $sessionData;
        }
    }

    /**
     * Get a value from storage
     * 
     * @param string $key The key to retrieve
     * @param mixed|null $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->session[$key] ?? $default;
    }

    /**
     * Set a value in storage
     * 
     * @param string $key The key to store
     * @param mixed $value The value to store
     * @return void
     */
    public function set(string $key, $value): void
    {
        $this->session[$key] = $value;
    }

    /**
     * Check if a key exists in storage
     * 
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->session[$key]);
    }

    /**
     * Remove a value from storage
     * 
     * @param string $key The key to remove
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->session[$key]);
    }

    /**
     * Get all stored values
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->session;
    }

    /**
     * Clear all stored values
     * 
     * @return void
     */
    public function clear(): void
    {
        $this->session = [];
    }

    /**
     * Get the session reference
     * 
     * @return array
     */
    public function &getSession(): array
    {
        return $this->session;
    }

    /**
     * Set the entire session array
     * 
     * @param array $data Session data
     * @return void
     */
    public function setSession(array $data): void
    {
        $this->session = $data;
    }
}
