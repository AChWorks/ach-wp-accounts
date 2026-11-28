<?php
// All HTTP requests originate in the private loopback TLS proxy.
if (!getenv('ACH_BROWSER_URL') || !getenv('ACH_TEST_WP')) { http_response_code(403); return; }
$_SERVER['HTTPS']='on';
$_SERVER['SERVER_PORT']=(string)parse_url(getenv('ACH_BROWSER_URL'),PHP_URL_PORT);
$path=rawurldecode((string)parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH));
$file=realpath(getenv('ACH_TEST_WP').$path);
if ($file && str_starts_with($file,getenv('ACH_TEST_WP').'/') && is_file($file)) {
    if (!str_ends_with($file,'.php')) { return false; }
    $_SERVER['SCRIPT_FILENAME']=$file;
    $_SERVER['SCRIPT_NAME']=$path;
    require $file;
    return;
}
if (str_ends_with($path,'/') && is_file(getenv('ACH_TEST_WP').$path.'index.php')) {
    $file=getenv('ACH_TEST_WP').$path.'index.php';
    $_SERVER['SCRIPT_FILENAME']=$file;
    $_SERVER['SCRIPT_NAME']=$path.'index.php';
    require $file;
    return;
}
$_SERVER['SCRIPT_FILENAME']=getenv('ACH_TEST_WP').'/index.php';
$_SERVER['SCRIPT_NAME']='/index.php';
require $_SERVER['SCRIPT_FILENAME'];
