<?php

declare(strict_types=1);

namespace Kodhe\Framework\Session\Factory;

use Kodhe\Framework\Session\Contracts\SessionHandlerInterface;
use Kodhe\Framework\Session\Exceptions\SessionException;
use Kodhe\Framework\Session\Support\SessionConfig;

/**
 * Driver Factory - Creates session driver instances
 * 
 * @package Kodhe\Framework\Session\Factory
 */
class DriverFactory
{
    /**
     * @var array Registered driver mappings
     */
    private static array $drivers = [
        'files' => \Kodhe\Framework\Session\Drivers\FilesDriver::class,
        'database' => \Kodhe\Framework\Session\Drivers\DatabaseDriver::class,
        'redis' => \Kodhe\Framework\Session\Drivers\RedisDriver::class,
        'memcached' => \Kodhe\Framework\Session\Drivers\MemcachedDriver::class,
    ];

    /**
     * Register a custom driver
     * 
     * @param string $name Driver name
     * @param string $class Driver class (must implement SessionHandlerInterface)
     * @return void
     */
    public static function register(string $name, string $class): void
    {
        if (!is_subclass_of($class, SessionHandlerInterface::class)) {
            throw new \InvalidArgumentException(
                "Driver class must implement SessionHandlerInterface"
            );
        }

        self::$drivers[$name] = $class;
    }

    /**
     * Create a session driver instance
     * 
     * @param string $driver Driver name
     * @param array $config Configuration array
     * @return SessionHandlerInterface
     * @throws SessionException If driver not found
     */
    public static function create(string $driver, array $config): SessionHandlerInterface
    {
        $driverClass = self::resolveDriver($driver);
        
        if (!class_exists($driverClass)) {
            throw SessionException::driverNotFound($driver);
        }

        return new $driverClass($config);
    }

    /**
     * Resolve driver name to class name
     * 
     * @param string $driver Driver name
     * @return string Fully qualified class name
     * @throws SessionException If driver not found
     */
    private static function resolveDriver(string $driver): string
    {
        $driver = strtolower($driver);

        if (isset(self::$drivers[$driver])) {
            return self::$drivers[$driver];
        }

        // Try legacy naming convention
        $legacyClass = 'Kodhe\\Framework\\Session\\Drivers\\' . ucfirst($driver);
        if (class_exists($legacyClass)) {
            return $legacyClass;
        }

        // Try CI3-style naming
        $ciClass = 'CI_Session_' . $driver . '_driver';
        if (class_exists($ciClass)) {
            return $ciClass;
        }

        throw SessionException::driverNotFound($driver);
    }

    /**
     * Check if a driver is available
     * 
     * @param string $driver Driver name
     * @return bool
     */
    public static function hasDriver(string $driver): bool
    {
        $driver = strtolower($driver);
        
        if (isset(self::$drivers[$driver])) {
            return true;
        }

        $legacyClass = 'Kodhe\\Framework\\Session\\Drivers\\' . ucfirst($driver);
        if (class_exists($legacyClass)) {
            return true;
        }

        $ciClass = 'CI_Session_' . $driver . '_driver';
        if (class_exists($ciClass)) {
            return true;
        }

        return false;
    }

    /**
     * Get all registered drivers
     * 
     * @return array
     */
    public static function getRegisteredDrivers(): array
    {
        return array_keys(self::$drivers);
    }
}
