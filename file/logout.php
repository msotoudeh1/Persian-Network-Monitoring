<?php
require_once 'config.php';

// پاک کردن تمام session ها
session_unset();
session_destroy();

// انتقال به صفحه لاگین
header('Location: login.php');
exit();
?>