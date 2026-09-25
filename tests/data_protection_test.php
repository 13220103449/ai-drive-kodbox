<?php
$tmp=sys_get_temp_dir().'/aidrive-protection-'.bin2hex(random_bytes(4)).'/';mkdir($tmp,0777,true);define('DATA_PATH',$tmp);define('REPEAT_REPLACE',1);
function _get($data,$key,$default=null){return is_array($data)&&array_key_exists($key,$data)?$data[$key]:$default;}function mk_dir($path){return is_dir($path)||mkdir($path,0777,true);}
class VersionRows {public static $rows=array();private $where=array();public function setDataAuto($v){return $this;}public function add($data){$data['id']=count(self::$rows)+1;self::$rows[]=$data;return $data['id'];}public function where($where){$this->where=$where;return $this;}public function find(){foreach(self::$rows as $r){$ok=true;foreach($this->where as $k=>$v)if($r[$k]!=$v)$ok=false;if($ok)return $r;}return false;}public function order($x){return $this;}public function limit($x){return $this;}public function select(){return self::$rows;}public function delete(){return 1;}}
function Model($name){if($name==='plugin_ai_drive_version')return new VersionRows();throw new RuntimeException($name);}
class IO {public static $files=array();public static function infoFull($p){return isset(self::$files[$p])?array('type'=>'file','size'=>strlen(self::$files[$p])):false;}public static function fileSubstr($p,$o,$l){return substr(self::$files[$p],$o,$l);}public static function setContent($p,$c){self::$files[$p]=$c;return $p;}public static function mkfile($p,$c,$r){self::$files[$p]=$c;return $p;}}
class StoreStub {public function initTable(){}}
require dirname(__DIR__).'/plugins/aiDrive/lib/DataProtection.class.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$agent=array('agentID'=>'agent_test','userID'=>2);$path='{source:1}/note.txt';IO::$files[$path]='before';$p=new AiDriveDataProtection(new StoreStub());
$version=$p->snapshot($agent,'personal','/note.txt',$path,'overwrite');check($version&&$version['sha256']===hash('sha256','before'),'snapshot hash mismatch');
IO::$files[$path]='after';$restored=$p->restore($version['id'],$path);check($restored&&IO::$files[$path]==='before','restore failed');
$list=$p->listVersions('agent_test',10);check(count($list)===1&&$list[0]['path']==='/note.txt','version listing failed');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($tmp);echo "AI Drive data protection tests passed\n";
