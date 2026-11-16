<?php
require_once('path.inc');
require_once('get_host_info.inc');
require_once('rabbitMQLib.inc');

if ($_SERVER['REQUEST_METHOD'] != 'POST')
{
    header("Location: login.html");
    exit();
}

if (!isset($_POST['type'], $_POST['username'], $_POST['password']))
{
    exit("Missing form information.");
}

$type = $_POST['type'];

if ($type != "login" && $type != "register")
{
    exit("Unsupported request.");
}

if ($type == "register")
{
    if (!isset($_POST['confirm_password']) ||
        $_POST['password'] != $_POST['confirm_password'])
    {
        exit("Passwords do not match.");
    }

    if (!isset($_POST['first_name'], $_POST['last_name'], $_POST['email']))
    {
        exit("Missing required information.");
    }
}

$client = new rabbitMQClient("WebRabbitMQ.ini", "testServer");

$request = array();
$request['type'] = $type;
$request['username'] = $_POST['username'];
$request['password'] = $_POST['password'];

if ($type == "register")
{
    $request['first_name'] = $_POST['first_name'];
    $request['last_name'] = $_POST['last_name'];
    $request['email'] = $_POST['email'];
}

$response = $client->send_request($request);

if ($type == "login")
{
//Divya
//if password was right the db gives pending token instead of session right away, real session will come after the code is verfied from
//verifyCode.php
    if (isset($response['success'], $response['pendingToken']) &&
        $response['success'] === true &&
        is_string($response['pendingToken']) &&
        $response['pendingToken'] !== "")
    {
        setcookie("pending_token", $response['pendingToken'], array(
            "expires" => time() + 900, //15 minutes
	    "path" => "/",
            "httponly" => true,
            "samesite" => "Lax",
            "secure" => !empty($_SERVER['HTTPS']) &&
                        $_SERVER['HTTPS'] !== "off"
        ));

        header("Location: login.html?step=code");
        exit();
    }

    echo "Incorrect username or password.";
}
else
{
    if (isset($response['success']) && $response['success'] === true)
    {
        echo "<script>
                alert('Registration successful! Please log in.');
                window.location.href = 'login.html';
              </script>";
        exit();
    }
    else
    {
        $message = isset($response['message'])
            ? $response['message']
            : "Registration failed. Please try again.";

        echo "<script>
                alert(" . json_encode($message) . ");
                window.location.href = 'register.html';
              </script>";
        exit();
    }
}
?>
