<?php

namespace Gekko\Console\Nginx;

use \Gekko\Console\{ ConsoleContext, Command};

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
        
        $is_windows = strcasecmp(substr(PHP_OS, 0, 3), 'WIN') == 0;

        if ($operation === "start")
        {
            if ($is_windows) {
                $this->startWindows($ctx);
            } 

            return 0;
        }
        else if ($operation === "stop")
        {
            if ($is_windows) {
                $this->stopWindows($ctx);
            }
        }

        return -2;
    }

    private function startWindows(ConsoleContext $ctx) : int
    {
        $tmpdir = $ctx->toLocalPath("/.tmp/nginx");
        if (!\file_exists($tmpdir))
            mkdir($tmpdir);
        
        $pid_file = $ctx->toLocalPath("/.tmp/nginx/nginx.pid");
        if (\file_exists($pid_file))
        {
            \fwrite(STDOUT, "Nginx server is already running (PID " . \file_get_contents($pid_file) .  ")");
            return -1;
        }


        $nginx_path = shell_exec("where nginx.exe");
        $nginx_path = \str_replace("nginx.exe", "", $nginx_path);
        $nginx_path = \str_replace("\n", "", $nginx_path);

        $config_content = <<<TEXT
        worker_processes  1;

        error_log  "{$tmpdir}/error.log";
        pid        "{$pid_file}";

        events {
            worker_connections  1024;
        }

        http {
            include "{$nginx_path}/conf/mime.types";
            default_type  application/octet-stream;
            client_body_temp_path "{$nginx_path}/temp/nginx-client-body";
            proxy_temp_path "{$nginx_path}/temp/nginx-proxy";
            fastcgi_temp_path "{$nginx_path}/temp/nginx-fastcgi";
            uwsgi_temp_path "{$nginx_path}/temp/nginx-uwsgi";
            scgi_temp_path "{$nginx_path}/temp/nginx-scgi";

            access_log  "{$tmpdir}/site-access.log";
            error_log  "{$tmpdir}/site-error.log";

            server {
                listen       8081;
                server_name  localhost;
                
                # For PHP files, pass to 127.0.0.1:9123
                location / {
                    root {$ctx->getRootDirectory()};
                    index   index.html index.htm;
                    fastcgi_pass   127.0.0.1:9123;
                    fastcgi_index  index.php;
                    fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
                    include        {$nginx_path}/conf/fastcgi_params;
                }
            }
        }
TEXT;

        $config_content = \str_replace(DIRECTORY_SEPARATOR, "/", $config_content);

        $nginx_conf = $ctx->toLocalPath("/.tmp/nginx/nginx.conf");
        \file_put_contents($nginx_conf, $config_content);

        pclose(popen("start /B nginx -c {$nginx_conf}", "r"));
        
        if (!\file_exists($pid_file))
            return -2;

        return 0;
    }

    private function stopWindows(ConsoleContext $ctx) : int
    {
        $pid_file = $ctx->toLocalPath("/.tmp/nginx/nginx.pid");

        if (!\file_exists($pid_file))
            return -1;


        $nginx_conf = $ctx->toLocalPath("/.tmp/nginx/nginx.conf");
        pclose(popen("nginx -c {$nginx_conf} -s stop", "r"));

        return 0;
    }
}
