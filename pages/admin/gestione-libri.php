<?php
// GESTIONE LIBRI - VERSIONE SENZA PLACEHOLDER IN LIMIT
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Avvia sessione
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

// Connessione database
try {
    $pdo = new PDO("mysql:host=localhost;dbname=jmvvznbb_tornate_db;charset=utf8mb4", 
                   'jmvvznbb_tornate_user', 'Puntorosso22');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Errore connessione database: " . $e->getMessage());
}

// Simula sessione admin per debug
if (!isset($_SESSION['fratello_id'])) {
    $_SESSION['fratello_id'] = 16;
    $_SESSION['fratello_nome'] = 'Paolo Giulio Gazzano (Debug)';
    $debug_mode = true;
} else {
    $debug_mode = false;
}

// Gestione azioni POST
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
if ($action === 'nuovo_libro') {
    $titolo = trim($_POST['titolo']);
    $autore = trim($_POST['autore']);
    $categoria_id = (int)$_POST['categoria_id'];
    $isbn = trim($_POST['isbn'] ?? '');
    $anno_pubblicazione = $_POST['anno_pubblicazione'] ? (int)$_POST['anno_pubblicazione'] : null;
    $editore = trim($_POST['editore'] ?? '');
    $pagine = $_POST['numero_pagine'] ? (int)$_POST['numero_pagine'] : null; // NOTA: campo form = numero_pagine, colonna DB = pagine
    $descrizione = trim($_POST['descrizione'] ?? '');
    $grado_minimo = $_POST['grado_minimo'] ?? 'pubblico';
    
    try {
        $stmt = $pdo->prepare("
            INSERT INTO libri (titolo, autore, categoria_id, isbn, anno_pubblicazione, 
                               editore, pagine, descrizione, grado_minimo, stato, created_at) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'disponibile', NOW())
        ");
        
        $stmt->execute([$titolo, $autore, $categoria_id, $isbn, $anno_pubblicazione, 
                       $editore, $pagine, $descrizione, $grado_minimo]);
        
        $message = 'Libro aggiunto con successo!';
        $message_type = 'success';
        
        header('Location: gestione-libri.php?success=1');
        exit;
    } catch (PDOException $e) {
        $message = 'Errore nell\'aggiunta del libro: ' . $e->getMessage();
        $message_type = 'error';
    }
}
}

// Messaggio da redirect
if (isset($_GET['success'])) {
    $message = 'Libro aggiunto con successo!';
    $message_type = 'success';
}

