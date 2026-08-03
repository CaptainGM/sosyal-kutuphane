<?php
require_once 'config.php';

session_destroy();
jsonResponse([
    'success' => true,
    'message' => 'Çıkış başarılı!'
]);
?>
