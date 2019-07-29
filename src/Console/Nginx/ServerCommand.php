<?php

namespace Gekko\Console\Nginx;

use \Gekko\Console\{ ConsoleContext, Command, PHP\FastCGICommand};

class ServerCommand extends Command
{
    public function run(ConsoleContext $ctx) : int
    {
        // argv[1] should be the ServerCommand's name
        if ($ctx->getArgumentsCount() < 3)
        {
            \fwrite(STDOUT, "Usage: gko nginx [ start | stop ]");
            return -1;
        }

        $operation = $ctx->getArguments()[2];
        
        if ($operation === "start")
        {
            $pid = $this->start($ctx);

            return $pid > 0 ? 0 : -1;
        }
        else if ($operation === "stop")
        {
            return $this->stop($ctx) ? 0 : -1;
        }

        return -2;
    }

    protected function isWindowsOs() : bool
    {
        return strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0;
    }

    private function start(ConsoleContext $ctx) : int
    {        
        $pid = -1;

        if ($this->isWindowsOs())
            $pid = $this->startWindows($ctx);

        if ($pid > 0)
        {
            if ($this->startPhpCgi($ctx) <= 0)
            {
                $this->stop($ctx);
                fprintf(STDERR, "Couldn't start PHP FastCGI process, Nginx relies on it to work.");
                return -1;
            }
        }

        return $pid;
    }

    private function stop(ConsoleContext $ctx) : bool
    {
        // Try to stop PHP CGI, (don't worry about errors)
        $this->stopPhpCgi($ctx);

        if ($this->isWindowsOs())
            return $this->stopWindows($ctx);

        return false;
    }

    private function startPhpCgi(ConsoleContext $ctx) : int
    {
        // Nginx relies on FastCGI, so we need to run it using the php-console package,
        // particularly the FastCGICommand
        $cgi_command = $ctx->getInjector()->make(FastCGICommand::class);
        
        if ($cgi_command == null)
            return -1;

        return $cgi_command->start($ctx);
    }

    private function stopPhpCgi(ConsoleContext $ctx) : bool
    {
        // Nginx relies on FastCGI, so we need to run it using the php-console package,
        // particularly the FastCGICommand
        $cgi_command = $ctx->getInjector()->make(FastCGICommand::class);
        
        if ($cgi_command == null)
            return -1;

        return $cgi_command->stop($ctx);
    }

    private function startWindows(ConsoleContext $ctx) : int
    {
        // Build/get the temp folder
        $tmp_dir = $ctx->toLocalPath("/.tmp/nginx");
        if (!\file_exists($tmp_dir))
            mkdir($tmp_dir, 0777, true);

        // Create the logs dir (this also satisfies Nginx's alert when it fails to create the error log file)
        $nginx_logs_dir = $ctx->toLocalPath("/.tmp/nginx/logs");
        if (!\file_exists($nginx_logs_dir))
            mkdir($nginx_logs_dir, 0777, true);
        
        // Check if nginx is already running
        $pid_file = $ctx->toLocalPath("/.tmp/nginx/nginx.pid");
        if (\file_exists($pid_file))
        {
            \fwrite(STDOUT, "Nginx server is already running (PID " . \str_replace("\r\n", "", \file_get_contents($pid_file)) .  ")");
            return -1;
        }

        // We need the Nginx path to access configuration files
        $nginx_path = $this->getNginxPath();

        if ($nginx_path == null)
        {
            \fwrite(STDOUT, "Couldn't find nginx binary. Make sure your PATH is correct.");
            return -2;
        }

        $config = new NginxConfiguration($nginx_path, $ctx->getRootDirectory(), $tmp_dir, $pid_file);
        $config_content = \str_replace(DIRECTORY_SEPARATOR, "/", $config->__toString());

        $nginx_conf = $ctx->toLocalPath("/.tmp/nginx/nginx.conf");
        \file_put_contents($nginx_conf, $config_content);

        pclose(popen("start /B nginx -c {$nginx_conf} -p {$tmp_dir}", "r"));
        
        $pid = -1;
        $tries = 0;
        while ($tries++ < 10)
        {
            if (\file_exists($pid_file))
            {
                $pid = \intval(\file_get_contents($pid_file));
                break;
            }
            \usleep(500000);
        }

        if ($tries >= 10)
            fwrite(STDERR, "Couldn't find the process PID, the PID file does not exist");

        return $pid;
    }

    private function getNginxPath() : ?string
    {
        $nginx_path = null;

        if ($this->isWindowsOs())
        {
            $nginx_path = shell_exec("where nginx.exe");

            if (\strstr($nginx_path, "Could not find files for the given pattern") !== false)
                return null;

            $nginx_path = \str_replace("nginx.exe", "", $nginx_path);
            $nginx_path = \str_replace("\n", "", $nginx_path);
        }

        return $nginx_path;
    }

    private function stopWindows(ConsoleContext $ctx) : bool
    {
        $pid_file = $ctx->toLocalPath("/.tmp/nginx/nginx.pid");

        if (!\file_exists($pid_file))
            return false;


        $tmp_dir = $ctx->toLocalPath("/.tmp/nginx");
        $nginx_conf = $ctx->toLocalPath("/.tmp/nginx/nginx.conf");
        pclose(popen("nginx -c {$nginx_conf} -s stop -p {$tmp_dir}", "r"));

        return true;
    }
}
