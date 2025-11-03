<?php
session_start();
require_once '../config/database.php';

// Headers per API REST
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Gestione preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Verifica autenticazione
if (!isset($_SESSION['fratello_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Non autenticato']);
    exit;
}

$db = getDBConnection();
$method = $_SERVER['REQUEST_METHOD'];

// Lista admin autorizzati per operazioni CRUD
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$is_admin = in_array($_SESSION['fratello_id'], $admin_ids);

try {
    switch ($method) {
        case 'GET':
            handleGet($db);
            break;
            
        case 'POST':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
                exit;
            }
            handlePost($db);
            break;
            
        case 'PUT':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
                exit;
            }
            handlePut($db);
            break;
            
        case 'DELETE':
            if (!$is_admin) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Permessi insufficienti']);
                exit;
            }
            handleDelete($db);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'message' => 'Metodo non supportato']);
    }
} catch (Exception $e) {
    error_log("API Libri Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Errore interno del server']);
}

// Gestione GET - Lettura libri
function handleGet($db) {
    if (isset($_GET['id'])) {
        // Dettaglio singolo libro
        getLibroDettaglio($db, (int)$_GET['id']);
    } elseif (isset($_GET['action'])) {
        // Azioni speciali
        handleSpecialGet($db, $_GET['action']);
    } else {
        // Lista libri con filtri
        getLibriLista($db);
    }
}

// Dettaglio singolo libro
function getLibroDettaglio($db, $libro_id) {
    $query = "
        SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
               f.nome as prestato_a_nome, fp.nome as proprietario_nome,
               fa.nome as aggiunto_da_nome
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
        LEFT JOIN fratelli fp ON l.proprietario_fratello_id = fp.id
        LEFT JOIN fratelli fa ON l.aggiunto_da = fa.id
        WHERE l.id = ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$libro_id]);
    $libro = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$libro) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Libro non trovato']);
        return;
    }
    
    // Aggiungi statistiche del libro
    $stats_query = "
        SELECT 
            COUNT(DISTINCT sp.fratello_id) as fratelli_che_hanno_letto,
            AVG(sp.giorni_prestito) as media_giorni_prestito,
            COUNT(DISTINCT r.fratello_id) as numero_recensioni,
            AVG(r.valutazione) as valutazione_media
        FROM libri l
        LEFT JOIN storico_prestiti sp ON l.id = sp.libro_id AND sp.data_restituzione IS NOT NULL
        LEFT JOIN recensioni_libri r ON l.id = r.libro_id
        WHERE l.id = ?
    ";
    
    $stmt_stats = $db->prepare($stats_query);
    $stmt_stats->execute([$libro_id]);
    $stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);
    
    $libro['statistiche'] = $stats;
    
    echo json_encode(['success' => true, 'libro' => $libro]);
}

