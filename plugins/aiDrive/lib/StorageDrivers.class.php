<?php

/** Shared bootstrap for REST and authenticated WebDAV storage operations. */
class AiDriveStorageDrivers {
	public static function load(){
		$base=PLUGIN_DIR.'webdav/php/';
		foreach(array('webdavClient.class.php','pathDriverWebdav.class.php','pathDriverNFS.class.php','pathDriverSamba.class.php') as $file){
			if(is_file($base.$file))include_once($base.$file);
		}
		$drivers=array();$loaded=array();
		if(is_dir(PLUGIN_DIR)){
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(PLUGIN_DIR,FilesystemIterator::SKIP_DOTS));
			foreach($iterator as $file){if($file->isFile() && preg_match('/^pathDriver.*\.class\.php$/i',$file->getFilename()))$drivers[]=$file->getPathname();}
		}
		sort($drivers,SORT_STRING);
		foreach($drivers as $file){$class=ucfirst(substr(basename($file),0,-10));include_once($file);if(class_exists($class,false))$loaded[]=$class;}
		return array_values(array_unique($loaded));
	}
}
