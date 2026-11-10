<?php

/**
 * v0.9.0 Baidu Net-disk mirror engine.
 *
 * Mirrors locally-stored (ioType=1) content of configured real users — their personal
 * spaces plus the shared 智能体 department space — to Baidu. Strictly one-way: content
 * only ever flows from the server to Baidu. Baidu is a write-only replica for disaster
 * read-through and deduplicated retrieval; nothing is ever downloaded back into the
 * server's storage.
 *
 * Deletion mirrors KodBox recycle semantics: a local delete hides the entry and moves
 * the Baidu copy into a visible per-owner trash folder on Baidu; a local restore moves
 * it back. Nothing on Baidu is ever physically deleted.
 */
class AiDriveMirrorEngine {
	const OWNER_USER=1;
	const OWNER_GROUP=2;
	private $plugin;
	private $store;
	private $table='plugin_ai_drive_mirror';
	private $driver=false;
	private $states=array('pending','synced','failed','deleted');
	private $protectionFolders=array('AI Drive回收站(勿删)','AI Drive历史版本(勿删)');

	public function __construct($plugin){
		$this->plugin=$plugin;
		include_once __DIR__.'/AgentStore.class.php';
		$this->store=new AiDriveAgentStore($plugin);
	}

	/* ---------- configuration ---------- */

	public function config(){
		$config=$this->plugin->getConfig();
		$users=preg_split('/[\s,;，；]+/u',strval(_get($config,'mirrorUsers','')),-1,PREG_SPLIT_NO_EMPTY);
		return array(
			'enabled'=>strval(_get($config,'mirrorEnabled','0'))==='1',
			'users'=>$users,
			'root'=>'/'.trim(strval(_get($config,'mirrorRoot','AI-Drive镜像')),'/'),
			'trashRoot'=>'/'.trim(strval(_get($config,'mirrorTrashRoot','AI-Drive镜像回收站')),'/'),
			'batchSize'=>max(1,intval(_get($config,'mirrorBatchSize',10))),
			'reconcileMinutes'=>max(5,intval(_get($config,'mirrorReconcileMinutes',60))),
			'maxAttempts'=>max(3,intval(_get($config,'mirrorMaxAttempts',5))),
		);
	}
	public function isEnabled(){$c=$this->config();return $c['enabled']&&count($c['users'])>0;}

	/** Resolve configured usernames to existing users; unknown names are reported. */
	public function users(){
		$c=$this->config();$result=array('resolved'=>array(),'unknown'=>array());
		foreach($c['users'] as $name){
			$user=Model('User')->where(array('name'=>strval($name)))->find();
			if($user&&intval($user['status'])===1)$result['resolved'][]=array('userID'=>intval($user['userID']),'name'=>strval($user['name']));
			else $result['unknown'][]=strval($name);
		}
		return $result;
	}

	/** The shared agent department: mirrored whenever mirroring is enabled. */
	public function departmentOwner(){
		$department=$this->store->ensureAgentDepartment();
		return array('ownerType'=>self::OWNER_GROUP,'ownerID'=>intval($department['groupID']),'name'=>'智能体(部门空间)','sourceID'=>intval($department['sourceID']));
	}

	/* ---------- baidu driver ---------- */

	private function driver(){
		if($this->driver!==false) return $this->driver;
		$storages=Model('Storage')->listData();
		foreach($storages?$storages:array() as $item){
			if(strcasecmp(strval(_get($item,'driver','')),'Baidu')!==0) continue;
			// listData hides credentials by design; fetch the full config separately.
			$config=Model('Storage')->getConfig(intval($item['id']));
			if(!is_array($config)||empty($config['access_token'])) throw new RuntimeException('Baidu storage has no access token; re-authorize in storage admin');
			include_once PLUGIN_DIR.'baiduDisk/lib/PathDriverBaidu.class.php';
			if(!class_exists('PathDriverBaidu',false)) throw new RuntimeException('PathDriverBaidu is not available');
			$this->driver=new PathDriverBaidu($config);
			return $this->driver;
		}
		throw new RuntimeException('no Baidu storage driver is configured');
	}

