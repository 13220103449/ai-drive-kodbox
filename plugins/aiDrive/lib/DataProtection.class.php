<?php

/** Storage-driver independent version and soft-delete metadata. */
class AiDriveDataProtection {
	private $table='plugin_ai_drive_version';
	private $trashTable='plugin_ai_drive_trash';
	private $maxHashBytes=104857600;
	public function __construct($store){$store->initTable();}

	/** Copy the current file to the mounted storage's visible history folder. */
	public function snapshot($agent,$space,$relative,$absolute,$operation,$remoteFolder=''){
		$info=IO::infoFull($absolute);if(!$info || _get($info,'type')!=='file')return false;
		$size=max(0,intval(_get($info,'size',0)));$sha256='';$blobPath='';$stored='';
		if($remoteFolder){
			$stored=IO::copy($absolute,$remoteFolder,REPEAT_REPLACE);if(!$stored)return false;
			$original=preg_replace('/[\\\\\/:*?"<>|\r\n]+/u','_',strval(_get($info,'name','file')));$original=mb_substr($original,0,160);
			$name='v-'.date('Ymd-His').'-'.bin2hex(random_bytes(4)).'-'.$original;
			$renamed=IO::rename($stored,$name);if(!$renamed){IO::remove($stored,false);return false;}$stored=$renamed;
			$blobPath='io:'.$stored;
			if($size<=$this->maxHashBytes){$content=IO::fileSubstr($absolute,0,$size);if($content!==false)$sha256=hash('sha256',$content);}
		}else{
			if($size>$this->maxHashBytes)return false;$content=IO::fileSubstr($absolute,0,$size);if($content===false)return false;
			$folder=DATA_PATH.'ai-drive/versions/'.date('Y/m/');mk_dir($folder);$name=bin2hex(random_bytes(16)).'.bin';$file=$folder.$name;
			if(file_put_contents($file,$content,LOCK_EX)===false)return false;$sha256=hash('sha256',$content);
			$blobPath=str_replace('\\','/',substr($file,strlen(DATA_PATH)));
		}
		$data=array('agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'space'=>$space,'path'=>$relative,'operation'=>$operation,
			'size'=>$size,'sha256'=>$sha256,'blobPath'=>$blobPath,'createTime'=>time());
		Model($this->table)->setDataAuto(false);$id=Model($this->table)->add($data);
		if(!$id){if($stored)IO::remove($stored,false);elseif(isset($file))@unlink($file);return false;}$data['id']=intval($id);return $data;
	}
	public function listVersions($agentID='',$limit=100,$path=''){
		$where=array();if($agentID!=='')$where['agentID']=$agentID;if($path!=='')$where['path']=$path;
		$model=Model($this->table);if($where)$model=$model->where($where);
		$list=$model->order('id desc')->limit(min(max(intval($limit),1),500))->select();return $list?$list:array();
	}
	public function restore($id,$target){
		$item=Model($this->table)->where(array('id'=>intval($id)))->find();if(!$item)return false;
		if(strpos($item['blobPath'],'io:')===0)return $this->restoreRemote(substr($item['blobPath'],3),$target,$item);
		$blob=DATA_PATH.ltrim($item['blobPath'],'/');if(!is_file($blob))return false;
		$content=file_get_contents($blob);if($content===false || ($item['sha256']&&!hash_equals($item['sha256'],hash('sha256',$content))))return false;
		$before=IO::infoFull($target);$result=$before?IO::setContent($target,$content):IO::mkfile($target,$content,REPEAT_REPLACE);return $result?$item:false;
	}
	private function restoreRemote($blob,$target,$item){
		$source=IO::infoFull($blob);if(!$source || _get($source,'type')!=='file')return false;
		$parent=IO::pathFather($target);if(!$parent || !IO::infoFull($parent))return false;
		$targetInfo=IO::infoFull($target);$targetName=$targetInfo?_get($targetInfo,'name',''):basename(str_replace('\\','/',$target));
		$copy=IO::copy($blob,$parent,REPEAT_REPLACE);if(!$copy)return false;$copyInfo=IO::infoFull($copy);if(!$copyInfo)return false;
		if($targetInfo && !IO::remove($target,false) && IO::infoFull($target)){IO::remove($copy,false);return false;}
		if(_get($copyInfo,'name','')!==$targetName){$renamed=IO::rename($copy,$targetName);if(!$renamed){IO::remove($copy,false);return false;}$copy=$renamed;}
		$restored=IO::infoFull($copy);if(!$restored || intval(_get($restored,'size',0))!==intval($item['size']))return false;return $item;
	}

	public function recordTrash($agent,$space,$relative,$storagePath,$info){
		$data=array('agentID'=>$agent['agentID'],'userID'=>intval($agent['userID']),'space'=>$space,'originalPath'=>$relative,
			'storagePath'=>$storagePath,'itemType'=>_get($info,'type',''),'size'=>intval(_get($info,'size',0)),'deletedAt'=>time());
		Model($this->trashTable)->setDataAuto(false);$id=Model($this->trashTable)->add($data);if(!$id)return false;$data['id']=intval($id);return $data;
	}
	public function listTrash($agentID,$limit=100){
		$list=Model($this->trashTable)->where(array('agentID'=>$agentID))->order('id desc')->limit(min(max(intval($limit),1),500))->select();return $list?$list:array();
	}
	public function trashItem($id,$agentID){return Model($this->trashTable)->where(array('id'=>intval($id),'agentID'=>$agentID))->find();}
	public function forgetTrash($id,$agentID){return !!Model($this->trashTable)->where(array('id'=>intval($id),'agentID'=>$agentID))->delete();}

	public function prune($retentionDays=90){
		$cutoff=time()-max(1,intval($retentionDays))*86400;$rows=Model($this->table)->order('id asc')->limit(5000)->select();$deleted=0;
		foreach($rows?$rows:array() as $item){if(intval($item['createTime'])>=$cutoff || strpos($item['blobPath'],'io:')===0)continue;
			$blob=DATA_PATH.ltrim($item['blobPath'],'/');if(is_file($blob))@unlink($blob);Model($this->table)->where(array('id'=>$item['id']))->delete();$deleted++;}return $deleted;
	}
}
