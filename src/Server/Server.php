<?php


namespace Monyxie\FtpDemo\Server;


use Closure;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Logger;
use Psr\Log\LoggerAwareTrait;
use React\EventLoop\Factory as LoopFactory;
use React\EventLoop\LoopInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector as SocketConnector;
use React\Socket\Server as SocketServer;

class Server
{
    const VERSION = '0.0.1';

    use LoggerAwareTrait;

    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var SocketServer
     */
    private $controlServer;
    /**
     * @var array
     */
    private $sessions;
    /**
     * @var array
     */
    private $options;
    /**
     * @var SocketConnector
     */
    private $activeConnector;

    /**
     * Server constructor.
     * @param LoopInterface|null $loop
     * @param array $options
     */
    public function __construct(LoopInterface $loop = null, array $options = [])
    {
        $this->loop = $loop ?? LoopFactory::create();
        $this->sessions = [];
        $this->options = array_merge([
            'listen_ip' => '127.0.0.1',
            'listen_port' => '2121',
            'anonymous' => true,
            'root_dir' => null,
            'users' => []
        ], $options);
        if ($this->options['root_dir'] === null) {
            $this->options['root_dir'] = getcwd();
        }
        $this->activeConnector = new SocketConnector($this->loop);


        $logger = new Logger(__CLASS__);
        $logger->pushHandler(new ErrorLogHandler());
        $this->setLogger($logger);
    }

    public function run($listen)
    {
        if ($listen) {
            list($this->options['listen_ip'], $this->options['listen_port']) = explode(':', $listen);
        }
        $this->prepare($this->options['listen_ip'] . ':' . $this->options['listen_port']);
        $this->loop->run();
    }

    protected function prepare($listen)
    {
        $this->controlServer = new SocketServer($listen, $this->loop);

        $this->controlServer->on('connection', function (ConnectionInterface $connection) {
            $this->logger->debug('connection established');

            $connection->write("220 (FTP Demo v" . static::VERSION . ")\n");

            $connection->on('data', function ($data) use ($connection) {
                $this->handleControlData($connection, $data);
            });

            $connection->on('close', function () use ($connection) {
                $this->logger->debug('connection closed');
                $sessionId = spl_object_id($connection);
                if (isset($this->sessions[$sessionId])) {
                    if (isset($this->sessions[$sessionId]['passive_server'])) {
                        $this->sessions[$sessionId]['passive_server']->close();
                    }
                    unset($this->sessions[$sessionId]);
                }
            });
        });
    }

