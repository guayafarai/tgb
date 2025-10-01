<?php
require_once '../config/database.php';
require_once '../includes/auth.php';

setSecurityHeaders();
startSecureSession();

$auth->logout();

header('Location: login.php');
exit();
?>