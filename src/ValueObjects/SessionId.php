<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\ValueObjects;

/**
 * SessionId Value Object
 * 
 * Encapsulates session ID with validation
 * 
 * @package Kodhe\Framework\Session\ValueObjects
 */
class SessionId
{
    /**
     * @var string The session ID
     */
    private string $id;

    /**
     * @var string Regular expression pattern for valid session IDs
     */
    private string $pattern;

    /**
     * Constructor
     * 
     * @param string $id The session ID
     * @param string $pattern Regular expression pattern for validation
     * @throws \InvalidArgumentException If session ID is invalid
     */
    public function __construct(string $id, string $pattern = '/^[0-9a-zA-Z,-]+$/')
    {
        $this->pattern = $pattern;
        
        if (!$this->isValid($id)) {
            throw new \InvalidArgumentException('Invalid session ID format');
        }
        
        $this->id = $id;
    }

    /**
     * Get the session ID
     * 
     * @return string
     */
    public function toString(): string
    {
        return $this->id;
    }

    /**
     * Check if session ID is valid
     * 
     * @param string $id Session ID to validate
     * @return bool
     */
    public function isValid(string $id): bool
    {
        if (empty($id)) {
            return false;
        }
        
        return (bool) preg_match($this->pattern, $id);
    }

    /**
     * Create a new SessionId from string
     * 
     * @param string $id Session ID
     * @param string $pattern Validation pattern
     * @return self
     */
    public static function fromString(string $id, string $pattern = '/^[0-9a-zA-Z,-]+$/'): self
    {
        return new self($id, $pattern);
    }

    /**
     * Generate a new random session ID
     * 
     * @param int $length Length of the session ID
     * @return self
     */
    public static function generate(int $length = 40): self
    {
        $bytes = random_bytes(($length * 3 + 4) / 4);
        $id = substr(str_replace(['+', '/'], ['', ''], base64_encode($bytes)), 0, $length);
        
        return new self($id);
    }

    /**
     * Compare with another SessionId
     * 
     * @param SessionId $other Other SessionId to compare
     * @return bool
     */
    public function equals(SessionId $other): bool
    {
        return $this->id === $other->toString();
    }

    /**
     * Get hash representation
     * 
     * @return string
     */
    public function __toString(): string
    {
        return $this->id;
    }
}
