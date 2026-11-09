<?php
/**
 * v0.8.0 WebDAV integration consistency test.
 *
 * Since 0.7.0 the WebDAV integration is a runtime alias patch (installed by
 * AiDriveAgentStore::installWebdavAliases) instead of the hand-patched driver
 * bootstrap used before. Mounted storage drivers are loaded by the official
 * WebDAV plugin route itself and by the Agent API (loadOptionalStorageDrivers),
 * so the class file must carry the alias patch and must NOT reintroduce the
 * hand-patched AiDriveStorageDrivers bootstrap inside run().
 */
$source=file_get_contents(__DIR__.'/../plugins/webdav/php/webdavServerKod.class.php');
if($source===false) throw new RuntimeException('webdavServerKod.class.php is missing');
if(strpos($source,'AI Drive stable WebDAV aliases')===false) throw new RuntimeException('AI Drive stable WebDAV alias patch missing; run aiDrive webdav/enableWebdav once');
if(strpos($source,'AiDriveStorageDrivers::load')!==false) throw new RuntimeException('hand-patched driver bootstrap must not return into webdavServerKod; drivers load via the plugin route and the Agent API');
foreach(array('$this->checkUser()','$this->initPath(') as $needle){
	if(strpos($source,$needle)===false) throw new RuntimeException('webdavServerKod run() lost '.$needle);
}
echo "WebDAV alias patch consistency test passed\n";
