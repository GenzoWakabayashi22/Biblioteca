<?php
/**
 * API SSO Login - Single Sign-On dalla app Tornate
 * R∴ L∴ Kilwinning - Sistema Biblioteca
 */

session_start();

require_once '../config/database.php';

/**
 * Verifica e decodifica JWT token
 */
function verifyAndDecodeJWT($token) {
    try {
        // Split JWT in parti
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("JWT invalido: formato non corretto");
            return null;
        }

        // Decodifica payload (parte centrale)
        $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
        
        if (!$payload) {
            error_log("JWT invalido: payload non decodificabile");
            return null;
        }

        // Verifica scadenza
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            error_log("JWT scaduto: exp = " . $payload['exp'] . ", now = " . time());
            return null;
        }

        // Verifica issuer (sorgente token)
        $valid_issuers = ['tornate_app', 'loggiakilwinning'];
        if (isset($payload['iss']) && !in_array($payload['iss'], $valid_issuers)) {
            error_log("JWT invalido: issuer non autorizzato = " . $payload['iss']);
            return null;
        }

        return $payload;

    } catch (Exception $e) {
        error_log("Errore decodifica JWT: " . $e->getMessage());
        return null;
    }
}

/**
 * Processa login SSO
 */
function processSSOLogin() {
    global $conn;

    // 1. Verifica presenza token
    $sso_token = $_GET['sso_token'] ?? '';
    
    if (empty($sso_token)) {
        error_log("SSO: Token mancante");
        return ['success' => false, 'error' => 'token_missing'];
    }

    // 2. Decodifica e valida JWT
    $payload = verifyAndDecodeJWT($sso_token);
    
    if (!$payload) {
        error_log("SSO: Token invalido o scaduto");
        return ['success' => false, 'error' => 'invalid_token'];
    }

    // 3. Estrai informazioni utente dal payload
    $nome = $payload['nome'] ?? '';
    $user_id = $payload['user_id'] ?? null;
    $source = $payload['iss'] ?? 'unknown';

    if (empty($nome)) {
        error_log("SSO: Nome utente mancante nel payload");
        return ['success' => false, 'error' => 'invalid_payload'];
    }

    error_log("SSO: Tentativo login per: $nome (source: $source)");

    // 4. Cerca utente nel database
    $query = "SELECT id, nome, grado, cariche_fisse, email, telefono, attivo, role FROM fratelli WHERE nome = ? AND attivo = 1";
    $fratello = getSingleResult($query, [$nome], 's');

    if (!$fratello) {
        error_log("SSO: Fratello non trovato o non attivo: $nome");
        return ['success' => false, 'error' => 'fratello_non_trovato'];
    }

    // 5. Determina ruolo
    $user_role = $fratello['role'] ?? 'user';
    $is_admin = ($user_role === 'admin');
    $is_guest = ($user_role === 'guest');

    // 6. Rigenera session ID per sicurezza
    session_regenerate_id(true);

    // 7. Crea sessione
    $_SESSION['fratello_id'] = $fratello['id'];
    $_SESSION['fratello_nome'] = $fratello['nome'];
    $_SESSION['fratello_grado'] = $fratello['grado'];
    $_SESSION['fratello_cariche'] = $fratello['cariche_fisse'];
    $_SESSION['fratello_email'] = $fratello['email'];
    $_SESSION['fratello_telefono'] = $fratello['telefono'];
    $_SESSION['is_admin'] = $is_admin;
    $_SESSION['is_guest'] = $is_guest;
    $_SESSION['_role_cache'] = $user_role;
    $_SESSION['login_time'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['sso_login'] = true; // Flag per identificare login SSO

    // 8. Log successo
    $user_type = $is_guest ? 'Ospite' : ($is_admin ? 'Admin' : 'Fratello');
    error_log("SSO: Login riuscito per {$user_type}: {$fratello['nome']} (ID: {$fratello['id']})");

    return ['success' => true, 'user' => $fratello];
}

// === MAIN EXECUTION ===

// Se c'è un token SSO, processa il login
if (isset($_GET['sso_token'])) {
    $result = processSSOLogin();
    
    if ($result['success']) {
        // Redirect alla dashboard
        header('Location: ../pages/dashboard.php');
        exit;
    } else {
        // Redirect a login con errore
        header('Location: ../index.php?error=' . $result['error']);
        exit;
    }
}

// Se non c'è token SSO, redirect normale a index
header('Location: ../index.php');
exit;
?>
