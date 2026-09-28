<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Storage;

use Kodhe\Framework\Session\Contracts\StorageInterface;

/**
 * Null Storage - No-op implementation for testing or disabled sessions
 * 
 * @package Kodhe\Framework\Session\Storage
 */
class NullStorage implements StorageInterface
{
    /**
     * @var array Internal storage (not persisted)
     */
    private array $storage = [];

    /**
     * Get a value from storage
     * 
     * @param string $key The key to retrieve
     * @param mixed|null $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->storage[$key] ?? $default;
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
        $this->storage[$key] = $value;
    }

    /**
     * Check if a key exists in storage
     * 
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->storage[$key]);
    }

    /**
     * Remove a value from storage
     * 
     * @param string $key The key to remove
     * @return void
     */
    public function remove(string $key): void
    {
        unset($this->storage[$key]);
    }

    /**
     * Get all stored values
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->storage;
    }

    /**
     * Clear all stored values
     * 
     * @return void
     */
    public function clear(): void
    {
        $this->storage = [];
    }
}
