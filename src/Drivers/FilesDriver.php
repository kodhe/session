<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Drivers;

use Kodhe\Framework\Session\Driver;

/**
 * CodeIgniter Session Files Driver
 *
 * @package	CodeIgniter
 * @subpackage	Libraries
 * @category	Sessions
 * @author	Andrey Andreev
 * @link	https://codeigniter.com/user_guide/libraries/sessions.html
 */
class FilesDriver extends Driver
{
    /**
     * Save path
     */
    protected string $_save_path;

    /**
     * File handle
     */
    protected $file_handle;

    /**
     * File name
     */
    protected ?string $file_path = null;

    /**
     * File new flag
     */
    protected bool $file_new = false;

    /**
     * Validate SID regular expression
     */
    protected string $_sid_regexp;

    /**
     * mbstring.func_overload flag
     */
    protected static ?bool $func_overload = null;

    /**
     * Session ID for current file handle
     */
    protected ?string $session_id = null;

    /**
     * Constructor
     */
    public function __construct(array &$params)
    {
        parent::__construct($params);

        if (isset($this->_config['save_path'])) {
            $this->_config['save_path'] = rtrim($this->_config['save_path'], '/\\');
            @ini_set('session.save_path', $this->_config['save_path']);
        } else {
            log_message('debug', 'Session: "sess_save_path" is empty; using "session.save_path" value from php.ini.');
            $this->_config['save_path'] = rtrim(ini_get('session.save_path'), '/\\');
        }

        $this->_sid_regexp = $this->_config['_sid_regexp'];

        if (self::$func_overload === null) {
            self::$func_overload = extension_loaded('mbstring') && ini_get('mbstring.func_overload');
        }
    }

    /**
     * Open session handler
     */
    public function open(string $save_path, string $name): bool
    {
        // The files driver MUST store sessions in its own configured directory.
        // PHP passes the *effective* ini save_path to open(); when it differs
        // from our configured one (e.g. an empty sess_save_path fell back to a
        // php.ini value that changed afterwards), align the ini setting so
        // read()/write() and gc() all operate on the same directory. Without
        // this, data written under one path is never found under the other and
        // sessions silently fail to persist.
        if ($this->_config['save_path'] !== '' && rtrim($this->_config['save_path'], '/\\') !== rtrim($save_path, '/\\')) {
            $save_path = rtrim($this->_config['save_path'], '/\\');
            @ini_set('session.save_path', $save_path);
        }

        if (!is_dir($save_path)) {
            if (!@mkdir($save_path, 0700, true)) {
                log_message('error', "Session: Configured save path '" . $save_path . "' cannot be created.");
                return false;
            }
        } elseif (!is_writable($save_path)) {
            log_message('error', "Session: Configured save path '" . $save_path . "' is not writable.");
            return false;
        }

        $this->_config['save_path'] = $save_path;
        $ip_suffix = $this->_config['match_ip'] ? md5($_SERVER['REMOTE_ADDR'] ?? '') : '';
        $this->file_path = $this->_config['save_path'] . DIRECTORY_SEPARATOR . $name . $ip_suffix;

        $this->php5_validate_id();

        return true;
    }

    /**
     * Read session data
     */
    public function read(string $session_id): string|false
    {
        if ($this->file_handle === null) {
            $filename = $this->file_path . $session_id;
            $this->file_new = !file_exists($filename);

            $this->file_handle = @fopen($filename, 'c+b');
            if ($this->file_handle === false) {
                log_message('error', "Session: Unable to open file '" . $filename . "'.");
                return false;
            }

            if (flock($this->file_handle, LOCK_EX) === false) {
                log_message('error', "Session: Unable to lock file '" . $filename . "'.");
                fclose($this->file_handle);
                $this->file_handle = null;
                return false;
            }

            $this->session_id = $session_id;

            if ($this->file_new) {
                chmod($filename, 0600);
                $this->_fingerprint = md5('');
                return '';
            }
        } elseif ($this->file_handle === false) {
            return false;
        } else {
            rewind($this->file_handle);
        }

        $filename = $this->file_path . $session_id;
        $file_size = filesize($filename);
        
        if ($file_size === false) {
            return false;
        }

        $session_data = '';
        for ($read = 0; $read < $file_size; $read += strlen($buffer)) {
            $bytes_to_read = min(4096, $file_size - $read);
            $buffer = fread($this->file_handle, $bytes_to_read);
            
            if ($buffer === false) {
                break;
            }
            
            $session_data .= $buffer;
        }

        $this->_fingerprint = md5($session_data);
        return $session_data;
    }

