<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/functions.php';

clearSession();
header('Location: index.php');
exit;
