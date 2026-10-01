<!DOCTYPE html>

<html>

<head>

    <title>Register</title>

</head>

<body>



<h1>Create Account</h1>



<form method="POST" action="register.php">



    <label>Username:</label>

    <input type="text" name="username" required>



    <br><br>



    <label>Password:</label>

    <input type="password" name="password" required>



    <br><br>



    <label>Confirm Password:</label>

    <input type="password" name="confirm_password" required>



    <br><br>



    <button type="submit">Register</button>



</form>



<br>



<a href="login.php">Login Page Login</a>



<?php

/* Need to make request method on middle vm

if ($_SERVER["REQUEST_METHOD"] == "POST") {



    $username = $_POST["username"];

    $password = $_POST["password"];

    $confirm_password = $_POST["confirm_password"];



    if ($password !== $confirm_password) {

        echo "<p>Passwords do not match.</p>";

    } else {

        echo "<p>Registration request received.</p>";

    }

}

*/

?>



</body>

</html>
