<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Flash;

/**
 * Flash Data Manager
 * 
 * Manages flashdata and tempdata lifecycle
 * 
 * @package Kodhe\Framework\Session\Flash
 */
class FlashDataManager
{
    /**
     * @var array Reference to $_SESSION['__ci_vars']
     */
    private $flashVars;

    /**
     * @var array Reference to $_SESSION
     */
    private $session;

    /**
     * Constructor
     * 
     * @param array|null $sessionData Session data reference (defaults to $_SESSION)
     */
    public function __construct(?array &$sessionData = null)
    {
        if ($sessionData === null) {
            $this->session =& $_SESSION;
        } else {
            $this->session =& $sessionData;
        }
        
        if (!isset($this->session['__ci_vars'])) {
            $this->session['__ci_vars'] = [];
        }
        
        $this->flashVars =& $this->session['__ci_vars'];
    }

    /**
     * Mark keys as flash data
     * 
     * @param string|array $keys Keys to mark as flash
     * @return bool
     */
    public function markAsFlash($keys): bool
    {
        if (is_array($keys)) {
            foreach ($keys as $key) {
                if (!isset($this->session[$key])) {
                    return false;
                }
            }
            
            $new = array_fill_keys($keys, 'new');
            $this->flashVars = empty($this->flashVars) 
                ? $new 
                : array_merge($this->flashVars, $new);
            
            return true;
        }

        if (!isset($this->session[$keys])) {
            return false;
        }

        $this->flashVars[$keys] = 'new';
        return true;
    }

    /**
     * Get all flash keys
     * 
     * @return array
     */
    public function getFlashKeys(): array
    {
        if (empty($this->flashVars)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($this->flashVars) as $key) {
            if (!is_int($this->flashVars[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get flash data value
     * 
     * @param string|null $key Key to retrieve
     * @return mixed
     */
    public function getFlashdata(?string $key = null)
    {
        if ($key !== null) {
            if (isset($this->flashVars[$key], $this->session[$key]) && !is_int($this->flashVars[$key])) {
                return $this->session[$key];
            }
            return null;
        }

        $flashdata = [];
        if (!empty($this->flashVars)) {
            foreach ($this->flashVars as $key => &$value) {
                if (!is_int($value)) {
                    $flashdata[$key] = $this->session[$key];
                }
            }
        }

        return $flashdata;
    }

    /**
     * Keep flash data for another request
     * 
     * @param string|array $keys Keys to keep
     * @return void
     */
    public function keepFlashdata($keys): void
    {
        $this->markAsFlash($keys);
    }

    /**
     * Unmark flash data
     * 
     * @param string|array $keys Keys to unmark
     * @return void
     */
    public function unmarkFlash($keys): void
    {
        if (empty($this->flashVars)) {
            return;
        }

        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            if (isset($this->flashVars[$key]) && !is_int($this->flashVars[$key])) {
                unset($this->flashVars[$key]);
            }
        }

        if (empty($this->flashVars)) {
            unset($this->session['__ci_vars']);
            $this->flashVars =& $this->session['__ci_vars'];
        }
    }

    /**
     * Mark keys as temp data
     * 
     * @param string|array $keys Keys to mark as temp
     * @param int $ttl Time-to-live in seconds
     * @return bool
     */
    public function markAsTemp($keys, int $ttl = 300): bool
    {
        $expiration = time() + $ttl;

        if (is_array($keys)) {
            $temp = [];
            foreach ($keys as $k => $v) {
                if (is_int($k)) {
                    $k = $v;
                    $v = $expiration;
                } else {
                    $v += time();
                }

                if (!isset($this->session[$k])) {
                    return false;
                }

                $temp[$k] = $v;
            }

            $this->flashVars = empty($this->flashVars)
                ? $temp
                : array_merge($this->flashVars, $temp);

            return true;
        }

        if (!isset($this->session[$keys])) {
            return false;
        }

        $this->flashVars[$keys] = $expiration;
        return true;
    }

    /**
     * Get all temp keys
     * 
     * @return array
     */
    public function getTempKeys(): array
    {
        if (empty($this->flashVars)) {
            return [];
        }

        $keys = [];
        foreach (array_keys($this->flashVars) as $key) {
            if (is_int($this->flashVars[$key])) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Get temp data value
     * 
     * @param string|null $key Key to retrieve
     * @return mixed
     */
    public function getTempdata(?string $key = null)
    {
        if ($key !== null) {
            if (isset($this->flashVars[$key], $this->session[$key]) && is_int($this->flashVars[$key])) {
                return $this->session[$key];
            }
            return null;
        }

        $tempdata = [];
        if (!empty($this->flashVars)) {
            foreach ($this->flashVars as $key => &$value) {
                if (is_int($value)) {
                    $tempdata[$key] = $this->session[$key];
                }
            }
        }

        return $tempdata;
    }

    /**
     * Unmark temp data
     * 
     * @param string|array $keys Keys to unmark
     * @return void
     */
    public function unmarkTemp($keys): void
    {
        if (empty($this->flashVars)) {
            return;
        }

        $keys = is_array($keys) ? $keys : [$keys];

        foreach ($keys as $key) {
            if (isset($this->flashVars[$key]) && is_int($this->flashVars[$key])) {
                unset($this->flashVars[$key]);
            }
        }

        if (empty($this->flashVars)) {
            unset($this->session['__ci_vars']);
            $this->flashVars =& $this->session['__ci_vars'];
        }
    }

    /**
     * Process flash data - convert new to old, remove expired temp
     * 
     * @return void
     */
    public function processFlash(): void
    {
        if (empty($this->flashVars)) {
            return;
        }

        $currentTime = time();

        foreach ($this->flashVars as $key => &$value) {
            if ($value === 'new') {
                $this->flashVars[$key] = 'old';
            } elseif (is_int($value) && $value < $currentTime) {
                // Expired temp data
                unset($this->session[$key], $this->flashVars[$key]);
            }
        }

        if (empty($this->flashVars)) {
            unset($this->session['__ci_vars']);
            $this->flashVars =& $this->session['__ci_vars'];
        }
    }

    /**
     * Clear all flash and temp data markers
     * 
     * @return void
     */
    public function clear(): void
    {
        unset($this->session['__ci_vars']);
        $this->flashVars =& $this->session['__ci_vars'];
    }
}
