<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Drivers;

use Kodhe\Framework\Session\Driver;
use Memcached;

/**
 * CodeIgniter Session Memcached Driver
 */
class MemcachedDriver extends Driver
{
    /**
     * Memcached instance
     */
    protected ?Memcached $memcached = null;

    /**
     * Key prefix
     */
    protected string $key_prefix = 'ci_session:';

    /**
     * Lock key
     */
    protected ?string $lock_key = null;

    /**
     * Lock flag
     */
    protected bool $lock = false;

    /**
     * Constructor
     */
    public function __construct(array &$params)
    {
        parent::__construct($params);

        if (empty($this->_config['save_path'])) {
            log_message('error', 'Session: No Memcached save path configured.');
        }

        if ($this->_config['match_ip'] === true) {
            $this->key_prefix .= ($_SERVER['REMOTE_ADDR'] ?? '') . ':';
        }
    }

    /**
     * Open session handler
     */
    public function open(string $save_path, string $name): bool
    {
        $this->memcached = new Memcached();
        $this->memcached->setOption(Memcached::OPT_BINARY_PROTOCOL, true);

        if (! preg_match_all('#,?([^,:]+):(\d{1,5})(?::(\d+))?#', $this->_config['save_path'], $matches, PREG_SET_ORDER)) {
            $this->memcached = null;
            log_message('error', 'Session: Invalid Memcached save path format: ' . $this->_config['save_path']);
            return false;
        }

        $server_list = [];
        foreach ($this->memcached->getServerList() as $server) {
            $server_list[] = $server['host'] . ':' . $server['port'];
        }

        foreach ($matches as $match) {
            $server = $match[1] . ':' . $match[2];
            
            if (in_array($server, $server_list, true)) {
                log_message('debug', 'Session: Memcached server pool already has ' . $server);
                continue;
            }

            $weight = $match[3] ?? 0;
            if (! $this->memcached->addServer($match[1], (int) $match[2], (int) $weight)) {
                log_message('error', 'Could not add ' . $server . ' to Memcached server pool.');
            } else {
                $server_list[] = $server;
            }
        }

        if (empty($server_list)) {
            log_message('error', 'Session: Memcached server pool is empty.');
            return false;
        }

        $this->php5_validate_id();
        return true;
    }

    /**
     * Read session data
     */
    public function read(string $session_id): string|false
    {
        if (isset($this->memcached) && $this->getLock($session_id)) {
            $this->_session_id = $session_id;
            $session_data = (string) $this->memcached->get($this->key_prefix . $session_id);
            $this->_fingerprint = md5($session_data);
            return $session_data;
        }

        return false;
    }

    /**
     * Write session data
     */
    public function write(string $session_id, string $session_data): bool
    {
        if (! isset($this->memcached, $this->lock_key)) {
            return false;
        }

        // Was the ID regenerated?
        if ($session_id !== $this->_session_id) {
            if (! $this->releaseLock() || ! $this->getLock($session_id)) {
                return false;
            }
            $this->_fingerprint = md5('');
            $this->_session_id = $session_id;
        }

        $key = $this->key_prefix . $session_id;
        $this->memcached->replace($this->lock_key, time(), 300);
        
        if ($this->_fingerprint !== ($fingerprint = md5($session_data))) {
            if ($this->memcached->set($key, $session_data, (int) $this->_config['expiration'])) {
                $this->_fingerprint = $fingerprint;
                return true;
            }
            return false;
        }

        if ($this->memcached->touch($key, (int) $this->_config['expiration']) ||
            ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND && 
             $this->memcached->set($key, $session_data, (int) $this->_config['expiration']))) {
            return true;
        }

        return false;
    }

    /**
     * Close session handler
     */
    public function close(): bool
    {
        if (isset($this->memcached)) {
            $this->releaseLock();
            
            if (! $this->memcached->quit()) {
                return false;
            }
            
            $this->memcached = null;
            return true;
        }

        return false;
    }

    /**
     * Destroy session
     */
    public function destroy(string $session_id): bool
    {
        if (isset($this->memcached, $this->lock_key)) {
            $this->memcached->delete($this->key_prefix . $session_id);
            $this->_cookie_destroy();
            return true;
        }

        return false;
    }

    /**
     * Garbage collection
     */
    public function gc(int $maxlifetime): int|false
    {
        return true; // Memcached takes care of expiration
    }

    /**
     * Validate session ID
     */
    public function validateSessionId(string $id): bool
    {
        $this->memcached->get($this->key_prefix . $id);
        return $this->memcached->getResultCode() === Memcached::RES_SUCCESS;
    }

    /**
     * Get lock
     */
    protected function getLock(string $session_id): bool
    {
        // Check if lock key is for the correct session ID
        if ($this->lock_key === $this->key_prefix . $session_id . ':lock') {
            return $this->memcached->replace($this->lock_key, time(), 300) ||
                   ($this->memcached->getResultCode() === Memcached::RES_NOTFOUND && 
                    $this->memcached->add($this->lock_key, time(), 300));
        }

        $lock_key = $this->key_prefix . $session_id . ':lock';
        $attempt = 0;
        
        do {
            if ($this->memcached->get($lock_key)) {
                sleep(1);
                continue;
            }

            $method = $this->memcached->getResultCode() === Memcached::RES_NOTFOUND ? 'add' : 'set';
            if (! $this->memcached->{$method}($lock_key, time(), 300)) {
                log_message('error', 'Session: Error while trying to obtain lock for ' . $this->key_prefix . $session_id);
                return false;
            }

            $this->lock_key = $lock_key;
            break;
        } while (++$attempt < 30);

        if ($attempt === 30) {
            log_message('error', 'Session: Unable to obtain lock for ' . $this->key_prefix . $session_id . ' after 30 attempts.');
            return false;
        }

        $this->lock = true;
        return true;
    }

    /**
     * Release lock
     */
    protected function releaseLock(): bool
    {
        if (isset($this->memcached, $this->lock_key) && $this->lock) {
            if (! $this->memcached->delete($this->lock_key) && 
                $this->memcached->getResultCode() !== Memcached::RES_NOTFOUND) {
                log_message('error', 'Session: Error while trying to free lock for ' . $this->lock_key);
                return false;
            }

            $this->lock_key = null;
            $this->lock = false;
        }

        return true;
    }
}
