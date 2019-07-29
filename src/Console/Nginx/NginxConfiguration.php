<?php

namespace Gekko\Console\Nginx;

class NginxConfiguration
{
    /**
     * Nginx binary path
     *
     * @var string
     */
    private $nginx_path;

    /**
     * Project's/site's root directory
     *
     * @var string
     */
    private $root_dir;

    /**
     * Temp folder
     *
     * @var string
     */
    private $tmp_dir;

    /**
     * File to store the Nginx process PID
     *
     * @var string
     */
    private $pid_file;

    public function __construct(string $nginx_path, string $root_dir, string $tmp_dir, string $pid_file)
    {
        $this->nginx_path = $nginx_path;
        $this->root_dir = $root_dir;
        $this->tmp_dir = $tmp_dir;
        $this->pid_file = $pid_file;
    }
 
    public function __toString() : string
    {
        return <<<NGINX
            worker_processes  1;

            error_log  "{$this->tmp_dir}/logs/error.log";
            pid        "{$this->pid_file}";

            events {
                worker_connections  1024;
            }

            http {
                include "{$this->nginx_path}/conf/mime.types";
                default_type  application/octet-stream;
                client_body_temp_path "{$this->nginx_path}/temp/nginx-client-body";
                proxy_temp_path "{$this->nginx_path}/temp/nginx-proxy";
                fastcgi_temp_path "{$this->nginx_path}/temp/nginx-fastcgi";
                uwsgi_temp_path "{$this->nginx_path}/temp/nginx-uwsgi";
                scgi_temp_path "{$this->nginx_path}/temp/nginx-scgi";

                access_log  "{$this->tmp_dir}/logs/site-access.log";
                error_log  "{$this->tmp_dir}/logs/site-error.log";

                server {
                    listen       8081;
                    server_name  localhost;
                    
                    # For PHP files, pass to 127.0.0.1:9123
                    location / {
                        root {$this->root_dir};
                        index   index.html index.htm;
                        fastcgi_pass   127.0.0.1:9123;
                        fastcgi_index  index.php;
                        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
                        include        {$this->nginx_path}/conf/fastcgi_params;
                    }
                }
            }
NGINX;

    }
}
