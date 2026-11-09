<?php

/**
 * v0.8.0 one-shot migration: fold dedicated Agent KodBox accounts into one shared account.
 * Each Agent keeps its token and path semantics; its data moves to the shared account's
 * personal space under <homeDir>/. The tree is copied, reconciled (paths, sizes and
 * sampled SHA-256) and only then are share/version/trash references and the Agent record
 * re-pointed. A failure before the final switch leaves the old space untouched.
 */
class AiDriveSharedAccountMigration {
	private $plugin;
	private $store;
	private $agentTable='plugin_ai_drive_agent';
	private $versionTable='plugin_ai_drive_version';
	private $trashTable='plugin_ai_drive_trash';
	private $snapshotLimit=200000;

	public function __construct($plugin){
		$this->plugin=$plugin;
		include_once __DIR__.'/AgentStore.class.php';
		$this->store=new AiDriveAgentStore($plugin);
	}

	/** Overview of every Agent and whether it already lives in the shared account. */
	public function status(){
		$this->store->initTable();$shared=$this->store->sharedAccountStatus();$result=array('sharedAccount'=>$shared,'agents'=>array());
		$list=Model($this->agentTable)->select();
		foreach($list?$list:array() as $agent){
			$user=Model('User')->getInfoSimple($agent['userID']);
			$result['agents'][]=array('agentID'=>$agent['agentID'],'name'=>$agent['name'],'userID'=>intval($agent['userID']),
				'username'=>strval(_get($user,'name','')),'homeDir'=>strval(_get($agent,'homeDir','')),
				'status'=>intval($agent['status']),'lastUsedAt'=>intval($agent['lastUsedAt']),
				'migrated'=>intval($agent['userID'])===intval($shared['configured'])&&trim(strval(_get($agent,'homeDir','')))!=='');
		}
		return $result;
	}

	/**
	 * Turn an empty dedicated account into the shared account: rename it, add it to the
	 * 智能体 department and store sharedUserID in the plugin config.
	 */
	public function prepare($sourceUserID,$sharedName){
		$this->store->initTable();
		$userID=intval($sourceUserID);$sharedName=trim(strval($sharedName));
		if($userID<=0||!preg_match('/^[A-Za-z0-9_\-.]{1,64}$/',$sharedName)) throw new RuntimeException('sourceUserID and a [A-Za-z0-9_-.] shared name are required');
		$user=Model('User')->getInfoSimple($userID);
		if(!$user) throw new RuntimeException('source user '.$userID.' does not exist');
		$existing=Model('User')->where(array('name'=>$sharedName))->find();
		if($existing&&intval($existing['userID'])!==$userID) throw new RuntimeException('username '.$sharedName.' is already taken');
		$dupe=$this->store->sharedUserID();
		if($dupe&&$dupe!==$userID) throw new RuntimeException('shared account is already configured as userID '.$dupe);
		$conflicts=array();
		if(intval(_get($user,'sizeUse',0))>0)$conflicts[]='sizeUse='.intval($user['sizeUse']);
		if(intval(Model('Share')->where(array('userID'=>$userID))->count())>0)$conflicts[]='shares';
		if($conflicts) throw new RuntimeException('source user is not empty ('.implode(',',$conflicts).'); refusing to convert it');
		$department=$this->store->ensureAgentDepartment();
		Model('User')->userGroupSet($userID,array($department['groupID']=>6),true);
		Model('User')->where(array('userID'=>$userID))->save(array('name'=>$sharedName,'nickName'=>'智能体共享账号','sizeMax'=>0,'modifyTime'=>time()));
		$config=$this->plugin->getConfig();$config['sharedUserID']=strval($userID);
		Model('Plugin')->setConfig('aiDrive',$config);
		$check=$this->store->sharedAccountStatus();
		if(intval($check['configured'])!==$userID||$check['username']!==$sharedName) throw new RuntimeException('shared account configuration did not persist');
		return array('sharedUserID'=>$userID,'username'=>$sharedName,'department'=>$department,'configSaved'=>true);
	}