    private function handleControlData(ConnectionInterface $connection, $data)
    {
        $this->logger->debug('command received: ' . $data);

        $sessionId = spl_object_id($connection);

        $command = mb_strtoupper(trim(substr($data, 0, 4)));
        if ($command === '') {
            return;
        }

        if (!isset($this->sessions[$sessionId])) {
            $this->sessions[$sessionId] = [
                'user' => null,
                'authenticated' => false,
                'active_address' => null,
                'passive_command' => null,
                'passive_server' => null,
                'cwd' => '/',
                'mode' => 'I',
            ];
        }

        $session = &$this->sessions[$sessionId];

        $args = trim(substr($data, strlen($command)));


        switch ($command) {
            case 'USER':
                if ($session['authenticated'] ?? false) {
                    $connection->write("530 Already logged in.\n");
                    return;
                }
                $session['user'] = $args;
                $connection->write("331 Please specify the password.\n");
                break;
            case 'PASS':
                if ($session['authenticated'] ?? false) {
                    $connection->write("530 Already logged in.\n");
                    return;
                }
                if (!isset($session['user'])) {
                    $connection->write("503 Login with USER first.\n");
                    return;
                }
                if ($this->passwordMatches($session['user'], $args)) {
                    $session['authenticated'] = true;
                    $connection->write("230 Login successful.\n");
                    return;
                }

                unset($session['user']);
                $connection->write("530 Login incorrect.\n");
                break;
            case 'PORT':
                // 指定主动模式回连地址
                if (!$this->ensureAuth($session, $connection)) return;

                $port = $this->parsePort($args);
                if (!$port) {
                    $connection->write("500 Illegal port command.\n");
                    return;
                }

                $session['active_address'] = $port;
                if (isset($this->sessions[$sessionId]['passive_server'])) {
                    $this->sessions[$sessionId]['passive_server']->close();
                    $session['passive_server'] = null;
                }

                $connection->write("200 PORT command successful. Consider using PASV.\n");
                break;
            case 'PWD':
                // 打印当前目录
                if (!$this->ensureAuth($session, $connection)) return;

                $connection->write("257 \"${session['cwd']}\" is the current directory.\n");
                break;
            case 'CDUP':
                $args = '../';
            // fallthrough
            case 'CWD':
                // 更改当前目录
                if (!$this->ensureAuth($session, $connection)) return;
                if ($args === '') {
                    $connection->write("550 Failed to change directory.\n");
                    return;
                }

                $pathStr = $this->options['root_dir'];
                if ($args[0] === '/') {
                    $pathStr .= $args;
                } else {
                    $pathStr .= '/' . $session['cwd'] . '/' . $args;
                }
                $path = realpath($pathStr);

                if ($path !== $this->options['root_dir'] && (strpos($path, $this->options['root_dir'] . '/') !== 0 || !is_dir($path))) {
                    $connection->write("550 Failed to change directory.\n");
                    return;
                }

                $session['cwd'] = substr($path, strlen($this->options['root_dir'])) ?: '/';

                $connection->write("250 Directory successfully changed.\n");
                break;

            case 'TYPE':
                // 切换传输模式
                if (!$this->ensureAuth($session, $connection)) return;
                $mode = strtoupper($args);
                $names = ['I' => 'binary', 'A' => 'ASCII'];
                if (!isset($names[$mode])) {
                    $connection->write("500 Unrecognised TYPE command.\n");
                    return;
                }

                $session['mode'] = $mode;
                $connection->write("200 Switching to {$names[$mode]} mode.\n");
                break;

            case 'LIST':
                // 列出目录内容
                if (!$this->ensureAuth($session, $connection)) return;
                if (!$this->ensurePort($session, $connection)) return;
                $this->sendOrReceiveData($session, $connection, function (ConnectionInterface $dataConnection) use ($connection, $session) {
                    $dir = realpath($this->options['root_dir'] . '/' . $session['cwd']);
                    list($output) = $this->executeCommand(['ls', '-l', $dir]);
                    unset($output[0]);

                    $connection->write("150 Here comes the directory listing.\n");
                    $dataConnection->write(join("\n", $output));
                    $dataConnection->end();
                    $connection->write("226 Directory send OK.\n");
                });
                break;
            case 'RETR':
                // 下载文件
                if (!$this->ensureAuth($session, $connection)) return;
                if (!$this->ensurePort($session, $connection)) return;
                $filePath = realpath($this->options['root_dir'] . '/' . $session['cwd'] . '/' . $args);
                if (strpos($filePath, $this->options['root_dir'] . '/') !== 0 || !is_file($filePath)) {
                    $connection->write("550 File not found.\n");
                    return;
                }

                $this->sendOrReceiveData($session, $connection, function (ConnectionInterface $dataConnection) use ($args, $connection, $session, $filePath) {
                    $isBinary = $session['mode'] === 'I';
                    try {
                        $fp = fopen($filePath, $isBinary ? 'rb' : 'r');
                        if (!$fp) {
                            $connection->write("550 Failed to open file.\n");
                            return;
                        }

                        $modeStr = $isBinary ? 'BINARY' : 'ASCII';
                        $connection->write("150 Opening $modeStr mode data connection for $args.\n");
                        while (!feof($fp)) {
                            $dataConnection->write(fread($fp, 1024));
                        }
                        $dataConnection->end();
                        $connection->write("226 Transfer complete.\n");
                    } finally {
//                        if ($fp) {
//                            fclose($fp);
//                        }
                    }
                });
                break;
            case 'STOR':
                // 上传文件
                if (!$this->ensureAuth($session, $connection)) return;
                if (!$this->ensurePort($session, $connection)) return;
                if (strpos($args, '..') !== false) {
                    $connection->write("550 Permission denied.\n");
                }

                $dirPath = realpath($this->options['root_dir'] . '/' . $session['cwd']);
                $filePath = $dirPath . '/' . $args;
                if (($dirPath !== $this->options['root_dir'] && strpos($dirPath, $this->options['root_dir'] . '/') !== 0) || file_exists($filePath)) {
                    $connection->write("550 Permission denied.\n");
                    return;
                }

                $this->sendOrReceiveData($session, $connection, function (ConnectionInterface $dataConnection) use ($args, $connection, $session, $filePath) {
                    $isBinary = $session['mode'] === 'I';
                    try {
                        $fp = fopen($filePath, $isBinary ? 'xb' : 'x');
                        if (!$fp) {
                            $connection->write("550 Failed to open file.\n");
                            return;
                        }

                        $dataConnection->on('data', function ($data) use ($fp) {
                            fwrite($fp, $data);
                        });
                        $dataConnection->on('close', function () use ($dataConnection, $fp, $connection) {
                            if ($fp) {
                                fclose($fp);
                            }
                            $dataConnection->end();
                            $connection->write("226 Transfer complete.\n");
                        });

                        $connection->write("150 Ok to send data.\n");
                    } finally {
//                        if ($fp) {
//                            fclose($fp);
//                        }
                    }
                });
                break;
            case 'DELE':
                if (!$this->ensureAuth($session, $connection)) return;
                if (!$this->ensurePort($session, $connection)) return;
                $filePath = realpath($this->options['root_dir'] . '/' . $session['cwd'] . '/' . $args);
                if (strpos($filePath, $this->options['root_dir'] . '/') !== 0 || !is_file($filePath)) {
                    $connection->write("550 Permission denied.\n");
                    return;
                }

                unlink($filePath);

                $connection->write("250 Delete operation successful.\n");
                // 删除文件
                break;
            case 'PASV':
                // 切换到被动模式
                if (!$this->ensureAuth($session, $connection)) return;
                // 生成随机端口号
                $p1 = rand(100, 256);
                $p2 = rand(0, 255);
                $response = str_replace('.', ',', $this->options['listen_ip']) . ",$p1,$p2";
                $session['active_address'] = null;
                $passivePort = $this->options['listen_ip'] . ":" . ($p1 * 256 + $p2);
                if (isset($session['passive_server'])) {
                    $session['passive_server']->close();
                }
                $session['passive_server'] = new SocketServer($passivePort, $this->loop);

                $this->logger->debug('passive data server listening at: ' . $passivePort);
                $connection->write("227 Entering Passive Mode ($response).\n");
                break;
            default:
                $this->logger->warning("unknown command: $command");
                $connection->write("500 Unknown command: $command.\n");
                break;
        }
    }

