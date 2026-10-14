<?php
declare(strict_types=1);

// Keep the site's existing database configuration untouched. A deployment may
// install mcp-local.php separately; it is ignored by Git and denied by Apache.
$config = require __DIR__.'/config.php';
$localFile = __DIR__.'/mcp-local.php';
if (is_file($localFile)) {
    $local = require $localFile;
    if (!is_array($local)) throw new RuntimeException('MCP local configuration invalid');
    $config = array_replace($config, $local);
}
return $config;
