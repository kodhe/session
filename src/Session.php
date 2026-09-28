<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session;

use Kodhe\Framework\Session\Contracts\SessionHandlerInterface;
use Kodhe\Framework\Session\Contracts\SessionInterface;
use Kodhe\Framework\Session\Contracts\StorageInterface;
use Kodhe\Framework\Session\Exceptions\SessionException;
use Kodhe\Framework\Session\Factory\DriverFactory;
use Kodhe\Framework\Session\Flash\FlashDataManager;
use Kodhe\Framework\Session\Storage\SessionStorage;
use Kodhe\Framework\Session\Support\CookieManager;
use Kodhe\Framework\Session\Support\SessionConfig;
use Kodhe\Framework\Session\Support\SessionIdGenerator;
use Kodhe\Framework\Session\ValueObjects\SessionId;

/**
 * CodeIgniter Session Class - Refactored Modular Version
 * 
 * Maintains 100% backward compatibility with CI3 API while using
 * modern design patterns internally.
 * 
 * @package Kodhe\Framework\Session
 */
class Session implements SessionInterface
{
    /**
     * Userdata array - reference to $_SESSION for BC
     */
    public $userdata;

    /**
     * @var SessionConfig Configuration
     */
    protected SessionConfig $config;

    /**
     * @var SessionHandlerInterface|null Session driver (lazy-loaded)
     */
    protected ?SessionHandlerInterface $driver = null;

    /**
     * @var StorageInterface Storage backend
     */
    protected StorageInterface $storage;

    /**
     * @var FlashDataManager Flash and temp data manager
     */
    protected FlashDataManager $flashManager;

    /**
     * @var CookieManager Cookie handler
     */
    protected CookieManager $cookieManager;

    /**
     * @var SessionIdGenerator Session ID generator
     */
    protected SessionIdGenerator $idGenerator;

    /**
     * @var string Session ID regex pattern
     */
    protected string $sidRegexp;

    /**
     * @var bool Whether session is initialized
     */
    protected bool $initialized = false;

    /**
     * @var int Last regeneration timestamp
     */
    protected int $lastRegenerate = 0;

    /**
     * Class constructor
     * 
     * @param array $params Configuration parameters
     */
    public function __construct(array $params = [])
    {
        // No sessions under CLI
        if ($this->isCli()) {
            $this->log('debug', 'Session: Initialization under CLI aborted.');
            return;
        }

        // Check for auto_start
        if ((bool) ini_get('session.auto_start')) {
            $this->log('error', 'Session: session.auto_start is enabled in php.ini. Aborting.');
            return;
        }

        // Normalize CI3-style "sess_*" config items into constructor params
        // so the app's config.php values (e.g. sess_save_path) are honoured
        // instead of silently falling back to php.ini defaults. Explicit
        // keys passed via $params always win over the mapped ones.
        $params = array_merge($this->ciConfigParams(), $params);

        // Determine driver
        $driver = $this->resolveDriver($params);

        // Build configuration. SessionConfig normalizes CI3's
        // "sess_expiration = 0" (cookie until browser close) internally so
        // validation does not abort session startup entirely — a very common
        // cause of "login always fails / session never persists".
        $this->config = new SessionConfig(array_merge($params, ['driver' => $driver]));

        // Configure SID pattern
        $this->sidRegexp = $this->config->getSidPattern();

        // Align PHP's native session settings with our config BEFORE any
        // session_start(). Without this, PHP generates IDs using php.ini's
        // session.sid_length / session.sid_bits_per_character, which do not
        // match our SID regex — every returning ci_session cookie is then
        // discarded on the next request and session data never persists.
        @ini_set('session.sid_length', (string) $this->config->get('sid_length'));
        @ini_set('session.sid_bits_per_character', (string) $this->config->get('sid_bits_per_character'));

        // Initialize components
        $this->idGenerator = new SessionIdGenerator(
            $this->config->get('sid_length'),
            $this->config->get('sid_bits_per_character')
        );

        // Fall back to "/" when no cookie path is configured: an empty path
        // would scope the session cookie to the current script directory
        // only, so it stops being sent on other routes and login appears to
        // "fail" immediately after redirect.
        $cookiePath = $this->config->get('cookie_path');
        if (!is_string($cookiePath) || $cookiePath === '') {
            $cookiePath = '/';
        }

        $this->cookieManager = new CookieManager([
            'cookie_name' => $this->config->get('cookie_name'),
            'cookie_lifetime' => $this->config->get('cookie_lifetime', $this->config->get('expiration')),
            'cookie_path' => $cookiePath,
            'cookie_domain' => $this->config->get('cookie_domain'),
            'cookie_secure' => $this->config->get('cookie_secure'),
            'cookie_httponly' => true,
            'cookie_samesite' => $this->config->get('cookie_samesite', 'Lax'),
        ]);

        // Create driver instance (lazy load until needed)
        $this->prepareDriver($driver);

        // Start session
        $this->startSession();

        // Handle auto-regeneration
        $this->handleAutoRegenerate();

        // Initialize flash data processing
        $this->initVars();

        $this->initialized = true;
        $this->log('info', "Session: Class initialized using '{$driver}' driver.");
    }

