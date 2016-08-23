<?php

namespace Laravoole\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;

class LaravooleCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravoole {action : start | stop | reload | reload_task | restart | quit}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Start laravoole';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function fire()
    {
        switch ($action = $this->argument('action')) {

            case 'start':
                $this->start();
                break;
            case 'restart':
                $pid = $this->sendSignal(SIGTERM);
                $time = 0;
                while (posix_getpgid($pid) && $time <= 10) {
                    usleep(100000);
                    $time++;
                }
                if ($time > 100) {
                    echo 'timeout' . PHP_EOL;
                    exit(1);
                }
                $this->start();
                break;
            case 'stop':
            case 'quit':
            case 'reload':
            case 'reload_task':

                $map = [
                    'stop' => SIGTERM,
                    'quit' => SIGQUIT,
                    'reload' => SIGUSR1,
                    'reload_task' => SIGUSR2,
                ];
                $this->sendSignal($map[$action]);
                break;

        }
    }

    protected function sendSignal($sig)
    {
        if ($pid = $this->getPid()) {

            posix_kill($pid, $sig);
        } else {

            echo "not running!" . PHP_EOL;
            exit(1);
        }
    }

    protected function start()
    {
        if ($this->getPid()) {
            echo 'already running' . PHP_EOL;
            exit(1);
        }

        $mode = config('laravoole.base_config.mode');
        if (!$mode) {
            echo "Laravoole needs Swoole or Workerman." . PHP_EOL .
                "You can install Swoole by command:" . PHP_EOL .
                " pecl install swoole" . PHP_EOL .
                "Or you can install Workerman by command:" . PHP_EOL .
                " composer require workerman/workerman" . PHP_EOL;
            exit;
        }

        $wrapper = "Laravoole\\Wrapper\\{$mode}Wrapper";

        foreach ([
            'handler_config' => $wrapper::getParams(),
        ] as $config_name => $params) {
            $$config_name = [];
            foreach ($params as $paramName => $default) {
                if (is_int($paramName)) {
                    $paramName = $default;
                    $default = null;
                }
                $key = $paramName;
                $value = config("laravoole.{$config_name}.{$key}", function () use ($key, $default) {
                    return env("LARAVOOLE_" . strtoupper($key), $default);
                });
                if ($value !== null) {
                    if ((is_array($value) || is_object($value)) && is_callable($value)) {
                        $value = $value();
                    }
                    $$config_name[$paramName] = $value;
                }
            }

        }

        if (!strcasecmp('SwooleFastCGI', $mode)) {
            $handler_config['dispatch_mode'] = 2;
        }

        global $argv;
        $configs = [
            'host' => config('laravoole.base_config.host'),
            'port' => config('laravoole.base_config.port'),
            'mode' => config('laravoole.base_config.mode'),
            'pid_file' => config('laravoole.base_config.pid_file'),
            'root_dir' => base_path(),
            // for swoole / workerman
            'handler_config' => $handler_config,
            // for wrapper, like http / fastcgi / websocket
            'wrapper_config' => config('laravoole.wrapper_config'),
            'argv' => $argv,
        ];

        $handle = popen('/usr/bin/env php ' . __DIR__ . '/../../laravoole', 'w');
        fwrite($handle, serialize($configs));
        fclose($handle);
    }

    protected function getPid()
    {

        $pid_file = config('laravoole.base_config.pid_file');
        if (file_exists($pid_file)) {
            $pid = file_get_contents($pid_file);
            if (posix_getpgid($pid)) {
                return $pid;
            } else {
                unlink($pid_file);
            }
        }
        return false;
    }

}
