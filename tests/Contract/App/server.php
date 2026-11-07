<?php

// Router for `php -S` serving the contract app: the testbench skeleton's server.php, with the
// process environment copied into $_SERVER/$_ENV (where php-fpm puts it; `php -S` does not).
foreach (getenv() as $name => $value) {
    $_SERVER[$name] ??= $value;
    $_ENV[$name] ??= $value;
}

return require getenv('TESTBENCH_WORKING_PATH').'/vendor/orchestra/testbench-core/laravel/server.php';