	private function spaceRoot($ownerType,$ownerID){
		$row=Model('Source')->where(array('targetType'=>intval($ownerType),'targetID'=>intval($ownerID),'parentID'=>0,'isDelete'=>0))->find();
		if(!$row) throw new RuntimeException('space root not found (ownerType='.$ownerType.' ownerID='.$ownerID.')');
		return intval($row['sourceID']);
	}

	/* ---------- scanning & reconciliation ---------- */

	private function scanTree($rootID){
		$nodes=array();$queue=array(intval($rootID));$guard=0;
		$nodes[intval($rootID)]=array('rel'=>'','isFolder'=>1,'size'=>0,'modifyTime'=>0,'ioType'=>0);
		while($queue){
			if($guard++>200000) throw new RuntimeException('mirror scan safety limit hit');
			$parentID=array_shift($queue);
			$rows=Model('Source')->where(array('parentID'=>$parentID,'isDelete'=>0))->select();
			foreach($rows?$rows:array() as $row){
				$sourceID=intval($row['sourceID']);
				$name=strval($row['name']);
				// Protection folders are excluded at any depth, subtree included.
				if(in_array($name,$this->protectionFolders,true)) continue;
				$fileID=intval($row['fileID']);$ioType=0;
				if($fileID>0){
					$file=Model('File')->where(array('fileID'=>$fileID))->field('ioType')->find();
					$ioType=$file?intval($file['ioType']):0;
				}
				$nodes[$sourceID]=array('rel'=>ltrim($nodes[$parentID]['rel'].'/'.$name,'/'),'isFolder'=>intval($row['isFolder']),
					'size'=>intval($row['size']),'modifyTime'=>intval($row['modifyTime']),'ioType'=>$ioType);
				if(intval($row['isFolder'])===1)$queue[]=$sourceID;
			}
		}
		unset($nodes[intval($rootID)]);
		return $nodes;
	}

	/** Full reconciliation for one space: enqueue new/changed, retire locally-gone entries. */
	public function reconcileSpace($ownerType,$ownerID,$label){
		$this->store->initTable();$c=$this->config();
		$rootID=$this->spaceRoot($ownerType,$ownerID);
		$tree=$this->scanTree($rootID);
		$now=time();$stats=array('label'=>$label,'queued'=>0,'updated'=>0,'retired'=>0,'verified'=>0,'skippedRemote'=>0,'dirs'=>0,'total'=>count($tree));
		$seen=array();
		foreach($tree as $sourceID=>$node){
			$rel='/'.$node['rel'];
			$seen[$rel]=true;
			$row=Model($this->table)->where(array('ownerType'=>$ownerType,'ownerID'=>$ownerID,'relPath'=>$rel))->find();
			$localOnly=($node['isFolder']===1)||($node['ioType']===1)||($node['ioType']===0&&intval($node['size'])===0);
			if(!$row){
				if(!$localOnly){$stats['skippedRemote']++;continue;} // already lives on a remote storage; nothing to mirror
				$this->upsert($ownerType,$ownerID,$sourceID,$rel,$node,$now);
				if($node['isFolder']===1)$stats['dirs']++;else $stats['queued']++;
				continue;
			}
			if(intval($row['sourceID'])!==$sourceID){$this->retireRow($row,$now);$stats['retired']++;continue;} // path recreated elsewhere
			// Re-verify rows whose upload-time size check was deferred by Baidu metadata delay.
			if(strval($row['state'])==='synced'&&strpos(strval($row['lastError']),'size verify pending')!==false&&$node['isFolder']===0){
				$this->clearBaiduPathMeta(strval($row['baiduPath']));
				try{$info=$this->driver()->fileInfo(strval($row['baiduPath']));$remoteSize=is_array($info)?intval(_get($info,'size',-1)):-1;}catch(Throwable $e){$remoteSize=-1;}
				if($remoteSize===intval($node['size'])){
					Model($this->table)->setDataAuto(false);
					Model($this->table)->where(array('id'=>$row['id']))->save(array('lastError'=>'','updatedAt'=>$now));$stats['verified']++;
				}elseif($remoteSize>0&&$remoteSize!==intval($node['size'])){
					$this->upsert($ownerType,$ownerID,$sourceID,$rel,$node,$now,true);$stats['updated']++;
				}
			}
			if($node['isFolder']===0&&intval($row['modifyTime'])!==intval($node['modifyTime'])&&strval($row['state'])!=='deleted'){
				if(!$localOnly){$stats['skippedRemote']++;continue;}
				$this->upsert($ownerType,$ownerID,$sourceID,$rel,$node,$now,true);
				$stats['updated']++;
			}
		}
		// Locally-gone entries: rows whose relPath is no longer present in the tree.
		$rows=Model($this->table)->where(array('ownerType'=>$ownerType,'ownerID'=>$ownerID))->select();
		foreach($rows?$rows:array() as $row){
			if(strval($row['state'])==='deleted'||isset($seen[strval($row['relPath'])])) continue;
			$this->retireRow($row,$now);$stats['retired']++;
		}
		return $stats;
	}