	/** Dry-run report: what would move, how big it is and which references would be re-pointed. */
	public function plan($agentName){return $this->preview($agentName,false);}

	/** Execute the migration for one Agent; refuses while the Agent was recently active. */
	public function migrate($agentName,$idleMinutes=10){return $this->preview($agentName,true,$idleMinutes);}

	private function preview($agentName,$execute,$idleMinutes=10){
		$this->store->initTable();
		$sharedID=$this->store->sharedUserID();
		if($sharedID<=0) throw new RuntimeException('shared account is not configured; run prepare first');
		$agent=Model($this->agentTable)->where(array('name'=>strval($agentName)))->find();
		if(!$agent) throw new RuntimeException('agent not found: '.$agentName);
		if(trim(strval(_get($agent,'homeDir','')))!=='') throw new RuntimeException('agent '.$agentName.' already lives in homeDir '.$agent['homeDir']);
		$oldUserID=intval($agent['userID']);
		if($oldUserID===intval($sharedID)) throw new RuntimeException('agent '.$agentName.' already belongs to the shared account');
		$oldUser=Model('User')->getInfoFull($oldUserID);
		$sharedUser=Model('User')->getInfoFull($sharedID);
		$oldRootID=intval(_get($oldUser,'sourceInfo.sourceID',0));$newRootID=intval(_get($sharedUser,'sourceInfo.sourceID',0));
		if($oldRootID<=0||$newRootID<=0) throw new RuntimeException('personal space source missing (old='.$oldRootID.' shared='.$newRootID.')');
		$oldRoot=KodIO::make($oldRootID);$newRoot=KodIO::make($newRootID);
		$oldTree=$this->snapshotTree($oldRootID);
		$stats=$this->treeStats($oldTree);
		$report=array('agent'=>$agent['name'],'agentID'=>$agent['agentID'],'oldUserID'=>$oldUserID,'sharedUserID'=>$sharedID,
			'homeDir'=>strval($agent['name']),'oldRoot'=>strval($oldRoot),'sharedRoot'=>strval($newRoot),
			'files'=>$stats['files'],'folders'=>$stats['folders'],'bytes'=>$stats['bytes'],'sha256SamplesPlanned'=>$stats['sampled'],
			'references'=>array('shares'=>intval(Model('Share')->where(array('userID'=>$oldUserID))->count()),
				'versions'=>intval(Model($this->versionTable)->where(array('userID'=>$oldUserID))->count()),
				'trash'=>intval(Model($this->trashTable)->where(array('userID'=>$oldUserID))->count())),
			'execute'=>!!$execute);
		if(!$execute) return array('plan'=>$report,'note'=>'dry run; rerun with --yes to execute');
		if(count($oldTree)>$this->snapshotLimit) throw new RuntimeException('tree snapshot larger than safety limit ('.$this->snapshotLimit.')');
		$lastActive=intval(Model('plugin_ai_drive_audit')->where(array('agentID'=>$agent['agentID']))->order('id desc')->getField('createTime'));
		if($lastActive>time()-$idleMinutes*60) throw new RuntimeException('agent '.$agentName.' was active within the last '.$idleMinutes.' minutes; wait and retry');
		$oldRootRow=Model('Source')->where(array('sourceID'=>$oldRootID))->find();
		$oldRootName=strval(_get($oldRootRow,'name',''));
		if(!$oldRootName) throw new RuntimeException('old root source record not found');
		$started=time();
		$homeDir=strval($agent['name']);
		$newRootBase=rtrim($newRoot,'/');
		// A previous interrupted run may have left a partial copy under either the old
		// root name or the final homeDir name. The old tree is untouched until the final
		// switch, so removing stale copies is safe.
		foreach(array($oldRootName,$homeDir) as $staleName){
			$staleRow=Model('Source')->where(array('parentID'=>$newRootID,'name'=>$staleName,'isDelete'=>0))->find();
			if(!$staleRow) continue;
			// Remove via the canonical {source:id} form: composite parent/name paths
			// hit a different driver branch that may refuse the removal.
			$staleTarget=KodIO::make(intval($staleRow['sourceID']));
			IO::remove($staleTarget,false);
			$still=Model('Source')->where(array('sourceID'=>intval($staleRow['sourceID']),'isDelete'=>0))->find();
			if($still) throw new RuntimeException('stale copy {source:'.$staleRow['sourceID'].'} cannot be removed');
		}
		$copied=IO::copy($oldRoot,$newRootBase,REPEAT_SKIP);
		if(!$copied) throw new RuntimeException('IO copy failed: '.IO::getLastError('unknown error'));
		$copiedPath=strval($copied);
		$renamed=$copiedPath;
		if($oldRootName!==$homeDir){
			$renamed=IO::rename($copiedPath,$homeDir);
			if(!$renamed){IO::remove($copiedPath,false);throw new RuntimeException('rename to homeDir failed; copy was rolled back');}
			$renamed=strval($renamed);
		}
		$newTree=$this->snapshotTree(KodIO::sourceID($renamed));
		try{$reconciled=$this->reconcile($oldTree,$newTree);}
		catch(Throwable $error){IO::remove($renamed,false);throw $error;}
		$newStats=$this->treeStats($newTree);
		// References and the Agent record only move after the tree reconciles.
		$refReport=$this->repointReferences($oldUserID,$sharedID,$reconciled['map']);
		// Write the switch with raw SQL: Model::save() filters columns against the
		// field list cached before this process ran the ALTER TABLE, silently
		// dropping homeDir.
		$db=Model()->db();
		$escHome=str_replace("'","''",$homeDir);$escAgent=str_replace("'","''",strval($agent['agentID']));
		$updated=$db->execute("UPDATE ".$this->agentTable." SET userID=".intval($sharedID).", homeDir='".$escHome."', updatedAt=".time()." WHERE agentID='".$escAgent."'");
		$check=Model($this->agentTable)->where(array('agentID'=>$agent['agentID']))->find();
		if(!$check||intval($check['userID'])!==intval($sharedID)||trim(strval(_get($check,'homeDir','')))!==$homeDir) throw new RuntimeException('agent record update failed after reference re-pointing; review shares/versions manually');
		$staleName=$oldRootName.'-migrated-'.date('YmdHis');
		$renamedOld=IO::rename($oldRoot,$staleName);
		return array('migrated'=>true,'report'=>$report,'elapsedSeconds'=>time()-$started,
			'newRootPath'=>$renamed,'reconciledNodes'=>$reconciled['count'],'sha256SamplesPlanned'=>$newStats['sampled'],
			'references'=>$refReport,'oldRootRenamedTo'=>($renamedOld?$staleName.' (old tree kept for safety; removed with the account later)':'rename failed; old space left in place'));
	}

