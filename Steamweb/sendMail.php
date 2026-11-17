<?php
//for sending mail using php mailer and google app pass
//Divyakumar 11/14/26

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once(__DIR__ . '/vendor/autoload.php');

function sendMail ($to,$subject,$body) 
{

	$mail = new PHPMailer(true); // for catching errors
	
	try
	{
	$mail->isSMTP();
	$mail->Host = 'smtp.gmail.com';
	$mail->Username =  "viralsphere9@gmail.com";
	$mail->Password = "vpybjodyieylpagl";  // rotating password
	$mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
	$mail->Port = 587;
	$mail->setFrom("viralsphere9@gmail.com", "SteamTrophies");
	$mail->addAddress($to);
	$mail->isHTML(false); //for now, unless we decide we want hyperlinks later
	$mail->Subject = $subject;
	$mail->Body = $body;
	$mail->send();
	
	return true;
	}
	catch (Exception $e)
	{
	error_Log("sendMail function failed: " . $mail->ErrorInfo);
	return false;
	}
}

