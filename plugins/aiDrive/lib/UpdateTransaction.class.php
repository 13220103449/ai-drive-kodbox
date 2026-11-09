<?php

/** Reversible CODE-only replacement. User databases, accounts and blobs are untouched. */
class AiDriveUpdateTransaction {
	private $backup;
	private $entries=array();
	public function __construct($backup){$this->backup=rtrim($backup,'/\\').'/';if(!is_dir($this->backup)&&!mkdir($this->backup,0700,true))throw new RuntimeException('Cannot prepare rollback snapshot');}
	public function replaceTree($source,$target,$label){
		if(!preg_match('/^[a-zA-Z0-9_-]+$/',$label)||!is_dir($source))throw new RuntimeException('Invalid update source');
		$old=$this->backup.$label.'/';$existed=is_dir($target);if($existed)$this->copy($target,$old);
		$this->entries[]=array('label'=>$label,'target'=>$target,'existed'=>$existed);
		file_put_contents($this->backup.'transaction.json',json_encode($this->entries),LOCK_EX);
		$this->copy($source,$target);
	}
	public function rollback(){
		foreach(array_reverse($this->entries) as $entry){$target=rtrim($entry['target'],'/\\').'/';$old=$this->backup.$entry['label'].'/';
			// Only files inside the explicitly registered code tree may be removed.
			$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
			foreach($iterator as $file){$relative=substr($file->getPathname(),strlen($target));if(!$file->isDir()&&!is_file($old.$relative))unlink($file->getPathname());}
			if($entry['existed'])$this->copy($old,$target);
		}
	}
	private function copy($source,$target){
		$source=rtrim($source,'/\\').'/';$target=rtrim($target,'/\\').'/';if(!is_dir($target)&&!mkdir($target,0750,true))throw new RuntimeException('Cannot create code directory');
		foreach(scandir($source) as $name){if($name==='.'||$name==='..')continue;$from=$source.$name;$to=$target.$name;if(is_link($from)||is_link($to))throw new RuntimeException('Update refuses symlink code paths');if(is_dir($from)){$this->copy($from,$to);continue;}
			$temp=$to.'.update-'.bin2hex(random_bytes(4));if(!copy($from,$temp)||hash_file('sha256',$from)!==hash_file('sha256',$temp)){@unlink($temp);throw new RuntimeException('Update code copy verification failed');}chmod($temp,is_file($to)?fileperms($to)&0777:0644);if(!rename($temp,$to)){@unlink($temp);throw new RuntimeException('Cannot atomically replace code');}if(function_exists('opcache_invalidate'))@opcache_invalidate($to,true);
		}
	}
}
