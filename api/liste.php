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

// Crea tabelle se non esistono
$conn->query("
    CREATE TABLE IF NOT EXISTS liste_lettura (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fratello_id INT NOT NULL,
        nome VARCHAR(255) NOT NULL,
        descrizione TEXT,
        icona VARCHAR(50) DEFAULT '📚',
        colore VARCHAR(7) DEFAULT '#6366f1',
        privata BOOLEAN DEFAULT FALSE,
        data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (fratello_id) REFERENCES fratelli(id) ON DELETE CASCADE,
        INDEX idx_fratello (fratello_id)
    )
");

$conn->query("
    CREATE TABLE IF NOT EXISTS lista_libri (
        id INT AUTO_INCREMENT PRIMARY KEY,
        lista_id INT NOT NULL,
        libro_id INT NOT NULL,
        note TEXT,
        posizione INT DEFAULT 0,
        data_aggiunta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unq_lista_libro (lista_id, libro_id),
        FOREIGN KEY (lista_id) REFERENCES liste_lettura(id) ON DELETE CASCADE,
        FOREIGN KEY (libro_id) REFERENCES libri(id) ON DELETE CASCADE,
        INDEX idx_lista (lista_id),
        INDEX idx_libro (libro_id)
    )
");

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';

    switch ($method) {
        case 'GET':
            handleGet($action);
            break;
        case 'POST':
            handlePost($action, $input);
            break;
        case 'PUT':
            handlePut($action, $input);
            break;
        case 'DELETE':
            handleDelete($action, $input);
            break;
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metodo non supportato']);
    }
} catch (Exception $e) {
    error_log("Errore API liste: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore interno del server: ' . $e->getMessage()]);
}

function handleGet($action) {
    global $conn, $user_id;
    
    switch ($action) {
        case 'lista_dettaglio':
            getListaDettaglio();
            break;
        default:
            getMieListe();
    }
}

function getMieListe() {
    global $conn, $user_id;
    
    // Recupera tutte le liste dell'utente con il conteggio libri
    $query = "
        SELECT ll.*, COUNT(DISTINCT llb.libro_id) as num_libri
        FROM liste_lettura ll
        LEFT JOIN lista_libri llb ON ll.id = llb.lista_id
        WHERE ll.fratello_id = ?
        GROUP BY ll.id
        ORDER BY ll.data_modifica DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $liste = [];
    while ($row = $result->fetch_assoc()) {
        $liste[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'liste' => $liste,
        'count' => count($liste)
    ]);
}

function getListaDettaglio() {
    global $conn, $user_id;
    
    $lista_id = $_GET['lista_id'] ?? 0;
    
    if (!$lista_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID lista richiesto']);
        return;
    }
    
    // Recupera dettagli lista
    $stmt = $conn->prepare("
        SELECT * FROM liste_lettura 
        WHERE id = ? AND fratello_id = ?
    ");
    $stmt->bind_param("ii", $lista_id, $user_id);
    $stmt->execute();
    $lista = $stmt->get_result()->fetch_assoc();
    
    if (!$lista) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Lista non trovata']);
        return;
    }
    
    // Recupera libri della lista
    $stmt = $conn->prepare("
        SELECT llb.*, l.titolo, l.autore, l.stato, l.copertina_url,
               c.nome as categoria_nome, c.colore as categoria_colore
        FROM lista_libri llb
        INNER JOIN libri l ON llb.libro_id = l.id
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE llb.lista_id = ?
        ORDER BY llb.posizione ASC, llb.data_aggiunta DESC
    ");
    $stmt->bind_param("i", $lista_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $libri = [];
    while ($row = $result->fetch_assoc()) {
        $libri[] = $row;
    }
    
    echo json_encode([
        'success' => true,
        'lista' => $lista,
        'libri' => $libri
    ]);
}

function handlePost($action, $input) {
    global $conn, $user_id;
    
    switch ($action) {
        case 'crea_lista':
            creaLista($input);
            break;
        case 'aggiungi_libro':
            aggiungiLibroALista($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione POST non valida: ' . $action]);
    }
}

function creaLista($input) {
    global $conn, $user_id;
    
    $nome = trim($input['nome'] ?? '');
    $descrizione = trim($input['descrizione'] ?? '');
    $icona = $input['icona'] ?? '📚';
    $colore = $input['colore'] ?? '#6366f1';
    $privata = $input['privata'] ?? false;
    
    if (empty($nome)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nome lista richiesto']);
        return;
    }
    
    try {
        // Verifica che non esista già una lista con lo stesso nome per l'utente
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM liste_lettura 
            WHERE fratello_id = ? AND nome = ?
        ");
        $stmt->bind_param("is", $user_id, $nome);
        $stmt->execute();
        $esistente = $stmt->get_result()->fetch_assoc();
        
        if ($esistente['count'] > 0) {
            throw new Exception('Hai già una lista con questo nome');
        }
        
        // Inserisci lista
        $stmt = $conn->prepare("
            INSERT INTO liste_lettura (fratello_id, nome, descrizione, icona, colore, privata) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issssi", $user_id, $nome, $descrizione, $icona, $colore, $privata);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante la creazione della lista');
        }
        
        $lista_id = $conn->insert_id;
        
        echo json_encode([
            'success' => true,
            'message' => "Lista '{$nome}' creata con successo!",
            'lista_id' => $lista_id
        ]);
        
    } catch (Exception $e) {
        error_log("Errore creazione lista: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function aggiungiLibroALista($input) {
    global $conn, $user_id;
    
    $lista_id = $input['lista_id'] ?? 0;
    $libro_id = $input['libro_id'] ?? 0;
    $note = $input['note'] ?? '';
    
    if (!$lista_id || !$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID lista e libro richiesti']);
        return;
    }
    
    try {
        // Verifica che la lista appartenga all'utente
        $stmt = $conn->prepare("
            SELECT nome FROM liste_lettura 
            WHERE id = ? AND fratello_id = ?
        ");
        $stmt->bind_param("ii", $lista_id, $user_id);
        $stmt->execute();
        $lista = $stmt->get_result()->fetch_assoc();
        
        if (!$lista) {
            throw new Exception('Lista non trovata');
        }
        
        // Verifica che il libro esista
        $stmt = $conn->prepare("SELECT titolo FROM libri WHERE id = ?");
        $stmt->bind_param("i", $libro_id);
        $stmt->execute();
        $libro = $stmt->get_result()->fetch_assoc();
        
        if (!$libro) {
            throw new Exception('Libro non trovato');
        }
        
        // Ottieni la prossima posizione
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(posizione), 0) + 1 as next_pos 
            FROM lista_libri 
            WHERE lista_id = ?
        ");
        $stmt->bind_param("i", $lista_id);
        $stmt->execute();
        $pos_result = $stmt->get_result()->fetch_assoc();
        $posizione = $pos_result['next_pos'];
        
        // Inserisci libro nella lista
        $stmt = $conn->prepare("
            INSERT INTO lista_libri (lista_id, libro_id, note, posizione) 
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE note = VALUES(note)
        ");
        $stmt->bind_param("iisi", $lista_id, $libro_id, $note, $posizione);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'aggiunta del libro alla lista');
        }
        
        echo json_encode([
            'success' => true,
            'message' => "'{$libro['titolo']}' aggiunto alla lista '{$lista['nome']}'!"
        ]);
        
    } catch (Exception $e) {
        error_log("Errore aggiunta libro a lista: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handlePut($action, $input) {
    global $conn, $user_id;
    
    switch ($action) {
        case 'modifica_lista':
            modificaLista($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione PUT non valida: ' . $action]);
    }
}

function modificaLista($input) {
    global $conn, $user_id;
    
    $lista_id = $input['lista_id'] ?? 0;
    $nome = trim($input['nome'] ?? '');
    $descrizione = trim($input['descrizione'] ?? '');
    $icona = $input['icona'] ?? '';
    $colore = $input['colore'] ?? '';
    $privata = $input['privata'] ?? null;
    
    if (!$lista_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID lista richiesto']);
        return;
    }
    
    try {
        // Verifica che la lista appartenga all'utente
        $stmt = $conn->prepare("
            SELECT * FROM liste_lettura 
            WHERE id = ? AND fratello_id = ?
        ");
        $stmt->bind_param("ii", $lista_id, $user_id);
        $stmt->execute();
        $lista = $stmt->get_result()->fetch_assoc();
        
        if (!$lista) {
            throw new Exception('Lista non trovata');
        }
        
        // Prepara update
        $updates = [];
        $params = [];
        $types = '';
        
        if (!empty($nome)) {
            $updates[] = "nome = ?";
            $params[] = $nome;
            $types .= 's';
        }
        
        if (!empty($descrizione)) {
            $updates[] = "descrizione = ?";
            $params[] = $descrizione;
            $types .= 's';
        }
        
        if (!empty($icona)) {
            $updates[] = "icona = ?";
            $params[] = $icona;
            $types .= 's';
        }
        
        if (!empty($colore)) {
            $updates[] = "colore = ?";
            $params[] = $colore;
            $types .= 's';
        }
        
        if ($privata !== null) {
            $updates[] = "privata = ?";
            $params[] = $privata ? 1 : 0;
            $types .= 'i';
        }
        
        if (empty($updates)) {
            throw new Exception('Nessun campo da aggiornare');
        }
        
        $params[] = $lista_id;
        $params[] = $user_id;
        $types .= 'ii';
        
        $query = "UPDATE liste_lettura SET " . implode(', ', $updates) . " WHERE id = ? AND fratello_id = ?";
        $stmt = $conn->prepare($query);
        $stmt->bind_param($types, ...$params);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'aggiornamento della lista');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Lista aggiornata con successo!'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore modifica lista: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDelete($action, $input) {
    global $conn, $user_id;
    
    switch ($action) {
        case 'elimina_lista':
            eliminaLista($input);
            break;
        case 'rimuovi_libro':
            rimuoviLibroDaLista($input);
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione DELETE non valida: ' . $action]);
    }
}

function eliminaLista($input) {
    global $conn, $user_id;
    
    $lista_id = $input['lista_id'] ?? 0;
    
    if (!$lista_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID lista richiesto']);
        return;
    }
    
    try {
        $stmt = $conn->prepare("
            DELETE FROM liste_lettura 
            WHERE id = ? AND fratello_id = ?
        ");
        $stmt->bind_param("ii", $lista_id, $user_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante l\'eliminazione della lista');
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Lista non trovata');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Lista eliminata con successo!'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore eliminazione lista: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function rimuoviLibroDaLista($input) {
    global $conn, $user_id;
    
    $lista_id = $input['lista_id'] ?? 0;
    $libro_id = $input['libro_id'] ?? 0;
    
    if (!$lista_id || !$libro_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID lista e libro richiesti']);
        return;
    }
    
    try {
        // Verifica che la lista appartenga all'utente
        $stmt = $conn->prepare("
            SELECT id FROM liste_lettura 
            WHERE id = ? AND fratello_id = ?
        ");
        $stmt->bind_param("ii", $lista_id, $user_id);
        $stmt->execute();
        
        if ($stmt->get_result()->num_rows === 0) {
            throw new Exception('Lista non trovata');
        }
        
        // Rimuovi libro
        $stmt = $conn->prepare("
            DELETE FROM lista_libri 
            WHERE lista_id = ? AND libro_id = ?
        ");
        $stmt->bind_param("ii", $lista_id, $libro_id);
        
        if (!$stmt->execute()) {
            throw new Exception('Errore durante la rimozione del libro dalla lista');
        }
        
        if ($stmt->affected_rows === 0) {
            throw new Exception('Libro non trovato nella lista');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Libro rimosso dalla lista!'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore rimozione libro da lista: " . $e->getMessage());
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

$conn->close();
?>
