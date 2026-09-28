<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Contracts;

/**
 * Session Interface - Main session management contract
 * 
 * @package Kodhe\Framework\Session\Contracts
 */
interface SessionInterface
{
    /**
     * Get userdata value
     * 
     * @param string|null $key Session data key
     * @return mixed Session data value or array of all userdata
     */
    public function userdata(?string $key = null);

    /**
     * Set userdata
     * 
     * @param string|array $data Session data key or array of key-value pairs
     * @param mixed|null $value Value to store (if $data is string)
     * @return void
     */
    public function set_userdata($data, $value = null): void;

    /**
     * Unset userdata
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unset_userdata($key): void;

    /**
     * Check if userdata exists
     * 
     * @param string $key Session data key
     * @return bool
     */
    public function has_userdata(string $key): bool;

    /**
     * Get all userdata
     * 
     * @return array All session data excluding flash and temp data
     */
    public function all_userdata(): array;

    /**
     * Set flashdata
     * 
     * @param string|array $data Session data key or array of key-value pairs
     * @param mixed|null $value Value to store (if $data is string)
     * @return void
     */
    public function set_flashdata($data, $value = null): void;

    /**
     * Get flashdata
     * 
     * @param string|null $key Session data key
     * @return mixed Flash data value or array of all flashdata
     */
    public function flashdata(?string $key = null);

    /**
     * Keep flashdata for another request
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function keep_flashdata($key): void;

    /**
     * Mark data as flash
     * 
     * @param string|array $key Session data key(s)
     * @return bool
     */
    public function mark_as_flash($key): bool;

    /**
     * Get flash keys
     * 
     * @return array
     */
    public function get_flash_keys(): array;

    /**
     * Unmark flash data
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unmark_flash($key): void;

    /**
     * Get tempdata
     * 
     * @param string|null $key Session data key
     * @return mixed Temp data value or array of all tempdata
     */
    public function tempdata(?string $key = null);

    /**
     * Set tempdata
     * 
     * @param string|array $data Session data key or array of key-value pairs
     * @param mixed|null $value Value to store (if $data is string)
     * @param int $ttl Time-to-live in seconds
     * @return void
     */
    public function set_tempdata($data, $value = null, int $ttl = 300): void;

    /**
     * Unset tempdata
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unset_tempdata($key): void;

    /**
     * Mark data as temp
     * 
     * @param string|array $key Session data key(s)
     * @param int $ttl Time-to-live in seconds
     * @return bool
     */
    public function mark_as_temp($key, int $ttl = 300): bool;

    /**
     * Get temp keys
     * 
     * @return array
     */
    public function get_temp_keys(): array;

    /**
     * Unmark temp data
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unmark_temp($key): void;

    /**
     * Destroy session
     * 
     * @return void
     */
    public function sess_destroy(): void;

    /**
     * Regenerate session ID
     * 
     * @param bool $destroy Destroy old session data flag
     * @return void
     */
    public function sess_regenerate(bool $destroy = false): void;

    /**
     * Get userdata reference
     * 
     * @return array
     */
    public function &get_userdata(): array;

    /**
     * Get session ID
     * 
     * @return string
     */
    public function session_id(): string;
}
