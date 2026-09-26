<?php
$tmp=sys_get_temp_dir().'/aidrive-protection-'.bin2hex(random_bytes(4)).'/';mkdir($tmp,0777,true);define('DATA_PATH',$tmp);define('REPEAT_REPLACE',1);
function _get($data,$key,$default=null){return is_array($data)&&array_key_exists($key,$data)?$data[$key]:$default;}function mk_dir($path){return is_dir($path)||mkdir($path,0777,true);}
class MemoryRows {
	public static $tables=array('plugin_ai_drive_version'=>array(),'plugin_ai_drive_trash'=>array());private $table;private $where=array();
	public function __construct($table){$this->table=$table;}public function setDataAuto($v){return $this;}
	public function add($data){$data['id']=count(self::$tables[$this->table])+1;self::$tables[$this->table][]=$data;return $data['id'];}
	public function where($where){$this->where=$where;return $this;}public function order($x){return $this;}public function limit($x){return $this;}
	private function matches($row){foreach($this->where as $k=>$v)if($row[$k]!=$v)return false;return true;}
	public function find(){foreach(self::$tables[$this->table] as $row)if($this->matches($row))return $row;return false;}
	public function select(){$result=array();foreach(self::$tables[$this->table] as $row)if($this->matches($row))$result[]=$row;return array_reverse($result);}
	public function delete(){foreach(self::$tables[$this->table] as $index=>$row)if($this->matches($row)){unset(self::$tables[$this->table][$index]);self::$tables[$this->table]=array_values(self::$tables[$this->table]);return 1;}return 0;}
}
function Model($name){if(isset(MemoryRows::$tables[$name]))return new MemoryRows($name);throw new RuntimeException($name);}
class IO {
	public static $files=array();public static $folders=array('/mounted/history'=>true,'/source'=>true,'/target'=>true);
	public static function infoFull($path){if(isset(self::$files[$path]))return array('type'=>'file','name'=>basename($path),'size'=>strlen(self::$files[$path]));if(isset(self::$folders[$path]))return array('type'=>'folder','name'=>basename($path),'size'=>0);return false;}
	public static function fileSubstr($path,$offset,$length){return substr(self::$files[$path],$offset,$length);}
	public static function setContent($path,$content){self::$files[$path]=$content;return $path;}
	public static function mkfile($path,$content,$repeat){self::$files[$path]=$content;return $path;}
	public static function copy($path,$folder,$repeat){if(!isset(self::$files[$path])||!isset(self::$folders[$folder]))return false;$target=rtrim($folder,'/').'/'.basename($path);self::$files[$target]=self::$files[$path];return $target;}
	public static function rename($path,$name){if(!isset(self::$files[$path]))return false;$target=dirname($path).'/'.$name;self::$files[$target]=self::$files[$path];unset(self::$files[$path]);return $target;}
	public static function remove($path,$recycle=false){if(!isset(self::$files[$path]))return false;unset(self::$files[$path]);return true;}
	public static function pathFather($path){return dirname($path);}
}
class StoreStub {public function initTable(){}}
require dirname(__DIR__).'/plugins/aiDrive/lib/DataProtection.class.php';
function check($value,$message){if(!$value)throw new RuntimeException($message);}

$agent=array('agentID'=>'agent_test','userID'=>2);$path='/source/note.txt';IO::$files[$path]='before';$protection=new AiDriveDataProtection(new StoreStub());
$local=$protection->snapshot($agent,'personal','/note.txt',$path,'overwrite');check($local&&$local['sha256']===hash('sha256','before'),'local snapshot hash mismatch');
IO::$files[$path]='after';$restored=$protection->restore($local['id'],$path);check($restored&&IO::$files[$path]==='before','local restore failed');

IO::$files[$path]='remote-before';$remote=$protection->snapshot($agent,'personal','/note.txt',$path,'overwrite','/mounted/history');
check($remote&&strpos($remote['blobPath'],'io:')===0,'remote snapshot must use mounted storage');
IO::$files[$path]='remote-after';$restored=$protection->restore($remote['id'],$path);
check($restored&&IO::$files[$path]==='remote-before','remote restore failed');

$trash=$protection->recordTrash($agent,'personal','/gone.txt','/mounted/trash/gone.txt',array('type'=>'file','size'=>17));
check($trash&&$trash['originalPath']==='/gone.txt','trash metadata was not stored');
check(count($protection->listTrash('agent_test',10))===1,'trash list failed');
check($protection->trashItem($trash['id'],'agent_test')['storagePath']==='/mounted/trash/gone.txt','trash lookup failed');
check($protection->forgetTrash($trash['id'],'agent_test')&&count($protection->listTrash('agent_test',10))===0,'trash restore cleanup failed');

$versions=$protection->listVersions('agent_test',10,'/note.txt');check(count($versions)===2,'version path filter failed');
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($tmp);echo "AI Drive data protection tests passed\n";
