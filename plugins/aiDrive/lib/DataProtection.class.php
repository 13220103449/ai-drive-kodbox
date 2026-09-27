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
		if(intval(_get($source,'size',-1))!==intval($item['size']))return false;
		// A KodBox file path is an opaque source ID, not a filesystem pathname.
		// Do not copy to pathFather(sourceID), remove the live source and rename:
		// deleting that ID invalidates the destination before rename can succeed.
		$before=IO::infoFull($target);if($before && _get($before,'type')!=='file')return false;
		$destination=$target;
		if($before && intval(_get($before,'parentID',0))>0){
			$destination=rtrim(KodIO::make(intval($before['parentID'])),'/').'/'.$before['name'];
		}
		$stage=$this->stageContent($blob,intval($item['size']),strval(_get($item,'sha256','')));if(!$stage)return false;
		$rollback=false;
		try{
			if($before){$rollback=$this->stageContent($target,intval($before['size']),'');if(!$rollback)return false;}
			try{
				$result=IO::upload($destination,$stage['file'],false,REPEAT_REPLACE);
				if($result && $this->matchesContent($result,intval($item['size']),$stage['sha256']))return $item;
			}catch(Exception $error){error_log('AI Drive version restore write failed: '.$error->getMessage());}
			if($rollback){
				$recovered=false;
				try{$saved=IO::upload($destination,$rollback['file'],false,REPEAT_REPLACE);$recovered=$saved && $this->matchesContent($saved,intval($before['size']),$rollback['sha256']);}catch(Exception $error){error_log('AI Drive version rollback write failed: '.$error->getMessage());}
				if(!$recovered){
					// Keep the local recovery copy if the remote backend is unavailable.
					error_log('AI Drive restore rollback failed; recovery copy retained: '.$rollback['file']);$rollback=false;
				}
			}
			return false;
		}finally{@unlink($stage['file']);if($rollback)@unlink($rollback['file']);}
	}
	private function stageContent($path,$size,$expectedHash){
		$folder=defined('TEMP_FILES')?TEMP_FILES:sys_get_temp_dir().'/';mk_dir($folder);
		$file=tempnam($folder,'aidrive-restore-');if($file===false)return false;@chmod($file,0600);
		$handle=fopen($file,'wb');if(!$handle){@unlink($file);return false;}
		$hash=hash_init('sha256');$ok=true;
		try{for($offset=0;$offset<$size;$offset+=$length){
			$length=min(1024*1024,$size-$offset);$chunk=IO::fileSubstr($path,$offset,$length);
			if($chunk===false || strlen($chunk)!==$length || fwrite($handle,$chunk)!==$length){$ok=false;break;}hash_update($hash,$chunk);
		}}finally{fclose($handle);}
		$actual=hash_final($hash);if(!$ok || ($expectedHash!=='' && !hash_equals($expectedHash,$actual))){@unlink($file);return false;}
		return array('file'=>$file,'sha256'=>$actual);
	}
	private function matchesContent($path,$size,$sha256){
		$info=IO::infoFull($path);if(!$info || _get($info,'type')!=='file' || intval(_get($info,'size',-1))!==$size)return false;
		$check=$this->stageContent($path,$size,$sha256);if(!$check)return false;@unlink($check['file']);return true;
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