// Gestione filtri e paginazione
$search = trim($_GET['search'] ?? '');
$filtro_categoria = $_GET['categoria'] ?? '';
$filtro_stato = $_GET['stato'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

// Sanitizzazione valori per LIMIT (sicurezza)
$offset = (int)$offset;
$per_page = (int)$per_page;

// Costruzione query con filtri
$where_conditions = ["1=1"];
$params = [];

if ($search) {
    $where_conditions[] = "(l.titolo LIKE ? OR l.autore LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
}

if ($filtro_categoria) {
    $where_conditions[] = "l.categoria_id = ?";
    $params[] = $filtro_categoria;
}

if ($filtro_stato) {
    $where_conditions[] = "l.stato = ?";
    $params[] = $filtro_stato;
}

$where_clause = implode(" AND ", $where_conditions);

// Query libri SENZA PLACEHOLDER in LIMIT (valori diretti)
$query_libri = "
    SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
           f.nome as prestato_a_nome
    FROM libri l
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
    WHERE $where_clause
    ORDER BY l.created_at DESC
    LIMIT $offset, $per_page
";

$stmt = $pdo->prepare($query_libri);
$stmt->execute($params); // SOLO i parametri WHERE, non LIMIT
$libri = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Count totale per paginazione
$count_query = "SELECT COUNT(*) FROM libri l WHERE $where_clause";
$count_stmt = $pdo->prepare($count_query);
$count_stmt->execute($params);
$total_libri = $count_stmt->fetchColumn();
$total_pages = ceil($total_libri / $per_page);

// Dati per filtri
$categorie = $pdo->query("SELECT MIN(id) as id, nome FROM categorie_libri WHERE attiva = 1 GROUP BY nome ORDER BY nome")->fetchAll(PDO::FETCH_ASSOC);

// Statistiche admin
$stats = [
    'totale_libri' => $pdo->query("SELECT COUNT(*) FROM libri")->fetchColumn(),
    'disponibili' => $pdo->query("SELECT COUNT(*) FROM libri WHERE stato = 'disponibile'")->fetchColumn(),
    'prestati' => $pdo->query("SELECT COUNT(*) FROM libri WHERE stato = 'prestato'")->fetchColumn(),
    'prenotati' => $pdo->query("SELECT COUNT(*) FROM libri WHERE stato = 'prenotato'")->fetchColumn()
];
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Libri - Admin | R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
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
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
    </style>
</head>

<body class="text-gray-800">
    <?php if ($debug_mode): ?>
    <!-- Banner debug -->
    <div class="bg-green-500 text-white px-4 py-2 text-center font-bold">
        ✅ MODALITÀ COMPATIBILITÀ - Query LIMIT senza placeholder
    </div>
    <?php endif; ?>

    <!-- Header Admin -->
    <div class="bg-white/10 backdrop-blur-md border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
               <div class="flex items-center space-x-8">
    <div class="flex items-center">
        <h1 class="text-2xl font-bold text-white">
            🏛️ R∴ L∴ Kilwinning
        </h1>
        <span class="ml-4 px-3 py-1 bg-yellow-500/20 text-yellow-100 rounded-full text-sm font-medium">
            ADMIN
        </span>
    </div>
    
    <nav class="hidden md:flex items-center space-x-4">
        <a href="../dashboard.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
            📚 Dashboard
        </a>
        <span class="px-3 py-2 rounded-md text-sm font-medium bg-primary text-white">
            <i class="fas fa-cogs mr-2"></i>Gestione Libri
        </span>
        <a href="gestione-categorie.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
            🏷️ Categorie
        </a>
    </nav>
</div>

                      
                 
                </div>
                <div class="flex items-center space-x-4">
                    <span class="text-sm text-white/80">
                        <i class="fas fa-user-shield mr-1"></i><?php echo htmlspecialchars($_SESSION['fratello_nome']); ?>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb -->
        <nav class="mb-6">
            <ol class="flex items-center space-x-2 text-sm text-white/80">
                <li><a href="../dashboard.php" class="hover:text-white">Dashboard</a></li>
                <li class="text-white/60">/</li>
                <li><a href="#" class="hover:text-white">Admin</a></li>
                <li class="text-white/60">/</li>
                <li class="text-white font-medium">Gestione Libri</li>
            </ol>
        </nav>

        <!-- Messaggi -->
        <?php if ($message): ?>
            <div class="mb-6 p-4 rounded-lg <?php echo $message_type === 'success' ? 'bg-green-100 border border-green-400 text-green-700' : 'bg-red-100 border border-red-400 text-red-700'; ?>">
                <i class="fas <?php echo $message_type === 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Statistiche Admin -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-book text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Totale Libri</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['totale_libri']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-check text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Disponibili</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['disponibili']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-hand-holding text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">In Prestito</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['prestati']; ?></p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6">
                <div class="flex items-center">
                    <div class="flex-shrink-0">
                        <div class="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                            <i class="fas fa-bookmark text-white text-sm"></i>
                        </div>
                    </div>
                    <div class="ml-4">
                        <p class="text-sm font-medium text-gray-600">Prenotati</p>
                        <p class="text-2xl font-bold text-gray-900"><?php echo $stats['prenotati']; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Azioni e filtri -->
        <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6 mb-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                <!-- Pulsanti Azioni -->
<div class="flex gap-3">
    <button onclick="toggleNuovoLibro()" class="bg-primary hover:bg-primary/90 text-white px-6 py-3 rounded-lg font-medium">
        <i class="fas fa-plus mr-2"></i>Nuovo Libro
    </button>
    <a href="gestione-categorie.php" class="bg-purple-500 hover:bg-purple-600 text-white px-6 py-3 rounded-lg font-medium">
        🏷️ Gestione Categorie
    </a>
</div>

                <!-- Filtri -->
                <div class="flex flex-col sm:flex-row gap-4">
                    <form method="GET" class="flex flex-col sm:flex-row gap-4">
                        <div>
                            <select name="categoria" class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                                <option value="">Tutte le categorie</option>
                                <?php foreach ($categorie as $categoria): ?>
                                    <option value="<?php echo $categoria['id']; ?>" <?php echo $filtro_categoria == $categoria['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($categoria['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <select name="stato" class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                                <option value="">Tutti gli stati</option>
                                <option value="disponibile" <?php echo $filtro_stato == 'disponibile' ? 'selected' : ''; ?>>Disponibile</option>
                                <option value="prestato" <?php echo $filtro_stato == 'prestato' ? 'selected' : ''; ?>>In prestito</option>
                                <option value="prenotato" <?php echo $filtro_stato == 'prenotato' ? 'selected' : ''; ?>>Prenotato</option>
                            </select>
                        </div>
                        
                        <div>
                            <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                                   placeholder="Cerca titolo, autore..." 
                                   class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                        </div>
                        
                        <button type="submit" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg">
                            <i class="fas fa-search"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Form Nuovo Libro -->
        <div id="nuovoLibroForm" class="hidden bg-white/95 backdrop-blur-sm rounded-xl shadow-lg p-6 mb-8">
            <h3 class="text-lg font-bold mb-4">
                <i class="fas fa-plus-circle text-primary mr-2"></i>Nuovo Libro
            </h3>
            <form method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <input type="hidden" name="action" value="nuovo_libro">
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Titolo *</label>
                    <input type="text" name="titolo" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Autore *</label>
                    <input type="text" name="autore" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Categoria *</label>
                    <select name="categoria_id" required class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                        <option value="">Seleziona categoria</option>
                        <?php foreach ($categorie as $categoria): ?>
                            <option value="<?php echo $categoria['id']; ?>">
                                <?php echo htmlspecialchars($categoria['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">ISBN</label>
                    <input type="text" name="isbn" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Anno Pubblicazione</label>
                    <input type="number" name="anno_pubblicazione" min="1500" max="2025" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Editore</label>
                    <input type="text" name="editore" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Numero Pagine</label>
                    <input type="number" name="numero_pagine" min="1" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Grado Minimo</label>
                   <select name="grado_minimo" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary">
    <option value="pubblico">🌍 Pubblico</option>
    <option value="Apprendista">🔺 Apprendista</option>
    <option value="Compagno">🔷 Compagno</option>
    <option value="Maestro">🔶 Maestro</option>
</select>
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Descrizione</label>
                    <textarea name="descrizione" rows="3" class="w-full border border-gray-300 rounded-lg px-3 py-2 focus:ring-2 focus:ring-primary"></textarea>
                </div>
                
                <div class="md:col-span-2 flex gap-4">
                    <button type="submit" class="bg-primary hover:bg-primary/90 text-white px-6 py-2 rounded-lg">
                        <i class="fas fa-save mr-2"></i>Salva Libro
                    </button>
                    <button type="button" onclick="toggleNuovoLibro()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-2 rounded-lg">
                        Annulla
                    </button>
                </div>
            </form>
        </div>

        <!-- Lista Libri -->
        <div class="bg-white/95 backdrop-blur-sm rounded-xl shadow-lg">
            <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">
                    📚 Libri (<?php echo $total_libri; ?> totali)
                </h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Libro</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Categoria</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stato</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prestato a</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Azioni</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php if (empty($libri)): ?>
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-gray-500">
                                    <i class="fas fa-search text-4xl mb-4"></i>
                                    <p>Nessun libro trovato con i filtri attuali</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($libri as $libro): ?>
                                <tr class="hover:bg-gray-50 transition-colors">
                                    <td class="px-6 py-4">
                                        <div>
                                            <div class="text-sm font-medium text-gray-900">
                                                <?php echo htmlspecialchars($libro['titolo']); ?>
                                            </div>
                                            <div class="text-sm text-gray-500">
                                                <?php echo htmlspecialchars($libro['autore']); ?>
                                            </div>
                                            <?php if ($libro['isbn']): ?>
                                                <div class="text-xs text-gray-400">
                                                    ISBN: <?php echo htmlspecialchars($libro['isbn']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php if ($libro['categoria_nome']): ?>
                                            <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full" 
                                                  style="background-color: <?php echo $libro['categoria_colore']; ?>20; color: <?php echo $libro['categoria_colore']; ?>">
                                                <?php echo htmlspecialchars($libro['categoria_nome']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-gray-400 text-sm">Senza categoria</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <?php
                                        $stato_colors = [
                                            'disponibile' => 'bg-green-100 text-green-800',
                                            'prestato' => 'bg-orange-100 text-orange-800',
                                            'prenotato' => 'bg-purple-100 text-purple-800'
                                        ];
                                        $color_class = $stato_colors[$libro['stato']] ?? 'bg-gray-100 text-gray-800';
                                        ?>
                                        <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full <?php echo $color_class; ?>">
                                            <?php echo ucfirst($libro['stato']); ?>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?php echo $libro['prestato_a_nome'] ? htmlspecialchars($libro['prestato_a_nome']) : '-'; ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <div class="flex space-x-3">
                                            <a href="../libro-dettaglio.php?id=<?php echo $libro['id']; ?>" 
                                               class="text-blue-600 hover:text-blue-900" title="Visualizza">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <button onclick="modificaLibro(<?php echo $libro['id']; ?>)" 
                                                    class="text-indigo-600 hover:text-indigo-900" title="Modifica">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button onclick="eliminaLibro(<?php echo $libro['id']; ?>)" 
                                                    class="text-red-600 hover:text-red-900" title="Elimina">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Paginazione -->
            <?php if ($total_pages > 1): ?>
                <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
                    <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
                        <div>
                            <p class="text-sm text-gray-700">
                                Mostrando
                                <span class="font-medium"><?php echo $offset + 1; ?></span>
                                a
                                <span class="font-medium"><?php echo min($offset + $per_page, $total_libri); ?></span>
                                di
                                <span class="font-medium"><?php echo $total_libri; ?></span>
                                risultati
                            </p>
                        </div>
                        <div>
                            <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                                <?php if ($page > 1): ?>
                                    <a href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>" 
                                       class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <i class="fas fa-chevron-left"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php 
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++): 
                                ?>
                                    <a href="?page=<?php echo $i; ?>&<?php echo http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>" 
                                       class="relative inline-flex items-center px-4 py-2 border text-sm font-medium 
                                              <?php echo $i == $page ? 'bg-primary text-white border-primary' : 'bg-white text-gray-500 border-gray-300 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <?php if ($page < $total_pages): ?>
                                    <a href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query(array_filter($_GET, function($key) { return $key !== 'page'; }, ARRAY_FILTER_USE_KEY)); ?>" 
                                       class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                                        <i class="fas fa-chevron-right"></i>
                                    </a>
                                <?php endif; ?>
                            </nav>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Info risultati -->
        <div class="mt-6 text-center text-white/80">
            <p class="text-sm">
                Visualizzando <?php echo count($libri); ?> libri di <?php echo $total_libri; ?> totali
                <?php if ($search || $filtro_categoria || $filtro_stato): ?>
                    | <a href="gestione-libri-nolimit.php" class="text-yellow-300 hover:text-yellow-100">Rimuovi filtri</a>
                <?php endif; ?>
            </p>
        </div>

        <!-- Debug info -->
        <?php if ($debug_mode): ?>
        <div class="mt-8 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg">
            <h3 class="font-bold">✅ Modalità Compatibilità MariaDB</h3>
            <p><strong>Query SQL:</strong> <code>LIMIT <?php echo $offset; ?>, <?php echo $per_page; ?></code> (valori diretti)</p>
            <p><strong>Risultati:</strong> <?php echo count($libri); ?> libri trovati su <?php echo $total_libri; ?> totali</p>
            <p><strong>Paginazione:</strong> Pagina <?php echo $page; ?> di <?php echo $total_pages; ?></p>
            <p><strong>Status:</strong> Nessun placeholder in LIMIT, massima compatibilità</p>
        </div>
        <?php endif; ?>
    </div>

    <script>
        function toggleNuovoLibro() {
            const form = document.getElementById('nuovoLibroForm');
            form.classList.toggle('hidden');
            
            if (!form.classList.contains('hidden')) {
                form.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function modificaLibro(id) {
    window.location.href = 'modifica-libro.php?id=' + id;
}

        function eliminaLibro(id) {
            if (confirm('Sei sicuro di voler eliminare questo libro?\n\nQuesta azione è irreversibile.')) {
                alert('Funzione eliminazione libro in sviluppo per libro ID: ' + id);
            }
        }

        // Auto-submit filtri
        document.querySelectorAll('select[name="categoria"], select[name="stato"]').forEach(function(select) {
            select.addEventListener('change', function() {
                this.form.submit();
            });
        });

        // Conferma nuovo libro
        document.querySelector('form[method="POST"]')?.addEventListener('submit', function(e) {
            const titolo = this.querySelector('input[name="titolo"]').value;
            const autore = this.querySelector('input[name="autore"]').value;
            
            if (!confirm(`Confermi l'aggiunta del libro?\n\nTitolo: ${titolo}\nAutore: ${autore}`)) {
                e.preventDefault();
            }
        });

        // Notifica successo auto-hide
        <?php if ($message_type === 'success'): ?>
        setTimeout(function() {
            const successMsg = document.querySelector('.bg-green-100');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.remove(), 500);
            }
        }, 3000);
        <?php endif; ?>

        // Log successo
        console.log('🎉 Gestione Libri COMPATIBILE caricato!');
        console.log('📊 Query SQL senza placeholder in LIMIT');
        console.log('✅ Totale libri visualizzati:', <?php echo count($libri); ?>);
    </script>
</body>
</html>