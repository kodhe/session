<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Drivers;

use Exception;
use Kodhe\Framework\Database\Query\Builder;
use Kodhe\Framework\Session\Driver;

/**
 * CodeIgniter Session Database Driver
 */
class DatabaseDriver extends Driver
{
    /**
     * DB object
     */
    protected Builder $_db;

    /**
     * Row exists flag
     */
    protected bool $row_exists = false;

    /**
     * Lock "driver" flag
     */
    protected ?string $platform = null;

    /**
     * Lock identifier
     */
    protected $lock = false;

    /**
     * Expiry column used by garbage collection ('timestamp' or legacy CI3
     * 'lastactivity'); resolved lazily on first gc() run.
     */
    protected ?string $_gc_column = null;

    /**
     * Whether the session table schema has been validated once per request.
     */
    protected bool $_schema_checked = false;

    /**
     * Constructor
     */
    public function __construct(array &$params)
    {
        parent::__construct($params);

        $ci = kodhe();
        isset($ci->db) or $ci->load->database();
        $this->_db = $ci->db;

        if (! $this->_db instanceof Builder) {
            throw new Exception('Query Builder not enabled for the configured database.');
        }

        if ($this->_db->pconnect) {
            throw new Exception('Configured database connection is persistent.');
        }

        if ($this->_db->cache_on) {
            throw new Exception('Configured database connection has cache enabled.');
        }

        $db_driver = $this->_db->dbdriver . (empty($this->_db->subdriver) ? '' : '_' . $this->_db->subdriver);
        
        if (str_contains($db_driver, 'mysql')) {
            $this->platform = 'mysql';
        } elseif (in_array($db_driver, ['postgre', 'pdo_pgsql'], true)) {
            $this->platform = 'postgre';
        }

        // BC work-around for old 'sess_table_name' setting
        if (empty($this->_config['save_path'])) {
            $this->_config['save_path'] = (string) config_item('sess_table_name');
            if ($this->_config['save_path'] !== '') {
                log_message('debug', 'Session: "sess_save_path" is empty; using BC fallback to "sess_table_name".');
            }
        }

        // The database session driver requires a table name in sess_save_path.
        // Fail fast here with an actionable message instead of letting the
        // empty value travel into the query builder, where it surfaces as an
        // obscure TypeError ("strcspn(): Argument #1 ($string) must be of type
        // string, null given") from protect_identifiers().
        if ($this->_config['save_path'] === '') {
            throw new Exception(
                'Session: "sess_save_path" is empty; the database session driver'
                . " expects the session table name (e.g. \$config['sess_save_path'] = 'ci_sessions')"
                . ' with the CI3 schema: id, ip_address, timestamp (or legacy lastactivity), data.'
                . ' See session/README.md.'
            );
        }
    }

