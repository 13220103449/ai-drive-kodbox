<?php

/** Lightweight, storage-driver independent file version snapshots. */
class AiDriveDataProtection {
	private $table='plugin_ai_drive_version';
	private $maxBytes=104857600;
	public function __construct($store){$store->initTable();}

	public function snapshot($agent,$space,$relative,$absolute,$operation){
		$info=IO::infoFull($absolute);if(!$info || _get($info,'type')!=='file')return false;
		$size=intval(_get($info,'size',0));if($size<0 || $size>$this->maxBytes)return false;
		$content=IO::fileSubstr($absolute,0,$size);if($content===false)return false;
		$folder=DATA_PATH.'ai-drive/versions/'.date('Y/m/');mk_dir($folder);$name=bin2hex(random_bytes(16)).'.bin';$file=$folder.$name;
		if(file_put_contents($file,$content,LOCK_EX)===false)return false;
		$data=array('agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'space'=>$space,'path'=>$relative,'operation'=>$operation,
			'size'=>$size,'sha256'=>hash('sha256',$content),'blobPath'=>str_replace('\\','/',substr($file,strlen(DATA_PATH))),'createTime'=>time());
		Model($this->table)->setDataAuto(false);$id=Model($this->table)->add($data);if(!$id){@unlink($file);return false;}$data['id']=intval($id);return $data;
	}
	public function listVersions($agentID='',$limit=100){
		$model=Model($this->table);if($agentID!=='')$model=$model->where(array('agentID'=>$agentID));$list=$model->order('id desc')->limit(min(max(intval($limit),1),500))->select();return $list?$list:array();
	}
	public function restore($id,$target){
		$item=Model($this->table)->where(array('id'=>intval($id)))->find();if(!$item)return false;$blob=DATA_PATH.ltrim($item['blobPath'],'/');if(!is_file($blob))return false;
		$content=file_get_contents($blob);if($content===false || !hash_equals($item['sha256'],hash('sha256',$content)))return false;
		$before=IO::infoFull($target);$result=$before?IO::setContent($target,$content):IO::mkfile($target,$content,REPEAT_REPLACE);return $result?$item:false;
	}
	public function prune($retentionDays=90){
		$cutoff=time()-max(1,intval($retentionDays))*86400;$rows=Model($this->table)->order('id asc')->limit(5000)->select();$deleted=0;
		foreach($rows?$rows:array() as $item){if(intval($item['createTime'])>=$cutoff)continue;$blob=DATA_PATH.ltrim($item['blobPath'],'/');if(is_file($blob))@unlink($blob);Model($this->table)->where(array('id'=>$item['id']))->delete();$deleted++;}return $deleted;
	}
}
