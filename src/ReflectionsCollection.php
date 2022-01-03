<?php
namespace wapmorgan\OpenApiGenerator;

use ReflectionClass;
use ReflectionMethod;

class ReflectionsCollection
{
    protected static $classes = [];
    protected static $methods = [];

    /**
     * @param string $className
     * @return ReflectionClass|bool
     * @throws \ReflectionException
     */
    public static function getClass(string $className)
    {
        if (!isset(static::$classes[$className])) {
            /**
             * @var ReflectionClass
             */
            static::$classes[$className] = class_exists($className) ? new ReflectionClass($className) : false;
        }
        return static::$classes[$className];
    }

    /**
     * @param string $className
     * @param string $methodName
     * @return ReflectionMethod|bool
     * @throws \ReflectionException
     */
    public static function getMethod(string $className, string $methodName)
    {
        if (!isset(static::$methods[$className][$methodName])) {
            /**
             * @var ReflectionMethod
             */
            static::$methods[$className][$methodName] = class_exists($className) ? new ReflectionMethod($className, $methodName) : false;
        }
        return static::$methods[$className][$methodName];
    }

    public static function getProtectedProperty(object $obj, string $property)
    {
        $refl = new \ReflectionObject($obj);
        $prop = $refl->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($obj);
    }
}