// Lista libri con filtri avanzati
function getLibriLista($db) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per_page = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
    $offset = ($page - 1) * $per_page;
    
    // Filtri
    $categoria = $_GET['categoria'] ?? '';
    $stato = $_GET['stato'] ?? '';
    $grado = $_GET['grado'] ?? '';
    $search = $_GET['search'] ?? '';
    $sort = $_GET['sort'] ?? 'titolo';
    $order = $_GET['order'] ?? 'ASC';
    
    // Validazione sort
    $allowed_sorts = ['titolo', 'autore', 'anno_pubblicazione', 'volte_prestato', 'created_at'];
    if (!in_array($sort, $allowed_sorts)) $sort = 'titolo';
    
    $allowed_orders = ['ASC', 'DESC'];
    if (!in_array($order, $allowed_orders)) $order = 'ASC';
    
    // Costruzione WHERE
    $where_conditions = ["1=1"];
    $params = [];
    
    if ($categoria) {
        $where_conditions[] = "l.categoria_id = ?";
        $params[] = $categoria;
    }
    
    if ($stato) {
        $where_conditions[] = "l.stato = ?";
        $params[] = $stato;
    }
    
    if ($grado) {
        $where_conditions[] = "l.grado_minimo = ?";
        $params[] = $grado;
    }
    
    if ($search) {
        $where_conditions[] = "(l.titolo LIKE ? OR l.autore LIKE ? OR l.numero_inventario LIKE ? OR l.descrizione LIKE ?)";
        $search_term = "%$search%";
        $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    }
    
    $where_clause = implode(" AND ", $where_conditions);
    
    // Query principale
    $query = "
        SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
               f.nome as prestato_a_nome,
               (SELECT AVG(valutazione) FROM recensioni_libri WHERE libro_id = l.id) as valutazione_media,
               (SELECT COUNT(*) FROM recensioni_libri WHERE libro_id = l.id) as numero_recensioni
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
        WHERE $where_clause
        ORDER BY l.$sort $order
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    $libri = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Count totale
    $count_params = array_slice($params, 0, -2);
    $count_query = "SELECT COUNT(*) FROM libri l WHERE $where_clause";
    $stmt_count = $db->prepare($count_query);
    $stmt_count->execute($count_params);
    $total = $stmt_count->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'libri' => $libri,
        'pagination' => [
            'current_page' => $page,
            'per_page' => $per_page,
            'total' => $total,
            'total_pages' => ceil($total / $per_page)
        ]
    ]);
}

// Azioni speciali GET
function handleSpecialGet($db, $action) {
    switch ($action) {
        case 'categorie':
            getCategorieLibri($db);
            break;
            
        case 'disponibili':
            getLibriDisponibili($db);
            break;
            
        case 'popolari':
            getLibriPopolari($db);
            break;
            
        case 'nuovi':
            getLibriNuovi($db);
            break;
            
        case 'search_suggestions':
            getSearchSuggestions($db);
            break;
            
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Azione non supportata']);
    }
}

// Categorie per filtri
function getCategorieLibri($db) {
    $query = "
        SELECT c.*, COUNT(l.id) as count_libri
        FROM categorie_libri c
        LEFT JOIN libri l ON c.id = l.categoria_id
        WHERE c.attiva = 1
        GROUP BY c.id
        ORDER BY c.ordine
    ";
    
    $stmt = $db->query($query);
    $categorie = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'categorie' => $categorie]);
}

// Libri disponibili per prestiti
function getLibriDisponibili($db) {
    $query = "
        SELECT l.id, l.titolo, l.autore, c.nome as categoria_nome
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE l.stato = 'disponibile'
        ORDER BY l.titolo
    ";
    
    $stmt = $db->query($query);
    $libri = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'libri' => $libri]);
}

// Libri più popolari
function getLibriPopolari($db) {
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
    
    $query = "
        SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
               (SELECT AVG(valutazione) FROM recensioni_libri WHERE libro_id = l.id) as valutazione_media,
               (SELECT COUNT(*) FROM recensioni_libri WHERE libro_id = l.id) as numero_recensioni
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE l.volte_prestato > 0
        ORDER BY l.volte_prestato DESC, valutazione_media DESC
        LIMIT ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$limit]);
    $libri = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'libri' => $libri]);
}

// Libri più recenti
function getLibriNuovi($db) {
    $limit = min(50, max(5, (int)($_GET['limit'] ?? 10)));
    
    $query = "
        SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore
        FROM libri l
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        ORDER BY l.created_at DESC
        LIMIT ?
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$limit]);
    $libri = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'libri' => $libri]);
}

// Suggerimenti di ricerca
function getSearchSuggestions($db) {
    $term = $_GET['term'] ?? '';
    if (strlen($term) < 2) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        return;
    }
    
    $search_term = "%$term%";
    
    $query = "
        (SELECT DISTINCT titolo as suggestion, 'titolo' as type FROM libri WHERE titolo LIKE ? LIMIT 5)
        UNION
        (SELECT DISTINCT autore as suggestion, 'autore' as type FROM libri WHERE autore LIKE ? AND autore IS NOT NULL LIMIT 5)
        UNION
        (SELECT DISTINCT c.nome as suggestion, 'categoria' as type FROM categorie_libri c WHERE c.nome LIKE ? LIMIT 3)
        ORDER BY suggestion
        LIMIT 10
    ";
    
    $stmt = $db->prepare($query);
    $stmt->execute([$search_term, $search_term, $search_term]);
    $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['success' => true, 'suggestions' => $suggestions]);
}

