<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Utility\Debug;

use Throwable;
use Twig\Environment;
use Whoops\Handler\JsonResponseHandler;
use Whoops\Handler\PrettyPageHandler;
use Whoops\Run;

/**
 * Renders an uncaught throwable, with the level of detail decided by debug mode.
 *
 * Two things this fixes at once.
 *
 * Production leakage: HtmlStrategy used to write the exception message and the full
 * stack trace into errors.html.twig unconditionally, so whether a live site exposed its
 * internals came down to whether the template happened to print those variables. Nothing
 * beyond the status code and reason phrase leaves this class unless debug is on.
 *
 * Debugging: with debug on and filp/whoops installed, the same throwable is rendered as
 * an interactive page with source context — the Symfony/Ignition experience. Whoops is a
 * dev dependency, so a missing package is normal and simply falls back to the Twig
 * template.
 */
final class ErrorRenderer
{
    public function __construct(
        private bool $debug = false,
        private ?Environment $twig = null
    ) {
    }

    /**
     * @return bool
     */
    public function isDebug(): bool
    {
        return $this->debug;
    }

    /**
     * Whether the rich error page can actually be produced.
     *
     * @return bool
     */
    public function canRenderPrettyPage(): bool
    {
        return $this->debug && class_exists(Run::class);
    }

    /**
     * Full HTML for a throwable, or null when the caller should render its own template.
     *
     * @param Throwable $throwable
     * @return string|null
     */
    public function renderPrettyPage(Throwable $throwable): ?string
    {
        if (!$this->canRenderPrettyPage()) {
            return null;
        }

        try {
            $whoops = new Run();
            $handler = new PrettyPageHandler();
            $handler->handleUnconditionally(true);
            $whoops->pushHandler($handler);
            $whoops->allowQuit(false);
            $whoops->writeToOutput(false);

            $output = $whoops->handleException($throwable);

            return $output === '' ? null : $output;
        } catch (Throwable) {
            // The error page failing must never replace the original error.
            return null;
        }
    }

    /**
     * The template variables for errors.html.twig.
     *
     * @param Throwable $throwable
     * @param int $status
     * @param string $reason
     * @return array<string, mixed>
     */
    public function templateParams(Throwable $throwable, int $status, string $reason): array
    {
        $params = array(
            'title' => $status,
            'block' => array(
                'title' => $reason,
            ),
        );

        if ($this->debug) {
            $params['block']['message'] = $throwable->getMessage();
            $params['block']['trace'] = $throwable->getTraceAsString();
            $params['block']['exception'] = get_class($throwable);
            $params['block']['file'] = $throwable->getFile() . ':' . $throwable->getLine();
        }

        return $params;
    }

    /**
     * Extra payload for a JSON error response. Empty outside debug mode.
     *
     * @param Throwable $throwable
     * @return array<string, mixed>
     */
    public function jsonDebugData(Throwable $throwable): array
    {
        if (!$this->debug) {
            return array();
        }

        if (class_exists(JsonResponseHandler::class)) {
            try {
                $whoops = new Run();
                $handler = new JsonResponseHandler();
                $handler->addTraceToOutput(true);
                $whoops->pushHandler($handler);
                $whoops->allowQuit(false);
                $whoops->writeToOutput(false);

                $decoded = json_decode($whoops->handleException($throwable), true);
                if (is_array($decoded) && isset($decoded['error'])) {
                    return array('exception' => $decoded['error']);
                }
            } catch (Throwable) {
                // Fall through to the plain representation below.
            }
        }

        return array(
            'exception' => array(
                'type' => get_class($throwable),
                'message' => $throwable->getMessage(),
                'file' => $throwable->getFile(),
                'line' => $throwable->getLine(),
                'trace' => explode("\n", $throwable->getTraceAsString()),
            ),
        );
    }

    /**
     * Render errors.html.twig, or a minimal built-in page when Twig is unavailable —
     * a headless application still has to answer a 500 with something.
     *
     * @param Throwable $throwable
     * @param int $status
     * @param string $reason
     * @return string
     */
    public function renderTemplate(Throwable $throwable, int $status, string $reason): string
    {
        $params = $this->templateParams($throwable, $status, $reason);

        if ($this->twig instanceof Environment) {
            try {
                return $this->twig->render('errors.html.twig', $params);
            } catch (Throwable) {
                // Fall through to the built-in page.
            }
        }

        return $this->fallbackPage($status, $reason);
    }

    /**
     * @param int $status
     * @param string $reason
     * @return string
     */
    public function fallbackPage(int $status, string $reason): string
    {
        return sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<title>%1$d %2$s</title></head><body><h1>%1$d</h1><p>%2$s</p></body></html>',
            $status,
            htmlspecialchars($reason, ENT_QUOTES, 'UTF-8')
        );
    }
}
