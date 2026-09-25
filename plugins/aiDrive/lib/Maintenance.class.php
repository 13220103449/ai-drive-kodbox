<?php

class AiDriveMaintenance {
	private $store;
	public function __construct($store){$this->store=$store;$store->initTable();}
	public function health(){
		$versionRows=Model('plugin_ai_drive_version')->order('id desc')->limit(1000)->select();$missing=0;foreach($versionRows?$versionRows:array() as $row){if($row['blobPath']&&!is_file(DATA_PATH.ltrim($row['blobPath'],'/')))$missing++;}
		return array('dataWritable'=>path_writeable(DATA_PATH),'pluginWritable'=>path_writeable(PLUGIN_DIR.'aiDrive/'),'diskFree'=>intval(@disk_free_space(DATA_PATH)),
			'diskTotal'=>intval(@disk_total_space(DATA_PATH)),'missingVersionBlobs'=>$missing,'checkedVersions'=>count($versionRows?$versionRows:array()),'time'=>time());
	}
	public function backup(){
		$folder=DATA_PATH.'ai-drive/backups/';mk_dir($folder);$file=$folder.'metadata-'.date('Ymd-His').'.json';$payload=array('format'=>'ai-drive-backup-v1','createdAt'=>time(),'health'=>$this->health(),
			'agents'=>$this->store->listAgents(),'audit'=>$this->store->listAudit(array('limit'=>500)),'versions'=>Model('plugin_ai_drive_version')->order('id desc')->limit(5000)->select(),
			'updates'=>Model('plugin_ai_drive_update')->order('id desc')->limit(100)->select());
		$json=json_encode($payload,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(file_put_contents($file,$json,LOCK_EX)===false)throw new Exception('Cannot write backup');
		return array('file'=>str_replace('\\','/',substr($file,strlen(DATA_PATH))),'size'=>filesize($file),'sha256'=>hash_file('sha256',$file),'createdAt'=>$payload['createdAt']);
	}
	public function listBackups(){
		$folder=DATA_PATH.'ai-drive/backups/';if(!is_dir($folder))return array();$result=array();foreach(glob($folder.'metadata-*.json') as $file)$result[]=array('file'=>basename($file),'size'=>filesize($file),'modifyTime'=>filemtime($file),'sha256'=>hash_file('sha256',$file));usort($result,function($a,$b){return $b['modifyTime']-$a['modifyTime'];});return array_slice($result,0,100);
	}
	public function maybeDaily(){
		$marker=DATA_PATH.'ai-drive/backups/.last-daily';$last=is_file($marker)?intval(file_get_contents($marker)):0;if($last>time()-86400)return false;$result=$this->backup();file_put_contents($marker,strval(time()),LOCK_EX);return $result;
	}
}
