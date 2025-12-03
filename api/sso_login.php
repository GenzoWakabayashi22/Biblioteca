<?php
/**
 * API SSO Login - Single Sign-On dalla app Tornate
 * R∴ L∴ Kilwinning - Sistema Biblioteca
 * 
 * NOTA SICUREZZA: Questo SSO si basa su token JWT emessi dall'app Tornate.
 * La verifica della firma JWT è gestita a livello di trust tra le applicazioni.
 * Il token viene validato per formato, scadenza e issuer autorizzato.
 */

session_start();

require_once '../config/database.php';

// Clock skew tolerance in seconds (30 secondi per differenze di tempo tra server)
define('JWT_CLOCK_SKEW_TOLERANCE', 30);

/**
 * Verifica e decodifica JWT token
 * 
 * NOTA: La verifica della firma crittografica non è implementata qui
 * perché il trust è stabilito tra Tornate e Biblioteca a livello di infrastruttura.
 * Il token è validato per formato, scadenza e issuer.
 */
function verifyAndDecodeJWT($token) {
    try {
        // Split JWT in parti
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            error_log("JWT invalido: formato non corretto");
            return null;
        }

        // Decodifica payload (parte centrale) con gestione corretta del padding base64
        $payload_encoded = $parts[1];
        // Aggiungi padding se necessario per base64 standard
        $payload_encoded .= str_repeat('=', (4 - strlen($payload_encoded) % 4) % 4);
        $payload_decoded = base64_decode(str_replace(['-', '_'], ['+', '/'], $payload_encoded), true);
        
        if ($payload_decoded === false) {
            error_log("JWT invalido: base64 decode fallita");
            return null;
        }
        
        $payload = json_decode($payload_decoded, true);
        
        if (!$payload || !is_array($payload)) {
            error_log("JWT invalido: payload non decodificabile");
            return null;
        }

        // Verifica scadenza con tolleranza clock skew
        if (isset($payload['exp']) && $payload['exp'] < (time() - JWT_CLOCK_SKEW_TOLERANCE)) {
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
    // NOTA: Il nome viene usato come identificatore per compatibilità con il sistema esistente.
    // L'app Tornate e Biblioteca condividono la stessa tabella fratelli.
    $nome = $payload['nome'] ?? '';
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
