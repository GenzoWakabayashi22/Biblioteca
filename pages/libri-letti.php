<?php
/**
 * Libri Letti - Sistema Biblioteca
 * R∴ L∴ Kilwinning
 */

session_start();

// Include file necessari
require_once '../config/database.php';

// Verifica autenticazione
if (!isset($_SESSION['fratello_id'])) {
    header('Location: ../index.php');
    exit;
}

// Verifica timeout sessione (24 ore)
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > 86400)) {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

// Aggiorna timestamp ultima attività
$_SESSION['last_activity'] = time();

// Dati utente corrente
$user = [
    'id' => $_SESSION['fratello_id'],
    'nome' => $_SESSION['fratello_nome'],
    'grado' => $_SESSION['fratello_grado'],
    'cariche' => $_SESSION['fratello_cariche'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
];

// Parametri per paginazione e filtri
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Filtri
$search = $_GET['search'] ?? '';
$categoria_filter = $_GET['categoria'] ?? '';
$anno_filter = $_GET['anno'] ?? '';

// Query per recuperare tutti i libri letti dall'utente
$where_conditions = ['ll.fratello_id = ?'];
$params = [$user['id']];
$types = 'i';

if (!empty($search)) {
    $where_conditions[] = '(l.titolo LIKE ? OR l.autore LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $types .= 'ss';
}

if (!empty($categoria_filter)) {
    $where_conditions[] = 'l.categoria_id = ?';
    $params[] = $categoria_filter;
    $types .= 'i';
}

if (!empty($anno_filter)) {
    $where_conditions[] = 'YEAR(ll.data_lettura) = ?';
    $params[] = $anno_filter;
    $types .= 'i';
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Query principale per i libri letti
$libri_letti_query = "
    SELECT ll.*, l.id as libro_id, l.titolo, l.autore, l.descrizione,
           c.nome as categoria_nome, c.colore as categoria_colore,
           (SELECT AVG(r.valutazione) FROM recensioni_libri r WHERE r.libro_id = l.id) as voto_medio,
           (SELECT COUNT(*) FROM recensioni_libri r WHERE r.libro_id = l.id) as num_recensioni
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    {$where_clause}
    ORDER BY ll.data_lettura DESC
    LIMIT ? OFFSET ?
";

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$libri_letti = getAllResults($libri_letti_query, $params, $types);

// Count per paginazione
$count_query = "
    SELECT COUNT(*)
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    {$where_clause}
";

$count_params = array_slice($params, 0, -2);
$count_types = substr($types, 0, -2);
$total_libri = getSingleResult($count_query, $count_params, $count_types)['COUNT(*)'] ?? 0;
$total_pages = ceil($total_libri / $per_page);

// Statistiche dei libri letti
$stats_query = "
    SELECT 
        COUNT(*) as totale_libri_letti,
        COUNT(DISTINCT l.categoria_id) as categorie_diverse,
        AVG(DATEDIFF(CURDATE(), ll.data_lettura)) as giorni_media_lettura,
        YEAR(ll.data_lettura) as anno_lettura,
        COUNT(*) as libri_per_anno
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    WHERE ll.fratello_id = ?
    GROUP BY YEAR(ll.data_lettura)
    ORDER BY anno_lettura DESC
";

$statistiche = getAllResults($stats_query, [$user['id']], 'i');

$totale_generale = array_sum(array_column($statistiche, 'libri_per_anno'));
$categorie_diverse = getSingleResult("
    SELECT COUNT(DISTINCT l.categoria_id) as count
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    WHERE ll.fratello_id = ?
", [$user['id']], 'i')['count'] ?? 0;

// Recupera tutte le categorie per il filtro
$categorie = getAllResults("
    SELECT DISTINCT c.id, c.nome, c.colore
    FROM categorie_libri c
    INNER JOIN libri l ON c.id = l.categoria_id
    INNER JOIN libri_letti ll ON l.id = ll.libro_id
    WHERE ll.fratello_id = ?
    ORDER BY c.nome
", [$user['id']], 'i');

// Anni disponibili per il filtro
$anni = getAllResults("
    SELECT DISTINCT YEAR(ll.data_lettura) as anno
    FROM libri_letti ll
    WHERE ll.fratello_id = ?
    ORDER BY anno DESC
", [$user['id']], 'i');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>I Miei Libri Letti - Biblioteca R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: system-ui, -apple-system, sans-serif;
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- Header -->
    <div class="bg-white/10 backdrop-blur-md border-b border-white/20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center py-4">
                <!-- Logo e titolo -->
                <div class="flex items-center space-x-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <span class="text-2xl"><?php echo strtoupper(substr($user['nome'], 0, 1)); ?></span>
                    </div>
                    <div>
                        <h1 class="text-white text-xl font-bold"><?php echo htmlspecialchars($user['nome']); ?></h1>
                        <p class="text-white/80 text-sm"><?php echo htmlspecialchars($user['grado']); ?> • Loggia Kilwinning</p>
                        <?php if ($user['cariche']): ?>
                            <span class="inline-block bg-purple-500 text-white text-xs px-2 py-1 rounded-full mt-1">
                                <?php echo htmlspecialchars($user['cariche']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Menu navigazione -->
                <div class="hidden md:flex items-center space-x-4">
                    <nav class="flex space-x-6">
                        <a href="dashboard.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            📚 Biblioteca
                        </a>
                        <a href="catalogo.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            📖 Catalogo
                        </a>
                        <span class="text-white font-medium bg-white/20 px-4 py-2 rounded-lg">
                            ✅ Libri Letti
                        </span>
                        <?php if ($user['is_admin']): ?>
                            <a href="admin/gestione-libri.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                                ⚙️ Gestione
                            </a>
                        <?php endif; ?>
                    </nav>
                </div>

                <!-- Pulsante logout -->
                <div class="flex items-center space-x-4">
                    <a href="../api/logout.php" class="bg-red-500/80 hover:bg-red-500 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        🚪 Esci
                    </a>
                </div>
            </div>
        </div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <!-- Breadcrumb -->
        <nav class="mb-6">
            <ol class="flex items-center space-x-2 text-sm">
                <li><a href="dashboard.php" class="text-white/80 hover:text-white">Dashboard</a></li>
                <li class="text-white/60">/</li>
                <li class="text-white font-medium">I Miei Libri Letti</li>
            </ol>
        </nav>

        <!-- Statistiche -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">📚</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $totale_generale; ?></h3>
                <p class="text-gray-600">Libri Letti</p>
                <p class="text-sm text-gray-500 mt-1">Totale completati</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">🏷️</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $categorie_diverse; ?></h3>
                <p class="text-gray-600">Categorie</p>
                <p class="text-sm text-gray-500 mt-1">Argomenti diversi</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">📅</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo count($statistiche); ?></h3>
                <p class="text-gray-600">Anni Attivi</p>
                <p class="text-sm text-gray-500 mt-1">Periodo di lettura</p>
            </div>

            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">⭐</div>
                <h3 class="text-3xl font-bold text-gray-800">
                    <?php 
                    $libri_con_voto = array_filter($libri_letti, function($libro) {
                        return $libro['voto_medio'] > 0;
                    });
                    echo count($libri_con_voto);
                    ?>
                </h3>
                <p class="text-gray-600">Con Recensioni</p>
                <p class="text-sm text-gray-500 mt-1">Libri valutati</p>
            </div>
        </div>

        <!-- Filtri e Ricerca -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-xl font-bold text-gray-800 mb-4">🔍 Filtra i Tuoi Libri Letti</h2>
            <form method="GET" class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <!-- Ricerca -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Cerca Titolo/Autore</label>
                    <input type="text" 
                           name="search" 
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="Inserisci titolo o autore..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                </div>

                <!-- Filtro Categoria -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Categoria</label>
                    <select name="categoria" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tutte le categorie</option>
                        <?php foreach ($categorie as $categoria): ?>
                            <option value="<?php echo $categoria['id']; ?>" <?php echo $categoria['id'] == $categoria_filter ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($categoria['nome']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Filtro Anno -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Anno di Lettura</label>
                    <select name="anno" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="">Tutti gli anni</option>
                        <?php foreach ($anni as $anno): ?>
                            <option value="<?php echo $anno['anno']; ?>" <?php echo $anno['anno'] == $anno_filter ? 'selected' : ''; ?>>
                                <?php echo $anno['anno']; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Pulsanti -->
                <div class="flex items-end space-x-2">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                        🔍 Cerca
                    </button>
                    <a href="libri-letti.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Lista Libri Letti -->
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">
                    📚 I Tuoi Libri Letti
                    <?php if ($total_libri > 0): ?>
                        <span class="text-sm font-normal text-gray-600">
                            (<?php echo $total_libri; ?> <?php echo $total_libri == 1 ? 'libro' : 'libri'; ?>)
                        </span>
                    <?php endif; ?>
                </h2>
                
                <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm">
                    ← Torna alla Dashboard
                </a>
            </div>

            <?php if (empty($libri_letti)): ?>
                <div class="text-center py-12">
                    <div class="text-gray-400 text-6xl mb-4">📖</div>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Nessun libro letto trovato</h3>
                    <?php if (!empty($search) || !empty($categoria_filter) || !empty($anno_filter)): ?>
                        <p class="text-gray-500 mb-4">Prova a modificare i filtri di ricerca</p>
                        <a href="libri-letti.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                            🔄 Rimuovi Filtri
                        </a>
                    <?php else: ?>
                        <p class="text-gray-500 mb-4">Non hai ancora segnato nessun libro come letto</p>
                        <a href="catalogo.php" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg">
                            📖 Sfoglia il Catalogo
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($libri_letti as $libro): ?>
                        <div class="border border-gray-200 rounded-lg overflow-hidden hover:shadow-lg transition-shadow">
                            <!-- Header del libro -->
                            <div class="p-4 border-b border-gray-100">
                                <h3 class="font-semibold text-gray-800 hover:text-indigo-600 text-lg leading-tight mb-2">
                                    <a href="libro-dettaglio.php?id=<?php echo $libro['libro_id']; ?>">
                                        <?php echo htmlspecialchars($libro['titolo']); ?>
                                    </a>
                                </h3>
                                <p class="text-gray-600 mb-2"><?php echo htmlspecialchars($libro['autore']); ?></p>
                                
                                <!-- Categoria e Data -->
                                <div class="flex flex-wrap items-center gap-2 mb-3">
                                    <?php if ($libro['categoria_nome']): ?>
                                        <span class="px-2 py-1 text-xs rounded-full text-white" 
                                              style="background-color: <?php echo htmlspecialchars($libro['categoria_colore'] ?? '#8B4513'); ?>">
                                            <?php echo htmlspecialchars($libro['categoria_nome']); ?>
                                        </span>
                                    <?php endif; ?>
                                    <span class="text-green-600 text-xs font-medium">
                                        ✅ Letto il <?php echo date('d/m/Y', strtotime($libro['data_lettura'])); ?>
                                    </span>
                                </div>

                                <!-- Valutazione media -->
                                <?php if ($libro['voto_medio'] > 0): ?>
                                    <div class="flex items-center space-x-2 mb-2">
                                        <div class="flex">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="text-sm <?php echo $i <= round($libro['voto_medio']) ? 'text-yellow-400' : 'text-gray-300'; ?>">⭐</span>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="text-sm text-gray-600">
                                            <?php echo number_format($libro['voto_medio'], 1); ?>/5
                                            (<?php echo $libro['num_recensioni']; ?> voti)
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- Note personali -->
                            <?php if ($libro['note']): ?>
                                <div class="p-4 bg-gray-50">
                                    <h4 class="text-sm font-medium text-gray-700 mb-2">📝 Le tue note:</h4>
                                    <p class="text-sm text-gray-600 line-clamp-3">
                                        "<?php echo htmlspecialchars($libro['note']); ?>"
                                    </p>
                                </div>
                            <?php endif; ?>

                            <!-- Footer con azioni -->
                            <div class="p-4 bg-white border-t border-gray-100">
                                <div class="flex justify-between items-center">
                                    <a href="libro-dettaglio.php?id=<?php echo $libro['libro_id']; ?>" 
                                       class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                                        📖 Dettagli Libro
                                    </a>
                                    
                                    <?php if (!$libro['note']): ?>
                                        <button onclick="aggiungiNote(<?php echo $libro['libro_id']; ?>)" 
                                                class="text-gray-600 hover:text-gray-800 text-sm">
                                            ✏️ Aggiungi Note
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Paginazione -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-8 flex justify-center">
                        <nav class="flex items-center space-x-1">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo $categoria_filter; ?>&anno=<?php echo $anno_filter; ?>" 
                                   class="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                    ← Precedente
                                </a>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo $categoria_filter; ?>&anno=<?php echo $anno_filter; ?>" 
                                   class="px-3 py-2 <?php echo $i == $page ? 'bg-indigo-600 text-white' : 'bg-gray-200 text-gray-700 hover:bg-gray-300'; ?> rounded-lg">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <a href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&categoria=<?php echo $categoria_filter; ?>&anno=<?php echo $anno_filter; ?>" 
                                   class="px-3 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300">
                                    Successiva →
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Statistiche per Anno -->
        <?php if (!empty($statistiche)): ?>
            <div class="mt-8 bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4">📊 Statistiche per Anno</h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <?php foreach ($statistiche as $stat): ?>
                        <div class="bg-gradient-to-r from-indigo-50 to-blue-50 rounded-lg p-4 border border-indigo-200">
                            <div class="flex justify-between items-center">
                                <div>
                                    <h3 class="text-lg font-semibold text-gray-800"><?php echo $stat['anno_lettura']; ?></h3>
                                    <p class="text-sm text-gray-600">
                                        <?php echo $stat['libri_per_anno']; ?> libri letti
                                    </p>
                                </div>
                                <div class="text-2xl">📅</div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Script per note -->
    <script>
        function aggiungiNote(libroId) {
            // Qui potresti implementare un modal per aggiungere note
            alert('Funzionalità in sviluppo: aggiungi note al libro ' + libroId);
        }
    </script>
</body>
</html>