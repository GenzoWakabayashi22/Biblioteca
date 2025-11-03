<?php
/**
 * API Login per Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

// Inizia la sessione
session_start();

// Include la configurazione del database
require_once '../config/database.php';

// Gestisce sia POST che GET per test
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
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
        // Recupera i dati del fratello dal nome
        $query = "SELECT id, nome, grado, cariche_fisse, email, telefono, attivo FROM fratelli WHERE nome = ? AND attivo = 1";
        $fratello = getSingleResult($query, [$fratello_nome], 's');
        
        if (!$fratello) {
            header('Location: ../index.php?error=fratello_non_trovato');
            exit;
        }
        
        // Verifica password
        $password_verificata = false;
        
        // Caso speciale per utente Ospite
        if ($fratello['nome'] === 'Ospite' && $password === 'Ospite25') {
            $password_verificata = true;
        } else {
            // Per fratelli normali: costruisce la password attesa (Nome + 25)
            $nome_parts = explode(' ', $fratello['nome']);
            $primo_nome = $nome_parts[0];
            $password_attesa = $primo_nome . '25';
            $password_verificata = ($password === $password_attesa);
        }
        
        if (!$password_verificata) {
            // Log del tentativo di accesso fallito
            error_log("Tentativo di login fallito per fratello: {$fratello['nome']}");
            
            header('Location: ../index.php?error=password_errata');
            exit;
        }
        
        // Determina i permessi dell'utente
        $admin_names = [
            'Paolo Giulio Gazzano',
            'Luca Guiducci', 
            'Emiliano Menicucci',
            'Francesco Ropresti'
        ];
        
        $is_admin = in_array($fratello['nome'], $admin_names) && $fratello['nome'] !== 'Ospite';
        $is_guest = ($fratello['nome'] === 'Ospite');
        
        // Crea la sessione
        $_SESSION['fratello_id'] = $fratello['id'];
        $_SESSION['fratello_nome'] = $fratello['nome'];
        $_SESSION['fratello_grado'] = $fratello['grado'];
        $_SESSION['fratello_cariche'] = $fratello['cariche_fisse'];
        $_SESSION['fratello_email'] = $fratello['email'];
        $_SESSION['fratello_telefono'] = $fratello['telefono'];
        $_SESSION['is_admin'] = $is_admin;
        $_SESSION['is_guest'] = $is_guest;
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