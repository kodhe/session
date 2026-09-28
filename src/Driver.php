<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session;

use Kodhe\Framework\Session\Contracts\SessionHandlerInterface;

/**
 * CodeIgniter Session Driver Class
 *
 * @package	CodeIgniter
 * @subpackage	Libraries
 * @category	Sessions
 * @author	Andrey Andreev
 * @link	https://codeigniter.com/user_guide/libraries/sessions.html
 */
abstract class Driver implements SessionHandlerInterface {

	protected $_config;

	/**
	 * Data fingerprint
	 *
	 * @var	bool
	 */
	protected $_fingerprint;

	/**
	 * Lock placeholder
	 *
	 * @var	mixed
	 */
	protected $_lock = FALSE;

	/**
	 * Read session ID
	 *
	 * Used to detect session_regenerate_id() calls because PHP only calls
	 * write() after regenerating the ID.
	 *
	 * @var	string
	 */
	protected $_session_id;

	/**
	 * Success and failure return values
	 *
	 * Necessary due to a bug in all PHP 5 versions where return values
	 * from userspace handlers are not handled properly. PHP 7 fixes the
	 * bug, so we need to return different values depending on the version.
	 *
	 * @see	https://wiki.php.net/rfc/session.user.return-value
	 * @var	mixed
	 */
	protected $_success, $_failure;

	// ------------------------------------------------------------------------

	/**
	 * Class constructor
	 *
	 * @param	array	$params	Configuration parameters
	 * @return	void
	 */
	public function __construct(&$params)
	{
		$this->_config =& $params;

		// PHP >= 8.1 is required by this package, so the legacy PHP 5
		// return-value workaround (_success = 0 / _failure = -1) was removed:
		// userspace session handlers must return booleans.
		$this->_success = TRUE;
		$this->_failure = FALSE;
	}

	// ------------------------------------------------------------------------

	/**
	 * Validate a cookie-supplied session ID
	 *
	 * Enforces session.use_strict_mode by checking the ID against the configured
	 * SID pattern. The previous implementation called validateSessionId(),
	 * which for the files driver builds the filename from $this->file_path -
	 * still NULL at open() time - so every existing session failed validation
	 * and login state was wiped on each request.
	 *
	 * @return	void
	 */
	public function php5_validate_id()
	{
		$cookie_name = $this->_config['cookie_name'];

		if ( ! isset($_COOKIE[$cookie_name]))
		{
			return;
		}

		$sid = $_COOKIE[$cookie_name];
		$regexp = isset($this->_config['_sid_regexp']) ? $this->_config['_sid_regexp'] : NULL;

		if ( ! is_string($sid) OR $regexp === NULL OR ! preg_match('#\A'.$regexp.'\z#', $sid))
		{
			unset($_COOKIE[$cookie_name]);
		}
	}

	// ------------------------------------------------------------------------

	/**
	 * Cookie destroy
	 *
	 * Internal method to force removal of a cookie by the client
	 * when session_destroy() is called.
	 *
	 * @return	bool
	 */
	protected function _cookie_destroy()
	{
		// The array API requires PHP >= 7.3 (package minimum is 8.1) and it
		// is the only way to pass the SameSite attribute, which browsers now
		// default to Lax when omitted.
		return setcookie(
			$this->_config['cookie_name'],
			'',
			array(
				'expires' => 1,
				'path' => $this->_config['cookie_path'],
				'domain' => $this->_config['cookie_domain'],
				'secure' => $this->_config['cookie_secure'],
				'httponly' => TRUE,
				'samesite' => $this->_config['cookie_samesite'] ?? 'Lax',
			)
		);
	}

	// ------------------------------------------------------------------------

	/**
	 * Get lock
	 *
	 * A dummy method allowing drivers with no locking functionality
	 * (databases other than PostgreSQL and MySQL) to act as if they
	 * do acquire a lock.
	 *
	 * @param	string	$session_id
	 * @return	bool
	 */
	protected function _get_lock($session_id)
	{
		$this->_lock = TRUE;
		return TRUE;
	}

	// ------------------------------------------------------------------------

	/**
	 * Release lock
	 *
	 * @return	bool
	 */
	protected function _release_lock()
	{
		if ($this->_lock)
		{
			$this->_lock = FALSE;
		}

		return TRUE;
	}
}
