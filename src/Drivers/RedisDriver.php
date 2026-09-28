<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Drivers;

use Kodhe\Framework\Session\Driver;
use Redis;
use RedisException;

/**
 * CodeIgniter Session Redis Driver
 */
class RedisDriver extends Driver
{
    /**
     * Redis instance
     */
    protected ?Redis $redis = null;

    /**
     * Key prefix
     */
    protected string $key_prefix = 'ci_session:';

    /**
     * Lock key
     */
    protected ?string $lock_key = null;

    /**
     * Key exists flag
     */
    protected bool $key_exists = false;

    /**
     * Lock flag
     */
    protected bool $lock = false;

    /**
     * Method names for different Redis versions
     */
    protected string $setTimeout_name;
    protected string $delete_name;
    protected mixed $ping_success;

    /**
     * Constructor
     */
    public function __construct(array &$params)
    {
        parent::__construct($params);

        // Detect method names based on Redis version
        if (version_compare(phpversion('redis'), '5', '>=')) {
            $this->setTimeout_name = 'expire';
            $this->delete_name = 'del';
            $this->ping_success = true;
        } else {
            $this->setTimeout_name = 'setTimeout';
            $this->delete_name = 'delete';
            $this->ping_success = '+PONG';
        }

        if (empty($this->_config['save_path'])) {
            log_message('error', 'Session: No Redis save path configured.');
            return;
        }

        if (preg_match('#(?:tcp://)?([^:?]+)(?::(\d+))?(\?.+)?#', $this->_config['save_path'], $matches)) {
            $matches[3] ??= '';
            
            $this->_config['save_path'] = [
                'host'     => $matches[1],
                'port'     => empty($matches[2]) ? 6379 : (int) $matches[2],
                'password' => preg_match('#auth=([^\s&]+)#', $matches[3], $match) ? $match[1] : null,
                'database' => preg_match('#database=(\d+)#', $matches[3], $match) ? (int) $match[1] : 0,
                'timeout'  => preg_match('#timeout=(\d+\.\d+)#', $matches[3], $match) ? (float) $match[1] : 0.0
            ];

            if (preg_match('#prefix=([^\s&]+)#', $matches[3], $match)) {
                $this->key_prefix = $match[1];
            }
        } else {
            log_message('error', 'Session: Invalid Redis save path format: ' . $this->_config['save_path']);
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
        if (empty($this->_config['save_path'])) {
            return false;
        }

        $redis = new Redis();
        $config = $this->_config['save_path'];

        try {
            if (! $redis->connect($config['host'], $config['port'], $config['timeout'])) {
                throw new RedisException('Unable to connect to Redis');
            }

            if (isset($config['password']) && ! $redis->auth($config['password'])) {
                throw new RedisException('Unable to authenticate to Redis');
            }

            if (isset($config['database']) && ! $redis->select($config['database'])) {
                throw new RedisException('Unable to select Redis database');
            }
        } catch (RedisException $e) {
            log_message('error', 'Session: ' . $e->getMessage());
            return false;
        }

        $this->redis = $redis;
        $this->php5_validate_id();
        return true;
    }

    /**
     * Read session data
     */
    public function read(string $session_id): string|false
    {
        if (isset($this->redis) && $this->getLock($session_id)) {
            $this->_session_id = $session_id;
            $session_data = $this->redis->get($this->key_prefix . $session_id);

            if (is_string($session_data)) {
                $this->key_exists = true;
            } else {
                $session_data = '';
                $this->key_exists = false;
            }

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
        if (! isset($this->redis, $this->lock_key)) {
            return false;
        }

        // Was the ID regenerated?
        if ($session_id !== $this->_session_id) {
            if (! $this->releaseLock() || ! $this->getLock($session_id)) {
                return false;
            }
            $this->key_exists = false;
            $this->_session_id = $session_id;
        }

        $this->redis->{$this->setTimeout_name}($this->lock_key, 300);
        
        if ($this->_fingerprint !== ($fingerprint = md5($session_data)) || $this->key_exists === false) {
            if ($this->redis->set($this->key_prefix . $session_id, $session_data, $this->_config['expiration'])) {
                $this->_fingerprint = $fingerprint;
                $this->key_exists = true;
                return true;
            }
            return false;
        }

        return $this->redis->{$this->setTimeout_name}(
            $this->key_prefix . $session_id, 
            (int) $this->_config['expiration']
        );
    }

    /**
     * Close session handler
     */
    public function close(): bool
    {
        if (isset($this->redis)) {
            try {
                if ($this->redis->ping() === $this->ping_success) {
                    $this->releaseLock();
                    $this->redis->close();
                }
            } catch (RedisException $e) {
                log_message('error', 'Session: Got RedisException on close(): ' . $e->getMessage());
            }

            $this->redis = null;
        }

        return true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $session_id): bool
    {
        if (isset($this->redis, $this->lock_key)) {
            $result = $this->redis->{$this->delete_name}($this->key_prefix . $session_id);
            
            if ($result !== 1) {
                log_message('debug', 'Session: Redis::' . $this->delete_name . '() expected to return 1, got ' . var_export($result, true));
            }

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
        return true; // Redis takes care of expiration
    }

    /**
     * Validate session ID
     */
    public function validateSessionId(string $id): bool
    {
        return (bool) $this->redis->exists($this->key_prefix . $id);
    }

    /**
     * Get lock
     */
    protected function getLock(string $session_id): bool
    {
        // Check if lock key is for the correct session ID
        if ($this->lock_key === $this->key_prefix . $session_id . ':lock') {
            return $this->redis->{$this->setTimeout_name}($this->lock_key, 300);
        }

        $lock_key = $this->key_prefix . $session_id . ':lock';
        $attempt = 0;

        do {
            $ttl = $this->redis->ttl($lock_key);
            
            if ($ttl > 0) {
                sleep(1);
                continue;
            }

            if ($ttl === -2 && ! $this->redis->set($lock_key, time(), ['nx', 'ex' => 300])) {
                sleep(1);
                continue;
            }

            if (! $this->redis->setex($lock_key, 300, time())) {
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

        if ($ttl === -1) {
            log_message('debug', 'Session: Lock for ' . $this->key_prefix . $session_id . ' had no TTL.');
        }

        $this->lock = true;
        return true;
    }

    /**
     * Release lock
     */
    protected function releaseLock(): bool
    {
        if (isset($this->redis, $this->lock_key) && $this->lock) {
            if (! $this->redis->{$this->delete_name}($this->lock_key)) {
                log_message('error', 'Session: Error while trying to free lock for ' . $this->lock_key);
                return false;
            }

            $this->lock_key = null;
            $this->lock = false;
        }

        return true;
    }
}
