<?php
$link = mysqli_connect("database", "root", $_ENV['MYSQL_ROOT_PASSWORD'], null);

if (!$link) {
    echo "Error: Unable to connect to MySQL." . PHP_EOL;
    echo "Debugging errno: " . mysqli_connect_errno() . PHP_EOL;
    echo "Debugging error: " . mysqli_connect_error() . PHP_EOL;
    exit;
}

echo "Success: A proper connection to MySQL was made! The docker database is great." . PHP_EOL;

mysqli_close($link);

error_reporting(E_ALL & ~E_NOTICE);

$mc = new Memcached();
$mc->addServer("memcached", 11211);

$mc->set("foo", "Hello!");
$mc->set("bar", "Memcached...");

var_dump($mc->getAllKeys());
$mc->quit();

