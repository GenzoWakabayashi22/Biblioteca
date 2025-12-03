<?php
/**
 * API SSO Login - Single Sign-On dalla app Tornate
 * R∴ L∴ Kilwinning - Sistema Biblioteca
 * 
 * NOTA SICUREZZA: Questo SSO si basa su token Base64 semplici emessi dall'app Tornate. 
 * Il token viene validato per formato, scadenza (30s) e sorgente autorizzata.
 */

session_start();

require_once '../config/database.php';

// Timeout token SSO in millisecondi (30 secondi)
define('SSO_TOKEN_TIMEOUT', 30000);

/**
 * Verifica e decodifica token SSO Base64 da Tornate
 * 
 * Struttura token attesa:
 * {
 *   "fratello_nome": "Paolo Giulio Gazzano",
 *   "timestamp": 1234567890123,
 *   "from": "tornate",
 *   "userId": 16
 * }
 */
function verifyAndDecodeSSO($token) {
    try {
        // Decodifica Base64
        $decoded = base64_decode($token, true);
        
        if ($decoded === false) {
            error_log("SSO: Base64 decode fallita");
            return null;
        }
        
        // Parse JSON
        $payload = json_decode($decoded, true);
        
        if (!$payload || !is_array($payload)) {
            error_log("SSO: JSON decode fallita o payload non valido");
            return null;
        }

        // Verifica campi obbligatori
        if (!isset($payload['fratello_nome']) || !isset($payload['timestamp']) || !isset($payload['from'])) {
            error_log("SSO: Campi obbligatori mancanti nel payload");
            return null;
        }

        // Verifica scadenza token (30 secondi)
        $now = round(microtime(true) * 1000);
        $tokenAge = $now - $payload['timestamp'];
        
        if ($tokenAge > SSO_TOKEN_TIMEOUT) {
            error_log("SSO: Token scaduto. Age: {$tokenAge}ms (max: " . SSO_TOKEN_TIMEOUT . "ms)");
            return null;
        }

        // Verifica sorgente autorizzata
        if ($payload['from'] !== 'tornate') {
            error_log("SSO: Sorgente non autorizzata: " . $payload['from']);
            return null;
        }

        return $payload;

    } catch (Exception $e) {
        error_log("SSO: Errore decodifica token: " . $e->getMessage());
        return null;
    }
}

/**
 * Processa login SSO
 */
function processSSOLogin() {
    global $conn;

    // 1. Verifica presenza token con il parametro corretto 'sso'
    $sso_token = $_GET['sso'] ?? '';
    
    if (empty($sso_token)) {
        error_log("SSO: Token mancante (parametro 'sso' non trovato)");
        return ['success' => false, 'error' => 'token_missing'];
    }

    // 2. Decodifica e valida token Base64
    $payload = verifyAndDecodeSSO($sso_token);
    
    if (!$payload) {
        error_log("SSO: Token invalido o scaduto");
        return ['success' => false, 'error' => 'invalid_token'];
    }

    // 3. Estrai informazioni utente dal payload
    $fratello_nome = $payload['fratello_nome'] ?? '';
    $source = $payload['from'] ?? 'unknown';
    $userId = $payload['userId'] ?? 0;

    if (empty($fratello_nome)) {
        error_log("SSO: Nome fratello mancante nel payload");
        return ['success' => false, 'error' => 'invalid_payload'];
    }

    error_log("SSO: Tentativo login per: $fratello_nome (ID: $userId, source: $source)");

    // 4. Cerca utente nel database
    $query = "SELECT id, nome, grado, cariche_fisse, email, telefono, attivo, role FROM fratelli WHERE nome = ? AND attivo = 1";
    $fratello = getSingleResult($query, [$fratello_nome], 's');

    if (!$fratello) {
        error_log("SSO: Fratello non trovato o non attivo: $fratello_nome");
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
    $_SESSION['sso_login'] = true;
    $_SESSION['sso_source'] = 'tornate';

    // 8. Log successo
    $user_type = $is_guest ? 'Ospite' : ($is_admin ? 'Admin' : 'Fratello');
    error_log("SSO: ✅ Login riuscito per {$user_type}: {$fratello['nome']} (ID: {$fratello['id']}) da Tornate");

    return ['success' => true, 'user' => $fratello];
}

// === MAIN EXECUTION ===

if (isset($_GET['sso'])) {
    $result = processSSOLogin();
    
    if ($result['success']) {
        header('Location: ../pages/dashboard.php');
        exit;
    } else {
        header('Location: ../index.php?error=' . $result['error']);
        exit;
    }
}

header('Location: ../index.php');
exit;
?>
