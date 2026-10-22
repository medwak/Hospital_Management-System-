<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

verify_csrf();
