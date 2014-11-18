<?php
//配置信息
$config = array(
    'host' => 'localhost',
    'port' => '5672',
    'login' => 'guest',
    'password' => 'guest',
    'vhost' => '/'
);
$exname = 'blackbox'; //交换机名
$route = ''; //路由key

//创建连接和channel
$conn = new AMQPConnection($config);
if (!$conn->connect()) {
    die("Cannot connect to the broker!\n");
}
$channel = new AMQPChannel($conn);

//创建交换机对象
$ex = new AMQPExchange($channel);
$ex->setName($exname);

//消息内容
$message = "TEST MESSAGE! 测试消息！";
//发送消息
for ($i = 0; $i < 5; ++$i) {
    echo "Send Message:" . $ex->publish($message, $route) . "\n";
}

//$data = array(
//    'topic' => 'test',
//);
////消息内容
//$message = json_encode($data);
////发送消息
//for ($i = 0; $i < 5; ++$i) {
//    echo "Send Message:" . $ex->publish($message, $route) . "\n";
//}

$conn->disconnect();
