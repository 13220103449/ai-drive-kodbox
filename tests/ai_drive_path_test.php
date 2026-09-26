<?php

define('REPEAT_SKIP', 0);
define('REPEAT_REPLACE', 1);

function _get($data, $key, $default = null) {return is_array($data) && array_key_exists($key, $data) ? $data[$key] : $default;}
function show_json($data, $code = true) {throw new RuntimeException(json_encode(array('code' => $code, 'data' => $data)));}
function Model($name) {if ($name !== 'Source') throw new RuntimeException('unexpected model: '.$name);return new FakeSourceModel();}

class PluginBase {public $in = array();public function __construct() {}}
class FakeSourceStore {
	public static $rows = array(
		1 => array('sourceID' => 1, 'parentID' => 0, 'name' => 'root', 'isFolder' => 1, 'isDelete' => 0),
		2 => array('sourceID' => 2, 'parentID' => 1, 'name' => '我的文档', 'isFolder' => 1, 'isDelete' => 0),
		3 => array('sourceID' => 3, 'parentID' => 2, 'name' => 'agents-starbucks', 'isFolder' => 1, 'isDelete' => 0),
		4 => array('sourceID' => 4, 'parentID' => 3, 'name' => 'big1.json', 'isFolder' => 0, 'isDelete' => 0),
		5 => array('sourceID' => 5, 'parentID' => 1, 'name' => 'AI Drive回收站(勿删)', 'isFolder' => 1, 'isDelete' => 0),
	);
}
class FakeSourceModel {
	private $where = array();
	public function where($where) {$this->where = $where;return $this;}
	public function field($fields) {return $this;}
	public function find() {
		foreach (FakeSourceStore::$rows as $row) {
			$match = true;foreach ($this->where as $key => $value) if (strval($row[$key]) !== strval($value)) {$match = false;break;}
			if ($match) return $row;
		}
		return false;
	}
	public function remove($sourceID, $toRecycle = false) {if (!isset(FakeSourceStore::$rows[$sourceID])) return false;FakeSourceStore::$rows[$sourceID]['isDelete'] = 1;return true;}
}
class KodIO {
	public static function make($sourceID) {return '{source:'.intval($sourceID).'}/';}
	public static function sourceID($path) {return preg_match('/^\{source:(\d+)\}/', strval($path), $match) ? intval($match[1]) : 0;}
}
class IO {
	public static $listCalls = 0;
	public static function infoFull($path) {
		if (!preg_match('/^\{source:(\d+)\}\/\z/', strval($path), $match)) return false;
		$id = intval($match[1]);if (!isset(FakeSourceStore::$rows[$id]) || FakeSourceStore::$rows[$id]['isDelete']) return false;$row = FakeSourceStore::$rows[$id];
		return array('sourceID' => $id, 'name' => $row['name'], 'type' => $row['isFolder'] ? 'folder' : 'file', 'path' => KodIO::make($id));
	}
	public static function listPath($path) {
		self::$listCalls++;$parentID = KodIO::sourceID($path);$folders = array();$files = array();
		foreach (FakeSourceStore::$rows as $row) {
			if (intval($row['parentID']) !== $parentID || intval($row['isDelete'])) continue;
			$item = self::infoFull(KodIO::make($row['sourceID']));
			if ($row['isFolder']) $folders[] = $item;else $files[] = $item;
		}
		return array('folderList' => $folders, 'fileList' => $files);
	}
	public static function mkdir($path, $repeat) {
		if (!preg_match('/^\{source:(\d+)\}\/(.+)$/u', strval($path), $match)) return false;
		$parentID = intval($match[1]);$name = $match[2];$id = max(array_keys(FakeSourceStore::$rows)) + 1;
		FakeSourceStore::$rows[$id] = array('sourceID' => $id, 'parentID' => $parentID, 'name' => $name, 'isFolder' => 1, 'isDelete' => 0);
		return KodIO::make($id);
	}
	public static function copy($path, $folder, $repeat) {
		$source = self::infoFull($path);$parentID = KodIO::sourceID($folder);
		if (!$source || !$parentID) return false;
		$id = max(array_keys(FakeSourceStore::$rows)) + 1;
		FakeSourceStore::$rows[$id] = array('sourceID' => $id, 'parentID' => $parentID, 'name' => $source['name'], 'isFolder' => $source['type'] === 'folder' ? 1 : 0, 'isDelete' => 0);
		return KodIO::make($id);
	}
	public static function rename($path, $name) {
		$id = KodIO::sourceID($path);if (!$id || !isset(FakeSourceStore::$rows[$id])) return false;
		FakeSourceStore::$rows[$id]['name'] = $name;return KodIO::make($id);
	}
	public static function remove($path, $toRecycle = false) {return false;}
	public static function getLastError($fallback) {return $fallback;}
}

