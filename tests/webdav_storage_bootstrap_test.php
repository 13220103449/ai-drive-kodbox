<?php
$temp=sys_get_temp_dir().'/aidrive-dav-'.bin2hex(random_bytes(6)).'/';
mkdir($temp.'aiDrive/lib',0777,true);mkdir($temp.'cloud/nested',0777,true);
copy(__DIR__.'/../plugins/aiDrive/lib/StorageDrivers.class.php',$temp.'aiDrive/lib/StorageDrivers.class.php');
file_put_contents($temp.'cloud/nested/pathDriverBaidu.class.php',"<?php class PathDriverBaidu {}\n");
define('PLUGIN_DIR',$temp);
class HttpHeader {public static function method(){return 'GET';}}
class webdavServer {public static function response($result){if($result!==true)throw new RuntimeException('bad DAV result');}}
require __DIR__.'/../plugins/webdav/php/webdavServerKod.class.php';
class TestDav extends webdavServerKod {
	public $davPre='/dav/';public $authenticated=false;
	public function __construct(){}
	public function checkUser(){if(class_exists('PathDriverBaidu',false))throw new RuntimeException('driver loaded before authentication');$this->authenticated=true;}
	public function initPath($prefix){if(!$this->authenticated || !class_exists('PathDriverBaidu',false))throw new RuntimeException('mounted driver missing before path resolution');}
	public function httpGET(){return true;}
}
try{(new TestDav())->run();echo "WebDAV authenticated mounted driver bootstrap test passed\n";}
finally{foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($temp);}
