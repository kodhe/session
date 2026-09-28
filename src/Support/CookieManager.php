<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Support;

/**
 * Cookie Manager for Session
 * 
 * Handles session cookie operations securely
 * 
 * @package Kodhe\Framework\Session\Support
 */
class CookieManager
{
    /**
     * @var string Cookie name
     */
    private string $name;

    /**
     * @var int Cookie lifetime in seconds
     */
    private int $lifetime;

    /**
     * @var string Cookie path
     */
    private string $path;

    /**
     * @var string Cookie domain
     */
    private string $domain;

    /**
     * @var bool Cookie secure flag
     */
    private bool $secure;

    /**
     * @var bool Cookie httponly flag
     */
    private bool $httpOnly;

    /**
     * @var string SameSite attribute
     */
    private string $sameSite;

    /**
     * Constructor
     * 
     * @param array $config Cookie configuration
     */
    public function __construct(array $config = [])
    {
        $this->name = $config['cookie_name'] ?? 'ci_session';
        $this->lifetime = $config['cookie_lifetime'] ?? 7200;
        $this->path = $config['cookie_path'] ?? '/';
        $this->domain = $config['cookie_domain'] ?? '';
        $this->secure = $config['cookie_secure'] ?? false;
        $this->httpOnly = $config['cookie_httponly'] ?? true;
        $this->sameSite = $config['cookie_samesite'] ?? 'Lax';
    }

    /**
     * Set a session cookie
     * 
     * @param string $sessionId Session ID
     * @return bool
     */
    public function set(string $sessionId): bool
    {
        return $this->send($sessionId, time() + $this->lifetime);
    }

    /**
     * Send cookie with specific expiration
     * 
     * @param string $sessionId Session ID
     * @param int $expire Expiration timestamp
     * @return bool
     */
    public function send(string $sessionId, int $expire): bool
    {
        // The array API requires PHP >= 7.3; the package requires PHP >= 8.1,
        // so the legacy positional-argument fallback was removed.
        return setcookie(
            $this->name,
            $sessionId,
            [
                'expires' => $expire,
                'path' => $this->path,
                'domain' => $this->domain,
                'secure' => $this->secure,
                'httponly' => $this->httpOnly,
                'samesite' => $this->sameSite,
            ]
        );
    }

    /**
     * Delete the session cookie
     * 
     * @return bool
     */
    public function delete(): bool
    {
        // The array API requires PHP >= 7.3; the package requires PHP >= 8.1,
        // so the legacy positional-argument fallback was removed.
        return setcookie(
            $this->name,
            '',
            [
                'expires' => 1,
                'path' => $this->path,
                'domain' => $this->domain,
                'secure' => $this->secure,
                'httponly' => $this->httpOnly,
                'samesite' => $this->sameSite,
            ]
        );
    }

    /**
     * Get the current cookie value
     * 
     * @return string|null
     */
    public function get(): ?string
    {
        return $_COOKIE[$this->name] ?? null;
    }

    /**
     * Check if cookie exists
     * 
     * @return bool
     */
    public function exists(): bool
    {
        return isset($_COOKIE[$this->name]);
    }

    /**
     * Get cookie name
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Update cookie configuration
     * 
     * @param array $config New configuration
     * @return self
     */
    public function configure(array $config): self
    {
        if (isset($config['cookie_name'])) {
            $this->name = $config['cookie_name'];
        }
        if (isset($config['cookie_lifetime'])) {
            $this->lifetime = $config['cookie_lifetime'];
        }
        if (isset($config['cookie_path'])) {
            $this->path = $config['cookie_path'];
        }
        if (isset($config['cookie_domain'])) {
            $this->domain = $config['cookie_domain'];
        }
        if (isset($config['cookie_secure'])) {
            $this->secure = $config['cookie_secure'];
        }
        if (isset($config['cookie_httponly'])) {
            $this->httpOnly = $config['cookie_httponly'];
        }
        if (isset($config['cookie_samesite'])) {
            $this->sameSite = $config['cookie_samesite'];
        }

        return $this;
    }
}