    private function passwordMatches(string $user, string $password)
    {
        if ($user === 'anonymous' && $this->options['anonymous']) {
            return true;
        }

        if (isset($this->options['users'][$user]) && $this->options['users'][$user] === $password) {
            return true;
        }

        return false;
    }

    /**
     * @param string $args
     * @return string
     */
    private function parsePort(string $args)
    {
        $segments = explode(',', $args);
        if (count($segments) !== 6) {
            return null;
        }
        foreach ($segments as $segment) {
            $integer = filter_var($segment, FILTER_VALIDATE_INT);
            if ($integer === false || $integer < 0 || $integer > 256) {
                return null;

            }
        }

        $port = $segments[4] * 256 + $segments[5];

        $host = join('.', array_slice($segments, 0, 4));
        if (filter_var($host, FILTER_VALIDATE_IP | FILTER_FLAG_IPV4)) {
            return null;
        }

        return "$host:$port";
    }

    private function ensureAuth(array $session, ConnectionInterface $connection)
    {
        if (!$session['authenticated'] ?? false) {
            $connection->write("530 Please login with USER and PASS.\n");
            return false;
        }
        return true;
    }

    private function ensurePort(array $session, ConnectionInterface $connection)
    {
        if (!$session['active_address'] && !$session['passive_server']) {
            $connection->write("User PORT or PASV first.");
            return false;
        }
        return true;
    }

    /**
     * @param array $array
     * @return array
     */
    private function executeCommand(array $array)
    {
        $args = array_map('escapeshellarg', $array);
        $code = exec(join(' ', $args), $output);
        return [$output, $code];
    }

    /**
     * @param $session
     * @param ConnectionInterface $controlConnection
     * @param Closure $callback
     */
    private function sendOrReceiveData($session, ConnectionInterface $controlConnection, Closure $callback)
    {
        if ($session['active_address']) {
            // 主动模式
            $this->activeConnector
                ->connect($session['active_address'])
                ->then(function (ConnectionInterface $dataConnection) use ($session, $callback) {
                    $this->logger->debug('active data connection established at: ' . $session['active_address']);
                    $callback($dataConnection);
                })
                ->otherwise(function () use ($controlConnection) {
                    $controlConnection->write("425 Failed to establish connection.\n");
                });
        } else if ($session['passive_server']) {
            // 被动模式
            /** @var SocketServer $dataServer */
            $dataServer = $session['passive_server'];
            $dataServer->on('connection', function (ConnectionInterface $dataConnection) use ($dataServer, $callback) {
                $this->logger->debug('passive data connection established');
                $callback($dataConnection);

                $dataConnection->on('close', function () use ($dataServer) {
                    $dataServer->close();
                });
            });
        }
    }

}