	/** Queue every configured user space plus the shared department. */
	public function reconcileAll(){
		$c=$this->config();$users=$this->users();$out=array();
		foreach($users['resolved'] as $user){
			$out[$user['name']]=$this->reconcileSpace(self::OWNER_USER,$user['userID'],$user['name']);
		}
		$department=$this->departmentOwner();
		$out[$department['name']]=$this->reconcileSpace(self::OWNER_GROUP,$department['ownerID'],$department['name']);
		if($users['unknown'])$out['unknown']=$users['unknown'];
		return $out;
	}

	private function upsert($ownerType,$ownerID,$sourceID,$rel,$node,$now,$force=false){
		$c=$this->config();
		$baiduPath=$this->mirrorPath($c,$ownerType,$ownerID,$rel);
		$existing=Model($this->table)->where(array('ownerType'=>$ownerType,'ownerID'=>$ownerID,'relPath'=>$rel))->find();
		$data=array('ownerType'=>$ownerType,'ownerID'=>$ownerID,'sourceID'=>$sourceID,'relPath'=>$rel,'isFolder'=>$node['isFolder']===1?1:0,
			'size'=>$node['isFolder']===1?0:intval($node['size']),'modifyTime'=>intval($node['modifyTime']),
			'baiduPath'=>$baiduPath,'state'=>'pending','attempts'=>0,
			'nextAttemptAt'=>$now,'lastError'=>'','updatedAt'=>$now);
		$model=Model($this->table);$model->setDataAuto(false);
		if($existing){
			// Unchanged content never re-queues, whatever its current state, except
			// deleted rows whose local entry has genuinely reappeared.
			$unchanged=intval($existing['size'])===intval($data['size'])&&intval($existing['modifyTime'])===intval($data['modifyTime']);
			if(!$force&&$unchanged&&strval($existing['state'])!=='deleted'&&strval($existing['baiduPath'])===$baiduPath)return;
			$model->where(array('id'=>$existing['id']))->save($data);
		}else{$data['createTime']=$now;$model->add($data);}
	}

	/** Local entry gone (or recreated elsewhere): move the Baidu copy into the mirror trash. */
	private function retireRow($row,$now){
		if(strval($row['state'])==='synced'){
			try{$this->driver()->moveFile(strval($row['baiduPath']),$this->trashPathFor(strval($row['baiduPath'])));}catch(Throwable $e){}
		}
		Model($this->table)->setDataAuto(false);
		Model($this->table)->where(array('id'=>$row['id']))->save(array('state'=>'deleted','lastError'=>'local entry removed; baidu copy moved to mirror trash','updatedAt'=>$now));
	}

