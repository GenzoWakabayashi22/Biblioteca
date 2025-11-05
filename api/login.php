<?php
/**
 * API Login per Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

// Inizia la sessione
session_start();

// Include la configurazione del database
require_once '../config/database.php';
require_once '../config/rate_limiter.php';

// Gestisce sia POST che GET per test
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Rate Limiting: Verifica se IP è bloccato
    $rate_limit_check = checkRateLimit('login');
    if ($rate_limit_check !== null) {
        error_log(sprintf(
            "RATE LIMIT: Login bloccato per IP %s - Retry after: %d secondi",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $rate_limit_check['retry_after']
        ));

        header('Location: ../index.php?error=rate_limit&retry_after=' . $rate_limit_check['retry_after']);
        exit;
    }

    // CSRF Protection: Valida token prima di processare
    if (!validateCSRFToken()) {
        error_log("CSRF: Tentativo login con token non valido - IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        recordRateLimitFailure('login'); // Conta anche attacchi CSRF come tentativi
        header('Location: ../index.php?error=csrf_invalid');
        exit;
    }

    // Legge i dati dal form o JSON
    if (isset($_POST['fratello_nome']) && isset($_POST['password'])) {
        // Dati da form HTML
        $fratello_nome = trim($_POST['fratello_nome']);
        $password = trim($_POST['password']);
    } else {
        // Dati da JSON (per compatibilità futura)
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['fratello_nome']) && isset($input['password'])) {
            $fratello_nome = trim($input['fratello_nome']);
            $password = trim($input['password']);
        } else {
            header('Location: ../index.php?error=dati_mancanti');
            exit;
        }
    }
    
    // Validazione base
    if (empty($fratello_nome) || empty($password)) {
        header('Location: ../index.php?error=credenziali_vuote');
        exit;
    }
    
    try {
        // Recupera i dati del fratello dal nome (include password_hash e role)
        $query = "SELECT id, nome, grado, cariche_fisse, email, telefono, attivo, password_hash, role FROM fratelli WHERE nome = ? AND attivo = 1";
        $fratello = getSingleResult($query, [$fratello_nome], 's');

        if (!$fratello) {
            // Rate limiting: registra anche tentativi con username inesistente
            recordRateLimitFailure('login');

            header('Location: ../index.php?error=fratello_non_trovato');
            exit;
        }

        // Verifica password usando password_verify() (sicuro contro timing attacks)
        $password_verificata = false;

        if (!empty($fratello['password_hash'])) {
            // Sistema moderno con password_hash
            $password_verificata = password_verify($password, $fratello['password_hash']);
        } else {
            // Fallback per password non ancora migrate (solo temporaneo)
            // RIMUOVERE DOPO MIGRAZIONE COMPLETATA
            if ($fratello['nome'] === 'Ospite' && $password === 'Ospite25') {
                $password_verificata = true;
            } else {
                $nome_parts = explode(' ', $fratello['nome']);
                $primo_nome = $nome_parts[0];
                $password_attesa = $primo_nome . '25';
                $password_verificata = ($password === $password_attesa);
            }

            // Se password corretta, genera hash e aggiorna DB
            if ($password_verificata) {
                $new_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $update_stmt = $conn->prepare("UPDATE fratelli SET password_hash = ? WHERE id = ?");
                $update_stmt->bind_param('si', $new_hash, $fratello['id']);
                $update_stmt->execute();
                error_log("Password hashata automaticamente per fratello: {$fratello['nome']}");
            }
        }

        if (!$password_verificata) {
            // Log del tentativo di accesso fallito
            error_log("Tentativo di login fallito per fratello: {$fratello['nome']}");

            // Rate limiting: registra tentativo fallito
            recordRateLimitFailure('login');

            header('Location: ../index.php?error=password_errata');
            exit;
        }
        
        // Determina i permessi dell'utente dal database role
        $user_role = $fratello['role'] ?? 'user';
        $is_admin = ($user_role === 'admin');
        $is_guest = ($user_role === 'guest');

        // SICUREZZA: Rigenera session ID per prevenire session fixation attacks
        session_regenerate_id(true);

        // Rate Limiting: Reset dopo login successo
        resetRateLimit('login');

        // Crea la sessione
        $_SESSION['fratello_id'] = $fratello['id'];
        $_SESSION['fratello_nome'] = $fratello['nome'];
        $_SESSION['fratello_grado'] = $fratello['grado'];
        $_SESSION['fratello_cariche'] = $fratello['cariche_fisse'];
        $_SESSION['fratello_email'] = $fratello['email'];
        $_SESSION['fratello_telefono'] = $fratello['telefono'];
        $_SESSION['is_admin'] = $is_admin;
        $_SESSION['is_guest'] = $is_guest;
        $_SESSION['_role_cache'] = $user_role; // Cache role per performance
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        
        // Log dell'accesso riuscito
        $user_type = $is_guest ? 'Ospite' : ($is_admin ? 'Admin' : 'Fratello');
        error_log("Login riuscito per {$user_type}: {$fratello['nome']} (ID: {$fratello['id']})");
        
        // Redirect alla dashboard
        header('Location: ../pages/dashboard.php');
        exit;
        
    } catch (Exception $e) {
        // Log dell'errore
        error_log("Errore durante login: " . $e->getMessage());
        
        header('Location: ../index.php?error=errore_sistema');
        exit;
    }
    
} else {
    // Se non è POST, redirect al login
    header('Location: ../index.php');
    exit;
}

/**
 * Funzione per validare la sessione (da usare nelle altre pagine)
 */
function validateSession() {
    if (!isset($_SESSION['fratello_id'])) {
        return false;
    }
    
    // Verifica timeout sessione (24 ore)
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 86400)) {
        session_destroy();
        return false;
    }
    
    // Aggiorna timestamp ultima attività
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Funzione per verificare se l'utente è admin
 */
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

/**
 * Funzione per verificare se l'utente è ospite
 */
function isGuest() {
    return isset($_SESSION['is_guest']) && $_SESSION['is_guest'] === true;
}

/**
 * Funzione per ottenere i dati dell'utente corrente
 */
function getCurrentUser() {
    if (!validateSession()) {
        return null;
    }
    
    return [
        'id' => $_SESSION['fratello_id'],
        'nome' => $_SESSION['fratello_nome'],
        'grado' => $_SESSION['fratello_grado'],
        'cariche' => $_SESSION['fratello_cariche'],
        'email' => $_SESSION['fratello_email'],
        'telefono' => $_SESSION['fratello_telefono'],
        'is_admin' => $_SESSION['is_admin'],
        'is_guest' => $_SESSION['is_guest'] ?? false
    ];
}

/**
 * Funzione per logout
 */
function logout() {
    session_destroy();
    header('Location: ../index.php?logout=1');
    exit;
}
?>