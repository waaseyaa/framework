# Web server front-controller setup

Point the document root at the application's `public/` directory and send every
request that is not a real file to `public/index.php`. Without that fallback,
`/` may work through `DirectoryIndex` while every clean URL returns a web-server
404 before Waaseyaa can route it.

The skeleton includes `public/.htaccess` for Apache installations that permit
`AllowOverride`. Prefer the explicit virtual-host configuration below when you
control the server. Keep directory listing disabled.

## Apache

```apache
<VirtualHost *:80>
    ServerName example.test
    DocumentRoot /srv/waaseyaa/public

    <Directory /srv/waaseyaa/public>
        AllowOverride None
        Options -Indexes
        Require all granted
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>
</VirtualHost>
```

## nginx

```nginx
server {
    listen 80;
    server_name example.test;
    root /srv/waaseyaa/public;
    index index.php;
    autoindex off;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
    }
}
```

## Caddy

```caddyfile
example.test {
    root * /srv/waaseyaa/public
    php_fastcgi unix//run/php/php8.5-fpm.sock {
        try_files {path} /index.php?{query}
    }
    file_server
}
```

After deploying, set `APP_URL` to the site's public origin and run
`./vendor/bin/waaseyaa health:check`. The **Clean URL routing** check requests a
known non-root route and fails with `CLEAN_URL_ROUTING_UNREACHABLE` unless the
Waaseyaa router returns its sentinel response.
