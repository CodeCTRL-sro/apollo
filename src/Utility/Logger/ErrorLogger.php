<?php
namespace CodeCTRL\Apollo\Utility\Logger;


use CodeCTRL\Apollo\Utility\Logger\Interfaces\LoggerHelperInterface;
use CodeCTRL\Apollo\Utility\Logger\Traits\LoggerHelperTrait;
use Psr\Log\LoggerInterface;

class ErrorLogger implements LoggerHelperInterface
{
    use LoggerHelperTrait;

    public function __construct(LoggerInterface $logger)
    {
        $this->setLogger($logger);
        $this->setLogDebug(true);
    }

    /**
     * @param $severity
     * @param $message
     * @param $file
     * @param $line
     * @return bool
     * @throws \ErrorException
     */
    public function customErrorHandler($severity, $message, $file, $line)
    {
        switch ($severity) {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                $this->error($severity, array($message, $file, $line));
                throw new \ErrorException($message, 0, $severity, $file, $line);
                break;
            case E_WARNING:
            case E_CORE_WARNING:
            case E_COMPILE_WARNING:
            case E_USER_WARNING:
            case E_DEPRECATED:
            case E_USER_DEPRECATED:
                $this->warning($severity, array($message, $file, $line));
                break;
            case E_NOTICE:
            case E_USER_NOTICE:
            case E_STRICT:
                $this->notice($severity, array($message, $file, $line));
                break;
            default:
                $this->debug($severity, array($message, $file, $line));
                break;
        }
        return false;
    }

    /**
     * @deprecated 3.3.0 Never registered anywhere, and it read $error['type'] without
     *             checking that error_get_last() returned anything — so calling it on a
     *             clean shutdown raised a warning of its own. ApolloKernel::_fatal_handler()
     *             is the handler that actually runs. Removed in 4.0.
     */
    public function myShutdownFunction()
    {
        $error = error_get_last();

        if (!isset($error['type'])) {
            return;
        }

        switch ($error['type']) {
            case E_ERROR:
            case E_PARSE:
            case E_CORE_ERROR:
            case E_COMPILE_ERROR:
            case E_USER_ERROR:
            case E_RECOVERABLE_ERROR:
                $this->error($error['type'], $error);
                break;
        }
    }
}