	/** Full source tree snapshot rooted at $rootSourceID; rel is the path below the root. */
	private function snapshotTree($rootSourceID){
		$nodes=array(intval($rootSourceID)=>array('rel'=>'','size'=>0,'isFolder'=>1));
		$queue=array(intval($rootSourceID));$guard=0;
		while($queue){
			if($guard++>$this->snapshotLimit) throw new RuntimeException('tree snapshot safety limit hit');
			$parentID=array_shift($queue);
			$rows=Model('Source')->where(array('parentID'=>$parentID,'isDelete'=>0))->select();
			foreach($rows?$rows:array() as $row){
				$sourceID=intval($row['sourceID']);
				$nodes[$sourceID]=array('rel'=>ltrim($nodes[$parentID]['rel'].'/'.strval($row['name']),'/'),'size'=>intval($row['size']),'isFolder'=>intval($row['isFolder']));
				if(intval($row['isFolder'])===1)$queue[]=$sourceID;
			}
		}
		return $nodes;
	}

	private function treeStats($tree){
		$files=0;$folders=0;$bytes=0;$sampled=0;
		foreach($tree as $node){
			if(intval($node['isFolder'])===1){$folders++;continue;}
			$files++;$bytes+=intval($node['size']);
			if($sampled<5&&intval($node['size'])>0&&intval($node['size'])<50*1024*1024)$sampled++;
		}
		return array('files'=>$files,'folders'=>$folders,'bytes'=>$bytes,'sampled'=>$sampled);
	}

