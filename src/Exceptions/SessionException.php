<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Exceptions;

/**
 * Base Session Exception
 * 
 * @package Kodhe\Framework\Session\Exceptions
 */
class SessionException extends \RuntimeException
{
    /**
     * Create exception for invalid session ID
     * 
     * @param string $sessionId The invalid session ID
     * @return self
     */
    public static function invalidSessionId(string $sessionId): self
    {
        return new self('Invalid session ID: ' . substr($sessionId, 0, 10) . '...');
    }

    /**
     * Create exception for driver not found
     * 
     * @param string $driver Driver name
     * @return self
     */
    public static function driverNotFound(string $driver): self
    {
        return new self("Session driver '{$driver}' not found");
    }

    /**
     * Create exception for storage error
     * 
     * @param string $message Error message
     * @return self
     */
    public static function storageError(string $message): self
    {
        return new self('Storage error: ' . $message);
    }

    /**
     * Create exception for lock acquisition failure
     * 
     * @param string $sessionId Session ID
     * @return self
     */
    public static function lockFailed(string $sessionId): self
    {
        return new self('Failed to acquire lock for session: ' . substr($sessionId, 0, 10) . '...');
    }

    /**
     * Create exception for serialization error
     * 
     * @param string $message Error message
     * @return self
     */
    public static function serializationError(string $message): self
    {
        return new self('Serialization error: ' . $message);
    }
}
