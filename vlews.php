<?php

$uuid = getenv('UUID');
if (!$uuid) {
    $uuid = "37a0bd7c8b9f46938916bd1e2da0a817";
}
$uuid = str_replace('-', '', $uuid);

$port = getenv('PORT');
if (!$port) {
    $port = "8080";
}

$proxySessions = [];

$server = new Swoole\WebSocket\Server("0.0.0.0", (int)$port);

$server->set([
    'heartbeat_idle_time'   => 600,
    'heartbeat_check_interval' => 15,
    'worker_num'            => swoole_cpu_num() * 2,
    'max_request'           => 10000,
    'dispatch_mode'         => 2,
    'reload_async'          => true,
    'buffer_output_size'    => 32 * 1024 * 1024,
    'package_max_length'    => 10 * 1024 * 1024,
    'log_file'              => '/dev/null',
]);

$server->on("request", function($request, $response) {
    if ($request->server['request_uri'] === '/') {
        $response->status(200);
        $response->header("Content-Type", "text/plain; charset=utf-8");
        $response->end("Server is running");
    } else {
        $response->status(404);
        $response->end("Not Found");
    }
});

$server->on("start", function($server) use ($port, &$proxySessions) {
    Swoole\Timer::tick(60000, function() use (&$proxySessions, $server) {
        $now = time();
        foreach ($proxySessions as $fd => $session) {
            if ($now - $session['lastActive'] > 600) {
                if ($session['tcp']) {
                    $session['tcp']->close();
                }
                $server->close($fd);
                unset($proxySessions[$fd]);
            }
        }
    });
    // echo "Server is running on port $port\n";
});

$server->on("open", function($server, $request) {
    // echo "New WebSocket connection established: fd={$request->fd}\n";
});

$server->on("message", function($server, $frame) use (&$proxySessions, $uuid) {
    if ($frame->opcode !== WEBSOCKET_OPCODE_BINARY) {
        $server->push($frame->fd, "Server is running", WEBSOCKET_OPCODE_TEXT);
        return;
    }

    if (!isset($proxySessions[$frame->fd])) {
        handleProxyRequest($server, $frame, $uuid, $proxySessions);
    } else {
        $tcp = $proxySessions[$frame->fd]['tcp'];
        if ($tcp) {
            $ret = $tcp->send($frame->data);
            if ($ret === false) {
                $session = &$proxySessions[$frame->fd];
                if (!$session['reconnecting']) {
                    $session['reconnecting'] = true;
                    $maxAttempts = 3;
                    $reconnected = false;
                    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                        $newTcp = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                        $newTcp->set(['timeout' => 5]);
                        $ret = $newTcp->connect($session['host'], $session['targetPort'], 5);
                        if ($ret) {
                            $session['tcp'] = $newTcp;
                            $session['reconnect_attempts'] = 0;
                            $reconnected = true;
                            $newTcp->send($frame->data);
                            break;
                        } else {
                            $session['reconnect_attempts'] = $attempt;
                            Swoole\Coroutine::sleep(1);
                        }
                    }
                    $session['reconnecting'] = false;
                    if (!$reconnected) {
                        $server->close($frame->fd);
                        return;
                    }
                }
            }
            $proxySessions[$frame->fd]['lastActive'] = time();
        }
    }
});

$server->on("close", function($server, $fd) use (&$proxySessions) {
    // echo "Connection closed: fd={$fd}\n";
    if (isset($proxySessions[$fd])) {
        $tcp = $proxySessions[$fd]['tcp'];
        if ($tcp) {
            $tcp->close();
        }
        unset($proxySessions[$fd]);
    }
});

$server->start();

