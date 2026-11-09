<?php

/** Private application snapshots, including SQLite WAL via its online backup API.
 * Mounted cloud/WebDAV bytes are explicitly outside this archive's scope.
 */
class AiDriveApplicationBackup {
	private $root;
	private $directory;
	public function __construct($root,$directory){
		$resolved=realpath($root);if(!$resolved||!is_dir($resolved))throw new RuntimeException('Invalid application root');$this->root=rtrim($resolved,'/\\').DIRECTORY_SEPARATOR;
		if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create private backup directory');
		$this->directory=rtrim(realpath($directory),'/\\').DIRECTORY_SEPARATOR;
		if(!$this->root||strpos(strtolower($this->directory),strtolower($this->root))===0)throw new RuntimeException('Backups must be outside the website');
	}
	private function isExcluded($name){return preg_match('#^data/(temp|update-backup|ai-drive/backups)(/|$)#',$name)||preg_match('#(^|/)(\.git|node_modules)(/|$)#',$name)||$name==='.user.ini'||$name==='data/ai-drive/maintenance.flag';}
	public function listBackups(){$list=array();foreach(glob($this->directory.'application-*.zip') as $file){$list[]=array('file'=>basename($file),'size'=>filesize($file),'createdAt'=>filemtime($file),'sha256'=>is_file($file.'.sha256')?trim(file_get_contents($file.'.sha256')):'','scope'=>'Application and local persistent files; mounted contents excluded');}usort($list,function($a,$b){return $b['createdAt']-$a['createdAt'];});return array_slice($list,0,30);}
	private function snapshotDatabase($source,$target){
		$db=new SQLite3($source,SQLITE3_OPEN_READONLY);$db->busyTimeout(10000);$copy=new SQLite3($target);try{if(!$db->backup($copy)||$copy->querySingle('PRAGMA integrity_check')!=='ok')throw new RuntimeException('SQLite snapshot integrity check failed');}finally{$copy->close();$db->close();}chmod($target,0600);
	}
	public function create(){
		if(!class_exists('SQLite3')||!class_exists('ZipArchive'))throw new RuntimeException('SQLite3 and zip extensions are required');
		$lock=fopen($this->directory.'.backup.lock','c+');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('A backup is already running');
		$id='application-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4));$work=$this->directory.$id.'.stage';mkdir($work,0700);$file=$this->directory.$id.'.zip';$zip=new ZipArchive();
		try{
			$databases=array();foreach(glob($this->root.'data/system/*.php') as $candidate){$fp=fopen($candidate,'rb');$header=fread($fp,16);fclose($fp);if($header==="SQLite format 3\0")$databases[substr($candidate,strlen($this->root))]=$candidate;}
			if(count($databases)!==1)throw new RuntimeException('This backup currently supports exactly one SQLite database; MySQL requires a separate consistent dump');
			if($zip->open($file,ZipArchive::CREATE|ZipArchive::EXCL)!==true)throw new RuntimeException('Cannot create application archive');
			$manifest=array('format'=>'ai-drive-application-backup-v1','createdAt'=>time(),'scope'=>'Application code, configuration, consistent SQLite database and local persistent files; excludes mounted storage content','excluded'=>array('mounted Baidu/WebDAV/S3 file content','temporary caches','previous backup archives','BaoTa .user.ini and Nginx/TLS configuration'),'files'=>array());
			foreach($databases as $name=>$source){$stage=$work.'/database.php';$this->snapshotDatabase($source,$stage);$manifest['files'][$name]=array('size'=>filesize($stage),'sha256'=>hash_file('sha256',$stage));$zip->addFile($stage,$name);}
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS));$bytes=0;
			foreach($iterator as $entry){$name=str_replace('\\','/',substr($entry->getPathname(),strlen($this->root)));if($this->isExcluded($name)||isset($databases[$name]))continue;
				if($entry->isLink())throw new RuntimeException('Symlink found; backup refuses to follow external content');if(!$entry->isFile())continue;
				$skip=false;foreach($databases as $dbName=>$dbSource)if($name===$dbName.'-wal'||$name===$dbName.'-shm')$skip=true;if($skip)continue;
				$bytes+=$entry->getSize();if($bytes>2147483648||disk_free_space($this->directory)<$entry->getSize()+268435456)throw new RuntimeException('Backup exceeds private staging capacity');
				$stage=$work.'/files/'.$name;if(!is_dir(dirname($stage)))mkdir(dirname($stage),0700,true);$before=hash_file('sha256',$entry->getPathname());if(!copy($entry->getPathname(),$stage)||hash_file('sha256',$stage)!==$before||hash_file('sha256',$entry->getPathname())!==$before)throw new RuntimeException('Local content changed during backup; retry during maintenance');
				$manifest['files'][$name]=array('size'=>filesize($stage),'sha256'=>$before);$zip->addFile($stage,$name);
			}
			// Reject a snapshot if any database mutation happened while files were copied.
			foreach($databases as $name=>$source){$check=$work.'/database-after.php';$this->snapshotDatabase($source,$check);if(hash_file('sha256',$check)!==$manifest['files'][$name]['sha256'])throw new RuntimeException('Database changed during backup; retry during maintenance');}
			$zip->addFromString('backup-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));if(!$zip->close())throw new RuntimeException('Cannot finalize backup');chmod($file,0600);
			$this->verify($file);$result=array('file'=>basename($file),'size'=>filesize($file),'sha256'=>hash_file('sha256',$file),'files'=>count($manifest['files']),'scope'=>$manifest['scope']);file_put_contents($file.'.sha256',$result['sha256']."\n",LOCK_EX);chmod($file.'.sha256',0600);return $result;
		}catch(Throwable $e){try{@$zip->close();}catch(Throwable $ignored){}if(is_file($file)){chmod($file,0600);rename($file,$file.'.incomplete');}throw $e;}finally{$this->removeStage($work);flock($lock,LOCK_UN);fclose($lock);}
	}
	private function safeName($name){return $name!==''&&strpos($name,"\0")===false&&strpos($name,'\\')===false&&!preg_match('#(^/|^[A-Za-z]:|(^|/)\.\.(/|$))#',$name);}
	public function verify($file){
		$zip=new ZipArchive();if($zip->open($file)!==true)throw new RuntimeException('Cannot open backup');
		try{$manifest=json_decode($zip->getFromName('backup-manifest.json'),true);if(!is_array($manifest)||!isset($manifest['format'])||$manifest['format']!=='ai-drive-application-backup-v1'||empty($manifest['files']))throw new RuntimeException('Invalid backup manifest');
			if($zip->numFiles!==count($manifest['files'])+1)throw new RuntimeException('Unexpected archive entries');
			foreach($manifest['files'] as $name=>$expected){if(!$this->safeName($name))throw new RuntimeException('Unsafe archive path');$stream=$zip->getStream($name);if(!$stream)throw new RuntimeException('Missing archive file');$hash=hash_init('sha256');$size=0;while(!feof($stream)){$chunk=fread($stream,1048576);if($chunk===false){fclose($stream);throw new RuntimeException('Cannot read archive content');}$size+=strlen($chunk);hash_update($hash,$chunk);}fclose($stream);if($size!==$expected['size']||!hash_equals($expected['sha256'],hash_final($hash)))throw new RuntimeException('Backup content checksum mismatch');}
			return $manifest;
		}finally{$zip->close();}
	}
	/** Restore into a NEW private directory for rehearsal; never overwrites live data. */
	public function stageRestore($file,$target){
		$manifest=$this->verify($file);$parent=realpath(dirname($target));if(!$parent||strpos(strtolower($parent.DIRECTORY_SEPARATOR),strtolower($this->root))===0||file_exists($target))throw new RuntimeException('Restore requires a new private directory outside the website');
		mkdir($target,0700);$zip=new ZipArchive();$zip->open($file);try{foreach($manifest['files'] as $name=>$expected){$path=$target.'/'.$name;if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);$in=$zip->getStream($name);$out=fopen($path,'xb');if(!$in||!$out)throw new RuntimeException('Cannot stage restored file');stream_copy_to_stream($in,$out);fclose($in);fclose($out);chmod($path,0600);if(hash_file('sha256',$path)!==$expected['sha256'])throw new RuntimeException('Staged restore verification failed');}return array('staged'=>true,'files'=>count($manifest['files']),'scope'=>$manifest['scope']);}finally{$zip->close();}
	}
	private function removeStage($directory){if(!is_dir($directory))return;$entries=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($entries as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($directory);}
}
