<?php
/**
 * Pagina Login - Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

// Inizia la sessione
session_start();

// Include la configurazione del database
require_once 'config/database.php';

// Configura Security Headers
configureSecurityHeaders();

// Genera token CSRF per il form di login
$csrf_token = generateCSRFToken();

// Verifica se l'utente è già loggato
if (isset($_SESSION['fratello_id'])) {
    header('Location: pages/dashboard.php');
    exit;
}

// ✅ FIX: Verifica se c'è un tentativo di login SSO con parametro corretto 'sso'
if (isset($_GET['sso'])) {
    require_once 'api/sso_login.php';
    exit; // SSO login gestisce tutto (redirect o errore)
}

// Recupera la lista dei fratelli attivi ordinati per grado e nome (escluso Ospite)
$query = "SELECT id, nome, grado FROM fratelli WHERE attivo = 1 AND nome != 'Ospite' ORDER BY 
          CASE 
              WHEN grado = 'Maestro' THEN 1
              WHEN grado = 'Compagno' THEN 2
              WHEN grado = 'Apprendista' THEN 3
              ELSE 4
          END, nome ASC";

$fratelli = getAllResults($query);

// Recupera l'utente Ospite separatamente
$ospite_query = "SELECT id, nome, grado FROM fratelli WHERE nome = 'Ospite' AND attivo = 1";
$ospite = getSingleResult($ospite_query);

// Raggruppa fratelli per grado
$fratelli_per_grado = [];
foreach ($fratelli as $fratello) {
    $fratelli_per_grado[$fratello['grado']][] = $fratello;
}

// Gestione messaggi
$message = '';
$message_type = '';
if (isset($_GET['logout'])) {
    $message = 'Logout effettuato con successo';
    $message_type = 'success';
} elseif (isset($_GET['error'])) {
    switch($_GET['error']) {
        case 'password_errata':
            $message = 'Password non corretta';
            $message_type = 'error';
            break;
        case 'fratello_non_trovato':
            $message = 'Utente non trovato o non attivo';
            $message_type = 'error';
            break;
        case 'credenziali_vuote':
            $message = 'Inserisci nome e password';
            $message_type = 'error';
            break;
        case 'rate_limit':
            $retry_after = isset($_GET['retry_after']) ? (int)$_GET['retry_after'] : 3600;
            $minutes = ceil($retry_after / 60);
            $message = sprintf(
                '⚠️ Troppi tentativi di login falliti. Account temporaneamente bloccato. Riprova tra %d minuti.',
                $minutes
            );
            $message_type = 'error';
            break;
        case 'csrf_invalid':
            $message = '🛡️ Token di sicurezza non valido. Ricarica la pagina e riprova.';
            $message_type = 'error';
            break;
        case 'session_expired':
            $message = '⏱️ Sessione scaduta per inattività. Effettua nuovamente il login.';
            $message_type = 'error';
            break;
        default:
            $message = 'Errore di autenticazione';
            $message_type = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Biblioteca R∴ L∴ Kilwinning - Login</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: system-ui, -apple-system, sans-serif;
        }
        .gradient-text {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
    </style>
    <!-- SSO Error Handler -->
    <script>
        // Gestione messaggi errore SSO
        window.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const error = urlParams.get('error');

            const errorMessages = {
                'token_missing': 'Token SSO mancante. Effettua login manuale.',
                'invalid_token': 'Token SSO scaduto o invalido. Effettua login manuale.',
                'invalid_source': 'Token SSO non valido. Effettua login manuale.',
                'invalid_payload': 'Dati SSO incompleti. Effettua login manuale.',
                'session_error': 'Errore creazione sessione. Riprova.',
                'server_error': 'Errore del server. Riprova più tardi.',
                'fratello_non_trovato': 'Utente non trovato nel sistema.',
                'user_inactive': 'Account non attivo. Contatta l\'amministratore.'
            };

            if (error && errorMessages[error] && error !== 'password_errata' && error !== 'credenziali_vuote') {
                setTimeout(() => {
                    const alertDiv = document.createElement('div');
                    alertDiv.className = 'mt-4 p-3 rounded-lg bg-red-100 text-red-700';
                    alertDiv.innerHTML = `<strong>⚠️ Errore SSO:</strong> ${errorMessages[error]}`;

                    const container = document.querySelector('.bg-white.rounded-2xl');
                    if (container) {
                        const header = container.querySelector('.text-center');
                        if (header) {
                            header.appendChild(alertDiv);
                        }
                    }

                    // Rimuovi parametro error dall'URL dopo 5 secondi
                    setTimeout(() => {
                        const url = new URL(window.location);
                        url.searchParams.delete('error');
                        window.history.replaceState({}, document.title, url.pathname);
                        alertDiv.remove();
                    }, 5000);
                }, 100);
            }
        });
    </script>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-md">
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="text-4xl mb-3">🏛️</div>
            <h1 class="text-2xl font-bold text-gray-800 gradient-text">R∴ L∴ Kilwinning</h1>
            <p class="text-gray-600 mt-2">Sistema Biblioteca</p>
            
            <!-- Messaggio di stato -->
            <?php if ($message): ?>
                <div class="mt-4 p-3 rounded-lg <?php 
                    echo $message_type === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'; 
                ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Form Login -->
        <form action="api/login.php" method="POST" class="space-y-6">
            <!-- CSRF Token Protection -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <div>
                <h2 class="text-lg font-semibold text-center mb-4">
                    👥 Accesso Biblioteca
                </h2>
                
                <!-- Dropdown Fratelli -->
                <div class="space-y-2">
                    <label class="block text-sm font-medium text-gray-700">Seleziona il tuo nome</label>
                    <select name="fratello_nome" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-gray-900">
                        <option value="">-- Scegli il tuo nome --</option>
                        
                        <!-- OSPITE - SEMPRE IN CIMA -->
                        <?php if ($ospite): ?>
                            <option value="<?php echo htmlspecialchars($ospite['nome']); ?>" 
                                    class="font-semibold" 
                                    style="background-color: #dbeafe; color: #1d4ed8;">
                                👁️ <?php echo htmlspecialchars($ospite['nome']); ?> (<?php echo htmlspecialchars($ospite['grado']); ?>)
                            </option>
                            <option disabled>────────────────────</option>
                        <?php endif; ?>
                        
                        <!-- FRATELLI REGISTRATI -->
                        <?php foreach ($fratelli_per_grado as $grado => $lista_fratelli): ?>
                            <?php 
                            $emoji = '';
                            switch($grado) {
                                case 'Maestro': $emoji = '🔶'; break;
                                case 'Compagno': $emoji = '🔷'; break;
                                case 'Apprendista': $emoji = '🔺'; break;
                            }
                            ?>
                            <optgroup label="<?php echo $emoji; ?> <?php echo $grado; ?>i">
                                <?php foreach ($lista_fratelli as $fratello): ?>
                                    <option value="<?php echo htmlspecialchars($fratello['nome']); ?>">
                                        <?php echo htmlspecialchars($fratello['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <!-- Password -->
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">Password</label>
                <input type="password" 
                       name="password"
                       required
                       class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
                       placeholder="Inserisci la password"
                       autocomplete="current-password">
                

            </div>

            <!-- Pulsante Login -->
            <button type="submit" 
                    class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-3 px-4 rounded-lg transition duration-200 shadow-lg">
                🔓 Accedi all'Area Riservata
            </button>
        </form>

        <!-- Info per Ospiti -->
        <div class="mt-6 p-4 bg-blue-50 rounded-lg border border-blue-200">
            <h3 class="text-sm font-semibold text-blue-800 mb-2">👁️ Accesso Ospite</h3>
            <p class="text-xs text-blue-600 mb-2">
                Puoi accedere come ospite per esplorare il nostro catalogo di libri e leggere le recensioni.
            </p>
            <p class="text-xs text-blue-700 font-medium">
                Per le credenziali di accesso ospite, contatta l'amministratore del sistema.
            </p>
        </div>

        <!-- Link Indietro -->
        <div class="mt-6 text-center">
            <a href="https://tornate.loggiakilwinning.com/" 
               class="inline-flex items-center px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white text-sm rounded-lg transition duration-200">
                ← Indietro
            </a>
        </div>

        <!-- Footer -->
        <div class="mt-8 text-center text-sm text-gray-500">
            <p>&copy; 2025 R∴ L∴ Kilwinning</p>
            <p>Sistema di gestione biblioteca</p>
        </div>
    </div>

    <script>
        // Focus automatico sulla password quando si seleziona un fratello
        document.querySelector('select[name="fratello_nome"]').addEventListener('change', function() {
            if (this.value) {
                document.querySelector('input[name="password"]').focus();
            }
        });

        // Auto-compilazione per ospite (per demo) - solo doppio click
        document.querySelector('.bg-blue-50').addEventListener('dblclick', function() {
            document.querySelector('select[name="fratello_nome"]').value = 'Ospite';
            document.querySelector('input[name="password"]').value = 'Ospite25';
            document.querySelector('input[name="password"]').focus();
        });
    </script>
</body>
</html>