    /**
     * Map CI3-style "sess_*" config items to SessionConfig keys.
     *
     * The framework instantiates Session without constructor arguments, so
     * application configuration (config.php) must be picked up from the
     * global config_item() helper. Only non-null values are mapped so that
     * SessionConfig defaults remain for anything not configured.
     *
     * @return array<string, mixed>
     */
    private function ciConfigParams(): array
    {
        $map = [
            'driver'                 => 'sess_driver',
            'cookie_name'            => 'sess_cookie_name',
            'expiration'             => 'sess_expiration',
            'save_path'              => 'sess_save_path',
            'match_ip'               => 'sess_match_ip',
            'time_to_update'         => 'sess_time_to_update',
            'regenerate_destroy'     => 'sess_regenerate_destroy',
            'cookie_path'            => 'sess_cookie_path',
            'cookie_domain'          => 'sess_cookie_domain',
            'cookie_secure'          => 'sess_cookie_secure',
            'cookie_httponly'        => 'sess_cookie_httponly',
            'cookie_samesite'        => 'sess_cookie_samesite',
            'sid_length'             => 'sess_sid_length',
            'sid_bits_per_character' => 'sess_sid_bits_per_character',
        ];

        $params = [];
        foreach ($map as $key => $item) {
            $value = $this->configItem($item);
            if ($value !== null && $value !== '') {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Resolve which driver to use
     */
    private function resolveDriver(array &$params): string
    {
        if (!empty($params['driver'])) {
            $driver = $params['driver'];
            unset($params['driver']);
            return $driver;
        }

        // Try config item
        $driver = $this->configItem('sess_driver');
        if (!empty($driver)) {
            return $driver;
        }

        // BC fallback
        if ($this->configItem('sess_use_database')) {
            $this->log('debug', 'Session: "sess_driver" is empty; using BC fallback to "sess_use_database".');
            return 'database';
        }

        return 'files';
    }

    /**
     * Prepare the session driver
     */
    private function prepareDriver(string $driver): void
    {
        $configArray = $this->config->all();
        $configArray['_sid_regexp'] = $this->sidRegexp;

        try {
            $handler = DriverFactory::create($driver, $configArray);

            // A session that is already active (started elsewhere, e.g. by a
            // middleware or legacy code) MUST NOT have its save handler swapped:
            // PHP raises "Session save handler cannot be changed when a session
            // is active". Close it first so the configured driver takes over,
            // then resume with the new handler in place.
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
                $resumed = true;
            } else {
                $resumed = false;
            }

            // session_set_save_handler(object) requires PHP >= 5.4; the
            // package requires PHP >= 8.1, so the legacy per-callback
            // registration fallback was removed.
            session_set_save_handler($handler, true);

            $this->driver = $handler;

            if ($resumed) {
                // Re-open with the newly configured save handler
                @session_start();
            }
        } catch (\Exception $e) {
            $this->log('error', "Session: Driver '{$driver}' failed to initialize: " . $e->getMessage());
        }
    }

    /**
     * Start the PHP session
     */
    private function startSession(): void
    {
        // Validate cookie
        $cookieName = $this->config->get('cookie_name');
        if (isset($_COOKIE[$cookieName])) {
            $sessionId = $_COOKIE[$cookieName];
            
            if (!is_string($sessionId) || !preg_match('#\A' . $this->sidRegexp . '\z#', $sessionId)) {
                unset($_COOKIE[$cookieName]);
            }
        }

        // Apply security settings
        @ini_set('session.use_trans_sid', '0');
        @ini_set('session.use_strict_mode', '1');
        @ini_set('session.use_cookies', '1');
        @ini_set('session.use_only_cookies', '1');

        // Own the session cookie name explicitly. If a previous native
        // session_start() (e.g. started by middleware before this class was
        // resolved) used a different name, PHP would keep writing under that
        // stale name; re-issuing it here guarantees ci_session is used.
        @session_name($cookieName);

        // Cookie path must never be empty for the session cookie either —
        // an empty value scopes it to the current directory and the browser
        // stops sending it back on other routes (login "fails").
        $cookiePath = $this->config->get('cookie_path');
        if (!is_string($cookiePath) || $cookiePath === '') {
            $cookiePath = '/';
        }

        // CI3 semantics: expiration 0 => browser-session cookie (lifetime 0).
        $lifetime = $this->config->getCookieLifetime();

        @session_start([
            'cookie_lifetime' => $lifetime,
            'cookie_path'     => $cookiePath,
            'cookie_domain'   => (string) $this->config->get('cookie_domain', ''),
            'cookie_secure'   => (bool) $this->config->get('cookie_secure', false),
            'cookie_httponly' => true,
            'cookie_samesite' => (string) $this->config->get('cookie_samesite', 'Lax'),
        ]);

        // NOTE: Do NOT re-send the session cookie via setcookie() here.
        // session_start() already issues the cookie with the correct value,
        // path, domain and flags. Re-issuing it (as previously done) could
        // overwrite the fresh cookie with a wrong/expired one — e.g. when
        // expiration is 0, time()+0 expires the cookie immediately, so the
        // browser never returns the session ID and every request starts a
        // brand-new session (login always fails, session never persists).
    }

    /**
     * Handle automatic session ID regeneration
     */
    private function handleAutoRegenerate(): void
    {
        $regenerateTime = $this->configItem('sess_time_to_update', 300);
        
        if ($regenerateTime <= 0) {
            return;
        }

        // Ignore AJAX requests
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            return;
        }

        $currentTime = time();
        
        if (!isset($_SESSION['__ci_last_regenerate'])) {
            $_SESSION['__ci_last_regenerate'] = $currentTime;
        } elseif ($_SESSION['__ci_last_regenerate'] < ($currentTime - $regenerateTime)) {
            $this->sess_regenerate((bool) $this->configItem('sess_regenerate_destroy', false));
        }
    }

    /**
     * Initialize session variables and process flash data
     */
    private function initVars(): void
    {
        $this->storage = new SessionStorage();
        $this->flashManager = new FlashDataManager();
        
        // Process flash data (convert new->old, remove expired temp)
        $this->flashManager->processFlash();

        // Set userdata reference for BC
        $this->userdata =& $_SESSION;
    }

    /**
     * Get userdata value
     * 
     * @param string|null $key Session data key
     * @return mixed
     */
    public function userdata(?string $key = null)
    {
        if ($key !== null) {
            return $_SESSION[$key] ?? null;
        }

        if (empty($_SESSION)) {
            return [];
        }

        $exclude = array_merge(
            ['__ci_vars'],
            $this->get_flash_keys(),
            $this->get_temp_keys()
        );

        $userdata = [];
        foreach (array_keys($_SESSION) as $key) {
            if (!in_array($key, $exclude, true)) {
                $userdata[$key] = $_SESSION[$key];
            }
        }

        return $userdata;
    }

    /**
     * Set userdata
     * 
     * @param string|array $data Session data key or array
     * @param mixed|null $value Value to store
     * @return void
     */
    public function set_userdata($data, $value = null): void
    {
        if (is_array($data)) {
            foreach ($data as $key => &$val) {
                $_SESSION[$key] = $val;
            }
            return;
        }

        $_SESSION[$data] = $value;
    }

    /**
     * Unset userdata
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unset_userdata($key): void
    {
        if (is_array($key)) {
            foreach ($key as $k) {
                unset($_SESSION[$k]);
            }
            return;
        }

        unset($_SESSION[$key]);
    }

    /**
     * Check if userdata exists
     * 
     * @param string $key Session data key
     * @return bool
     */
    public function has_userdata(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /**
     * Get all userdata
     * 
     * @return array
     */
    public function all_userdata(): array
    {
        return $this->userdata();
    }

    /**
     * Set flashdata
     * 
     * @param string|array $data Session data key or array
     * @param mixed|null $value Value to store
     * @return void
     */
    public function set_flashdata($data, $value = null): void
    {
        $this->set_userdata($data, $value);
        $keys = is_array($data) ? array_keys($data) : $data;
        $this->mark_as_flash($keys);
    }

    /**
     * Get flashdata
     * 
     * @param string|null $key Session data key
     * @return mixed
     */
    public function flashdata(?string $key = null)
    {
        return $this->flashManager->getFlashdata($key);
    }

    /**
     * Keep flashdata for another request
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function keep_flashdata($key): void
    {
        $this->mark_as_flash($key);
    }

    /**
     * Mark data as flash
     * 
     * @param string|array $key Session data key(s)
     * @return bool
     */
    public function mark_as_flash($key): bool
    {
        return $this->flashManager->markAsFlash($key);
    }

    /**
     * Get flash keys
     * 
     * @return array
     */
    public function get_flash_keys(): array
    {
        return $this->flashManager->getFlashKeys();
    }

    /**
     * Unmark flash data
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unmark_flash($key): void
    {
        $this->flashManager->unmarkFlash($key);
    }

    /**
     * Get tempdata
     * 
     * @param string|null $key Session data key
     * @return mixed
     */
    public function tempdata(?string $key = null)
    {
        return $this->flashManager->getTempdata($key);
    }

    /**
     * Set tempdata
     * 
     * @param string|array $data Session data key or array
     * @param mixed|null $value Value to store
     * @param int $ttl Time-to-live in seconds
     * @return void
     */
    public function set_tempdata($data, $value = null, int $ttl = 300): void
    {
        $this->set_userdata($data, $value);
        $keys = is_array($data) ? array_keys($data) : $data;
        $this->mark_as_temp($keys, $ttl);
    }

    /**
     * Unset tempdata
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unset_tempdata($key): void
    {
        $this->unmark_temp($key);
    }

    /**
     * Mark data as temp
     * 
     * @param string|array $key Session data key(s)
     * @param int $ttl Time-to-live in seconds
     * @return bool
     */
    public function mark_as_temp($key, int $ttl = 300): bool
    {
        return $this->flashManager->markAsTemp($key, $ttl);
    }

    /**
     * Get temp keys
     * 
     * @return array
     */
    public function get_temp_keys(): array
    {
        return $this->flashManager->getTempKeys();
    }

    /**
     * Unmark temp data
     * 
     * @param string|array $key Session data key(s)
     * @return void
     */
    public function unmark_temp($key): void
    {
        $this->flashManager->unmarkTemp($key);
    }

    /**
     * Destroy session
     * 
     * @return void
     */
    public function sess_destroy(): void
    {
        session_destroy();
    }

    /**
     * Regenerate session ID
     * 
     * @param bool $destroy Destroy old session data
     * @return void
     */
    public function sess_regenerate(bool $destroy = false): void
    {
        $_SESSION['__ci_last_regenerate'] = time();
        session_regenerate_id($destroy);
    }

    /**
     * Get userdata reference
     * 
     * @return array
     */
    public function &get_userdata(): array
    {
        return $_SESSION;
    }

    /**
     * Get session ID
     * 
     * @return string
     */
    public function session_id(): string
    {
        return session_id();
    }

    /**
     * Magic getter
     * 
     * @param string $key Property name
     * @return mixed
     */
    public function __get($key)
    {
        if (isset($_SESSION[$key])) {
            return $_SESSION[$key];
        }

        if ($key === 'session_id') {
            return session_id();
        }

        return null;
    }

    /**
     * Magic isset
     * 
     * @param string $key Property name
     * @return bool
     */
    public function __isset($key)
    {
        if ($key === 'session_id') {
            return session_status() === PHP_SESSION_ACTIVE;
        }

        return isset($_SESSION[$key]);
    }

    /**
     * Magic setter
     * 
     * @param string $key Property name
     * @param mixed $value Value
     * @return void
     */
    public function __set($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    /**
     * Helper to get config items (CI3 compatibility)
     */
    protected function configItem(string $key, $default = null)
    {
        // Try global config function first
        if (function_exists('config_item')) {
            $value = config_item($key);
            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Check if running in CLI mode
     */
    protected function isCli(): bool
    {
        if (function_exists('is_cli')) {
            return is_cli();
        }
        return PHP_SAPI === 'cli';
    }

    /**
     * Log message (CI3 compatibility)
     */
    protected function log(string $level, string $message): void
    {
        if (function_exists('log_message')) {
            log_message($level, $message);
        }
    }
}
