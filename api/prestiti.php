<?php
session_start();

// Headers CORS e JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Connessione database
$conn = new mysqli('localhost', 'jmvvznbb_tornate_user', 'Puntorosso22', 'jmvvznbb_tornate_db');
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore connessione database']);
    exit;
}
$conn->set_charset('utf8mb4');

// Verifica autenticazione
if (!isset($_SESSION['fratello_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autenticato']);
    exit;
}

$user_id = $_SESSION['fratello_id'];
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$is_admin = in_array($user_id, $admin_ids);

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';

    switch ($method) {
        case 'POST':
            handlePost($action, $input);
            break;
        case 'PUT':
            handlePut($action, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metodo non supportato']);
    }
} catch (Exception $e) {
    error_log("Errore API prestiti: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore interno del server: ' . $e->getMessage()]);
}

function handlePost($action, $input) {
    global $conn, $is_admin, $user_id;
    
    switch ($action) {
        case 'richiedi_prestito':
            richieciPrestito($input);
            break;
        case 'nuovo_prestito':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Accesso negato']);
                return;
            }
            nuovoPrestito($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione POST non valida: ' . $action]);
    }
}

function handlePut($action, $input) {
    global $conn, $is_admin, $user_id;
    
    switch ($action) {
        case 'restituisci':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Accesso negato - Solo admin possono restituire libri']);
                return;
            }
            restituisciLibro($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione PUT non valida: ' . $action]);
    }
}

function restituisciLibro($input) {
    global $conn, $user_id;
    
    $libro_id = $input['libro_id'] ?? 0;
    $stato_rientro = $input['stato_rientro'] ?? 'buono';
    $note_restituzione = $input['note_restituzione'] ?? '';
    
    if (!$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro richiesto']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        // Verifica che il libro sia effettivamente in prestito
        $stmt = $conn->prepare("SELECT * FROM libri WHERE id = ? AND stato = 'prestato'");
        $stmt->bind_param("i", $libro_id);
        $stmt->execute();
        $libro = $stmt->get_result()->fetch_assoc();
        
        if (!$libro) {
            throw new Exception('Libro non trovato o non in prestito');
        }
        
        // Calcola giorni di prestito
        $giorni_prestito = 0;
        if ($libro['data_prestito_corrente']) {
            $data_prestito = new DateTime($libro['data_prestito_corrente']);
            $data_restituzione = new DateTime();
            $giorni_prestito = $data_restituzione->diff($data_prestito)->days;
        }
        
       // Registra nello storico (se esiste la tabella e il libro ha un prestito valido)
        $check_storico = $conn->query("SHOW TABLES LIKE 'storico_prestiti'");
        if ($check_storico && $check_storico->num_rows > 0 && $libro['prestato_a_fratello_id']) {
            $stmt = $conn->prepare("
                INSERT INTO storico_prestiti 
                (libro_id, fratello_id, data_prestito, data_scadenza, data_restituzione, giorni_prestito, note_restituzione, gestito_da) 
                VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
            ");
            $stmt->bind_param("iissisi", 
                $libro_id, 
                $libro['prestato_a_fratello_id'], 
                $libro['data_prestito_corrente'], 
                $libro['data_scadenza_corrente'], 
                $giorni_prestito, 
                $note_restituzione, 
                $user_id
            );
            
            if (!$stmt->execute()) {
                error_log("Errore inserimento storico: " . $stmt->error);
                // Non fermare l'operazione per errore storico
            }
        }
        
        // **NUOVO**: Inserisci automaticamente in libri_letti
        if ($libro['prestato_a_fratello_id']) {
            $nota_default = "Letto tramite prestito biblioteca";
            $nota_separatore = " | ";
            
            $stmt_letti = $conn->prepare("
                INSERT INTO libri_letti (fratello_id, libro_id, data_lettura, note) 
                VALUES (?, ?, NOW(), ?)
                ON DUPLICATE KEY UPDATE 
                    data_lettura = NOW(), 
                    note = IF(
                        COALESCE(note, '') LIKE CONCAT('%', ?, '%'),
                        note,
                        CONCAT(COALESCE(note, ''), ?, ?)
                    )
            ");
            
            $stmt_letti->bind_param("iissss", 
                $libro['prestato_a_fratello_id'], 
                $libro_id, 
                $nota_default,
                $nota_default,
                $nota_separatore,
                $nota_default
            );
            
            if (!$stmt_letti->execute()) {
                // Log warning ma NON bloccare la restituzione
                error_log("WARNING: Errore inserimento in libri_letti per libro ID $libro_id - Fratello ID " . $libro['prestato_a_fratello_id'] . " - Errore: " . $stmt_letti->error);
            }
        }
        
        // Aggiorna lo stato del libro
        $stmt = $conn->prepare("
            UPDATE libri 
            SET stato = 'disponibile', 
                prestato_a_fratello_id = NULL, 
                data_prestito_corrente = NULL, 
                data_scadenza_corrente = NULL,
                condizioni = ?
            WHERE id = ?
        ");
        $stmt->bind_param("si", $stato_rientro, $libro_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'aggiornamento del libro');
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => 'Libro restituito con successo'
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Errore restituzione libro: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function richieciPrestito($input) {
    global $conn, $user_id;
    
    $libro_id = $input['libro_id'] ?? 0;
    $giorni_richiesti = $input['giorni_richiesti'] ?? 30;
    $note = $input['note'] ?? '';
    
    if (!$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro richiesto']);
        return;
    }
    
    try {
        // Verifica che il libro sia disponibile
        $stmt = $conn->prepare("SELECT titolo, stato FROM libri WHERE id = ?");
        $stmt->bind_param("i", $libro_id);
        $stmt->execute();
        $libro = $stmt->get_result()->fetch_assoc();
        
        if (!$libro) {
            throw new Exception('Libro non trovato');
        }
        
        if ($libro['stato'] !== 'disponibile') {
            throw new Exception('Il libro non è disponibile per il prestito');
        }
        
        // Crea tabella richieste se non esiste
        $conn->query("
            CREATE TABLE IF NOT EXISTS richieste_prestito (
                id INT AUTO_INCREMENT PRIMARY KEY,
                libro_id INT NOT NULL,
                fratello_id INT NOT NULL,
                giorni_richiesti INT NOT NULL DEFAULT 30,
                note_richiesta TEXT,
                stato ENUM('in_attesa', 'approvata', 'rifiutata', 'annullata') DEFAULT 'in_attesa',
                data_richiesta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                data_risposta TIMESTAMP NULL,
                note_admin TEXT,
                admin_id INT NULL,
                FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
                FOREIGN KEY (fratello_id) REFERENCES fratelli(id) ON DELETE CASCADE,
                FOREIGN KEY (admin_id) REFERENCES fratelli(id) ON DELETE SET NULL
            )
        ");
        
        // **NUOVO**: Verifica se ci sono già richieste in attesa per questo libro
        $stmt_check_richieste = $conn->prepare("
            SELECT COUNT(*) as count_richieste
            FROM richieste_prestito 
            WHERE libro_id = ? AND stato = 'in_attesa'
        ");
        $stmt_check_richieste->bind_param("i", $libro_id);
        $stmt_check_richieste->execute();
        $richieste_attive = $stmt_check_richieste->get_result()->fetch_assoc();

        if ($richieste_attive['count_richieste'] > 0) {
            throw new Exception('C\'è già una richiesta in attesa per questo libro. Sarai avvisato quando sarà disponibile.');
        }
        
        // Verifica se non ha già una richiesta in sospeso per questo libro
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM richieste_prestito 
            WHERE libro_id = ? AND fratello_id = ? AND stato = 'in_attesa'
        ");
        $stmt->bind_param("ii", $libro_id, $user_id);
        $stmt->execute();
        $esistente = $stmt->get_result()->fetch_assoc();
        
        if ($esistente['count'] > 0) {
            throw new Exception('Hai già una richiesta in sospeso per questo libro');
        }
        
        // Inserisci richiesta
        $stmt = $conn->prepare("
            INSERT INTO richieste_prestito (libro_id, fratello_id, giorni_richiesti, note_richiesta) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iiis", $libro_id, $user_id, $giorni_richiesti, $note);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'inserimento della richiesta');
        }
        
        echo json_encode([
            'success' => true, 
            'message' => 'Richiesta di prestito inviata con successo! Un amministratore la esaminerà.'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore richiesta prestito: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function nuovoPrestito($input) {
    global $conn, $user_id;
    
    $libro_id = $input['libro_id'] ?? 0;
    $fratello_id = $input['fratello_id'] ?? 0;
    $giorni_prestito = $input['giorni_prestito'] ?? 30;
    $note = $input['note'] ?? '';
    
    if (!$libro_id || !$fratello_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro e fratello richiesti']);
        return;
    }
    
    try {
        $conn->begin_transaction();
        
        // Verifica che il libro sia disponibile
        $stmt = $conn->prepare("SELECT * FROM libri WHERE id = ? AND stato = 'disponibile'");
        $stmt->bind_param("i", $libro_id);
        $stmt->execute();
        $libro = $stmt->get_result()->fetch_assoc();
        
        if (!$libro) {
            throw new Exception('Libro non trovato o non disponibile');
        }
        
        // Verifica che il fratello esista
        $stmt = $conn->prepare("SELECT nome FROM fratelli WHERE id = ?");
        $stmt->bind_param("i", $fratello_id);
        $stmt->execute();
        $fratello = $stmt->get_result()->fetch_assoc();
        
        if (!$fratello) {
            throw new Exception('Fratello non trovato');
        }
        
        // Calcola date
        $data_prestito = date('Y-m-d');
        $data_scadenza = date('Y-m-d', strtotime("+{$giorni_prestito} days"));
        
        // Aggiorna libro
        $stmt = $conn->prepare("
            UPDATE libri 
            SET stato = 'prestato', 
                prestato_a_fratello_id = ?, 
                data_prestito_corrente = ?, 
                data_scadenza_corrente = ?
            WHERE id = ?
        ");
        $stmt->bind_param("issi", $fratello_id, $data_prestito, $data_scadenza, $libro_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'aggiornamento del libro');
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true, 
            'message' => "Prestito registrato con successo per {$fratello['nome']}"
        ]);
        
    } catch (Exception $e) {
        $conn->rollback();
        error_log("Errore nuovo prestito: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>