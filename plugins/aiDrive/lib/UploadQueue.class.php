<?php

/** Durable, bounded upload jobs. No credentials are stored in job metadata. */
class AiDriveUploadQueue {
	private $directory;
	private $clock;
	const MAX_BYTES=20971520;
	public function __construct($directory,$clock=null){
		$this->directory=rtrim($directory,'/\\').'/';$this->clock=$clock?:function(){return time();};
		if(!is_dir($this->directory) && !mkdir($this->directory,0700,true))throw new RuntimeException('Cannot prepare upload queue');
	}
	private function now(){return call_user_func($this->clock);}
	private function path($id){if(!preg_match('/^[a-f0-9]{32}$/',$id))throw new InvalidArgumentException('Invalid jobID');return $this->directory.$id.'.json';}
	private function save($job){$target=$this->path($job['jobID']);$temp=$target.'.'.bin2hex(random_bytes(4)).'.tmp';if(file_put_contents($temp,json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),LOCK_EX)===false)throw new RuntimeException('Cannot persist upload state');chmod($temp,0600);if(!rename($temp,$target)){@unlink($temp);throw new RuntimeException('Cannot commit upload state');}}
	private function load($id){$file=$this->path($id);if(!is_file($file))return false;$job=json_decode(file_get_contents($file),true);if(!is_array($job)||$job['jobID']!==$id)throw new RuntimeException('Invalid upload state');return $job;}
	private function publicJob($job){unset($job['localFile'],$job['agentID'],$job['userID']);return $job;}
	public function enqueue($agent,$space,$path,$file,$requestID=''){
		$size=filesize($file);if($size===false||$size>self::MAX_BYTES)throw new InvalidArgumentException('Queued upload limit is 20 MiB');
		$sha=hash_file('sha256',$file);$id=$requestID!==''?substr(hash('sha256',$agent['agentID']."\0".$requestID),0,32):bin2hex(random_bytes(16));
		$submission=fopen($this->directory.'.submit.lock','c+');if(!$submission||!flock($submission,LOCK_EX))throw new RuntimeException('Cannot lock upload submission');
		$lock=fopen($this->path($id).'.lock','c+');if(!$lock||!flock($lock,LOCK_EX)){flock($submission,LOCK_UN);fclose($submission);throw new RuntimeException('Cannot lock upload job');}
		try{
			$existing=$this->load($id);if($existing){if($existing['space']!==$space||$existing['path']!==$path||$existing['sha256']!==$sha)throw new InvalidArgumentException('requestID already belongs to different upload');return $this->publicJob($existing);}
			$pending=0;foreach(glob($this->directory.'*.json') as $row){$item=json_decode(file_get_contents($row),true);if($item&&in_array($item['status'],array('queued','uploading','retry_wait','failed'),true)){$pending+=intval($item['size']);if($item['status']!=='failed' && $item['space']===$space && $item['path']===$path && ($space==='department'||$item['agentID']===$agent['agentID']))throw new RuntimeException('An upload to this target is already pending');}}
			if($pending+$size>1073741824 || disk_free_space($this->directory)<$size+268435456)throw new RuntimeException('Upload staging capacity exceeded');
			$local=$this->directory.$id.'.bin';if(!copy($file,$local))throw new RuntimeException('Cannot stage upload');chmod($local,0600);
			$job=array('jobID'=>$id,'agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'space'=>$space,'path'=>$path,'localFile'=>$local,'size'=>$size,'sha256'=>$sha,'status'=>'queued','attempts'=>0,'maxAttempts'=>5,'nextAttemptAt'=>$this->now(),'createdAt'=>$this->now(),'updatedAt'=>$this->now(),'error'=>'');
			$this->save($job);return $this->publicJob($job);
		}finally{flock($lock,LOCK_UN);fclose($lock);flock($submission,LOCK_UN);fclose($submission);}
	}
	public function status($owner,$id){$job=$this->load($id);return $job && hash_equals($job['agentID'],$owner)?$this->publicJob($job):false;}
	public function listJobs($owner=null){$jobs=array();foreach(glob($this->directory.'*.json') as $file){$job=json_decode(file_get_contents($file),true);if($job&&($owner===null||$job['agentID']===$owner))$jobs[]=$this->publicJob($job);}usort($jobs,function($a,$b){return $b['createdAt']-$a['createdAt'];});return array_slice($jobs,0,100);}
	/** A single worker lock also serializes jobs targeting the same remote file. */
	public function runOne($callback){
		$worker=fopen($this->directory.'.worker.lock','c+');if(!$worker||!flock($worker,LOCK_EX|LOCK_NB)){if($worker)fclose($worker);return false;}
		try{foreach(glob($this->directory.'*.json') as $file){$job=json_decode(file_get_contents($file),true);if(!$job||!in_array($job['status'],array('queued','retry_wait','uploading'),true)||$job['nextAttemptAt']>$this->now())continue;
			// An interrupted final attempt must not retry without a bound.
			if($job['attempts']>=$job['maxAttempts']){$job['status']='failed';$job['error']='retry limit reached; staged content retained';$this->save($job);continue;}
			$job['status']='uploading';$job['attempts']++;$job['updatedAt']=$this->now();$this->save($job);
			try{
				if(!is_file($job['localFile'])||filesize($job['localFile'])!==$job['size']||hash_file('sha256',$job['localFile'])!==$job['sha256'])throw new RuntimeException('staged upload integrity mismatch');
				$result=call_user_func($callback,$job);
				if(!is_array($result)||intval(isset($result['size'])?$result['size']:-1)!==$job['size']||!isset($result['sha256'])||!hash_equals($job['sha256'],$result['sha256']))throw new RuntimeException('remote upload verification failed');
				$job['status']='completed';$job['error']='';$job['completedAt']=$this->now();$job['updatedAt']=$this->now();$this->save($job);
				// Only verified disposable staging bytes are removed, never remote files.
				@unlink($job['localFile']);
			}catch(Throwable $error){$job['status']=$job['attempts']<$job['maxAttempts']?'retry_wait':'failed';$job['error']='Upload or integrity verification failed; staged content retained';$job['nextAttemptAt']=$this->now()+min(3600,60*pow(2,$job['attempts']-1));$job['updatedAt']=$this->now();$this->save($job);}
			return $this->publicJob($job);
		}return false;}finally{flock($worker,LOCK_UN);fclose($worker);}
	}
}
