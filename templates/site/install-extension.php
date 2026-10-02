<?php

/**
 * Installs an extracted Joomla extension, package or library through the
 * Installer API, from the command line, and reports the outcome as JSON.
 *
 * Copied into a site by cwm-site-create and removed afterwards. Run inside the
 * site's container:
 *
 *   php cli/cwm-install-extension.php /abs/path/to/extracted/extension
 *
 * Why not `joomla extension:install`: when the installer returns false, that
 * command prints "Unable to install extension" and drops the reason, which only
 * lives in the application's message queue. This prints the queue.
 *
 * The last line of output is `CWM_RESULT:` followed by one JSON object, so the
 * caller can find it among any PHP notices that came before.
 */

declare(strict_types=1);

const _JEXEC = 1;

require_once \dirname(__DIR__) . '/includes/defines.php';
require_once JPATH_BASE . '/includes/framework.php';

$result = ['ok' => false, 'name' => null, 'type' => null, 'version' => null, 'messages' => [], 'error' => null];

try {
    $source = $argv[1] ?? '';

    if ($source === '' || !is_dir($source)) {
        throw new \RuntimeException('Pass the extracted extension directory as the first argument.');
    }

    $container = \Joomla\CMS\Factory::getContainer();
    $container->alias('session', 'session.cli')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

    $app = $container->get(\Joomla\Console\Application::class);
    \Joomla\CMS\Factory::$application = $app;

    // Extensions installed earlier in the request, or by an earlier request, are
    // only autoloadable once the namespace map has been rebuilt.
    $app->createExtensionNamespaceMap();

    $installer = new \Joomla\CMS\Installer\Installer();
    $installer->setDatabase($container->get(\Joomla\Database\DatabaseInterface::class));

    $result['ok'] = (bool) $installer->install($source);

    if ($installer->manifest instanceof \SimpleXMLElement) {
        $result['name']    = (string) ($installer->manifest->name ?? '');
        $result['type']    = (string) ($installer->manifest['type'] ?? '');
        $result['version'] = (string) ($installer->manifest->version ?? '');
    }

    // The queue is grouped by type ("message", "warning", "error"), though older
    // cores return a flat list of {type, message}; accept either.
    foreach ($app->getMessageQueue(true) as $key => $entry) {
        if (\is_array($entry) && isset($entry['message'])) {
            $result['messages'][(string) ($entry['type'] ?? 'message')][] = strip_tags((string) $entry['message']);

            continue;
        }

        foreach ((array) $entry as $text) {
            $result['messages'][(string) $key][] = strip_tags((string) $text);
        }
    }
} catch (\Throwable $e) {
    $result['ok']    = false;
    $result['error'] = \get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
}

echo "\nCWM_RESULT:" . json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

exit($result['ok'] ? 0 : 1);
