<?php
session_start();
require_once '../../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

// Lista admin autorizzati
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
if (!in_array($_SESSION['fratello_id'], $admin_ids)) {
    header('Location: ../dashboard.php?error=non_autorizzato');
    exit;
}

// Connessione database PDO
try {
    $db = new PDO("mysql:host=localhost;dbname=jmvvznbb_tornate_db;charset=utf8mb4", 
                   'jmvvznbb_tornate_user', 'Puntorosso22');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Errore connessione database: " . $e->getMessage());
}

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'nuovo_prestito') {
        $libro_id = (int)$_POST['libro_id'];
        $fratello_id = (int)$_POST['fratello_id'];
        $giorni_prestito = (int)($_POST['giorni_prestito'] ?? 30);
        $note = $_POST['note'] ?? '';
        
        try {
            $db->beginTransaction();
            
            // Verifica che il libro sia disponibile
            $stmt = $db->prepare("SELECT titolo FROM libri WHERE id = ? AND stato = 'disponibile'");
            $stmt->execute([$libro_id]);
            $libro = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$libro) {
                throw new Exception('Libro non trovato o non disponibile');
            }
            
            // Verifica che il fratello esista
            $stmt = $db->prepare("SELECT nome FROM fratelli WHERE id = ?");
            $stmt->execute([$fratello_id]);
            $fratello = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$fratello) {
                throw new Exception('Fratello non trovato');
            }
            
            // Calcola date
            $data_prestito = date('Y-m-d');
            $data_scadenza = date('Y-m-d', strtotime("+{$giorni_prestito} days"));
            
            // Aggiorna libro
            $stmt = $db->prepare("
                UPDATE libri 
                SET stato = 'prestato', 
                    prestato_a_fratello_id = ?, 
                    data_prestito_corrente = ?, 
                    data_scadenza_corrente = ?
                WHERE id = ?
            ");
            $stmt->execute([$fratello_id, $data_prestito, $data_scadenza, $libro_id]);
            
            $db->commit();
            $_SESSION['success'] = 'Prestito registrato con successo!';
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Errore nella registrazione del prestito: ' . $e->getMessage();
        }
        
        header('Location: gestione-prestiti.php');
        exit;
    }
    
    if ($action === 'restituzione') {
        $libro_id = (int)$_POST['libro_id'];
        $stato_rientro = $_POST['stato_rientro'] ?? 'buono';
        $note_restituzione = $_POST['note_restituzione'] ?? '';
        
        try {
            $db->beginTransaction();
            
            // Verifica che il libro sia effettivamente in prestito
            $stmt = $db->prepare("SELECT * FROM libri WHERE id = ? AND stato = 'prestato'");
            $stmt->execute([$libro_id]);
            $libro = $stmt->fetch(PDO::FETCH_ASSOC);
            
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
            $check_storico = $db->query("SHOW TABLES LIKE 'storico_prestiti'");
            if ($check_storico->rowCount() > 0 && $libro['prestato_a_fratello_id']) {
                $stmt = $db->prepare("
                    INSERT INTO storico_prestiti 
                    (libro_id, fratello_id, data_prestito, data_scadenza, data_restituzione, giorni_prestito, note_restituzione, gestito_da) 
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, ?)
                ");
                $stmt->execute([
                    $libro_id, 
                    $libro['prestato_a_fratello_id'], 
                    $libro['data_prestito_corrente'], 
                    $libro['data_scadenza_corrente'], 
                    $giorni_prestito, 
                    $note_restituzione, 
                    $_SESSION['fratello_id']
                ]);
            }
            
            // Inserisci automaticamente in libri_letti
            if ($libro['prestato_a_fratello_id']) {
                $nota_default = "Letto tramite prestito biblioteca";
                $stmt_letti = $db->prepare("
                    INSERT INTO libri_letti (fratello_id, libro_id, data_lettura, note) 
                    VALUES (?, ?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE 
                        data_lettura = NOW(), 
                        note = IF(
                            COALESCE(note, '') LIKE CONCAT('%', ?, '%'),
                            note,
                            CONCAT(COALESCE(note, ''), ' | ', ?)
                        )
                ");
                $stmt_letti->execute([
                    $libro['prestato_a_fratello_id'], 
                    $libro_id, 
                    $nota_default,
                    $nota_default,
                    $nota_default
                ]);
            }
            
            // Aggiorna lo stato del libro
            $stmt = $db->prepare("
                UPDATE libri 
                SET stato = 'disponibile', 
                    prestato_a_fratello_id = NULL, 
                    data_prestito_corrente = NULL, 
                    data_scadenza_corrente = NULL,
                    condizioni = ?
                WHERE id = ?
            ");
            $stmt->execute([$stato_rientro, $libro_id]);
            
            $db->commit();
            $_SESSION['success'] = 'Restituzione registrata con successo!';
        } catch (Exception $e) {
            $db->rollBack();
            $_SESSION['error'] = 'Errore nella restituzione: ' . $e->getMessage();
        }
        
        header('Location: gestione-prestiti.php');
        exit;
    }
}

// Gestione filtri
$filtro_stato = $_GET['stato'] ?? 'tutti';
$filtro_fratello = $_GET['fratello'] ?? '';
$filtro_scadenza = $_GET['scadenza'] ?? '';
$search = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Costruzione query con filtri
$where_conditions = [];
$params = [];

if ($filtro_stato === 'attivi') {
    $where_conditions[] = "l.stato = 'prestato'";
} elseif ($filtro_stato === 'scaduti') {
    $where_conditions[] = "l.stato = 'prestato' AND l.data_scadenza_corrente < CURDATE()";
} elseif ($filtro_stato === 'urgenti') {
    $where_conditions[] = "l.stato = 'prestato' AND l.data_scadenza_corrente BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)";
}

if ($filtro_fratello) {
    $where_conditions[] = "f.id = ?";
    $params[] = $filtro_fratello;
}

if ($search) {
    $where_conditions[] = "(l.titolo LIKE ? OR l.autore LIKE ? OR f.nome LIKE ?)";
    $search_term = "%$search%";
    $params = array_merge($params, [$search_term, $search_term, $search_term]);
}

// Query principale prestiti
$base_query = "
    FROM libri l
    LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
";

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
} else {
    // Mostra tutti i prestiti attivi di default
    $where_clause = "WHERE l.stato = 'prestato'";
}

$query_prestiti = "
    SELECT l.*, f.nome as fratello_nome, f.telefono, f.email, 
           c.nome as categoria_nome, c.colore as categoria_colore,
           DATEDIFF(l.data_scadenza_corrente, CURDATE()) as giorni_rimanenti,
           CASE 
               WHEN l.data_scadenza_corrente < CURDATE() THEN 'SCADUTO'
               WHEN DATEDIFF(l.data_scadenza_corrente, CURDATE()) <= 3 THEN 'URGENTE'
               WHEN DATEDIFF(l.data_scadenza_corrente, CURDATE()) <= 7 THEN 'ATTENZIONE'
               ELSE 'OK'
           END as stato_scadenza
    $base_query 
    $where_clause
    ORDER BY l.data_scadenza_corrente ASC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;
$stmt_prestiti = $db->prepare($query_prestiti);
$stmt_prestiti->execute($params);
$prestiti = $stmt_prestiti->fetchAll(PDO::FETCH_ASSOC);

// Count totale per paginazione
$count_params = array_slice($params, 0, -2);
$count_query = "SELECT COUNT(*) $base_query $where_clause";
$stmt_count = $db->prepare($count_query);
$stmt_count->execute($count_params);
$total_prestiti = $stmt_count->fetchColumn();
$total_pages = ceil($total_prestiti / $per_page);

// Statistiche dashboard
$stats = [
    'prestiti_attivi' => $db->query("SELECT COUNT(*) FROM libri WHERE stato = 'prestato'")->fetchColumn(),
    'prestiti_scaduti' => $db->query("SELECT COUNT(*) FROM libri WHERE stato = 'prestato' AND data_scadenza_corrente < CURDATE()")->fetchColumn(),
    'prestiti_urgenti' => $db->query("SELECT COUNT(*) FROM libri WHERE stato = 'prestato' AND data_scadenza_corrente BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)")->fetchColumn(),
    'libri_disponibili' => $db->query("SELECT COUNT(*) FROM libri WHERE stato = 'disponibile'")->fetchColumn()
];

// Fratelli per filtro e nuovo prestito
$fratelli = $db->query("SELECT id, nome, grado FROM fratelli WHERE attivo = 1 ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Libri disponibili per nuovo prestito
$libri_disponibili = $db->query("
    SELECT l.id, l.titolo, l.autore, c.nome as categoria_nome 
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id 
    WHERE l.stato = 'disponibile' 
    ORDER BY l.titolo
")->fetchAll(PDO::FETCH_ASSOC);

// Prestiti in scadenza per sidebar
$prestiti_scadenza = $db->query("
    SELECT l.id, l.titolo, l.autore, f.nome as fratello_nome, f.telefono,
           l.data_scadenza_corrente,
           DATEDIFF(l.data_scadenza_corrente, CURDATE()) as giorni_rimanenti
    FROM libri l 
    JOIN fratelli f ON l.prestato_a_fratello_id = f.id 
    WHERE l.stato = 'prestato' AND l.data_scadenza_corrente <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ORDER BY l.data_scadenza_corrente ASC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Ultimi prestiti storici per monitoraggio
$storico_recente = $db->query("
    SELECT sp.*, l.titolo, l.autore, f.nome as fratello_nome
    FROM storico_prestiti sp
    JOIN libri l ON sp.libro_id = l.id
    JOIN fratelli f ON sp.fratello_id = f.id
    ORDER BY sp.data_prestito DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Prestiti - Admin | R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#6366f1',
                        secondary: '#8b5cf6'
                    }
                }
            }
        }
    </script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>

<body class="min-h-screen" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
    <!-- Header -->
    <div class="bg-white/95 backdrop-blur-sm shadow-lg sticky top-0 z-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <div class="flex items-center space-x-4">
                    <div class="flex items-center space-x-2">
                        <span class="text-2xl">🏛️</span>
                        <h1 class="text-xl font-bold text-gray-900">R∴ L∴ Kilwinning</h1>
                    </div>
                    <nav class="hidden md:flex space-x-1">
                        <a href="../dashboard.php" class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">
                            <i class="fas fa-home mr-2"></i>Dashboard
                        </a>
                        <a href="../catalogo.php" class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">
                            <i class="fas fa-book mr-2"></i>Catalogo
                        </a>
                        <a href="gestione-libri.php" class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">
                            <i class="fas fa-cogs mr-2"></i>Gestione Libri
                        </a>
                        <a href="richieste-prestito.php" class="px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:text-gray-900 hover:bg-gray-100">
                            <i class="fas fa-clipboard-list mr-2"></i>Richieste
                        </a>
                        <span class="px-3 py-2 rounded-md text-sm font-medium bg-primary text-white">
                            <i class="fas fa-exchange-alt mr-2"></i>Prestiti
                        </span>
                    </nav>
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-gray-600">
                        <i class="fas fa-user-shield mr-1"></i><?php echo htmlspecialchars($_SESSION['nome'] ?? 'Admin'); ?>
                    </span>
                    <a href="../../api/logout.php" class="bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        <i class="fas fa-sign-out-alt mr-2"></i>Esci
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb -->
        <nav class="mb-6">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="../dashboard.php" class="text-white/80 hover:text-white">Dashboard</a></li>
                <li class="text-white/60">/</li>
                <li><a href="#" class="text-white/80 hover:text-white">Admin</a></li>
                <li class="text-white/60">/</li>
                <li class="text-white font-medium">Gestione Prestiti</li>
            </ol>
        </nav>

        <!-- Messaggi -->
        <?php if (isset($_SESSION['success'])): ?>
            <div class="mb-6 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
                <i class="fas fa-check-circle mr-2"></i><?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>

        <?php if (isset($_SESSION['error'])): ?>
            <div class="mb-6 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg">
                <i class="fas fa-exclamation-circle mr-2"></i><?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>

        <!-- Statistiche Admin -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hand-holding text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Prestiti Attivi</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['prestiti_attivi']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-red-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-exclamation-triangle text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Scaduti</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['prestiti_scaduti']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-clock text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">In Scadenza</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['prestiti_urgenti']; ?></p>
                    </div>
                </div>
            </div>

            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check-circle text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Disponibili</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['libri_disponibili']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Pannello principale prestiti -->
            <div class="lg:col-span-3">
                <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg">
                    <!-- Header con filtri e nuovo prestito -->
                    <div class="p-6 border-b border-gray-200">
                        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                            <h2 class="text-xl font-bold text-gray-900 flex items-center">
                                <i class="fas fa-exchange-alt mr-2 text-primary"></i>
                                Gestione Prestiti (<?php echo $total_prestiti; ?>)
                            </h2>
                            <button id="btnNuovoPrestito" class="bg-primary hover:bg-blue-700 text-white px-4 py-2 rounded-lg font-medium">
                                <i class="fas fa-plus mr-2"></i>Nuovo Prestito
                            </button>
                        </div>

                        <!-- Filtri -->
                        <div class="mt-4">
                            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                                <div>
                                    <select name="stato" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                        <option value="attivi" <?php echo $filtro_stato === 'attivi' ? 'selected' : ''; ?>>Prestiti Attivi</option>
                                        <option value="scaduti" <?php echo $filtro_stato === 'scaduti' ? 'selected' : ''; ?>>Scaduti</option>
                                        <option value="urgenti" <?php echo $filtro_stato === 'urgenti' ? 'selected' : ''; ?>>In Scadenza (3gg)</option>
                                        <option value="tutti" <?php echo $filtro_stato === 'tutti' ? 'selected' : ''; ?>>Tutti</option>
                                    </select>
                                </div>
                                <div>
                                    <select name="fratello" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                        <option value="">Tutti i fratelli</option>
                                        <?php foreach ($fratelli as $fratello): ?>
                                            <option value="<?php echo $fratello['id']; ?>" <?php echo $filtro_fratello == $fratello['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($fratello['nome']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                           placeholder="Cerca libro o fratello..." 
                                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                </div>
                                <div class="flex space-x-2">
                                    <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg flex-1">
                                        <i class="fas fa-search mr-2"></i>Filtra
                                    </button>
                                    <a href="gestione-prestiti.php" class="bg-gray-400 hover:bg-gray-500 text-white px-4 py-2 rounded-lg">
                                        <i class="fas fa-refresh"></i>
                                    </a>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Lista prestiti -->
                    <div class="overflow-hidden">
                        <?php if (empty($prestiti)): ?>
                            <div class="p-8 text-center">
                                <i class="fas fa-hand-holding text-gray-400 text-4xl mb-4"></i>
                                <p class="text-gray-600">Nessun prestito trovato con i filtri selezionati.</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Libro</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fratello</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date Prestito</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($prestiti as $prestito): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4">
                                                    <div class="flex items-start space-x-3">
                                                        <div class="flex-shrink-0 w-2 h-16 rounded" style="background-color: <?php echo $prestito['categoria_colore'] ?? '#6366f1'; ?>"></div>
                                                        <div>
                                                            <div class="text-sm font-medium text-gray-900">
                                                                <?php echo htmlspecialchars($prestito['titolo']); ?>
                                                            </div>
                                                            <div class="text-sm text-gray-500">
                                                                <?php echo htmlspecialchars($prestito['autore'] ?? 'Autore sconosciuto'); ?>
                                                            </div>
                                                            <?php if ($prestito['categoria_nome']): ?>
                                                                <div class="text-xs text-gray-400">
                                                                    <?php echo htmlspecialchars($prestito['categoria_nome']); ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <div class="text-sm font-medium text-gray-900">
                                                        <?php echo htmlspecialchars($prestito['fratello_nome']); ?>
                                                    </div>
                                                    <?php if ($prestito['telefono']): ?>
                                                        <div class="text-xs text-gray-500">
                                                            <i class="fas fa-phone mr-1"></i><?php echo htmlspecialchars($prestito['telefono']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                                    <div>
                                                        <div class="font-medium">Prestito: <?php echo date('d/m/Y', strtotime($prestito['data_prestito_corrente'])); ?></div>
                                                        <div class="text-xs">Scadenza: <?php echo date('d/m/Y', strtotime($prestito['data_scadenza_corrente'])); ?></div>
                                                    </div>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php
                                                    $status_colors = [
                                                        'SCADUTO' => 'bg-red-100 text-red-800',
                                                        'URGENTE' => 'bg-orange-100 text-orange-800',
                                                        'ATTENZIONE' => 'bg-yellow-100 text-yellow-800',
                                                        'OK' => 'bg-green-100 text-green-800'
                                                    ];
                                                    $color_class = $status_colors[$prestito['stato_scadenza']] ?? 'bg-gray-100 text-gray-800';
                                                    ?>
                                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo $color_class; ?>">
                                                        <?php if ($prestito['stato_scadenza'] === 'SCADUTO'): ?>
                                                            <i class="fas fa-exclamation-triangle mr-1"></i>
                                                            Scaduto da <?php echo abs($prestito['giorni_rimanenti']); ?> giorni
                                                        <?php elseif ($prestito['stato_scadenza'] === 'URGENTE'): ?>
                                                            <i class="fas fa-clock mr-1"></i>
                                                            <?php echo $prestito['giorni_rimanenti']; ?> giorni rimanenti
                                                        <?php elseif ($prestito['stato_scadenza'] === 'ATTENZIONE'): ?>
                                                            <i class="fas fa-calendar-alt mr-1"></i>
                                                            <?php echo $prestito['giorni_rimanenti']; ?> giorni rimanenti
                                                        <?php else: ?>
                                                            <i class="fas fa-check mr-1"></i>
                                                            In regola
                                                        <?php endif; ?>
                                                    </span>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium space-x-2">
                                                    <a href="../libro-dettaglio.php?id=<?php echo $prestito['id']; ?>" 
                                                       class="text-primary hover:text-blue-700" title="Visualizza dettaglio">
                                                        <i class="fas fa-eye"></i>
                                                    </a>
                                                    <button onclick="restituisciLibro(<?php echo $prestito['id']; ?>)" 
                                                            class="text-green-600 hover:text-green-900" title="Restituisci libro">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                    <?php if ($prestito['telefono']): ?>
                                                        <button onclick="richiamaPrestito('<?php echo htmlspecialchars($prestito['fratello_nome']); ?>', '<?php echo htmlspecialchars($prestito['telefono']); ?>', '<?php echo htmlspecialchars($prestito['titolo']); ?>')" 
                                                                class="text-orange-600 hover:text-orange-900" title="Richiama fratello">
                                                            <i class="fas fa-phone"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <button onclick="prolongaPrestito(<?php echo $prestito['id']; ?>)" 
                                                            class="text-blue-600 hover:text-blue-900" title="Prolunga prestito">
                                                        <i class="fas fa-calendar-plus"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Paginazione -->
                            <?php if ($total_pages > 1): ?>
                                <div class="bg-white px-4 py-3 border-t border-gray-200 sm:px-6">
                                    <div class="flex items-center justify-between">
                                        <div class="flex-1 flex justify-between sm:hidden">
                                            <?php if ($page > 1): ?>
                                                <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" 
                                                   class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                    Precedente
                                                </a>
                                            <?php endif; ?>
                                            <?php if ($page < $total_pages): ?>
                                                <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" 
                                                   class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                    Successiva
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                                            <div>
                                                <p class="text-sm text-gray-700">
                                                    Mostra <span class="font-medium"><?php echo $offset + 1; ?></span> a 
                                                    <span class="font-medium"><?php echo min($offset + $per_page, $total_prestiti); ?></span> di 
                                                    <span class="font-medium"><?php echo $total_prestiti; ?></span> risultati
                                                </p>
                                            </div>
                                            <div>
                                                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                                        <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, fn($k) => $k !== 'page', ARRAY_FILTER_USE_KEY)); ?>" 
                                                           class="relative inline-flex items-center px-4 py-2 border text-sm font-medium <?php echo $i === $page ? 'bg-primary text-white border-primary' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'; ?>">
                                                            <?php echo $i; ?>
                                                        </a>
                                                    <?php endfor; ?>
                                                </nav>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Sidebar informazioni -->
            <div class="lg:col-span-1 space-y-6">
                <!-- Prestiti in scadenza -->
                <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-exclamation-triangle mr-2 text-red-500"></i>
                        Alert Scadenze
                    </h3>
                    <?php if (empty($prestiti_scadenza)): ?>
                        <p class="text-gray-500 text-sm">Nessun prestito in scadenza</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($prestiti_scadenza as $alert): ?>
                                <div class="p-3 rounded-lg <?php echo $alert['giorni_rimanenti'] < 0 ? 'bg-red-50 border border-red-200' : ($alert['giorni_rimanenti'] <= 3 ? 'bg-orange-50 border border-orange-200' : 'bg-yellow-50 border border-yellow-200'); ?>">
                                    <div class="text-sm font-medium text-gray-900 truncate">
                                        <?php echo htmlspecialchars($alert['titolo']); ?>
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        <?php echo htmlspecialchars($alert['fratello_nome']); ?>
                                    </div>
                                    <div class="text-xs mt-1 font-medium">
                                        <?php if ($alert['giorni_rimanenti'] < 0): ?>
                                            <span class="text-red-600">
                                                <i class="fas fa-exclamation-circle mr-1"></i>
                                                Scaduto da <?php echo abs($alert['giorni_rimanenti']); ?> giorni
                                            </span>
                                        <?php elseif ($alert['giorni_rimanenti'] == 0): ?>
                                            <span class="text-orange-600">
                                                <i class="fas fa-clock mr-1"></i>
                                                Scade oggi!
                                            </span>
                                        <?php else: ?>
                                            <span class="text-yellow-600">
                                                <i class="fas fa-calendar-alt mr-1"></i>
                                                Scade tra <?php echo $alert['giorni_rimanenti']; ?> giorni
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($alert['telefono']): ?>
                                        <button onclick="richiamaPrestito('<?php echo htmlspecialchars($alert['fratello_nome']); ?>', '<?php echo htmlspecialchars($alert['telefono']); ?>', '<?php echo htmlspecialchars($alert['titolo']); ?>')" 
                                                class="mt-2 text-xs bg-blue-500 hover:bg-blue-600 text-white px-2 py-1 rounded">
                                            <i class="fas fa-phone mr-1"></i>Chiama
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Storico recente -->
                <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-history mr-2 text-blue-500"></i>
                        Storico Recente
                    </h3>
                    <?php if (empty($storico_recente)): ?>
                        <p class="text-gray-500 text-sm">Nessun prestito recente</p>
                    <?php else: ?>
                        <div class="space-y-3">
                            <?php foreach ($storico_recente as $storico): ?>
                                <div class="p-3 bg-gray-50 rounded-lg">
                                    <div class="text-sm font-medium text-gray-900 truncate">
                                        <?php echo htmlspecialchars($storico['titolo']); ?>
                                    </div>
                                    <div class="text-xs text-gray-600">
                                        a <?php echo htmlspecialchars($storico['fratello_nome']); ?>
                                    </div>
                                    <div class="text-xs text-gray-500 mt-1">
                                        <?php echo date('d/m/Y', strtotime($storico['data_prestito'])); ?>
                                        <?php if ($storico['data_restituzione']): ?>
                                            <span class="text-green-600">
                                                - restituito il <?php echo date('d/m/Y', strtotime($storico['data_restituzione'])); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-orange-600">- in corso</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Azioni rapide -->
                <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center">
                        <i class="fas fa-lightning-bolt mr-2 text-yellow-500"></i>
                        Azioni Rapide
                    </h3>
                    <div class="space-y-3">
                        <button onclick="notificaScadenze()" class="w-full text-left px-4 py-3 bg-red-50 hover:bg-red-100 rounded-lg text-sm font-medium text-red-700">
                            <i class="fas fa-bell mr-2"></i>
                            Notifica Scadenze
                        </button>
                        <button onclick="esportaPrestiti()" class="w-full text-left px-4 py-3 bg-blue-50 hover:bg-blue-100 rounded-lg text-sm font-medium text-blue-700">
                            <i class="fas fa-download mr-2"></i>
                            Esporta Prestiti
                        </button>
                        <a href="gestione-libri.php" class="block w-full text-left px-4 py-3 bg-green-50 hover:bg-green-100 rounded-lg text-sm font-medium text-green-700">
                            <i class="fas fa-cogs mr-2"></i>
                            Gestione Libri
                        </a>
                        <button onclick="generaReportPrestiti()" class="w-full text-left px-4 py-3 bg-purple-50 hover:bg-purple-100 rounded-lg text-sm font-medium text-purple-700">
                            <i class="fas fa-chart-line mr-2"></i>
                            Report Prestiti
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Nuovo Prestito -->
    <div id="modalNuovoPrestito" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-2/3 lg:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900">Nuovo Prestito</h3>
                    <button onclick="closeModalPrestito()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="nuovo_prestito">
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Libro *</label>
                            <select name="libro_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                <option value="">Seleziona libro</option>
                                <?php foreach ($libri_disponibili as $libro): ?>
                                    <option value="<?php echo $libro['id']; ?>">
                                        <?php echo htmlspecialchars($libro['titolo']); ?>
                                        <?php if ($libro['autore']): ?>
                                            - <?php echo htmlspecialchars($libro['autore']); ?>
                                        <?php endif; ?>
                                        <?php if ($libro['categoria_nome']): ?>
                                            (<?php echo htmlspecialchars($libro['categoria_nome']); ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Fratello *</label>
                            <select name="fratello_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                <option value="">Seleziona fratello</option>
                                <?php 
                                $gradi_order = ['Apprendista' => 1, 'Compagno' => 2, 'Maestro' => 3];
                                usort($fratelli, function($a, $b) use ($gradi_order) {
                                    $order_a = $gradi_order[$a['grado']] ?? 0;
                                    $order_b = $gradi_order[$b['grado']] ?? 0;
                                    if ($order_a === $order_b) {
                                        return strcmp($a['nome'], $b['nome']);
                                    }
                                    return $order_b - $order_a; // Maestri prima
                                });
                                
                                $current_grado = '';
                                foreach ($fratelli as $fratello): 
                                    if ($fratello['grado'] !== $current_grado):
                                        if ($current_grado !== '') echo '</optgroup>';
                                        $current_grado = $fratello['grado'];
                                        $icon = $fratello['grado'] === 'Maestro' ? '🔶' : ($fratello['grado'] === 'Compagno' ? '🔷' : '🔺');
                                        echo '<optgroup label="' . $icon . ' ' . htmlspecialchars($fratello['grado']) . '">';
                                    endif;
                                ?>
                                    <option value="<?php echo $fratello['id']; ?>">
                                        <?php echo htmlspecialchars($fratello['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if ($current_grado !== '') echo '</optgroup>'; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Durata Prestito (giorni)</label>
                            <select name="giorni_prestito" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                                <option value="15">15 giorni</option>
                                <option value="30" selected>30 giorni (standard)</option>
                                <option value="45">45 giorni</option>
                                <option value="60">60 giorni</option>
                                <option value="90">90 giorni</option>
                            </select>
                        </div>
                        
                        <div class="md:col-span-2">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Note</label>
                            <textarea name="note" rows="3" placeholder="Note aggiuntive sul prestito..."
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
                        </div>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeModalPrestito()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-primary hover:bg-blue-700 text-white rounded-lg font-medium">
                            <i class="fas fa-hand-holding mr-2"></i>Crea Prestito
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Restituzione -->
    <div id="modalRestituzione" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900">Restituzione Libro</h3>
                    <button onclick="closeModalRestituzione()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="restituzione">
                    <input type="hidden" id="restituzione_libro_id" name="libro_id">
                    
                    <div class="bg-blue-50 p-4 rounded-lg mb-4">
                        <p class="text-sm text-blue-700">
                            <i class="fas fa-info-circle mr-2"></i>
                            Stai registrando la restituzione del libro: <span id="restituzione_libro_titolo" class="font-medium"></span>
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Condizioni Libro al Rientro</label>
                        <select name="stato_rientro" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary">
                            <option value="ottimo">Ottimo</option>
                            <option value="buono" selected>Buono</option>
                            <option value="discreto">Discreto</option>
                            <option value="da_riparare">Da Riparare</option>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Note Restituzione</label>
                        <textarea name="note_restituzione" rows="3" placeholder="Note sulle condizioni del libro, eventuali danni..."
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-primary"></textarea>
                    </div>
                    
                    <div class="flex justify-end space-x-3 pt-4">
                        <button type="button" onclick="closeModalRestituzione()" 
                                class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">
                            Annulla
                        </button>
                        <button type="submit" 
                                class="px-4 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium">
                            <i class="fas fa-undo mr-2"></i>Conferma Restituzione
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Gestione Modal Nuovo Prestito
        function openModalPrestito() {
            document.getElementById('modalNuovoPrestito').classList.remove('hidden');
        }

        function closeModalPrestito() {
            document.getElementById('modalNuovoPrestito').classList.add('hidden');
        }

        document.getElementById('btnNuovoPrestito').addEventListener('click', openModalPrestito);

        // Gestione Modal Restituzione
        function openModalRestituzione(libroId, titoloLibro) {
            document.getElementById('restituzione_libro_id').value = libroId;
            document.getElementById('restituzione_libro_titolo').textContent = titoloLibro;
            document.getElementById('modalRestituzione').classList.remove('hidden');
        }

        function closeModalRestituzione() {
            document.getElementById('modalRestituzione').classList.add('hidden');
        }

        // Funzioni azioni prestiti
        function restituisciLibro(libroId) {
            // Trova il titolo del libro nella tabella
            const row = event.target.closest('tr');
            const titoloElement = row.querySelector('td:first-child .text-sm.font-medium');
            const titolo = titoloElement ? titoloElement.textContent.trim() : 'Libro sconosciuto';
            
            openModalRestituzione(libroId, titolo);
        }

        function richiamaPrestito(nomeFratello, telefono, titoloLibro) {
            const messaggio = `Ciao ${nomeFratello}, ti ricordiamo la restituzione del libro "${titoloLibro}". Grazie!`;
            
            if (confirm(`Vuoi chiamare ${nomeFratello} al numero ${telefono}?\n\nMessaggio suggerito:\n"${messaggio}"`)) {
                // Crea link tel: per aprire il dialer
                window.location.href = `tel:${telefono}`;
                
                // Copia il messaggio negli appunti
                navigator.clipboard.writeText(messaggio).then(() => {
                    alert('Messaggio copiato negli appunti!');
                }).catch(() => {
                    alert(`Numero: ${telefono}\nMessaggio: ${messaggio}`);
                });
            }
        }

        function prolongaPrestito(libroId) {
            const giorni = prompt('Per quanti giorni vuoi prolungare il prestito?', '15');
            if (giorni && !isNaN(giorni) && giorni > 0) {
                fetch('../../api/prestiti.php', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ 
                        action: 'prolunga',
                        libro_id: libroId,
                        giorni: parseInt(giorni)
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Prestito prolungato di ${giorni} giorni!`);
                        location.reload();
                    } else {
                        alert('Errore: ' + (data.message || 'Operazione fallita'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Errore di connessione');
                });
            }
        }

        // Azioni rapide
        function notificaScadenze() {
            if (confirm('Vuoi inviare notifiche a tutti i fratelli con prestiti in scadenza?')) {
                fetch('../../api/notifiche.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ action: 'notifica_scadenze' })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(`Notifiche inviate a ${data.count || 0} fratelli!`);
                    } else {
                        alert('Errore nell\'invio delle notifiche');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Funzionalità in sviluppo');
                });
            }
        }

        function esportaPrestiti() {
            window.open('../../api/export.php?type=prestiti', '_blank');
        }

        function generaReportPrestiti() {
            window.open('../../api/report.php?type=prestiti', '_blank');
        }

        // Close modals on outside click
        document.getElementById('modalNuovoPrestito').addEventListener('click', function(e) {
            if (e.target === this) closeModalPrestito();
        });

        document.getElementById('modalRestituzione').addEventListener('click', function(e) {
            if (e.target === this) closeModalRestituzione();
        });

        // Close modals on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                if (!document.getElementById('modalNuovoPrestito').classList.contains('hidden')) {
                    closeModalPrestito();
                }
                if (!document.getElementById('modalRestituzione').classList.contains('hidden')) {
                    closeModalRestituzione();
                }
            }
        });
    </script>
</body>
</html>