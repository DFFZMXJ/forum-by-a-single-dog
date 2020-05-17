<?php
/*
 * 单身狗论坛仅后端 by 学神之女
 * （可回复可标题的留言板？）
 * 与完成版论坛的差异是，这个论坛不返回HTML，而是JSON或其它格式文本（JSON外的返回格式需定制）。
**/

//偏好值。
const SITE_NAME = '单身狗论坛'; //论坛名。
const DATABASE_LOCATION = __DIR__ . '/data'; //数据库目录。
const HASH_ALGORITHM = PASSWORD_BCRYPT; //加密算法，新PHP可改 PASSWORD_ARGON2I
define('SERVER_ID', mt_rand(0, 0xFFFFFF)); //服务器标识，生成数据库标识用。
define('PROCESS_ID', getmypid()); //进程标识，生成数据库标识用。
//const CUSTOM_BASE_PATH = ''; //如有伪静态，手动设定地基路径。
define('INITIAL_DATA', [
	//初始数据
	'users' =>[
		['username' => 'OwO', 'password' => password_hash('114514', HASH_ALGORITHM), 'admin' => true, 'when' => time()]
	]
]);

//JSON数据库，手艺人可以改改自用，或换别的。
class Database{
	public $directory = DATABASE_LOCATION;
	function __construct(){
		if(!is_dir($this->directory))
			mkdir($this->directory);
	}
	function __isset($table){
		assert(strpos($table, '/') === false, '亲，不要这么玩。');
		return is_dir($this->directory . '/' . $table);
	}
	function __get($table){
		assert(strpos($table, '/') === false, '亲，不要这么玩。');
		if(!isset($this->{$table})) //调用__isset。
			mkdir($this->directory . '/' . $table);
		return new Collection($this, $table);
	}
}

class Collection{
	public $super;
	public $table;
	//function __construct($this->super, $this->table){} //想要Dart的特性。
	function __construct($super, $table){
		$this->super = $super;
		$this->table = $table;
	}
	//务必确保$records的每个项都是个数组，否则会有麻烦。
	function append(...$records){
		$appendedIDs = [];
		foreach ($records as $record)
			if(!$this->has($record))
				array_push($appendedIDs, $this->save($record)[0]);
			else
				throw new Exception('ID已存在');
		return $appendedIDs;
	}
	function read($query = [], $single = true){
		if(isset($query['_id'])){
			if(!file_exists($this->getFileName($query['_id']))) return null;
			$record = array_merge(
				['_id' => $query['_id']],
				json_decode(file_get_contents($this->getFileName($query['_id'])), true)
			);
			if($this->match($record, $query)){
				return $single ? $record : [$record];
			} else
				return null;
		}
		$ids = $this->ls();
		if($single) {
			$record = json_decode(file_get_contents($this->getFileName($ids[0])), true);
			if($this->match($record, $query))
				return array_merge(
					['_id' => $ids[0]],
					$record
				);
			else
				return null;
		}
		$records = [];
		foreach($ids as $id){
			$record = json_decode(file_get_contents($this->getFileName($id)), true);
			if($this->match($record, $query))
				array_push($records, array_merge(
					['_id' => $id],
					$record
				));
		}
		return $records;
	}
	function update($query = [], $modifications = []){} //改密码都不支持，要它何用？（其实是没想好怎么处理$modifications）
	//false => 失败, 0 => 啥事没有, <数字> => <数字>条记录被删
	function delete($query = []){
		if(isset($query['_id'])){
			$_id = $query['_id'];
			unset($query['_id']);
			if(!file_exists($this->getFileName($_id))) return 0;
			if(!$this->match(json_decode(file_get_contents($this->getFileName($_id)), true), $query)) return 0;
			if(!unlink($this->getFileName($query['_id']))) return false;
			return 1;
		}
		$count = 0;
		if(!count($query)){ //无条件时直接删。
			foreach ($this->ls() as $file)
				if(!unlink($this->getFileName($file)))
					return false;
				else
					++$count;
			return $count;
		}
		foreach ($this->ls() as $_id)
			if($this->match(json_decode(file_get_contents($this->getFileName($_id)), true), $query))
				if(unlink($this->getFileName($_id)))
					++$count;
				else
					return false;
		return $count;
	}
	function save(...$records){
		//和append比，save不会检查_id是否存在，存在时会直接覆盖。
		$savedIDs = [];
		foreach ($records as $record) {
			$_id = $record['_id'] ?? objectID();
			unset($record['_id']);
			file_put_contents($this->getFileName($_id), json_encode($record));
			array_push($savedIDs, $_id);
		}
		return $savedIDs;
	}
	function drop(){
		$this->delete();
		rmdir($this->super->directory . '/' . $this->table);
	}
	function has($query = []){
		if(isset($query['_id'])){
			if(!file_exists($this->getFileName($query['_id']))) return false;
			$record = json_decode(file_get_contents($this->getFileName($query['_id'])), true);
			unset($query['_id']);
			return $this->match($record, $query);
		}
		foreach ($this->ls() as $_id)
			if($this->match(json_decode(file_get_contents($this->getFileName($_id)), true), $query)) return true;
		return false;
	}
	function count($query = []){
		if($query['_id'])
			return $this->match(array_merge(['_id' => $query['_id']], json_decode(file_get_contents($this->getFileName($query['_id'])))), $query) ? 1 : 0;
		if(!count($query))
			return count(glob($this->getFileName('*')));
		$count = 0;
		foreach ($this-ls() as $_id)
			if($this->match( json_decode(file_get_contents($this->getFileName($_id)), true), $query ))
				++$count;
		return $count;
	}
	private function getFileName($_id = ''){
		assert(strpos($_id, '/') === false, '亲，不要这么玩。');
		return $this->super->directory . '/' . $this->table . '/' . $_id;
	}
	private function ls(){
		$ids = [];
		foreach(scandir($this->super->directory . '/' . $this->table) as $index => $id)
			if(!in_array($id, ['.', '..']))
				array_push($ids, $id);
		return $ids;
	}
	private function match($record, $query){
		if(is_object($query) && is_callable($query))
			return call_user_func($query, $record);
		if(!is_array($query))
			return $record === $query;
		foreach ($query as $key => $value)
			switch ($key) {
				//会优化的话可以建议。
				case '$!':
					if($this->match($record, $value)) return false;
					break;
				case '$in':
                    if(is_array($record)){
                    	foreach ($value as $v)
                    		if(!in_array($v, $record)) return false;
                    }else //我不知所措，JS里就是这么写的。
                    	foreach ($value as $condition)
                    		if(!$this->match($record, $condition)) return false;
                    break;
                case '$exists':
                	if(isset($record) !== (bool)$value) return false;
                	break;
                case '$regex':
                	if(!preg_match($value, $record)) return false;
                	break;
                case '$type':
                	if(typeof($record) !== $value) return false;
                	break;
                case '$size':
                	if(count($record) !== $value) return false; //避免用它测量UTF8字符。
                	break;
                case '$||':
                	$hasTrueCondition = false;
                	foreach ($value as $condition)
                		if($this->match($record, $condition)){
                			$hasTrueCondition = true;
                			break;
                		}
                	if(!$hasTrueCondition) return false;
                	break;
                case '$&&':
                	foreach ($value as $condition)
                		if(!$this->match($record, $condition)) return false;
                	break;
                case '$>':
                	if($record <= $value) return false;
                	break;
                case '$>=':
                	if($record < $value) return false;
                	break;
                case '$<':
                	if($record >= $value) return false;
                	break;
                case '$<=':
                	if($record > $value) return false;
                	break;
				default:
					if(!$this->match($record[$key], $value)) return false;
					break;
			}
		return true;
	}
}

