#!/usr/bin/php
<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');
require_once('databaseConnection.php');  // gives us connectDB() that will be used for most of the functions


function doLogin($username,$password)
{
    $mydb = connectDB();   // needs to connect to database for what I've changed
    
    // lookup username in database

    $stmt = $mydb->prepare("SELECT id, password_hash FROM users WHERE username = ?");     // starting the SQL command and ? is used to prevent potential SQL injections
    $stmt->bind_param("s", $username);     // using the real $username var into that "?" placeholder as a string ("s").
    $stmt->execute();     // Run it
    $user = $stmt->get_result()->fetch_assoc(); // Grab the result from db and turn the user's data into a list.

    // check password - also included the username just to make sure
    if ($user && password_verify($password, $user['password_hash'])) //both have to be correct
    {
        return true; //return true
    }

    //return false if not valid
    return false;
}

function makeSession($username) 
{
    $mydb = connectDB(); //connect

    // get the user's id
    $stmt = $mydb->prepare("SELECT id FROM users WHERE username = ?"); //similar to before
    $stmt->bind_param("s", $username); //similar to before
    $stmt->execute(); //similar to before
    $user = $stmt->get_result()->fetch_assoc();

    // make a token that lasts one hour and save it (researched online for how to do this)
    $token = bin2hex(random_bytes(32));
    $expires = date("Y-m-d H:i:s", time() + 3600);

    $stmt = $mydb->prepare("INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, ?)"); // this will help ready a new SQL command to save the session details into the database.

    $stmt->bind_param("sis", $token, $user['id'], $expires); //binds these values into ???
    $stmt->execute();//run and save to database

    return $token;//return the token so the rest of it can be used
}

function requestProcessor($request)
{
  echo "received request".PHP_EOL;
  var_dump($request);
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }
  switch ($request['type'])
  {
    case "login":
      return doLogin($request['username'],$request['password']);
    case "validate_session":
      return doValidate($request['sessionId']);
  }
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("databaseRabbitMQ.ini","databaseServer");   // CHANGED to the new database ini and this databse file

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>

