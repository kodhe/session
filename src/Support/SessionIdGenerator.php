<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Support;

/**
 * Session ID Generator
 * 
 * Generates secure random session IDs
 * 
 * @package Kodhe\Framework\Session\Support
 */
class SessionIdGenerator
{
    /**
     * @var int Length of generated session IDs
     */
    private int $length;

    /**
     * @var int Bits per character (4, 5, or 6)
     */
    private int $bitsPerCharacter;

    /**
     * Constructor
     * 
     * @param int $length Session ID length
     * @param int $bitsPerCharacter Bits per character
     */
    public function __construct(int $length = 40, int $bitsPerCharacter = 5)
    {
        $this->length = max(16, $length);
        $this->bitsPerCharacter = in_array($bitsPerCharacter, [4, 5, 6], true) 
            ? $bitsPerCharacter 
            : 5;
    }

    /**
     * Generate a new session ID
     * 
     * @return string
     */
    public function generate(): string
    {
        // Calculate bytes needed based on bits per character
        $bytesNeeded = (int) ceil($this->length * $this->bitsPerCharacter / 8);
        
        // Generate random bytes
        $bytes = random_bytes($bytesNeeded);
        
        // Convert to session ID characters
        return $this->encodeSessionId($bytes);
    }

    /**
     * Encode bytes to session ID string
     * 
     * @param string $bytes Random bytes
     * @return string
     */
    private function encodeSessionId(string $bytes): string
    {
        switch ($this->bitsPerCharacter) {
            case 4:
                // Hexadecimal encoding
                return substr(bin2hex($bytes), 0, $this->length);
            
            case 5:
                // Base32-like encoding (0-9, a-v)
                return $this->encodeBase32($bytes);
            
            case 6:
                // Base64-like encoding without +/
                return $this->encodeBase64Url($bytes);
            
            default:
                return $this->encodeBase64Url($bytes);
        }
    }

    /**
     * Encode to base32-like format (0-9, a-v)
     * 
     * @param string $bytes
     * @return string
     */
    private function encodeBase32(string $bytes): string
    {
        $chars = '0123456789abcdefghijklmnopqrstuv';
        $result = '';
        $buffer = 0;
        $bitsLeft = 0;
        
        for ($i = 0; $i < strlen($bytes) && strlen($result) < $this->length; $i++) {
            $buffer |= ord($bytes[$i]) << $bitsLeft;
            $bitsLeft += 8;
            
            while ($bitsLeft >= 5 && strlen($result) < $this->length) {
                $result .= $chars[$buffer & 31];
                $buffer >>= 5;
                $bitsLeft -= 5;
            }
        }
        
        // Pad if necessary
        while (strlen($result) < $this->length) {
            $result .= $chars[$buffer & 31];
            $buffer >>= 5;
        }
        
        return $result;
    }

    /**
     * Encode to base64url format (0-9, a-zA-Z, -)
     * 
     * @param string $bytes
     * @return string
     */
    private function encodeBase64Url(string $bytes): string
    {
        $encoded = base64_encode($bytes);
        // Replace + with -, remove / and padding
        $encoded = str_replace(['+', '/', '='], ['-', '', ''], $encoded);
        return substr($encoded, 0, $this->length);
    }

    /**
     * Get the configured length
     * 
     * @return int
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Get the configured bits per character
     * 
     * @return int
     */
    public function getBitsPerCharacter(): int
    {
        return $this->bitsPerCharacter;
    }
}