function typeof($variable = null){
	//PHP文档警告勿以gettype取类。
	if(is_null($variable)) return 'null';
	if(is_string($variable)) return 'string'; //is_numberic定'2'之类为true
	if(is_numeric($variable)) return 'number';
	if(is_bool($variable)) return 'boolean';
	if(is_array($variable)) return 'array';
	if(is_object($variable)) return 'object'; //new的class或匿名函数.
	//函数在PHP要么是string, 要么是object.
	return 'unknown';
}

function objectID($machineID = SERVER_ID, $pid = PROCESS_ID){
	return dechex(time() & 0xFFFFFFFF). //时间戳
		   dechex($machineID & 0xFFFFFF). //机器标识码
		   dechex($pid & 0xFFFF). //进程标识
		   dechex(mt_rand(0, 0xFFFFFF)); //随机数
}

class Response {
	static function error($status = 500, $title = 'oops-something-went-wrong', $detail = null){
		static::send(['title' => $title, 'detail' => $detail], $status);
	}
	static function send($data = [], $status = 200){
		header("{$_SERVER['SERVER_PROTOCOL']} $status");
		die(static::serialize($data));
	}
	private static function serialize($data){
		header('Content-Type: application/json');
		return json_encode($data);
	}
}

//PHP不处理JSON，坑……
if(@$_SERVER['CONTENT_TYPE'] === 'application/json')
	$_POST = json_decode(file_get_contents('php://input'), true);

class RouteResolver{
	public $askedPaths = [];
	public $pathInfo;
	function __construct(){
		$this->pathInfo = strtolower(@$_SERVER['PATH_INFO'] ?? '/');
	}
	function route($path = '/', $method = 'GET'){
		array_push($this->askedPaths, $path);
		if( $this->pathInfo === $path && $_SERVER['REQUEST_METHOD'] === $method) return true;
	}
	function methodNotAllowed(){
		if(in_array($this->pathInfo, $this->askedPaths)) return true;
	}
	function notFound(){
		return !$this->methodNotAllowed();
	}
}

