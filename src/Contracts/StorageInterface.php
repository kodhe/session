<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Contracts;

/**
 * Storage Interface - Contract for session data storage operations
 * 
 * @package Kodhe\Framework\Session\Contracts
 */
interface StorageInterface
{
    /**
     * Get a value from storage
     * 
     * @param string $key The key to retrieve
     * @param mixed|null $default Default value if key doesn't exist
     * @return mixed
     */
    public function get(string $key, $default = null);

    /**
     * Set a value in storage
     * 
     * @param string $key The key to store
     * @param mixed $value The value to store
     * @return void
     */
    public function set(string $key, $value): void;

    /**
     * Check if a key exists in storage
     * 
     * @param string $key The key to check
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Remove a value from storage
     * 
     * @param string $key The key to remove
     * @return void
     */
    public function remove(string $key): void;

    /**
     * Get all stored values
     * 
     * @return array
     */
    public function all(): array;

    /**
     * Clear all stored values
     * 
     * @return void
     */
    public function clear(): void;
}