// Gestione POST - Creazione libro
function handlePost($db) {
    $data = getInputData();
    
    // Validazione campi obbligatori
    if (empty($data['titolo'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Titolo obbligatorio']);
        return;
    }
    
    // Preparazione dati con defaults
    $fields = [
        'numero_inventario' => generateNumeroInventario($db),
        'titolo' => trim($data['titolo']),
        'autore' => !empty($data['autore']) ? trim($data['autore']) : null,
        'isbn' => !empty($data['isbn']) ? trim($data['isbn']) : null,
        'categoria_id' => !empty($data['categoria_id']) ? (int)$data['categoria_id'] : null,
        'editore' => !empty($data['editore']) ? trim($data['editore']) : null,
        'anno_pubblicazione' => !empty($data['anno_pubblicazione']) ? (int)$data['anno_pubblicazione'] : null,
        'data_acquisizione' => date('Y-m-d'),
        'pagine' => !empty($data['pagine']) ? (int)$data['pagine'] : null,
        'lingua' => $data['lingua'] ?? 'Italiano',
        'descrizione' => !empty($data['descrizione']) ? trim($data['descrizione']) : null,
        'argomenti' => !empty($data['argomenti']) ? trim($data['argomenti']) : null,
        'grado_minimo' => $data['grado_minimo'] ?? 'pubblico',
        'ubicazione' => !empty($data['ubicazione']) ? trim($data['ubicazione']) : null,
        'stato' => $data['stato'] ?? 'disponibile',
        'condizioni' => $data['condizioni'] ?? 'buono',
        'valore_stimato' => !empty($data['valore_stimato']) ? (float)$data['valore_stimato'] : null,
        'copertina_url' => !empty($data['copertina_url']) ? trim($data['copertina_url']) : null,
        'pdf_url' => !empty($data['pdf_url']) ? trim($data['pdf_url']) : null,
        'proprieta' => $data['proprieta'] ?? 'loggia',
        'proprietario_fratello_id' => !empty($data['proprietario_fratello_id']) ? (int)$data['proprietario_fratello_id'] : null,
        'note' => !empty($data['note']) ? trim($data['note']) : null,
        'aggiunto_da' => $_SESSION['fratello_id']
    ];
    
    // Validazioni specifiche
    $valid_stati = ['disponibile', 'prestato', 'manutenzione', 'perso'];
    if (!in_array($fields['stato'], $valid_stati)) {
        $fields['stato'] = 'disponibile';
    }
    
    $valid_gradi = ['pubblico', 'Apprendista', 'Compagno', 'Maestro'];
    if (!in_array($fields['grado_minimo'], $valid_gradi)) {
        $fields['grado_minimo'] = 'pubblico';
    }
    
    $valid_condizioni = ['ottimo', 'buono', 'discreto', 'da_riparare'];
    if (!in_array($fields['condizioni'], $valid_condizioni)) {
        $fields['condizioni'] = 'buono';
    }
    
    // Costruzione query INSERT
    $columns = implode(', ', array_keys($fields));
    $placeholders = ':' . implode(', :', array_keys($fields));
    
    $query = "INSERT INTO libri ($columns) VALUES ($placeholders)";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($fields);
        
        $libro_id = $db->lastInsertId();
        
        // Log dell'operazione
        logActivity($db, $_SESSION['fratello_id'], 'libro_creato', "Libro '{$fields['titolo']}' creato con ID $libro_id");
        
        echo json_encode([
            'success' => true, 
            'message' => 'Libro creato con successo',
            'libro_id' => $libro_id
        ]);
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) { // Duplicate entry
            http_response_code(409);
            echo json_encode(['success' => false, 'message' => 'Libro già esistente (ISBN o inventario duplicato)']);
        } else {
            error_log("Errore creazione libro: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Errore nella creazione del libro']);
        }
    }
}

// Gestione PUT - Aggiornamento libro
function handlePut($db) {
    $data = getInputData();
    
    if (empty($data['libro_id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro mancante']);
        return;
    }
    
    $libro_id = (int)$data['libro_id'];
    
    // Verifica esistenza libro
    $check_stmt = $db->prepare("SELECT id, titolo FROM libri WHERE id = ?");
    $check_stmt->execute([$libro_id]);
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$existing) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Libro non trovato']);
        return;
    }
    
    // Preparazione campi da aggiornare (solo campi presenti)
    $updateFields = [];
    $params = [];
    
    $allowedFields = [
        'titolo', 'autore', 'isbn', 'categoria_id', 'editore', 'anno_pubblicazione',
        'pagine', 'lingua', 'descrizione', 'argomenti', 'grado_minimo', 'ubicazione',
        'stato', 'condizioni', 'valore_stimato', 'copertina_url', 'pdf_url',
        'proprieta', 'proprietario_fratello_id', 'note'
    ];
    
    foreach ($allowedFields as $field) {
        if (array_key_exists($field, $data)) {
            $updateFields[] = "$field = ?";
            
            // Gestione valori null/empty
            if ($data[$field] === '' || $data[$field] === null) {
                $params[] = null;
            } elseif (in_array($field, ['categoria_id', 'anno_pubblicazione', 'pagine', 'proprietario_fratello_id'])) {
                $params[] = (int)$data[$field] ?: null;
            } elseif ($field === 'valore_stimato') {
                $params[] = (float)$data[$field] ?: null;
            } else {
                $params[] = trim($data[$field]);
            }
        }
    }
    
    if (empty($updateFields)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nessun campo da aggiornare']);
        return;
    }
    
    // Aggiorna timestamp
    $updateFields[] = "updated_at = CURRENT_TIMESTAMP";
    $params[] = $libro_id;
    
    $query = "UPDATE libri SET " . implode(', ', $updateFields) . " WHERE id = ?";
    
    try {
        $stmt = $db->prepare($query);
        $stmt->execute($params);
        
        // Log dell'operazione
        logActivity($db, $_SESSION['fratello_id'], 'libro_aggiornato', "Libro '{$existing['titolo']}' (ID $libro_id) aggiornato");
        
        echo json_encode(['success' => true, 'message' => 'Libro aggiornato con successo']);
        
    } catch (PDOException $e) {
        error_log("Errore aggiornamento libro: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore nell\'aggiornamento del libro']);
    }
}

