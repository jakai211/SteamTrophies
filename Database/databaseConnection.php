<?php
function connectDB()
{
    require('db_config.php');

    $mydb = new mysqli($db_host, $db_user, $db_pass, $db_name);

    if ($mydb->connect_errno != 0)
    {
        echo "failed to connect to database: " . $mydb->connect_error . PHP_EOL;
        return false;
    }

    return $mydb;
}
?>