require dirname(__DIR__).'/plugins/aiDrive/app.php';

function call_private($object, $method, array $arguments) {
	$reflection = new ReflectionMethod($object, $method);$reflection->setAccessible(true);return $reflection->invokeArgs($object, $arguments);
}
function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) throw new RuntimeException($message.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
}

$plugin = (new ReflectionClass('aiDrivePlugin'))->newInstanceWithoutConstructor();
$root = KodIO::make(1);

assert_same(KodIO::make(2), call_private($plugin, 'agentPath', array($root, '/我的文档')), 'alias must resolve to the real child');
assert_same(KodIO::make(3), call_private($plugin, 'agentPath', array($root, '/我的文档/agents-starbucks')), 'nested path must resolve exactly');
assert_same(false, call_private($plugin, 'agentPath', array($root, '/missing/child')), 'missing intermediate path must not fall back to root');
assert_same($root.'missing', call_private($plugin, 'agentPath', array($root, '/missing')), 'missing final path may only be represented as a nonexistent target');
assert_same(0, IO::$listCalls, 'path resolution must not use display-list aliases');

$rootList = call_private($plugin, 'listResult', array(IO::listPath($root), $root));
foreach ($rootList['folders'] as $folder) assert_same(false, $folder['name'] === 'AI Drive回收站(勿删)', 'normal listing must hide the mounted recycle folder');
$protected = call_private($plugin, 'ensureFolderPath', array($root, '/AI Drive回收站(勿删)/20260926', true));
assert_same('20260926', IO::infoFull($protected)['name'], 'internal protection code must be able to create dated recycle folders');

$created = call_private($plugin, 'ensureFolderPath', array($root, '/new-parent/new-child'));
$createdInfo = IO::infoFull($created);
assert_same('new-child', $createdInfo['name'], 'mkdir must create the complete directory chain');
$resolved = call_private($plugin, 'agentPath', array($root, '/new-parent/new-child'));
assert_same($created, $resolved, 'new nested folder must resolve through the same strict hierarchy');

$copyTarget = call_private($plugin, 'targetParts', array($root, '/我的文档/agents-starbucks/sub/deep/big1_copy.json', true));
assert_same('big1_copy.json', $copyTarget['name'], 'copy target must keep the requested filename');
assert_same('deep', IO::infoFull($copyTarget['folder'])['name'], 'copy target must create every missing parent folder');

$copied = call_private($plugin, 'copyToTarget', array(KodIO::make(4), $root, '/我的文档/agents-starbucks/copied/nested/big1_copy.json'));
assert_same('big1_copy.json', IO::infoFull($copied)['name'], 'copy must execute into a newly-created nested destination');
$copiedRow = FakeSourceStore::$rows[KodIO::sourceID($copied)];
assert_same('nested', FakeSourceStore::$rows[$copiedRow['parentID']]['name'], 'copy must place the file under the requested destination parent');

assert_same('renamed.json', call_private($plugin, 'renameTarget', array(array('newName' => 'renamed.json'))), 'newName must be accepted');
assert_same('', call_private($plugin, 'renameTarget', array(array('to' => 'ignored.json'))), 'rename to alias must not be advertised or accepted');

assert_same(true, call_private($plugin, 'folderIsEmpty', array($copyTarget['folder'])), 'new folder must be recognized as empty');
assert_same(true, call_private($plugin, 'deletePath', array($copyTarget['folder'])), 'empty Source folder must be deleted when the IO driver refuses it');
assert_same(false, IO::infoFull($copyTarget['folder']), 'deleted empty folder must no longer exist');

echo "AI Drive strict path tests passed\n";