// Gestione DELETE - Eliminazione libro
function handleDelete($db) {
    $data = getInputData();
    
    if (empty($data['id'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID libro mancante']);
        return;
    }
    
    $libro_id = (int)$data['id'];
    
    // Verifica esistenza e stato del libro
    $check_stmt = $db->prepare("
        SELECT l.id, l.titolo, l.stato, l.prestato_a_fratello_id,
               COUNT(sp.id) as prestiti_storici,
               COUNT(r.id) as recensioni
        FROM libri l
        LEFT JOIN storico_prestiti sp ON l.id = sp.libro_id
        LEFT JOIN recensioni_libri r ON l.id = r.libro_id
        WHERE l.id = ?
        GROUP BY l.id
    ");
    $check_stmt->execute([$libro_id]);
    $libro = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$libro) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Libro non trovato']);
        return;
    }
    
    // Verifica se il libro è attualmente in prestito
    if ($libro['stato'] === 'prestato') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Impossibile eliminare un libro attualmente in prestito']);
        return;
    }
    
    // Avvertimento per libri con storico
    if ($libro['prestiti_storici'] > 0 || $libro['recensioni'] > 0) {
        // Invece di eliminare, disattiva o marca come "rimosso"
        if (!isset($data['force']) || !$data['force']) {
            echo json_encode([
                'success' => false, 
                'message' => 'Il libro ha uno storico di prestiti/recensioni. Usare force=true per eliminare comunque.',
                'warning' => true,
                'stats' => [
                    'prestiti_storici' => $libro['prestiti_storici'],
                    'recensioni' => $libro['recensioni']
                ]
            ]);
            return;
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Elimina prima le dipendenze (se force)
        if (isset($data['force']) && $data['force']) {
            // Elimina recensioni
            $db->prepare("DELETE FROM recensioni_libri WHERE libro_id = ?")->execute([$libro_id]);
            
            // Elimina da liste di lettura
            $db->prepare("DELETE FROM lista_libri WHERE libro_id = ?")->execute([$libro_id]);
            
            // Lo storico prestiti viene mantenuto per integrità dati
            // Ma aggiorniamo con flag di libro eliminato
            $db->prepare("UPDATE storico_prestiti SET note_restituzione = CONCAT(COALESCE(note_restituzione, ''), ' [LIBRO ELIMINATO]') WHERE libro_id = ?")->execute([$libro_id]);
        }
        
        // Elimina il libro
        $stmt = $db->prepare("DELETE FROM libri WHERE id = ?");
        $stmt->execute([$libro_id]);
        
        $db->commit();
        
        // Log dell'operazione
        logActivity($db, $_SESSION['fratello_id'], 'libro_eliminato', "Libro '{$libro['titolo']}' (ID $libro_id) eliminato");
        
        echo json_encode(['success' => true, 'message' => 'Libro eliminato con successo']);
        
    } catch (PDOException $e) {
        $db->rollback();
        error_log("Errore eliminazione libro: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Errore nell\'eliminazione del libro']);
    }
}

// Utility Functions

// Ottieni dati input in base al content-type
function getInputData() {
    $content_type = $_SERVER['CONTENT_TYPE'] ?? '';
    
    if (strpos($content_type, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        return json_decode($input, true) ?? [];
    } elseif (strpos($content_type, 'multipart/form-data') !== false || strpos($content_type, 'application/x-www-form-urlencoded') !== false) {
        return $_POST;
    } else {
        parse_str(file_get_contents('php://input'), $data);
        return $data;
    }
}

// Genera numero inventario univoco
function generateNumeroInventario($db) {
    // Trova il numero più alto esistente
    $stmt = $db->query("
        SELECT numero_inventario 
        FROM libri 
        WHERE numero_inventario REGEXP '^BIB[0-9]+$' 
        ORDER BY CAST(SUBSTRING(numero_inventario, 4) AS UNSIGNED) DESC 
        LIMIT 1
    ");
    
    $last = $stmt->fetchColumn();
    
    if ($last && preg_match('/^BIB(\d+)$/', $last, $matches)) {
        $next_number = (int)$matches[1] + 1;
    } else {
        // Fallback: conta tutti i libri + 1
        $count = $db->query("SELECT COUNT(*) FROM libri")->fetchColumn();
        $next_number = $count + 1;
    }
    
    return 'BIB' . str_pad($next_number, 4, '0', STR_PAD_LEFT);
}

// Log attività per audit
function logActivity($db, $fratello_id, $azione, $dettagli) {
    try {
        // Crea tabella log se non esiste
        $db->exec("
            CREATE TABLE IF NOT EXISTS log_attivita (
                id INT AUTO_INCREMENT PRIMARY KEY,
                fratello_id INT,
                azione VARCHAR(50),
                dettagli TEXT,
                ip_address VARCHAR(45),
                user_agent TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX(fratello_id),
                INDEX(azione),
                INDEX(created_at)
            )
        ");
        
        $stmt = $db->prepare("
            INSERT INTO log_attivita (fratello_id, azione, dettagli, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $fratello_id,
            $azione,
            $dettagli,
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
        ]);
    } catch (PDOException $e) {
        // Log error ma non interrompere l'operazione principale
        error_log("Errore log attività: " . $e->getMessage());
    }
}
?>