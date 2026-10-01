<!DOCTYPE html>

<html>

<head>

    <title>Login</title>

</head>

<body>



<h1>Login Page</h1>



<form method="POST" action="login.php">



    <label>Username:</label>

    <input type="text" name="username" required>



    <br><br>



    <label>Password:</label>

    <input type="password" name="password" required>



    <br><br>



    <button type="submit">Login</button>



</form>



<br>



<a href="register.php">Create an account</a>



<?php

/* Rabbit MQ insertion

if ($_SERVER["REQUEST_METHOD"] == "POST") {



    $username = $_POST["username"];

    $password = $_POST["password"];



    echo "<p>Login request received.</p>";

}

*/

?>



</body>

</html>
