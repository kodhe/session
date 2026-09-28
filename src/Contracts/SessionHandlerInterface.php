<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Contracts;

/**
 * Session Handler Interface - Contract for session storage drivers
 * Extends PHP's SessionHandlerInterface with additional methods
 * 
 * @package Kodhe\Framework\Session\Contracts
 */
interface SessionHandlerInterface extends \SessionHandlerInterface
{
    /**
     * Validate session ID
     * 
     * @param string $id Session ID to validate
     * @return bool
     */
    public function validateSessionId(string $id): bool;
}