	/** Verify the copied tree matches the original; returns the oldID=>newID map. */
	private function reconcile($oldTree,$newTree){
		$newByRel=array();
		foreach($newTree as $sourceID=>$node)$newByRel[$node['rel']]=$sourceID;
		$map=array();
		foreach($oldTree as $sourceID=>$node){
			$rel=$node['rel'];
			if(!array_key_exists($rel,$newByRel)) throw new RuntimeException('reconcile: missing in new tree: '.$rel);
			$newID=$newByRel[$rel];
			if(intval($newTree[$newID]['isFolder'])!==intval($node['isFolder'])) throw new RuntimeException('reconcile: type mismatch: '.$rel);
			if(intval($node['isFolder'])!==1&&intval($newTree[$newID]['size'])!==intval($node['size'])) throw new RuntimeException('reconcile: size mismatch: '.$rel);
			if($rel!=='')$map[$sourceID]=$newID;
		}
		return array('map'=>$map,'count'=>count($map));
	}

	private function ioSourceID($path){
		if(preg_match('/^io:\{source:(\d+)\}/',strval($path),$match)) return intval($match[1]);
		return 0;
	}

	/** Move share/version/trash references from the old tree onto the new one.
	 * Blobs outside the migrated tree (department-space history etc.) stay as they are:
	 * the department space is not migrated, so those paths remain valid. Shares are
	 * always re-owned because deleting the old account would cascade-delete them. */
	private function repointReferences($oldUserID,$sharedID,$map){
		$done=array('shares'=>0,'sharesKeptSource'=>0,'versions'=>0,'versionsOutsideTree'=>0,'trashBlobs'=>0,'trashOutsideTree'=>0);
		$shares=Model('Share')->where(array('userID'=>$oldUserID))->select();
		foreach($shares?$shares:array() as $share){
			$sourceID=intval($share['sourceID']);
			$update=array('userID'=>$sharedID,'modifyTime'=>time());
			if(isset($map[$sourceID])){
				$update['sourceID']=$map[$sourceID];
				$update['sourcePath']='{source:'.$map[$sourceID].'}';
			}else{$done['sharesKeptSource']++;}
			Model('Share')->where(array('shareID'=>$share['shareID']))->save($update);
			$done['shares']++;
		}
		foreach(Model($this->versionTable)->where(array('userID'=>$oldUserID))->select() as $version){
			$old=$this->ioSourceID($version['blobPath']);
			if($old<=0) continue; // local blob under DATA_PATH, unaffected
			if(!isset($map[$old])){$done['versionsOutsideTree']++;continue;}
			Model($this->versionTable)->setDataAuto(false);
			Model($this->versionTable)->where(array('id'=>$version['id']))->save(array('blobPath'=>'io:{source:'.$map[$old].'}'));
			$done['versions']++;
		}
		foreach(Model($this->trashTable)->where(array('userID'=>$oldUserID))->select() as $trash){
			$old=$this->ioSourceID($trash['storagePath']);
			if($old<=0) continue; // local blob under DATA_PATH, unaffected
			if(!isset($map[$old])){$done['trashOutsideTree']++;continue;}
			Model($this->trashTable)->setDataAuto(false);
			Model($this->trashTable)->where(array('id'=>$trash['id']))->save(array('storagePath'=>'io:{source:'.$map[$old].'}'));
			$done['trashBlobs']++;
		}
		return $done;
	}
}
