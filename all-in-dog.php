<?php
/*
 * 单身狗论坛 by 学神之女
 * （To be honest, 这更像可回复可标题的留言板？）
 * 若仔细编排，除去冗余，可以更小，但这样是方便Ta人在自己项目中使用代码片段。（也是自己的癖好之一）
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
	],
	'posts' => [],
	'replies' => []
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

function linkTo($path = '', $properties = null){
	$url = defined('CUSTOM_BASE_PATH') ? CUSTOM_BASE_NAME : $_SERVER['SCRIPT_NAME'];
	$url .= $path;
	if(isset($properties)) $url .= '?' . http_build_query($properties);
	return $url;
}

function relativeTime($seconds = 0){
	if(abs($seconds) < 10) return '刚刚';
	$pastFuture = $seconds < 0 ? '前' : '后'; //过去还是未来。
	$thresholds = [
		//小于10秒是“刚刚”，不考虑毫秒、微秒等。
		60 => '秒',
		3600 => '分',
		86400 => '时',
		2678400 => '日',
		31557600 => '月',
		3155760000 => '年',
		log(0) => '世纪'
	]; //临界值
	$previous = 1;
	foreach($thresholds as $rate => $unit)
		if(abs($seconds) < $rate)
			return round(abs($seconds) / $previous) . $unit . $pastFuture;
		else
			$previous = $rate;
	return '不明';
}

function assertInformation($condition, $error){ //直接跳转的话，用户会丢失填写的数据。
	if(!$condition) return;
	startResponse($error, 406);
	echo "请<a href=\"javascript:history.back();\">退回上一页</a>，改好提交。";
	echo "<noscript>你似乎没有开启JavaScript，此时退回上一页的链接将失效，请手动按下浏览器<kbd>←</kbd>按钮退回。</noscript>";
	exit();
}

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

class NotFound extends Exception{}
class MethodNotAllowed extends Exception{}

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

function startResponse($title, $status = 200){
	global $authorization; //这什么鬼问题……
	header("{$_SERVER['SERVER_PROTOCOL']} $status"); //关闭<body>和<html>太麻烦，索性不用它们。
	?>
<!DOCTYPE html>
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width" />
	<title><?php echo htmlspecialchars($title); ?></title>
</head>
<h1><?php echo htmlspecialchars($title); ?></h1>
<p>
	<a href="<?php echo linkTo();?>">退回首页</a>
<?php if(isset($authorization)): ?>
	<a href="<?php echo linkTo('/profile', ['user' => $authorization['_id']]); ?>" title="进入个人主页"><?php echo htmlspecialchars($authorization['username']);?></a>
	<a href="<?php echo linkTo('/auth/reset'); ?>">退出此身份</a>
	<a href="<?php echo linkTo('/post');?>">发布新帖</a>
<?php else: ?>
	<a href="<?php echo linkTo('/auth'); ?>">登录或注册</a>
<?php endif; ?>
</p>
<hr />
	<?php
}

$router = new RouteResolver;

try{
	switch (true) {
	//奇妙的路由方法，基于Path Info.
	case $router->route():
		startResponse(SITE_NAME);
		$posts = $database->posts->read([], false);
		?>
<p>共<?php echo count($posts); ?>条帖子。</p>
<ul>
<?php foreach($posts as $id=>$post): ?>
	<li>
		[<a href="<?php echo linkTo('/profile', ['user' => $post['author']]); ?>"><?php echo htmlspecialchars(
			$database->users->read(['_id' => $post['author']])['username']
			); ?></a>]
		[<time><?php echo relativeTime($post['when'] - time()); ?></time>]
		<a href="<?php echo linkTo('/view', ['post' => $post['_id']]);?>"><?php echo htmlspecialchars($post['title']);?></a>
	</li>
<?php endforeach; ?>
</ul>
		<?php
		break;
	case $router->route('/view'):
		if(!$database->posts->has(['_id' => @$_GET['post'] ?? 'does-not-exist']))
			throw new NotFound('帖子不存在。');
		$post = $database->posts->read(['_id' => $_GET['post']]);
		startResponse($post['title']);
		?>
<p>
	<a href="<?php linkTo('/profile', ['user' => $post['author']]); ?>">
		<?php echo htmlspecialchars($database->users->read(['_id' => $post['author']])['username']); ?>
	</a>
	写于
	<time><?php echo relativeTime($post['when'] - time()); ?></time>
</p>
<article>
	<pre><?php echo htmlspecialchars($post['content']); ?></pre>
</article>
<p><a href="<?php echo linkTo('/reply', ['in' => $post['_id']]); ?>">回复帖子</a></p>
<hr />
<?php $replies = $database->replies->read(['in' => $post['_id']], false);?>
<p>共<?php echo count($replies);?>条回复。</p>
<dl>
<?php foreach($replies as $reply): ?>
	<dt>
		<a href="<?php linkTo('/profile', ['user' => $post['author']]); ?>"><?php echo htmlspecialchars($database->users->read(['_id' => $reply['author']])['username']); ?></a>
		于
		<time><?php echo relativeTime($reply['when'] - time()); ?></time>
	</dt>
	<dd><pre><?php echo htmlspecialchars($reply['content']); ?></pre></dd>
<?php endforeach; ?>
</dl>
		<?php
		break;
	case $router->route('/post'): //post是发帖，view才是看帖。
		if(!$authorization){
			header('Location: '. linkTo('/auth', [
				'redirect' => linkTo('/post')
			]));
			startResponse('需要登录', 401);
			exit();
		}
		startResponse('发布新帖');
		?>
<form action="<?php echo linkTo('/post'); ?>" method="POST">
		<fieldset>
			<input name="title" placeholder="标题" required maxlength="120" />
		</fieldset>
		<fieldset>
			<textarea name="content" required placeholder="内容"></textarea>
			<p>温馨提示：HTML、Markdown和BBCode等排版语言都不支持，只能输入纯文本。</p>
		</fieldset>
		<fieldset>
			<button type="submit">发布</button>
		</fieldset>
</form>
		<?php 
		break;
	case $router->route('/post', 'POST'):
		if(!$authorization){
			header('Location: '. linkTo('/auth', [
				'redirect' => linkTo('/post')
			]));
			startResponse('需要登录', 401);
			exit();
		}
		assertInformation(empty($_POST['title']) || empty($_POST['content']), '标题和内容是必须的');
		assertInformation(strlen($_POST['title']) > 120, '标题最多120字，震惊部也不会起这么长标题'); //大概40个汉字，确实比震惊体长。
		$id = $database->posts->append([
			'title' => $_POST['title'],
			'content' => $_POST['content'],
			'when' => time(),
			'author' => $authorization['_id']
		])[0];
		header('Location: ' . linkTo('/view', [
			'post' => $id
		]));
		startResponse('发帖成功', 201);
		?><p>如果没有自动跳转，<a href="<?php echo linkTo('/view', ['post' => $id]); ?>">戳我跳转</a>。</p><?php
		break;
	case $router->route('/auth'): //不想双标“登录”一词——课本用“log onto”，日常用“log in”、“sign in”。
		if($authorization){
			header('Location: ' . (@$_GET['redirect'] ?? linkTo('/')));
			startResponse('你登录过', 303); //或者202？
			?><p>你正在被跳转，如果没有知觉，请<a href="<?php echo @$_GET['redirect'] ?? linkTo('/'); ?>">戳我手动跳转</a>。</p><?php 
			exit();
		}
		startResponse('登录或注册');
		?>
<form action="<?php echo linkTo('/auth', ['redirect' => @$_GET['redirect']]); ?>" method="POST">
	<fieldset>
		<legend>基本信息</legend>
		<input name="username" placeholder="用户名" required maxlength="24"/> <!--前端长24字，在后端可能超-->
		<input name="password" type="password" placeholder="密码" /><!--内容越独特就越安全-->
	</fieldset>
	<fieldset>
		<legend>操作</legend>
		<button type="submit">提交</button>
		<label>
			<input type="checkbox" name="register" /> <!--which provides register=on when checked-->
			注册新用户
		</label>
		<label>
			<input type="checkbox" name="admin-permisson-granted" disabled />
			晋升为管理员
		</label>
		<label>
			<input type="checkbox" name="i-am-not-a-robot" disabled checked />
			我不是机器人
		</label>
	</fieldset>
	<fieldset>
		<legend>声明</legend>
		<ul>
			<li>前端指定的用户名长度仅供参考，实际请以后端返回为准。</li>
			<li>使用非ASCII字符作为用户名、密码，可能会导致迁移服务器遇到字符集问题。</li>
			<li>禁用的按钮、复选框、文本框等为未实现功能，F12强使可选不会有效。</li>
		</ul>
	</fieldset>
</form>
		<?php 
		break;
	case $router->route('/auth', 'POST'):
		assertInformation(empty($_POST['username']) || empty($_POST['password']), '用户名和密码是必须的');
		assertInformation(strlen($_POST['username']) > 24, '我的程序比较笨，只认24字以下的名字');
		$id = null;
		if(isset($_POST['register'])){
			assertInformation($database->users->has(['username' => $_POST['username']]), '用户已存在');
			$id = $database->users->append([
				'username' => $_POST['username'],
				'password' => password_hash($_POST['password'], HASH_ALGORITHM),
				'when' => time(),
				'admin' => false
			])[0];
		} else {
			$user = $database->users->read(['username' => $_POST['username']]);
			//assertInformation(empty($user), '用户不存在');
			if(empty($user))
				throw new NotFound('用户不存在');
			assertInformation(!password_verify($_POST['password'], $user['password']), '密码错误');
			$id = $user['_id'];
		}
		$_SESSION['forum-authorization'] = $id;
		header('Location: ' . (@$_GET['redirect'] ?? linkTo('/')));
		startResponse('成功登录或注册', 201);
		break;
	case $router->route('/auth/reset'): //是退出登录，不是注销用户。
		unset($_SESSION['forum-authorization']);
		startResponse('成功退出', 202);
		die("你不会被自动跳转，请手动跳转。");
		break;
	case $router->route('/profile'):
		$user = null;
		if(isset($_GET['user']))
			$user = $database->users->read(['_id' => $_GET['user']]);
		else if($authorization)
			$user = $authorization;
		else {
			header('Location: '. linkTo('/auth', [
				'redirect' => linkTo('/post')
			]));
			startResponse('需要登录', 401);
			exit();
		}
		if(empty($user))
			throw new NotFound('用户不存在');
		startResponse($user['username']);
		?>
<p>注册于<time><?php echo relativeTime($user['when'] - time()); ?></time>，<?php if(!$user['admin']) echo '不'; ?>是管理员。</p>
<?php $posts = $database->posts->read(['author' => $user['_id']], false); ?>
<detail open>
	<summary>Ta的<?php echo count($posts); ?>条帖子</summary>
	<ul>
	<?php foreach($posts as $post): ?>
		<li>[<time><?php echo relativeTime($post['when'] - time()); ?></time>]<a href="<?php echo linkTo('/post', ['post' => $post['_id']]); ?>"><?php echo htmlspecialchars($post['title']); ?></a></li>
	<?php endforeach;?>
	</ul>
</detail>
<?php $replies = $database->replies->read(['author' => $user['_id']], false); ?>
<detail>
	<summary>Ta的<?php echo count($replies); ?>条回复</summary>
	<dl>
	<?php foreach($replies as $reply): ?>
		<?php $post = $database->posts->read(['_id' => $reply['in']]);?>
		<dt>[<time><?php echo relativeTime($reply['when'] - time());?></time>]在<a href="<?php echo linkTo('/view', ['post' => $reply['in']]); ?>"><?php echo htmlspecialchars($post['title']); ?></a></dt>
		<dd><pre><?php echo htmlspecialchars($reply['content']); ?></pre></dd>
	<?php endforeach; ?>
	</dl>
</detail>
		<?php //要是每个人都打开 short_open_tags 多美妙。
		break;
	case $router->route('/reply'):
		if(!$authorization){
			header('Location: '. linkTo('/auth', [
				'redirect' => linkTo('/reply', [
					'in' => @$_GET['in']
				])
			]));
			startResponse('需要登录', 401);
			exit();
		}
		assertInformation(empty($_GET['in']), '没有指定帖子');
		$post = $database->posts->read(['_id' => $_GET['in']]);
		assertInformation(empty($post), '帖子不存在');
		startResponse('回复至《' . htmlspecialchars($post['title']) . '》');
		?>
<form action="<?php echo linkTo('/reply', ['in' => $_GET['in']]); ?>" method="POST">
	<fieldset>
		<textarea name="content" placeholder="内容"></textarea>
	</fieldset>
	<fieldset><button type="submit">回复</button></fieldset>
	<fieldset>
		<legend>声明</legend>
		<ul>
			<li>同发帖，回复不支持任何排版语言。</li>
			<li>不支持楼中楼回复和@，请直接在回复中指名道姓、引经据典。</li>
		</ul>
	</fieldset>
</form>
		<?php
		break; 
	case $router->route('/reply', 'POST'):
		if(!$authorization){
			header('Location: '. linkTo('/auth', [
				'redirect' => linkTo('reply', [
					'in' => @$_GET['in']
				])
			]));
			startResponse('需要登录', 401);
			exit();
		}
		assertInformation(empty($_POST['content']), '内容是必须的');
		assertInformation(empty($_GET['in']), '没有指定帖子');
		assertInformation(!$database->posts->has(['_id' => $_GET['in']]), '帖子不存在');
		$database->replies->append(['content' => $_POST['content'], 'when' => time(), 'in' => $_GET['in'], 'author' => $authorization['_id']]);
		header('Location: ' . linkTo('/view', ['post' => $_GET['in']]));
		startResponse('发布成功');
		break;
	case $router->route('/' . (string)(int)((time() - 1150081200)/3600)):
		startResponse('“测试重要勿删”', 418);
		?>茶壶说：“小朋友你是不是有很多问号？”<?php 
		for($i = 0; $i < 40; ++$i):
			?><div style="position: absolute; height: 4px; width: <?php echo mt_rand(1, 46);?>%; border-radius: 2px; background: rgb(<?php echo mt_rand(0, 255);?>, <?php echo mt_rand(0, 255);?>, <?php echo mt_rand(0, 255);?>); left: <?php echo mt_rand(0, 75);?>%; top: <?php echo mt_rand(0, 75);?>%; transform: rotate(<?php echo mt_rand(0, 359); ?>deg);">?????????????????????????????</div><?php 
		endfor;
		break;
	case $router->methodNotAllowed():
		throw new MethodNotAllowed('不合法的请求。');
		break;
	case $router->notFound():
		throw new NotFound('页面不存在。');
		break;
	default:
		throw new Exception('布吉岛的错误');
		break;
}
}catch(Exception $exception){
	if($exception instanceof NotFound)
		startResponse('页面不存在', 404);
	else if($exception instanceof MethodNotAllowed)
		startResponse('非法请求', 405);
	else
		startResponse('奇怪的Exception出现了', 500);
	?>
<pre>
信息：<?php echo htmlspecialchars($exception->getMessage()); ?>
编号：<?php echo $exception->getCode(); ?>
位置：文件“<?php echo $exception->getFile(); ?>”的第<?php echo $exception->getLine(); ?>行。
来龙去脉：

<?php var_dump($exception->getTrace()); ?>
</pre>
	<?php
}