<?php
session_start();
session_destroy();
header("Location: ../Login/login.php?success=" . urlencode("You have been logged out successfully!"));
exit();
?>
