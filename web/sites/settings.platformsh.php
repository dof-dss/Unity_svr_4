<?php

// @codingStandardsIgnoreFile

/**
 * @file
 * Platform.sh settings.
 */

use Drupal\Core\Installer\InstallerKernel;

if (!isset($subsite_id)) {
  $subsite_id = 'database';
}

$platformsh = new \Platformsh\ConfigReader\Config();

if (!$platformsh->inRuntime()) {
  return;
}

// Configure the database.
$creds = $platformsh->credentials($subsite_id);
if ($creds) {
  $databases['default']['default'] = [
    'driver' => $creds['scheme'],
    'database' => $creds['path'],
    'username' => $creds['username'],
    'password' => $creds['password'],
    'host' => $creds['host'],
    'port' => $creds['port'],
    'pdo' => [PDO::MYSQL_ATTR_COMPRESS => !empty($creds['query']['compression'])]
  ];
}

// Solr configuration.
$platformsh->registerFormatter('drupal-solr', function($solr) {
  // Default the solr core name to `collection1` for pre-Solr-6.x instances.
  return [
    'core' => substr($solr['path'], 5) ? : 'collection1',
    'path' => '',
    'host' => $solr['host'],
    'port' => $solr['port'],
  ];
});

// Update these values to the relationship name (from .platform.app.yaml)
// and the machine name of the server from your Drupal configuration.
$relationship_name = $subsite_id . '_solr';
$solr_server_name = 'solr_default';
if ($platformsh->hasRelationship($relationship_name)) {
  // Set the connector configuration to the appropriate value, as defined by the formatter above.
  $config['search_api.server.' . $solr_server_name]['backend_config']['connector_config'] = $platformsh->formattedCredentials($relationship_name, 'drupal-solr');
}

// Configure file paths.
if (!isset($settings['file_private_path'])) {
  $settings['file_private_path'] = $platformsh->appDir . '/private/' . $subsite_id;
}
if (!isset($config['file_temp_path'])) {
  $config['file_temp_path'] = $platformsh->appDir . '/tmp/' . $subsite_id;
}

// Configure the default PhpStorage and Twig template cache directories.
if (!isset($settings['php_storage']['default'])) {
  $settings['php_storage']['default']['directory'] = $settings['file_private_path'];
}
if (!isset($settings['php_storage']['twig'])) {
  $settings['php_storage']['twig']['directory'] = $settings['file_private_path'];
}

// The 'trusted_hosts_pattern' setting allows an admin to restrict the Host header values
// that are considered trusted.  If an attacker sends a request with a custom-crafted Host
// header then it can be an injection vector, depending on how the Host header is used.
// However, Platform.sh already replaces the Host header with the route that was used to reach
// Platform.sh, so it is guaranteed to be safe.  The following line explicitly allows all
// Host headers, as the only possible Host header is already guaranteed safe.
$settings['trusted_host_patterns'] = ['.*'];

// Import variables prefixed with 'd8settings:' into $settings
// and 'd8config:' into $config.
foreach ($platformsh->variables() as $name => $value) {
  $parts = explode(':', $name);
  list($prefix, $key) = array_pad($parts, 3, null);
  switch ($prefix) {
    // Variables that begin with `d8settings` or `drupal` get mapped
    // to the $settings array verbatim, even if the value is an array.
    // For example, a variable named d8settings:example-setting' with
    // value 'foo' becomes $settings['example-setting'] = 'foo';
    case 'd8settings':
    case 'drupal':
      $settings[$key] = $value;
      break;
    // Variables that begin with `d8config` get mapped to the $config
    // array.  Deeply nested variable names, with colon delimiters,
    // get mapped to deeply nested array elements. Array values
    // get added to the end just like a scalar. Variables without
    // both a config object name and property are skipped.
    // Example: Variable `d8config:conf_file:prop` with value `foo` becomes
    // $config['conf_file']['prop'] = 'foo';
    // Example: Variable `d8config:conf_file:prop:subprop` with value `foo` becomes
    // $config['conf_file']['prop']['subprop'] = 'foo';
    // Example: Variable `d8config:conf_file:prop:subprop` with value ['foo' => 'bar'] becomes
    // $config['conf_file']['prop']['subprop']['foo'] = 'bar';
    // Example: Variable `d8config:prop` is ignored.
    case 'd8config':
      if (count($parts) > 2) {
        $temp = &$config[$key];
        foreach (array_slice($parts, 2) as $n) {
          $prev = &$temp;
          $temp = &$temp[$n];
        }
        $prev[$n] = $value;
      }
      break;
  }
}


// Set the project-specific entropy value, used for generating one-time
// keys and such.
$settings['hash_salt'] = empty($settings['hash_salt']) ? $platformsh->projectEntropy . $subsite_id : $settings['hash_salt'];

// Set the deployment identifier, which is used by some Drupal cache systems.
$settings['deployment_identifier'] = $settings['deployment_identifier'] ?? $platformsh->treeId;

// Enable Redis caching.
// Set redis configuration.
if ($platformsh->hasRelationship('redis') && !\Drupal\Core\Installer\InstallerKernel::installationAttempted() && extension_loaded('redis')) {
  $redis = $platformsh->credentials('redis');

  // Set Redis as the default backend for any cache bin not otherwise specified.
  $settings['cache']['default'] = 'cache.backend.redis';
  $settings['redis.connection']['host'] = $redis['host'];
  $settings['redis.connection']['port'] = $redis['port'];
  // Add a cache prefix for the subsite to avoid clashes.
  $settings['cache_prefix'] = $subsite_id . '_';

  // Apply changes to the container configuration to better leverage Redis.
  // This includes using Redis for the lock and flood control systems, as well
  // as the cache tag checksum. Alternatively, copy the contents of that file
  // to your project-specific services.yml file, modify as appropriate, and
  // remove this line.
  $settings['container_yamls'][] = 'modules/contrib/redis/example.services.yml';

  // Allow the services to work before the Redis module itself is enabled.
  $settings['container_yamls'][] = 'modules/contrib/redis/redis.services.yml';

  // Manually add the classloader path, this is required for the container cache bin definition below
  // and allows to use it without the redis module being enabled.
  $class_loader->addPsr4('Drupal\\redis\\', 'modules/contrib/redis/src');

  // Use redis for container cache.
  // The container cache is used to load the container definition itself, and
  // thus any configuration stored in the container itself is not available
  // yet. These lines force the container cache to use Redis rather than the
  // default SQL cache.
  $settings['bootstrap_container_definition'] = [
    'parameters' => [],
    'services' => [
      'redis.factory' => [
        'class' => 'Drupal\redis\ClientFactory',
      ],
      'cache.backend.redis' => [
        'class' => 'Drupal\redis\Cache\CacheBackendFactory',
        'arguments' => ['@redis.factory', '@cache_tags_provider.container', '@serialization.phpserialize'],
      ],
      'cache.container' => [
        'class' => '\Drupal\redis\Cache\PhpRedis',
        'factory' => ['@cache.backend.redis', 'get'],
        'arguments' => ['container'],
      ],
      'cache_tags_provider.container' => [
        'class' => 'Drupal\redis\Cache\RedisCacheTagsChecksum',
        'arguments' => ['@redis.factory'],
      ],
      'serialization.phpserialize' => [
        'class' => 'Drupal\Component\Serialization\PhpSerialize',
      ],
    ],
  ];
}
