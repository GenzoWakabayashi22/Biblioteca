<?php
session_start();

// Headers CORS e JSON
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
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

// Crea tabella preferiti se non esiste
$conn->query("
    CREATE TABLE IF NOT EXISTS preferiti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fratello_id INT NOT NULL,
        libro_id INT NOT NULL,
        data_aggiunta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        note TEXT,
        UNIQUE KEY unq_fratello_libro (fratello_id, libro_id),
        FOREIGN KEY (fratello_id) REFERENCES fratelli(id) ON DELETE CASCADE,
        FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
        INDEX idx_fratello (fratello_id),
        INDEX idx_libro (libro_id)
    )
");

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);

    switch ($method) {
        case 'GET':
            handleGet();
            break;
        case 'POST':
            handlePost($input);
            break;
        case 'DELETE':
            handleDelete($input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metodo non supportato']);
    }
} catch (Exception $e) {
    error_log("Errore API preferiti: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore interno del server: ' . $e->getMessage()]);
}

function handleGet() {
    global $conn, $user_id;
    
    // Recupera tutti i preferiti dell'utente
    $query = "
        SELECT p.*, l.titolo, l.autore, l.stato, l.copertina_url, 
               c.nome as categoria_nome, c.colore as categoria_colore
        FROM preferiti p
        INNER JOIN libri l ON p.libro_id = l.id
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE p.fratello_id = ?
        ORDER BY p.data_aggiunta DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $preferiti = [];
    while ($row = $result->fetch_assoc()) {
        $preferiti[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'preferiti' => $preferiti,
        'count' => count($preferiti)
    ]);
}

function handlePost($input) {
    global $conn, $user_id;
    
    $libro_id = $input['libro_id'] ?? 0;
    $note = $input['note'] ?? '';
    
    if (!$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro richiesto']);
        return;
    }
    
    try {
        // Verifica che il libro esista
        $stmt = $conn->prepare("SELECT titolo FROM libri WHERE id = ?");
        $stmt->bind_param("i", $libro_id);
        $stmt->execute();
        $libro = $stmt->get_result()->fetch_assoc();
        
        if (!$libro) {
            throw new Exception('Libro non trovato');
        }
        
        // Inserisci preferito
        $stmt = $conn->prepare("
            INSERT INTO preferiti (fratello_id, libro_id, note) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)
        ");
        $stmt->bind_param("iis", $user_id, $libro_id, $note);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'aggiunta ai preferiti');
        }
        
        echo json_encode([
            'success' => true,
            'message' => "'{$libro['titolo']}' aggiunto ai preferiti!"
        ]);
        
    } catch (Exception $e) {
        error_log("Errore aggiunta preferito: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDelete($input) {
    global $conn, $user_id;
    
    $libro_id = $input['libro_id'] ?? 0;
    
    if (!$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro richiesto']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            DELETE FROM preferiti 
            WHERE fratello_id = ? AND libro_id = ?
        ");
        $stmt->bind_param("ii", $user_id, $libro_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante la rimozione dai preferiti');
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Preferito non trovato');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Rimosso dai preferiti!'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore rimozione preferito: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

$conn->close();
?>
