<?php
/**
 * Tutte le Recensioni - Sistema Biblioteca
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
    'is_admin' => $_SESSION['is_admin'] ?? false
];

// Parametri di ricerca e filtri
$search = $_GET['search'] ?? '';
$filtro_voto = $_GET['voto'] ?? '';
$filtro_fratello = $_GET['fratello'] ?? '';
$ordinamento = $_GET['ordine'] ?? 'recenti';
$per_page = 12;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Costruisci la query con filtri
$where_clauses = [];
$params = [];
$types = '';

if ($search) {
    $where_clauses[] = "(l.titolo LIKE ? OR l.autore LIKE ? OR r.titolo LIKE ? OR r.contenuto LIKE ?)";
    $search_term = "%{$search}%";
    $params = array_merge($params, [$search_term, $search_term, $search_term, $search_term]);
    $types .= 'ssss';
}

if ($filtro_voto) {
    $where_clauses[] = "r.valutazione = ?";
    $params[] = (int)$filtro_voto;
    $types .= 'i';
}

if ($filtro_fratello) {
    $where_clauses[] = "r.fratello_id = ?";
    $params[] = (int)$filtro_fratello;
    $types .= 'i';
}

$where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// Determina ordinamento
$order_sql = match($ordinamento) {
    'recenti' => 'ORDER BY r.created_at DESC',
    'antiche' => 'ORDER BY r.created_at ASC',
    'voto_alto' => 'ORDER BY r.valutazione DESC, r.created_at DESC',
    'voto_basso' => 'ORDER BY r.valutazione ASC, r.created_at DESC',
    'alfabetico' => 'ORDER BY l.titolo ASC',
    default => 'ORDER BY r.created_at DESC'
};

// Query principale per le recensioni
$recensioni_query = "
    SELECT r.*, l.id as libro_id, l.titolo, l.autore, 
           f.nome as fratello_nome, f.grado as fratello_grado,
           c.nome as categoria_nome, c.colore as categoria_colore
    FROM recensioni_libri r
    INNER JOIN libri l ON r.libro_id = l.id
    INNER JOIN fratelli f ON r.fratello_id = f.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    {$where_sql}
    {$order_sql}
    LIMIT {$per_page} OFFSET {$offset}
";

$recensioni = getAllResults($recensioni_query, $params, $types);

// Query per il conteggio totale
$count_query = "
    SELECT COUNT(*) as total
    FROM recensioni_libri r
    INNER JOIN libri l ON r.libro_id = l.id
    INNER JOIN fratelli f ON r.fratello_id = f.id
    {$where_sql}
";

$total_result = getSingleResult($count_query, $params, $types);
$total_recensioni = $total_result['total'];
$total_pages = ceil($total_recensioni / $per_page);

// Recupera fratelli per il filtro
$fratelli = getAllResults("
    SELECT DISTINCT f.id, f.nome, f.grado, COUNT(r.id) as num_recensioni
    FROM fratelli f
    INNER JOIN recensioni_libri r ON f.id = r.fratello_id
    GROUP BY f.id, f.nome, f.grado
    ORDER BY f.nome ASC
");

// Statistiche generali
$stats = getSingleResult("
    SELECT 
        COUNT(*) as totale_recensioni,
        AVG(valutazione) as voto_medio,
        COUNT(DISTINCT fratello_id) as fratelli_attivi,
        COUNT(DISTINCT libro_id) as libri_recensiti
    FROM recensioni_libri
");

// Funzione per mostrare le stelle
function mostraStelle($voto, $dimensione = 'text-base') {
    if ($voto === 0 || $voto === 0.0) return '';
    
    $output = '<div class="flex items-center">';
    
    // Stelle piene
    $stelle_piene = floor($voto);
    for ($i = 1; $i <= $stelle_piene; $i++) {
        $output .= "<span class=\"{$dimensione} text-yellow-400\">⭐</span>";
    }
    
    // Mezza stella se decimale >= 0.5
    $decimale = $voto - $stelle_piene;
    if ($decimale >= 0.5 && $stelle_piene < 5) {
        $output .= "<span class=\"{$dimensione} text-yellow-400\">⭐</span>";
        $stelle_piene++;
    }
    
    // Stelle vuote per completare le 5
    $stelle_vuote = 5 - $stelle_piene;
    for ($i = 1; $i <= $stelle_vuote; $i++) {
        $output .= "<span class=\"{$dimensione} text-gray-300\">☆</span>";
    }
    
    $output .= '</div>';
    return $output;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tutte le Recensioni - Biblioteca R∴ L∴ Kilwinning</title>
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
                        <a href="tutte-recensioni.php" class="text-white font-medium bg-white/20 px-4 py-2 rounded-lg">
                            💬 Recensioni
                        </a>
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
        <nav class="text-white/80 text-sm mb-6">
            <a href="dashboard.php" class="hover:text-white">🏠 Dashboard</a>
            <span class="mx-2">→</span>
            <span class="text-white">💬 Tutte le Recensioni</span>
        </nav>

        <!-- Header pagina -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                <div>
                    <h1 class="text-3xl font-bold text-gray-800 mb-2">💬 Tutte le Recensioni</h1>
                    <p class="text-gray-600">Scopri cosa pensano i fratelli dei libri letti</p>
                </div>
                
                <!-- Statistiche veloci -->
                <div class="mt-4 md:mt-0 grid grid-cols-2 md:grid-cols-4 gap-4 text-center">
                    <div class="bg-blue-50 rounded-lg p-3">
                        <div class="text-2xl font-bold text-blue-600"><?php echo $stats['totale_recensioni']; ?></div>
                        <div class="text-xs text-blue-500">Recensioni</div>
                    </div>
                    <div class="bg-yellow-50 rounded-lg p-3">
                        <div class="text-2xl font-bold text-yellow-600"><?php echo number_format($stats['voto_medio'], 1); ?></div>
                        <div class="text-xs text-yellow-500">Voto Medio</div>
                    </div>
                    <div class="bg-green-50 rounded-lg p-3">
                        <div class="text-2xl font-bold text-green-600"><?php echo $stats['fratelli_attivi']; ?></div>
                        <div class="text-xs text-green-500">Fratelli Attivi</div>
                    </div>
                    <div class="bg-purple-50 rounded-lg p-3">
                        <div class="text-2xl font-bold text-purple-600"><?php echo $stats['libri_recensiti']; ?></div>
                        <div class="text-xs text-purple-500">Libri Recensiti</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtri e ricerca -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <h2 class="text-lg font-semibold text-gray-800 mb-4">🔍 Filtra e Cerca</h2>
            
            <form method="GET" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
                <!-- Ricerca testo -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Cerca</label>
                    <input type="text" name="search" value="<?php echo htmlspecialchars($search); ?>" 
                           placeholder="Libro, autore, recensione..."
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                </div>

                <!-- Filtro voto -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Voto</label>
                    <select name="voto" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Tutti i voti</option>
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                            <option value="<?php echo $i; ?>" <?php echo $filtro_voto == $i ? 'selected' : ''; ?>>
                                <?php echo $i; ?> <?php echo $i == 1 ? 'stella' : 'stelle'; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <!-- Filtro fratello -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Fratello</label>
                    <select name="fratello" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="">Tutti i fratelli</option>
                        <?php foreach ($fratelli as $fratello): ?>
                            <option value="<?php echo $fratello['id']; ?>" <?php echo $filtro_fratello == $fratello['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($fratello['nome']); ?> (<?php echo $fratello['num_recensioni']; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Ordinamento -->
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Ordinamento</label>
                    <select name="ordine" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500">
                        <option value="recenti" <?php echo $ordinamento == 'recenti' ? 'selected' : ''; ?>>Più recenti</option>
                        <option value="antiche" <?php echo $ordinamento == 'antiche' ? 'selected' : ''; ?>>Più antiche</option>
                        <option value="voto_alto" <?php echo $ordinamento == 'voto_alto' ? 'selected' : ''; ?>>Voto più alto</option>
                        <option value="voto_basso" <?php echo $ordinamento == 'voto_basso' ? 'selected' : ''; ?>>Voto più basso</option>
                        <option value="alfabetico" <?php echo $ordinamento == 'alfabetico' ? 'selected' : ''; ?>>Alfabetico</option>
                    </select>
                </div>

                <!-- Pulsanti -->
                <div class="flex space-x-2">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        🔍 Cerca
                    </button>
                    <a href="tutte-recensioni.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-medium">
                        🔄 Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Risultati -->
        <div class="bg-white rounded-xl shadow-lg p-6 mb-8">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-800">
                    📋 Risultati 
                    <span class="text-sm font-normal text-gray-500">
                        (<?php echo $total_recensioni; ?> recensioni trovate)
                    </span>
                </h2>
                
                <div class="text-sm text-gray-500">
                    Pagina <?php echo $page; ?> di <?php echo $total_pages; ?>
                </div>
            </div>

            <?php if (empty($recensioni)): ?>
                <div class="text-center py-12">
                    <div class="text-gray-400 text-6xl mb-4">💬</div>
                    <h3 class="text-xl font-semibold text-gray-600 mb-2">Nessuna recensione trovata</h3>
                    <p class="text-gray-500 mb-4">Prova a modificare i filtri di ricerca</p>
                    <a href="tutte-recensioni.php" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg">
                        🔄 Vedi tutte le recensioni
                    </a>
                </div>
            <?php else: ?>
                <!-- Griglia recensioni -->
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <?php foreach ($recensioni as $recensione): ?>
                        <div class="border border-gray-200 rounded-lg p-6 hover:shadow-lg transition-all hover:border-blue-300">
                            <!-- Header recensione -->
                            <div class="flex justify-between items-start mb-4">
                                <div class="flex-1 min-w-0">
                                    <h3 class="font-bold text-lg text-gray-800 hover:text-blue-600 truncate">
                                        <a href="libro-dettaglio.php?id=<?php echo $recensione['libro_id']; ?>">
                                            <?php echo htmlspecialchars($recensione['titolo']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-gray-600 text-sm truncate">
                                        di <?php echo htmlspecialchars($recensione['autore']); ?>
                                    </p>
                                    <?php if ($recensione['categoria_nome']): ?>
                                        <span class="inline-block px-2 py-1 text-xs rounded-full text-white mt-1" 
                                              style="background-color: <?php echo htmlspecialchars($recensione['categoria_colore'] ?? '#8B4513'); ?>">
                                            <?php echo htmlspecialchars($recensione['categoria_nome']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Stelle -->
                                <div class="ml-4">
                                    <?php echo mostraStelle($recensione['valutazione']); ?>
                                </div>
                            </div>

                            <!-- Titolo recensione -->
                            <?php if ($recensione['titolo']): ?>
                                <h4 class="font-semibold text-gray-800 mb-2">
                                    "<?php echo htmlspecialchars($recensione['titolo']); ?>"
                                </h4>
                            <?php endif; ?>

                            <!-- Contenuto recensione -->
                            <?php if ($recensione['contenuto']): ?>
                                <p class="text-gray-700 mb-4 leading-relaxed">
                                    <?php 
                                    $contenuto = $recensione['contenuto'];
                                    if (strlen($contenuto) > 200) {
                                        echo htmlspecialchars(substr($contenuto, 0, 200)) . '...';
                                    } else {
                                        echo htmlspecialchars($contenuto);
                                    }
                                    ?>
                                </p>
                            <?php endif; ?>

                            <!-- Footer recensione -->
                            <div class="flex items-center justify-between pt-4 border-t border-gray-100">
                                <div class="flex items-center space-x-3">
                                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center">
                                        <span class="text-sm font-semibold text-blue-600">
                                            <?php echo strtoupper(substr($recensione['fratello_nome'], 0, 1)); ?>
                                        </span>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-gray-800">
                                            <?php echo htmlspecialchars($recensione['fratello_nome']); ?>
                                        </p>
                                        <p class="text-xs text-gray-500">
                                            <?php echo htmlspecialchars($recensione['fratello_grado']); ?>
                                        </p>
                                    </div>
                                </div>
                                
                                <div class="text-right">
                                    <p class="text-sm text-gray-600">
                                        <?php echo date('d/m/Y', strtotime($recensione['created_at'])); ?>
                                    </p>
                                    <?php if ($recensione['consigliato']): ?>
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700 mt-1">
                                            👍 Consigliato
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Paginazione -->
                <?php if ($total_pages > 1): ?>
                    <div class="mt-8 flex justify-center">
                        <nav class="flex space-x-2">
                            <!-- Pagina precedente -->
                            <?php if ($page > 1): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" 
                                   class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm">
                                    ← Precedente
                                </a>
                            <?php endif; ?>

                            <!-- Numeri pagina -->
                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);
                            
                            for ($i = $start_page; $i <= $end_page; $i++):
                            ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>" 
                                   class="px-3 py-2 <?php echo $i == $page ? 'bg-blue-600 text-white' : 'bg-gray-200 hover:bg-gray-300'; ?> rounded-lg text-sm">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>

                            <!-- Pagina successiva -->
                            <?php if ($page < $total_pages): ?>
                                <a href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" 
                                   class="px-3 py-2 bg-gray-200 hover:bg-gray-300 rounded-lg text-sm">
                                    Successiva →
                                </a>
                            <?php endif; ?>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Azioni rapide -->
        <div class="bg-white/10 backdrop-blur-md rounded-xl p-6">
            <h2 class="text-xl font-bold text-white mb-4">🚀 Azioni Rapide</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="dashboard.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">🏠</div>
                    <p class="font-medium">Dashboard</p>
                </a>
                <a href="catalogo.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">📖</div>
                    <p class="font-medium">Catalogo</p>
                </a>
                <a href="catalogo.php?disponibili=1" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">✅</div>
                    <p class="font-medium">Libri Disponibili</p>
                </a>
                <a href="prestiti.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">📚</div>
                    <p class="font-medium">I Miei Libri</p>
                </a>
            </div>
        </div>
    </div>

    <!-- Script per miglioramenti UX -->
    <script>
        // Auto-submit del form con debounce
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.querySelector('input[name="search"]');
            let timeout;
            
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(timeout);
                    timeout = setTimeout(() => {
                        // Auto-submit dopo 500ms di inattività
                        this.form.submit();
                    }, 500);
                });
            }
        });

        // Animazioni hover per le recensioni
        document.querySelectorAll('.border-gray-200').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-2px)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0)';
            });
        });
    </script>
</body>
</html>