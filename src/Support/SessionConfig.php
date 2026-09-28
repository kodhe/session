<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Support;

/**
 * Session Configuration Handler
 * 
 * Handles session configuration with defaults and validation
 * 
 * @package Kodhe\Framework\Session\Support
 */
class SessionConfig
{
    /**
     * @var array Configuration values
     */
    private array $config;

    /**
     * Default configuration values
     */
    private const DEFAULTS = [
        'driver' => 'files',
        'cookie_name' => 'ci_session',
        'cookie_path' => '/',
        'cookie_domain' => '',
        'cookie_secure' => false,
        'cookie_httponly' => true,
        'expiration' => 7200,
        'match_ip' => false,
        'save_path' => null,
        'time_to_update' => 300,
        'regenerate_destroy' => false,
        'sid_length' => 40,
        'sid_bits_per_character' => 5,
    ];

    /**
     * Constructor
     * 
     * @param array $config Configuration array
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::DEFAULTS, $config);

        // CI3 semantics: "sess_expiration = 0" means "until browser close".
        // Keep the raw value available and use a positive placeholder for
        // validation so the session can still be started.
        if (isset($this->config['expiration']) && (int) $this->config['expiration'] === 0) {
            $this->originalExpiration = 0;
            $this->config['expiration'] = 60 * 60 * 24 * 365;
        }

        $this->validate();
    }

    /**
     * @var int|null Raw expiration before normalization (0 = until close)
     */
    private ?int $originalExpiration = null;

    /**
     * Get the effective cookie lifetime in seconds.
     *
     * Returns 0 when expiration was configured as 0 (browser-session
     * cookie), otherwise the normalized positive lifetime.
     *
     * @return int
     */
    public function getCookieLifetime(): int
    {
        if ($this->originalExpiration === 0) {
            return 0;
        }

        return (int) ($this->config['cookie_lifetime'] ?? $this->config['expiration']);
    }

    /**
     * Validate configuration
     * 
     * @throws \InvalidArgumentException
     */
    private function validate(): void
    {
        if ($this->config['expiration'] <= 0) {
            throw new \InvalidArgumentException('Session expiration must be positive');
        }

        if (empty($this->config['cookie_name'])) {
            throw new \InvalidArgumentException('Cookie name cannot be empty');
        }

        if ($this->config['sid_length'] < 16) {
            throw new \InvalidArgumentException('Session ID length must be at least 16');
        }

        if (!in_array($this->config['sid_bits_per_character'], [4, 5, 6], true)) {
            throw new \InvalidArgumentException('sid_bits_per_character must be 4, 5, or 6');
        }
    }

    /**
     * Get a configuration value
     * 
     * @param string $key Configuration key
     * @param mixed|null $default Default value if not found
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Set a configuration value
     * 
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @return self
     */
    public function set(string $key, $value): self
    {
        $this->config[$key] = $value;
        return $this;
    }

    /**
     * Check if a configuration key exists
     * 
     * @param string $key Configuration key
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->config[$key]);
    }

    /**
     * Get all configuration values
     * 
     * @return array
     */
    public function all(): array
    {
        return $this->config;
    }

    /**
     * Get cookie configuration for setcookie()
     * 
     * @return array
     */
    public function getCookieOptions(): array
    {
        return [
            'lifetime' => $this->config['cookie_lifetime'] ?? $this->config['expiration'],
            'path' => $this->config['cookie_path'],
            'domain' => $this->config['cookie_domain'],
            'secure' => $this->config['cookie_secure'],
            'httponly' => $this->config['cookie_httponly'],
            'samesite' => $this->config['cookie_samesite'] ?? 'Lax',
        ];
    }

    /**
     * Get session ID validation pattern based on bits per character
     * 
     * @return string
     */
    public function getSidPattern(): string
    {
        switch ($this->config['sid_bits_per_character']) {
            case 4:
                $chars = '[0-9a-f]';
                break;
            case 5:
                $chars = '[0-9a-v]';
                break;
            case 6:
                $chars = '[0-9a-zA-Z,-]';
                break;
            default:
                $chars = '[0-9a-zA-Z,-]';
        }

        return $chars . '{' . $this->config['sid_length'] . '}';
    }
}
