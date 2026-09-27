<?php

class PluginBase { public function __construct() {} }
define('PLUGIN_DIR', __DIR__.'/fixtures/optional-storage-plugins/');
require_once __DIR__.'/../plugins/aiDrive/app.php';

$plugin = new aiDrivePlugin();
$method = new ReflectionMethod('aiDrivePlugin', 'loadOptionalStorageDrivers');
$method->setAccessible(true);
$method->invoke($plugin);

if (!class_exists('PathDriverBaidu')) {
	fwrite(STDERR, "Optional storage driver was not loaded.\n");
	exit(1);
}

echo "AI Drive optional storage driver bootstrap test passed.\n";