	private function mirrorPath($c,$ownerType,$ownerID,$rel){return rtrim($c['root'],'/').'/'.$this->ownerFolder($ownerType,$ownerID).'/'.ltrim($rel,'/');}
	private function trashPathFor($mirrorPath){return str_replace(rtrim($this->config()['root'],'/'),rtrim($this->config()['trashRoot'],'/'),$mirrorPath);}
	private function ownerFolder($ownerType,$ownerID){return $this->ownerName($ownerType,$ownerID);}
	private function ownerName($ownerType,$ownerID){
		if(intval($ownerType)===self::OWNER_USER){$u=Model('User')->getInfoSimple($ownerID);return strval(_get($u,'name','user'.$ownerID));}
		$g=Model('Group')->getInfo($ownerID);return strval(_get($g,'name','group'.$ownerID));
	}

	/* ---------- queue processing ---------- */

	/** Consume pending mirror tasks (dirs first come out in path order from the scan). */
	public function processPending($limit=10){
		$this->store->initTable();$c=$this->config();
		if(!$c['enabled']) return array('processed'=>0,'reason'=>'mirror disabled');
		// A worker lock keeps cron and manual runs from racing on the same rows.
		$lockDir=DATA_PATH.'ai-drive/mirror/';if(!is_dir($lockDir)&&!mkdir($lockDir,0700,true)&&!is_dir($lockDir)) throw new RuntimeException('cannot prepare mirror lock directory');
		$lock=fopen($lockDir.'worker.lock','c+');
		if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);return array('processed'=>0,'reason'=>'another mirror worker is running');}
		try{$out=$this->processPendingLocked($limit,$c);}finally{flock($lock,LOCK_UN);fclose($lock);}
		return $out;
	}

	private function processPendingLocked($limit,$c){
		$now=time();$done=0;$failed=0;$results=array();
		$rows=Model($this->table)->where(array('state'=>'pending'))->limit(max(1,intval($limit)))->select();
		foreach($rows?$rows:array() as $row){
			$baiduPath=strval($row['baiduPath']);
			try{
				if(intval($row['isFolder'])===1){
					$this->driver()->mkdir($baiduPath);
					$this->markSynced($row,$now);$done++;
				}else{
					$local=$this->stageLocal($row);
					if(!$local) throw new RuntimeException('local source blob is missing');
					$uploaded=$this->driver()->upload($baiduPath,$local,false,REPEAT_REPLACE);
					@unlink($local);
					if(!$uploaded) throw new RuntimeException('baidu upload failed');
					$this->clearBaiduPathMeta($baiduPath);
					// simple mode never queries remote metadata (size stays 0); use full mode.
					$info=$this->driver()->fileInfo($baiduPath);
					$remoteSize=is_array($info)?intval(_get($info,'size',-1)):-1;
					if($remoteSize!==-1&&$remoteSize!==intval($row['size'])&&$remoteSize>0) throw new RuntimeException('baidu size mismatch: remote '.$remoteSize.' vs local '.$row['size']);
					$note=($remoteSize<=0&&intval($row['size'])>0)?'size verify pending (baidu metadata delay)':'';
					$this->markSynced($row,$now,$note);$done++;
				}
			}catch(Throwable $error){
				$attempts=intval($row['attempts'])+1;
				$state=$attempts<$c['maxAttempts']?'pending':'failed';
				Model($this->table)->setDataAuto(false);
				Model($this->table)->where(array('id'=>$row['id']))->save(array('state'=>$state,'attempts'=>$attempts,
					'nextAttemptAt'=>$now+min(3600,60*pow(2,min($attempts,6))),'lastError'=>mb_substr($error->getMessage(),0,480),'updatedAt'=>$now));
				$failed++;
			}
			$results[]=strval($row['relPath']);
			if(count($results)>=max(1,intval($limit))) break;
		}
		return array('processed'=>count($results),'synced'=>$done,'failed'=>$failed,'items'=>$results);
	}

	/** Copy the local blob behind a mirror row to a verified temp file. */
	private function stageLocal($row){
		$path=KodIO::make(intval($row['sourceID']));
		$info=IO::infoFull($path);
		if(!$info||_get($info,'type')!=='file') return false;
		$size=intval($info['size']);
		$file=tempnam(defined('TEMP_FILES')?TEMP_FILES:sys_get_temp_dir().'/','aidrive-mirror-');
		if($file===false) return false;
		$handle=fopen($file,'wb');if(!$handle){@unlink($file);return false;}
		try{
			for($offset=0;$offset<$size;$offset+=1048576){
				$length=min(1048576,$size-$offset);
				$chunk=IO::fileSubstr($path,$offset,$length);
				if($chunk===false||strlen($chunk)!==$length||fwrite($handle,$chunk)!==$length) throw new RuntimeException('local blob read failed');
			}
		}catch(Throwable $error){fclose($handle);@unlink($file);return false;}
		fclose($handle);
		if(filesize($file)!==$size){@unlink($file);return false;}
		return $file;
	}

	private function markSynced($row,$now,$note=''){
		Model($this->table)->setDataAuto(false);
		Model($this->table)->where(array('id'=>$row['id']))->save(array('state'=>'synced','attempts'=>0,'lastError'=>$note,'syncedAt'=>$now,'updatedAt'=>$now));
	}

	/** Invalidate the Baidu driver's per-folder metadata cache after a write. */
	private function clearBaiduPathMeta($baiduPath){
		$dir='/'.trim(strval(preg_replace('#/[^/]+$#','',$baiduPath)),'/');
		try{Cache::remove('baidu_path_'.md5($dir));}catch(Throwable $error){}
	}

	/* ---------- disaster read-through (stream only, never persisted) ---------- */

	/** Verify a mirror copy exists; returns its Baidu path (never downloads it server-side). */
	public function findSynced($ownerType,$ownerID,$relPath){
		$this->store->initTable();
		$rel='/'.ltrim(str_replace('\\','/',strval($relPath)),'/');
		$row=Model($this->table)->where(array('ownerType'=>intval($ownerType),'ownerID'=>intval($ownerID),'relPath'=>$rel,'state'=>'synced'))->find();
		if(!$row||intval($row['isFolder'])===1) return false;
		try{$info=$this->driver()->fileInfo(strval($row['baiduPath']),true);}catch(Throwable $e){return false;}
		if(!is_array($info)) return false;
		return array('baiduPath'=>strval($row['baiduPath']),'size'=>intval(_get($info,'size',0)),'name'=>strval(_get($info,'name','')),'state'=>strval($row['state']));
	}

	/** Stream a mirror copy straight to the HTTP client without touching server storage. */
	public function streamFromMirror($baiduPath,$download,$name){
		$this->driver()->fileOut($baiduPath,$download,$name);
	}

	/* ---------- stats & admin ---------- */

	public function stats(){
		$this->store->initTable();$result=array('enabled'=>$this->isEnabled(),'byState'=>array(),'owners'=>array(),'lastError'=>'');
		foreach($this->states as $state){
			$count=0;foreach(Model($this->table)->where(array('state'=>$state))->select() as $row)$count++;
			$result['byState'][$state]=$count;
		}
		$c=$this->config();
		foreach($c['users'] as $name){
			$user=Model('User')->where(array('name'=>strval($name)))->find();
			$result['owners'][]=array('type'=>'user','name'=>strval($name),'exists'=>!!$user,'ownerID'=>$user?intval($user['userID']):0);
		}
		if($c['enabled']){
			$department=$this->departmentOwner();
			$result['owners'][]=array('type'=>'department','name'=>$department['name'],'exists'=>true,'ownerID'=>$department['ownerID']);
		}
		$failed=Model($this->table)->where(array('state'=>'failed'))->order('id desc')->limit(1)->find();
		if($failed)$result['lastError']=strval($failed['lastError']);
		return $result;
	}

	public function retryFailed(){
		$this->store->initTable();
		Model($this->table)->setDataAuto(false);
		$n=Model($this->table)->where(array('state'=>'failed'))->save(array('state'=>'pending','attempts'=>0,'nextAttemptAt'=>time(),'updatedAt'=>time()));
		return array('requeued'=>intval($n));
	}
}
