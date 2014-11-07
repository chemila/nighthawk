<?php
namespace NHK\System;
defined('NHK_PATH_ROOT') or die('No direct script access.');

/**
 * Class Core
 *
 * @package NHK\System
 */
class Core {
    /**
     * @desc namespace prefix
     */
    const NHK_NAMESPACE = 'nhk';
    const PRODUCT_NAME = 'nighthawk';
    /**
     * @var  array  PHP error code => human readable name
     */
    public static $phpErrors
        = array(
            E_ERROR => 'Fatal Error',
            E_USER_ERROR => 'User Error',
            E_PARSE => 'Parse Error',
            E_WARNING => 'Warning',
            E_USER_WARNING => 'User Warning',
            E_STRICT => 'Strict',
            E_NOTICE => 'Notice',
            E_RECOVERABLE_ERROR => 'Recoverable Error',
            E_DEPRECATED => 'Deprecated',
        );

    /**
     * @var  array  types of errors to display at shutdown
     */
    public static $shutdownErrors = array(E_PARSE, E_ERROR, E_USER_ERROR, E_COMPILE_ERROR);

    /**
     * @var bool
     */
    protected static $_init = false;

    protected static $_isDebug = false;

    /**
     * @desc setup handlers
     */
    public static function init() {
        if (Core::$_init) {
            // Do not allow execution twice
            return;
        }

        Core::$_isDebug = Config::getInstance()->get('master.debug', false);

        // Core is now initialized
        Core::$_init = true;

        // Enable Core exception handling, adds stack traces and error source.
        set_exception_handler(array('NHK\System\Core', 'exceptionHandler'));

        // Enable Core error handling, converts all PHP errors to exceptions.
        set_error_handler(array('NHK\System\Core', 'errorHandler'));

        // Enable the Core shutdown handler, which catches E_FATAL errors.
        register_shutdown_function(array('NHK\System\Core', 'shutdownHandler'));
    }

    /**
     * Cleans up the environment:
     * - Restore the previous error and exception handlers
     * - Destroy the Core::$log and Core::$config objects
     *
     * @return  void
     */
    public static function deinit() {
        if (Core::$_init) {
            // Removed the autoloader
            spl_autoload_unregister(array('NHK\System\Core', 'autoLoad'));

            // Go back to the previous error handler
            restore_error_handler();

            // Go back to the previous exception handler
            restore_exception_handler();

            // Core is no longer initialized
            Core::$_init = false;
        }
    }

    /**
     * @param $class
     * @return bool
     */
    public static function autoLoad($class) {
        // Transform the class name into a path
        $file = ltrim(str_replace(array('_', '\\'), '/', strtolower($class)), self::NHK_NAMESPACE);

        if ($path = Core::findFile($file)) {
            // Load the class file
            require $path;

            // Class has been found
            return true;
        }

        // Class is not in the filesystem
        return false;
    }

    /**
     * @param $file
     * @return bool|string
     */
    public static function findFile($file) {
        // Create a partial path of the filename
        $path = NHK_PATH_ROOT . $file . '.php';
        if (is_file($path)) {
            return $path;
        }
    }

    /**
     * @param      $code
     * @param      $error
     * @param null $file
     * @param null $line
     * @return bool
     * @throws Exception
     */
    public static function errorHandler($code, $error, $file = null, $line = null) {
        if (error_reporting() & $code) {
            self::alert(sprintf("file [%s] line [%s] exception [%s]", $file, $line, $error));
            throw new Exception($error, $code, 0, $file, $line);
        }

        // Do not execute the PHP error handler
        return true;
    }

    /**
     * @param \Exception $e
     */
    public static function exceptionHandler(\Exception $e) {
        try {
            // Create a text version of the exception
            $error = Core::displayException($e);

            Log::write($error);

            return true;
        }
        catch (\Exception $e) {
            // Display the exception text
            echo Core::displayException($e), "\n";

            // Exit with an error status
            exit(1);
        }
    }

    /**
     * @desc shutdown hook
     */
    public static function shutdownHandler() {
        if (!Core::$_init) {
            // Do not execute when not active
            return;
        }

        if ($error = error_get_last() AND in_array($error['type'], Core::$shutdownErrors)) {
            // Fake an exception for nice debugging
            Core::exceptionHandler(
                new Exception($error['message'], $error['type'], 0, $error['file'], $error['line'])
            );

            // Shutdown now to avoid a "death loop"
            exit(1);
        }
    }

    /**
     * @param \Exception $e
     * @return string
     */
    public static function displayException(\Exception $e) {
        $code = $e->getCode();
        if (isset(self::$phpErrors[$code])) {
            $code = self::$phpErrors[$code];
        }

        return sprintf(
            '%s [ %s ]: %s ~ %s [ %d ]',
            get_class($e), $code, strip_tags($e->getMessage()), $e->getFile(), $e->getLine()
        );
    }

    /**
     * @param      $message
     * @param bool $isError
     */
    public static function alert($message, $isError = true) {
        if (true === $isError) {
            printf("\033[31;40m%s\033[0m\n", $message);
        }
        else {
            printf("\033[32;49m%s\033[0m\n", $message);
        }
    }

    /**
     * @return bool
     */
    public static function getDebugMode() {
        return (bool)self::$_isDebug;
    }
}