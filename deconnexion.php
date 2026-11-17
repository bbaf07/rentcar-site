<?php
require __DIR__ . '/inc/app.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifier_jeton();
    $_SESSION = [];
    session_regenerate_id(true);
    flash('info', 'Vous êtes déconnecté.');
}
rediriger('index.php');