function handleProxyRequest($server, $frame, $uuid, &$proxySessions) {
    $message = $frame->data;
    $msgLen = strlen($message);
    if ($msgLen < 18) {
        $server->close($frame->fd);
        return;
    }

    $version = ord($message[0]);
    $id = substr($message, 1, 16);
    if (!validateUUID($id, $uuid)) {
        $server->close($frame->fd);
        return;
    }

    $lenByte = ord($message[17]);
    $i = $lenByte + 19;
    if ($msgLen < $i + 3) {
        $server->close($frame->fd);
        return;
    }

    $targetPortData = substr($message, $i, 2);
    $unpack = unpack("nport", $targetPortData);
    $targetPort = $unpack['port'];
    $i += 2;

    $atyp = ord($message[$i]);
    $i++;

    $host = "";
    switch ($atyp) {
        case 1:
            if ($msgLen < $i + 4) {
                $server->close($frame->fd);
                return;
            }
            $ipBytes = substr($message, $i, 4);
            $host = inet_ntop($ipBytes);
            $i += 4;
            break;
        case 2:
            if ($msgLen < $i + 1) {
                $server->close($frame->fd);
                return;
            }
            $domainLen = ord($message[$i]);
            $i++;
            if ($msgLen < $i + $domainLen) {
                $server->close($frame->fd);
                return;
            }
            $host = substr($message, $i, $domainLen);
            $i += $domainLen;
            break;
        case 3:
            if ($msgLen < $i + 16) {
                $server->close($frame->fd);
                return;
            }
            $ipBytes = substr($message, $i, 16);
            $host = inet_ntop($ipBytes);
            $i += 16;
            break;
        default:
            $server->close($frame->fd);
            return;
    }

    $initialData = "";
    if ($msgLen > $i) {
        $initialData = substr($message, $i);
    }

    $response = chr($version) . chr(0);
    $server->push($frame->fd, $response, WEBSOCKET_OPCODE_BINARY);

    $tcp = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
    $tcp->set(['timeout' => 5]);
    $ret = $tcp->connect($host, $targetPort, 5);
    if ($ret === false) {
        $server->close($frame->fd);
        return;
    }

    if ($initialData !== "") {
        $tcp->send($initialData);
    }

    $proxySessions[$frame->fd] = [
        'tcp'                => $tcp,
        'host'               => $host,
        'targetPort'         => $targetPort,
        'lastActive'         => time(),
        'reconnect_attempts' => 0,
        'reconnecting'       => false
    ];

    go(function() use ($server, $frame, &$proxySessions) {
        while (isset($proxySessions[$frame->fd])) {
            $session = &$proxySessions[$frame->fd];
            $tcp = $session['tcp'];
            $data = $tcp->recv();
            if ($data === false  $data === ''  $data === null) {
                if (!$session['reconnecting']) {
                    $session['reconnecting'] = true;
                    $maxAttempts = 3;
                    $reconnected = false;
                    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                        $newTcp = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
                        $newTcp->set(['timeout' => 5]);
                        $ret = $newTcp->connect($session['host'], $session['targetPort'], 5);
                        if ($ret) {
                            $session['tcp'] = $newTcp;
                            $session['reconnect_attempts'] = 0;
                            $reconnected = true;
                            break;
                        } else {
                            $session['reconnect_attempts'] = $attempt;
                            Swoole\Coroutine::sleep(1);
                        }
                    }
                    $session['reconnecting'] = false;
                    if (!$reconnected) {
                        $server->close($frame->fd);
                        break;
                    } else {
                        continue;
                    }
                } else {
                    Swoole\Coroutine::sleep(1);
                    continue;
                }
            }
            $session['lastActive'] = time();
            $server->push($frame->fd, $data, WEBSOCKET_OPCODE_BINARY);
        }
        if (isset($proxySessions[$frame->fd])) {
            unset($proxySessions[$frame->fd]);
        }
    });
}

function validateUUID($id, $uuid) {
    if (strlen($id) != 16 || strlen($uuid) != 32) {
        return false;
    }
    for ($i = 0; $i < 16; $i++) {
        $expected = hexdec(substr($uuid, $i * 2, 2));
        if (ord($id[$i]) !== $expected) {
            return false;
        }
    }
    return true;
}
?>
