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

// need to create a register function because we have one :/
function doRegister($username,$password) // it will need username and password for now 
{
    $mydb = connectDB(); //connect again

    // basic check to make sure that the username they create isn't already taken
    $stmt = $mydb->prepare("SELECT id FROM users WHERE username = ?"); 
    $stmt->bind_param("s", $username); //binding the username
    $stmt->execute(); //running it
    if ($stmt->get_result()->num_rows > 0) // checks whether the name matches with another user within the database
    {
        return false; //return false if its geater that 1 (matches with a name from the db)
    }

    // was created from what I saw online for hashed passwords
    $hash = password_hash($password, PASSWORD_DEFAULT); 
    $stmt = $mydb->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->bind_param("ss", $username, $hash);
    $stmt->execute();

    return true; //returns true
}

function doValidate($sessionId) // creating the missing validate function from the file
{
    $mydb = connectDB(); //connect

    $stmt = $mydb->prepare("SELECT user_id FROM sessions WHERE token = ? AND expires_at > NOW()"); //validate the tokens expiration and token itself
    $stmt->bind_param("s", $sessionId);//bind
    $stmt->execute();

    if ($stmt->get_result()->num_rows == 1) //checks 
    {
        return true;
    }
    return false;
}

function doLogout($sessionId)
{
    $mydb = connectDB(); //connect

    $stmt = $mydb->prepare("DELETE FROM sessions WHERE token = ?"); //removes then from database
    $stmt->bind_param("s", $sessionId); //bind
    $stmt->execute(); //execute

    return true;
}

function requestProcessor($request)
{
  echo "received request".PHP_EOL;
  //var_dump($request);
  if(!isset($request['type']))
  {
    return "ERROR: unsupported message type";
  }
   echo "type: ".$request['type'].PHP_EOL; //prints and logs in the listener's terminal
  try                                            
  {    
    switch ($request['type'])
    {
        case "login":
        if (doLogin($request['username'],$request['password']))
        {
            $token = makeSession($request['username']);
            return array("success" => true, "message" => "login ok", "token" => $token);
        }
        return array("success" => false, "message" => "wrong username or password");
        case "register": //added a case to register
        if (doRegister($request['username'],$request['password']))
            {
                return array("success" => true, "message" => "account created"); //if true show that the account is create
            }
        return array("success" => false, "message" => "username already taken"); //if not then that the username is taken
        case "validate_session":
        if (doValidate($request['sessionId']))
        {
            return array("success" => true, "message" => "session valid");
        }
        return array("success" => false, "message" => "session invalid");
        case "logout":
        if (doLogout($request['sessionId']))
        {
            return array("success" => true, "message" => "logged out");
        }
        return array("success" => false, "message" => "logout failed");
    }
  }    
  catch (Throwable $e)                          //helps catch and explain errors for mysql
  {                                              
    echo "ERROR: ".$e->getMessage().PHP_EOL;     
    return array("success" => false, "message" => "server error");  
  }                                              
  return array("returnCode" => '0', 'message'=>"Server received request and processed");
}

$server = new rabbitMQServer("databaseRabbitMQ.ini","databaseServer");   // CHANGED to the new database ini and this databse file

echo "testRabbitMQServer BEGIN".PHP_EOL;
$server->process_requests('requestProcessor');
echo "testRabbitMQServer END".PHP_EOL;
exit();
?>

