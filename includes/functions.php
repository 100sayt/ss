<?php
function sanitize_input($data) { return htmlspecialchars($data); }
function redirect($url) { header("Location: $url"); }
function is_logged_in() { return false; }
function is_admin() { return false; }
function ensure_database_schema($pdo) {}
function check_remember_me($pdo) {}
function check_expired_boosts($pdo) {}
function lang_col($col) { return $col . "_az"; }
function render_ad($pdo, $pos, $class) {}
function t($key) { return $key; }