    /**
     * Open session handler
     */
    public function open(string $save_path, string $name): bool
    {
        if (empty($this->_db->conn_id) && ! $this->_db->db_connect()) {
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
        if ($this->getLock($session_id) === false) {
            return false;
        }

        $this->_db->reset_query();
        $this->_session_id = $session_id;

        // Fail fast with a clear message instead of an opaque
        // mysqli_sql_exception ("Unknown column 'data' in 'SELECT'") when the
        // configured table is not the CI3-style session schema
        // (id, ip_address, timestamp|lastactivity, data).
        if ($this->_schema_checked === false) {
            $this->_schema_checked = true;
            try {
                $columns = (array) $this->_db->field_data($this->_config['save_path']);
                $names = array_map(
                    static function ($c) {
                        return is_object($c) ? ($c->name ?? '') : (string) $c;
                    },
                    $columns
                );
                foreach (['id', 'ip_address', 'data'] as $required) {
                    if (! in_array($required, $names, true)) {
                        throw new Exception(
                            "Session table '{$this->_config['save_path']}' is missing the required column"
                            . " '$required'. Expected the CI3 session schema: id, ip_address,"
                            . " timestamp (or legacy lastactivity), data. See session/README.md."
                        );
                    }
                }
            } catch (\Throwable $e) {
                // field_data() itself failed (table missing / no privileges):
                // surface that too rather than letting SELECT blow up later.
                if ($e instanceof Exception) {
                    throw $e;
                }
                throw new Exception(
                    "Unable to inspect session table '{$this->_config['save_path']}': " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }

        $this->_db->select('data')
                  ->from($this->_config['save_path'])
                  ->where('id', $session_id);

        if ($this->_config['match_ip']) {
            $this->_db->where('ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
        }

        $result = $this->_db->get();
        if (! $result || ($row = $result->row()) === null) {
            $this->row_exists = false;
            $this->_fingerprint = md5('');
            return '';
        }

        $session_data = ($this->platform === 'postgre')
            ? base64_decode(rtrim($row->data))
            : $row->data;

        $this->_fingerprint = md5($session_data);
        $this->row_exists = true;
        
        return $session_data;
    }

    /**
     * Write session data
     */
    public function write(string $session_id, string $session_data): bool
    {
        $this->_db->reset_query();

        // Was the ID regenerated?
        if (isset($this->_session_id) && $session_id !== $this->_session_id) {
            if (! $this->releaseLock() || ! $this->getLock($session_id)) {
                return false;
            }
            $this->row_exists = false;
            $this->_session_id = $session_id;
        } elseif ($this->lock === false) {
            return false;
        }

        if ($this->row_exists === false) {
            $insert_data = [
                'id'         => $session_id,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
                'timestamp'  => time(),
                'data'       => $this->platform === 'postgre' 
                    ? base64_encode($session_data) 
                    : $session_data
            ];

            if ($this->_db->insert($this->_config['save_path'], $insert_data)) {
                $this->_fingerprint = md5($session_data);
                $this->row_exists = true;
                return true;
            }
            return false;
        }

        $this->_db->where('id', $session_id);
        if ($this->_config['match_ip']) {
            $this->_db->where('ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
        }

        $update_data = ['timestamp' => time()];
        if ($this->_fingerprint !== md5($session_data)) {
            $update_data['data'] = $this->platform === 'postgre'
                ? base64_encode($session_data)
                : $session_data;
        }

        if ($this->_db->update($this->_config['save_path'], $update_data)) {
            $this->_fingerprint = md5($session_data);
            return true;
        }

        return false;
    }

    /**
     * Close session handler
     */
    public function close(): bool
    {
        return $this->lock && ! $this->releaseLock() ? false : true;
    }

    /**
     * Destroy session
     */
    public function destroy(string $session_id): bool
    {
        if ($this->lock) {
            $this->_db->reset_query();
            $this->_db->where('id', $session_id);
            
            if ($this->_config['match_ip']) {
                $this->_db->where('ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
            }

            if (! $this->_db->delete($this->_config['save_path'])) {
                return false;
            }
        }

        if ($this->close() === true) {
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
        $this->_db->reset_query();

        // Some deployments keep the legacy CI3 session schema whose expiry
        // column is named 'lastactivity' instead of 'timestamp'. Detect the
        // actual column once so garbage collection never triggers an
        // "Unknown column" database error.
        if ($this->_gc_column === null) {
            $this->_gc_column = 'timestamp';
            try {
                $columns = (array) $this->_db->field_data($this->_config['save_path']);
                $names = array_map(
                    static function ($c) {
                        return is_object($c) ? ($c->name ?? '') : (string) $c;
                    },
                    $columns
                );
                if (! in_array('timestamp', $names, true) && in_array('lastactivity', $names, true)) {
                    $this->_gc_column = 'lastactivity';
                }
            } catch (\Throwable $e) {
                // Schema probe failed; keep the default and let the delete
                // surface any genuine error as before.
            }
        }

        $result = $this->_db->delete(
            $this->_config['save_path'],
            $this->_gc_column . ' < ' . (time() - $maxlifetime)
        );

        return $result ? $this->_db->affected_rows() : false;
    }

    /**
     * Validate session ID
     */
    public function validateSessionId(string $id): bool
    {
        $this->_db->reset_query();
        $this->_db->select('1')
                  ->from($this->_config['save_path'])
                  ->where('id', $id);
        
        if ($this->_config['match_ip']) {
            $this->_db->where('ip_address', $_SERVER['REMOTE_ADDR'] ?? '');
        }
        
        $result = $this->_db->get();
        return $result && $result->row() !== null;
    }

    /**
     * Get lock
     */
    protected function getLock(string $session_id): bool
    {
        if ($this->platform === 'mysql') {
            $arg = md5($session_id . ($this->_config['match_ip'] ? '_' . ($_SERVER['REMOTE_ADDR'] ?? '') : ''));
            $result = $this->_db->query("SELECT GET_LOCK('" . $arg . "', 300) AS ci_session_lock")->row();
            
            if ($result && $result->ci_session_lock) {
                $this->lock = $arg;
                return true;
            }
            return false;
        }
        
        if ($this->platform === 'postgre') {
            $arg = "hashtext('" . $session_id . "')" . 
                   ($this->_config['match_ip'] ? ", hashtext('" . ($_SERVER['REMOTE_ADDR'] ?? '') . "')" : '');
            
            if ($this->_db->simple_query('SELECT pg_advisory_lock(' . $arg . ')')) {
                $this->lock = $arg;
                return true;
            }
            return false;
        }

        return parent::_get_lock($session_id);
    }

    /**
     * Release lock
     */
    protected function releaseLock(): bool
    {
        if (! $this->lock) {
            return true;
        }

        if ($this->platform === 'mysql') {
            $result = $this->_db->query("SELECT RELEASE_LOCK('" . $this->lock . "') AS ci_session_lock")->row();
            if ($result && $result->ci_session_lock) {
                $this->lock = false;
                return true;
            }
            return false;
        }
        
        if ($this->platform === 'postgre') {
            if ($this->_db->simple_query('SELECT pg_advisory_unlock(' . $this->lock . ')')) {
                $this->lock = false;
                return true;
            }
            return false;
        }

        return parent::_release_lock();
    }
}
