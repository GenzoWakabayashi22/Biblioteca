<?php
/**
 * API Logout per Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

session_start();

// Include la configurazione del database per eventuali log
require_once '../config/database.php';

// Salva informazioni per il log prima di distruggere la sessione
$user_info = null;
if (isset($_SESSION['fratello_id']) && isset($_SESSION['fratello_nome'])) {
    $user_info = [
        'id' => $_SESSION['fratello_id'],
        'nome' => $_SESSION['fratello_nome'],
        'login_time' => $_SESSION['login_time'] ?? null,
        'last_activity' => $_SESSION['last_activity'] ?? null
    ];
}

// Calcola durata della sessione
$session_duration = null;
if ($user_info && $user_info['login_time']) {
    $session_duration = time() - $user_info['login_time'];
}

// Distrugge tutte le variabili di sessione
$_SESSION = array();

// Se è desiderato eliminare completamente la sessione, eliminare anche il cookie di sessione
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Distrugge la sessione
session_destroy();

// Log del logout (se l'utente era loggato)
if ($user_info) {
    $duration_formatted = $session_duration ? gmdate("H:i:s", $session_duration) : 'N/A';
    error_log("Logout: {$user_info['nome']} (ID: {$user_info['id']}) - Durata sessione: {$duration_formatted}");
}

// Verifica se è una richiesta AJAX
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Risposta JSON per richieste AJAX
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Logout effettuato con successo',
        'redirect' => '../index.php'
    ]);
    exit;
}

// Verifica se è specificato un URL di redirect personalizzato
$redirect_url = '../index.php';
if (isset($_GET['redirect']) && !empty($_GET['redirect'])) {
    // Valida l'URL di redirect per sicurezza
    $custom_redirect = filter_var($_GET['redirect'], FILTER_SANITIZE_URL);
    
    // Permetti solo redirect interni al sito
    if (strpos($custom_redirect, '/') === 0 || strpos($custom_redirect, '../') === 0) {
        $redirect_url = $custom_redirect;
    }
}

// Redirect alla pagina di login con messaggio di conferma
header("Location: {$redirect_url}?logout=success");
exit;
?>