    /**
     * Write session data
     */
    public function write(string $session_id, string $session_data): bool
    {
        if ($session_id !== $this->session_id) {
            if ($this->close() === false || $this->read($session_id) === false) {
                return false;
            }
        }

        if (!is_resource($this->file_handle)) {
            return false;
        }

        if ($this->_fingerprint === md5($session_data)) {
            return !$this->file_new && !touch($this->file_path . $session_id) ? false : true;
        }

        if (!$this->file_new) {
            ftruncate($this->file_handle, 0);
            rewind($this->file_handle);
        }

        $length = strlen($session_data);
        $written = 0;

        while ($written < $length) {
            $result = fwrite($this->file_handle, substr($session_data, $written));
            
            if ($result === false) {
                break;
            }
            
            $written += $result;
        }

        if ($written !== $length) {
            $this->_fingerprint = md5(substr($session_data, 0, $written));
            log_message('error', 'Session: Unable to write complete session data.');
            return false;
        }

        $this->_fingerprint = md5($session_data);
        return true;
    }

    /**
     * Close session handler
     */
    public function close(): bool
    {
        if (is_resource($this->file_handle)) {
            flock($this->file_handle, LOCK_UN);
            fclose($this->file_handle);
            
            $this->file_handle = null;
            $this->file_new = false;
            $this->session_id = null;
        }

        return true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $session_id): bool
    {
        if ($this->close() === true) {
            $filename = $this->file_path . $session_id;
            
            if (file_exists($filename)) {
                $this->_cookie_destroy();
                return unlink($filename);
            }
            
            return true;
        }

        if ($this->file_path !== null) {
            $filename = $this->file_path . $session_id;
            
            if (file_exists($filename)) {
                $this->_cookie_destroy();
                return unlink($filename);
            }
            
            return true;
        }

        return false;
    }

    /**
     * Garbage collection
     */
    public function gc(int $maxlifetime): int|false
    {
        $save_path = $this->_config['save_path'];
        
        if (!is_dir($save_path)) {
            log_message('debug', "Session: Garbage collector - path '" . $save_path . "' is not a directory.");
            return false;
        }

        $directory = opendir($save_path);
        if ($directory === false) {
            log_message('debug', "Session: Garbage collector couldn't open directory '" . $save_path . "'.");
            return false;
        }

        $ts = time() - $maxlifetime;
        $pattern = $this->_config['match_ip'] ? '[0-9a-f]{32}' : '';
        $pattern = '#\A' . preg_quote($this->_config['cookie_name'], '#') . $pattern . $this->_sid_regexp . '\z#';

        $deleted = 0;
        while (($file = readdir($directory)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (!preg_match($pattern, $file)) {
                continue;
            }

            $filepath = $save_path . DIRECTORY_SEPARATOR . $file;
            
            if (!is_file($filepath)) {
                continue;
            }

            $mtime = filemtime($filepath);
            if ($mtime === false || $mtime > $ts) {
                continue;
            }

            if (unlink($filepath)) {
                $deleted++;
            }
        }

        closedir($directory);
        return $deleted;
    }

    /**
     * Validate session ID
     */
    public function validateSessionId(string $id): bool
    {
        $result = is_file($this->file_path . $id);
        clearstatcache(true, $this->file_path . $id);
        return $result;
    }

    /**
     * Byte-safe strlen()
     */
    protected static function strlen(string $str): int
    {
        return self::$func_overload ? mb_strlen($str, '8bit') : strlen($str);
    }
}