if(!is_dir(DATABASE_LOCATION)){
	//初始化数据。
	$database = new Database;
	foreach (INITIAL_DATA as $table => $records)
		$database->{$table}->append(...$records);
}
session_start();
$database = new Database;
$authorization =
	isset($_SESSION['forum-authorization']) ? //如果像JavaScript那样能用“&&”，绝对不这么写。
	$database->users->read(['_id' => $_SESSION['forum-authorization']]):
	null;

$router = new RouteResolver;

switch(true) {
	//和完全版比，差点意思，就是吐数据，存取数据库罢。
	//巧用and和or，原来可以省很多代码。
	case $router->route():
		Response::send(['name' => SITE_NAME, 'posts' => $database->posts->read([], false)]);
	break;
	case $router->route('/view'):
		isset($_GET['post']) or Response::error(404, 'post-not-determined');
		$post = $database->posts->read(['_id' => $_GET['post']]) or Response::error(404, 'post-not-exist');
		$replies = $database->replies->read(['in' => $_GET['post']], false);
		Response::send([
			'post' => $post,
			'replies' => $replies
		]);
	break;
	case $router->route('/post', 'POST'):
		$authorization or Response::error(401, 'unauthorized');
		(isset($_POST['title']) && isset($_POST['content'])) or Response::error(406, 'information-required', ['required-information' => ['title', 'content']]);
		(strlen($_POST['title']) <= 120) or Response::error(406, 'title-too-long', ['maxlength' => 120]);
		Response::send(['id' => $database->posts->append([
			'title' => $_POST['title'],
			'content' => $_POST['content'],
			'when' => time(),
			'author' => $authorization['_id']
		])[0]], 201);//感觉直接返回帖子更好。
	break;
	case $router->route('/auth', 'POST'):
		(isset($_POST['username']) && isset($_POST['password'])) or Response::error(406, 'information-required', ['required-information' => ['username', 'password']]);
		(strlen($_POST['username']) <= 24) or Response::error(406, 'username-too-long', ['maxlength' => 24]);
		$id = null;
		if(isset($_POST['register'])) {
			$database->users->has(['username' => $_POST['username']]) and Response::error(406, 'user-already-exists');
			$id = $database->users->append([
				'username' => $_POST['username'],
				'password' => password_hash($_POST['password'], HASH_ALGORITHM),
				'when' => time(),
				'admin' => false
			])[0];
		} else {
			$user = $database->users->read(['username' => $_POST['username']]) or Response::error(404, 'user-not-exists');
			password_verify($_POST['password'], $user['password']) or Response::error(404, 'password-incorrect');
			$id = $user['_id'];
		}
		$_SESSION['forum-authorization'] = $id;
		Response::send('ok-or-created', 201);
	break;
	case $router->route('/auth', 'DELETE'):
		unset($_SESSION['forum-authorization']);
		Response::send('reset', 202);
	break;
	case $router->route('/profile'):
		$user = null;
		if(isset($_GET['user']))
			$user = $database->users->read(['_id' => $_GET['user']]) or Response::error(404, 'user-not-exists');
		else if($authorization)
			$user = $authorization;
		else
			Response::error(401, 'unauthorized');
		$posts = $database->posts-read(['author' => $user['_id']], false);
		$replies = $database->replies->read(['author' => $user['_id']], false);
		Response::send([
			'info' => $user,
			'posts' => $posts,
			'replies' => $replies
		]);
	break;
	case $router->route('/reply'): //这是吐单条评论，而不是再回新评论的。
		isset($_GET['reply']) or Response::error(404, 'reply-not-determined');
		$reply = $database->replies->read(['_id' => $_GET['reply']]) or Response::error(404, 'reply-not-exists');
		Response::send($reply);
	break;
	case $router->route('/reply', 'POST'):
		$authorization or Response::error(401, 'unauthorized');
		(isset($_POST['content']) && isset($_GET['in'])) or Response::error(406, 'information-required', ['required-information' => ['content', 'in']]);
		$database->posts->has(['_id' => $_GET['in']]) or Response::error(404, 'post-not-exists');
		Response::send([
			'id' => $database->replies->append(['content' => $_POST['content'], 'when' => time(), 'in' => $_GET['in'], 'author'=> $authorization['_id']])[0],
			'post' => $_GET['in']
		]);
	case $router->route('/the-final-answer-to-the-universe'):
		Response::send(42, '418 FaQ');
	break;
	case $router->methodNotAllowed():
		Response::error(405, 'method-now-allowed');
	break;
	case $router->notFound():
		Response::error(404, 'page-not-exists');
	break;
	default:
		Response::error(500, '奇怪的错误出现了');
	break;
}