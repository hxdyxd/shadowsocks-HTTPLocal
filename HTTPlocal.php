<?php
/**
 * This file is another version of shadowsocks-php-local.
 * Based on shadowsocks-php by walkor<walkor@workerman.net>
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 * 
 */
use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Autoloader;
require_once __DIR__ . '/Workerman/Autoloader.php';
require_once __DIR__ . '/config.php';
Autoloader::setRootPath(__DIR__);
define('ADDRTYPE_IPV4', 1);
define('ADDRTYPE_IPV6', 4);
define('ADDRTYPE_HOST', 3);


$worker = new Worker('tcp://0.0.0.0:'.$LOCAL_PORT);
$worker->count = $PROCESS_COUNT;
$worker->name = 'shadowsocks-HTTPlocal';

if($METHOD == 'table'){
    Encryptor::initTable($PASSWORD);
}

$worker->onConnect = function($connection)use($METHOD, $PASSWORD){
    $connection->encryptor = new Encryptor($PASSWORD, $METHOD);
};

$worker->onMessage = function($connection, $buffer)use($LOCAL_PORT, $SERVER, $PORT){
	// Parse http header.
    list($method, $addr) = explode(' ', $buffer);
    echo $method.' '.$addr."\n";
    $url_data = parse_url($addr);
    $port = isset($url_data['port'])?"{$url_data['port']}":"80";
    $url_data['host'] = str_replace('[', '', $url_data['host']);
    $url_data['host'] = str_replace(']', '', $url_data['host']);
    $addrtype = getTypeByAddress($url_data['host']);
    if($addrtype == ADDRTYPE_IPV4){
    	$socks5_header = chr(ADDRTYPE_IPV4);
    	$socks5_header .= inet_pton($url_data['host']);
    	$socks5_header .= pack('n', $port);
    }else if($addrtype == ADDRTYPE_HOST){
    	$socks5_header = chr(ADDRTYPE_HOST);
    	$socks5_header .= chr(strlen($url_data['host']));
    	$socks5_header .= $url_data['host'];
    	$socks5_header .= pack('n', $port);
    }else{
        $socks5_header = chr(ADDRTYPE_IPV6);
        $socks5_header .= inet_pton($url_data['host']);
        $socks5_header .= pack('n', $port);
    }
    $address = "tcp://$SERVER:$PORT";
    $remote_connection = new AsyncTcpConnection($address);
    $connection->opposite = $remote_connection;
    $remote_connection->opposite = $connection;
    // 流量控制
    $remote_connection->onBufferFull = function($remote_connection){
        $remote_connection->opposite->pauseRecv();
    };
    $remote_connection->onBufferDrain = function($remote_connection){
        $remote_connection->opposite->resumeRecv();
    };

    $remote_connection->onMessage = function($remote_connection, $buffer){
        $remote_connection->opposite->send($remote_connection->opposite->encryptor->decrypt($buffer));
    };

    $remote_connection->onClose = function($remote_connection){
        $remote_connection->opposite->close();
        $remote_connection->opposite = null;
    };
    // 远程连接发生错误时（一般是建立连接失败错误），关闭客户端的连接
    $remote_connection->onError = function($remote_connection, $code, $msg)use($address){
        echo "remote_connection $address error code:$code msg:$msg\n";
        $remote_connection->close();
        if($remote_connection->opposite){
            $remote_connection->opposite->close();
        }
    };
    // 流量控制
    $connection->onBufferFull = function($connection){
        $connection->opposite->pauseRecv();
    };
    $connection->onBufferDrain = function($connection){
        $connection->opposite->resumeRecv();
    };
    $connection->onMessage = function($connection, $data){
        $connection->opposite->send($connection->encryptor->encrypt($data));
    };
    $connection->onClose = function($connection){
        $connection->opposite->close();
        $connection->opposite = null;
    };
    // 当客户端连接上有错误时，关闭远程服务端连接
    $connection->onError = function($connection, $code, $msg){
        echo "connection err code:$code msg:$msg\n";
        $connection->close();
        if(isset($connection->opposite)){
            $connection->opposite->close();
        }
    };
    // 执行远程连接
    $remote_connection->connect();
    //转发首个数据包，包含SOCKS5格式封装的目标地址，端口号等信息
    if ($method !== 'CONNECT'){
    	$buffer = $socks5_header.$buffer;
    	$buffer = $connection->encryptor->encrypt($buffer);
        $remote_connection->send($buffer);
    // POST GET PUT DELETE etc.
    }else{
    	$buffer = $socks5_header;
    	$buffer = $connection->encryptor->encrypt($buffer);
        $remote_connection->send($buffer);
        $connection->send("HTTP/1.1 200 Connection Established\r\n\r\n");
    }
};

function getTypeByAddress($addr){
	if(filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)){
		return ADDRTYPE_IPV4;
	}else if(filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)){
		return ADDRTYPE_IPV6;
	}else{
		return ADDRTYPE_HOST;
	}
}

// Run.
Worker::runAll();
