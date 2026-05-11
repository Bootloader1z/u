<?php
// Tiny FastCGI PING client for docker healthcheck.
// Exits 0 if php-fpm accepts a TCP connection on 127.0.0.1:9000.
// Kept deliberately minimal so it works with strict ini restrictions.

$fp = @stream_socket_client("tcp://127.0.0.1:9000", $errno, $errstr, 2.0);
if ($fp === false) {
    fwrite(STDERR, "fpm-ping: connect failed ($errno) $errstr\n");
    exit(1);
}
fclose($fp);
exit(0);
