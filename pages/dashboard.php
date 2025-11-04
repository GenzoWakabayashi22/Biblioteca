<?php
/**
 * Dashboard - Sistema Biblioteca
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
    'email' => $_SESSION['fratello_email'] ?? null,
    'telefono' => $_SESSION['fratello_telefono'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
];

$is_admin = $user['is_admin'];

// Recupera statistiche per le card superiori
$stats_query = "
    SELECT 
        (SELECT COUNT(*) FROM libri WHERE stato = 'disponibile') as libri_disponibili,
        (SELECT COUNT(*) FROM libri WHERE stato = 'prestato') as libri_prestati,
        (SELECT COUNT(*) FROM libri) as totale_libri,
        (SELECT COUNT(DISTINCT fratello_id) FROM (
            SELECT fratello_id FROM storico_prestiti WHERE data_restituzione IS NOT NULL
            UNION 
            SELECT prestato_a_fratello_id as fratello_id FROM libri WHERE prestato_a_fratello_id IS NOT NULL
        ) as lettori) as fratelli_lettori
";
$stats = getSingleResult($stats_query);

// Libri più letti basati sulla tabella libri_letti
$libri_piu_letti = getAllResults("
    SELECT l.id, l.titolo, l.autore, COUNT(ll.id) as volte_letto
    FROM libri l 
    INNER JOIN libri_letti ll ON l.id = ll.libro_id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    GROUP BY l.id, l.titolo, l.autore
    ORDER BY volte_letto DESC 
    LIMIT 3
");

// Libri con voto più alto (almeno 1 recensione)
$libri_voto_alto = getAllResults("
    SELECT l.id, l.titolo, l.autore, AVG(r.valutazione) as voto_medio, COUNT(r.id) as num_recensioni
    FROM libri l 
    INNER JOIN recensioni_libri r ON l.id = r.libro_id
    GROUP BY l.id, l.titolo, l.autore
    HAVING COUNT(r.id) >= 1
    ORDER BY voto_medio DESC, num_recensioni DESC
    LIMIT 3
");

// Recupera libri più recensiti
$libri_piu_recensiti = getAllResults("
    SELECT l.id, l.titolo, l.autore, COUNT(r.id) as num_recensioni
    FROM libri l 
    INNER JOIN recensioni_libri r ON l.id = r.libro_id
    GROUP BY l.id
    ORDER BY num_recensioni DESC
    LIMIT 5
");

// Recupera prestiti in scadenza (solo per admin)
$prestiti_scadenza = [];
if ($is_admin) {
    $prestiti_scadenza = getAllResults("
        SELECT l.titolo, l.autore, f.nome as fratello_nome, f.telefono, 
               l.data_scadenza_corrente, 
               DATEDIFF(l.data_scadenza_corrente, CURDATE()) as giorni_rimasti
        FROM libri l 
        INNER JOIN fratelli f ON l.prestato_a_fratello_id = f.id
        WHERE l.stato = 'prestato' 
        AND l.data_scadenza_corrente <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY l.data_scadenza_corrente ASC
        LIMIT 5
    ");
}

// Recupera dati personali dell'utente
$miei_prestiti = getAllResults("
    SELECT l.id, l.titolo, l.autore, l.data_prestito_corrente, l.data_scadenza_corrente,
           DATEDIFF(l.data_scadenza_corrente, CURDATE()) as giorni_rimasti
    FROM libri l 
    WHERE l.prestato_a_fratello_id = ? AND l.stato = 'prestato'
    ORDER BY l.data_scadenza_corrente ASC
", [$user['id']], 'i');

// Ultime recensioni di tutti i fratelli
$ultime_recensioni = getAllResults("
    SELECT l.id as libro_id, l.titolo, l.autore, 
           r.valutazione, r.created_at, r.titolo as recensione_titolo, 
           r.contenuto, r.consigliato,
           f.nome as fratello_nome, f.grado as fratello_grado
    FROM recensioni_libri r
    INNER JOIN libri l ON r.libro_id = l.id
    INNER JOIN fratelli f ON r.fratello_id = f.id
    ORDER BY r.created_at DESC
    LIMIT 3
");

// Libri letti dall'utente
$libri_letti_recenti = getAllResults("
    SELECT ll.*, l.id as libro_id, l.titolo, l.autore, c.nome as categoria_nome, c.colore as categoria_colore
    FROM libri_letti ll
    INNER JOIN libri l ON ll.libro_id = l.id
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    WHERE ll.fratello_id = ?
    ORDER BY ll.data_lettura DESC
    LIMIT 3
", [$user['id']], 'i');

// Top Fratelli Lettori
$top_fratelli_lettori = getAllResults("
    SELECT 
        f.nome, 
        f.grado,
        COUNT(DISTINCT ll.libro_id) as libri_letti,
        COUNT(DISTINCT CASE WHEN l.prestato_a_fratello_id = f.id THEN l.id END) as libri_attuali,
        COUNT(DISTINCT ll.libro_id) as totale_libri
    FROM fratelli f
    INNER JOIN libri_letti ll ON f.id = ll.fratello_id
    LEFT JOIN libri l ON f.id = l.prestato_a_fratello_id AND l.stato = 'prestato'
    WHERE f.attivo = 1
    GROUP BY f.id, f.nome, f.grado
    ORDER BY libri_letti DESC, f.nome ASC
    LIMIT 3
");

// Statistiche personali per preferiti e liste
$preferiti_result = getSingleResult("SELECT COUNT(*) as count FROM preferiti WHERE fratello_id = ?", [$user['id']], 'i');
$num_preferiti = $preferiti_result ? $preferiti_result['count'] : 0;

$liste_result = getSingleResult("SELECT COUNT(*) as count FROM liste_lettura WHERE fratello_id = ?", [$user['id']], 'i');
$num_liste = $liste_result ? $liste_result['count'] : 0;
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Biblioteca R∴ L∴ Kilwinning</title>
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
                        <a href="dashboard.php" class="text-white font-medium bg-white/20 px-4 py-2 rounded-lg">
                            📚 Biblioteca
                        </a>
                        <a href="catalogo.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            📖 Catalogo
                        </a>
                        <a href="preferiti.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            ⭐ Preferiti
                        </a>
                        <a href="liste.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            📋 Liste
                        </a>
                        <?php if ($is_admin): ?>
                            <a href="admin/gestione-libri.php" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                                ⚙️ Gestione
                            </a>
                        <?php endif; ?>
                        <a href="https://tornate.loggiakilwinning.com/" class="text-white/80 hover:text-white px-4 py-2 rounded-lg hover:bg-white/10">
                            🏛️ Tornate
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
        <!-- Statistiche principali -->
        <div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
            <!-- Totale libri -->
            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">📚</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $stats['totale_libri']; ?></h3>
                <p class="text-gray-600">Libri in Biblioteca</p>
                <p class="text-sm text-gray-500 mt-1">
                    <?php echo $stats['libri_disponibili']; ?> disponibili • <?php echo $stats['libri_prestati']; ?> prestati
                </p>
            </div>

            <!-- Prestiti attivi -->
            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">📖</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo count($miei_prestiti); ?></h3>
                <p class="text-gray-600">Miei Prestiti</p>
                <p class="text-sm text-gray-500 mt-1">Libri attualmente in lettura</p>
            </div>

            <!-- Preferiti -->
            <div class="bg-white rounded-xl shadow-lg p-6 text-center hover:shadow-xl transition cursor-pointer" onclick="location.href='preferiti.php'">
                <div class="text-4xl mb-2">⭐</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $num_preferiti; ?></h3>
                <p class="text-gray-600">Preferiti</p>
                <p class="text-sm text-gray-500 mt-1">Libri salvati</p>
            </div>

            <!-- Liste personali -->
            <div class="bg-white rounded-xl shadow-lg p-6 text-center hover:shadow-xl transition cursor-pointer" onclick="location.href='liste.php'">
                <div class="text-4xl mb-2">📋</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $num_liste; ?></h3>
                <p class="text-gray-600">Liste</p>
                <p class="text-sm text-gray-500 mt-1">Liste personali</p>
            </div>

            <!-- Fratelli lettori -->
            <div class="bg-white rounded-xl shadow-lg p-6 text-center">
                <div class="text-4xl mb-2">👥</div>
                <h3 class="text-3xl font-bold text-gray-800"><?php echo $stats['fratelli_lettori']; ?></h3>
                <p class="text-gray-600">Fratelli Lettori</p>
                <p class="text-sm text-gray-500 mt-1">Hanno letto almeno un libro</p>
            </div>
        </div>

        <!-- Griglia principale -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
            <!-- Miei prestiti -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    📖 I Miei Prestiti
                </h2>
                <?php if (empty($miei_prestiti)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 text-4xl mb-3">📚</div>
                        <p class="text-gray-500">Nessun libro in prestito</p>
                        <div class="space-y-2 mt-4">
                            <a href="catalogo.php" class="block text-indigo-600 hover:text-indigo-800 font-medium">
                                📖 Sfoglia il catalogo
                            </a>
                            <a href="prestiti.php" class="block text-blue-600 hover:text-blue-800 font-medium">
                                📋 Visualizza i miei prestiti
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($miei_prestiti as $prestito): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <h3 class="font-semibold text-gray-800 hover:text-indigo-600">
                                            <a href="libro-dettaglio.php?id=<?php echo $prestito['id']; ?>">
                                                <?php echo htmlspecialchars($prestito['titolo']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($prestito['autore']); ?></p>
                                        <div class="flex items-center space-x-4 mt-2 text-sm">
                                            <span class="text-green-600">
                                                📅 <?php echo date('d/m/Y', strtotime($prestito['data_prestito_corrente'])); ?>
                                            </span>
                                            <span class="<?php echo $prestito['giorni_rimasti'] < 0 ? 'text-red-600' : ($prestito['giorni_rimasti'] <= 3 ? 'text-orange-600' : 'text-blue-600'); ?>">
                                                ⏰ <?php 
                                                if ($prestito['giorni_rimasti'] < 0) {
                                                    echo 'Scaduto da ' . abs($prestito['giorni_rimasti']) . ' giorni';
                                                } elseif ($prestito['giorni_rimasti'] == 0) {
                                                    echo 'Scade oggi';
                                                } else {
                                                    echo $prestito['giorni_rimasti'] . ' giorni rimasti';
                                                }
                                                ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        
                        <!-- Link per vedere tutti i prestiti -->
                        <div class="mt-4 pt-4 border-t border-gray-200 text-center">
                            <a href="prestiti.php" class="text-blue-600 hover:text-blue-800 font-medium">
                                📋 Visualizza tutti i miei prestiti →
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Top Fratelli Lettori -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    🏆 Top Fratelli Lettori
                </h2>
                
                <?php if (empty($top_fratelli_lettori)): ?>
                    <p class="text-gray-500 text-center py-4">Nessun fratello ha ancora letto libri</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($top_fratelli_lettori as $index => $fratello): ?>
                            <div class="flex items-center justify-between p-3 rounded-lg <?php echo $index === 0 ? 'bg-gradient-to-r from-yellow-50 to-yellow-100 border border-yellow-200' : ($index === 1 ? 'bg-gradient-to-r from-gray-50 to-gray-100 border border-gray-200' : 'bg-gradient-to-r from-orange-50 to-orange-100 border border-orange-200'); ?>">
                                <div class="flex items-center space-x-3">
                                    <!-- Posizione -->
                                    <div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-white <?php echo $index === 0 ? 'bg-yellow-500' : ($index === 1 ? 'bg-gray-400' : 'bg-orange-500'); ?>">
                                        <?php echo $index + 1; ?>
                                    </div>
                                    
                                    <!-- Dati fratello -->
                                    <div>
                                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($fratello['nome']); ?></h3>
                                        <p class="text-sm text-gray-600"><?php echo htmlspecialchars($fratello['grado']); ?></p>
                                    </div>
                                </div>
                                
                                <!-- Statistiche -->
                                <div class="text-right">
                                    <div class="font-bold text-lg text-gray-800"><?php echo $fratello['totale_libri']; ?> libri</div>
                                    <div class="text-xs text-gray-500">
                                        <?php echo $fratello['libri_letti']; ?> letti • <?php echo $fratello['libri_attuali']; ?> attuali
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Link per vedere classifica completa -->
                    <div class="mt-4 text-center">
                        <a href="statistiche-lettori.php" class="text-blue-600 hover:text-blue-600 text-sm font-medium">
                            📊 Vedi classifica completa →
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Libri Letti di Recente -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    ✅ Libri Letti di Recente
                    <?php if (count($libri_letti_recenti) > 0): ?>
                        <span class="ml-2 bg-green-100 text-green-600 text-sm px-2 py-1 rounded-full">
                            <?php echo count($libri_letti_recenti); ?>
                        </span>
                    <?php endif; ?>
                </h2>
                <?php if (empty($libri_letti_recenti)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 text-4xl mb-3">📖</div>
                        <p class="text-gray-500">Nessun libro segnato come letto</p>
                        <p class="text-gray-400 text-sm">Vai nei dettagli di un libro per segnarlo come letto!</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($libri_letti_recenti as $letto): ?>
                            <div class="border border-gray-200 rounded-lg p-3 hover:shadow-md transition-shadow">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-semibold text-gray-800 hover:text-indigo-600 truncate">
                                            <a href="libro-dettaglio.php?id=<?php echo $letto['libro_id']; ?>">
                                                <?php echo htmlspecialchars($letto['titolo']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-gray-600 text-sm truncate"><?php echo htmlspecialchars($letto['autore']); ?></p>
                                        <div class="flex flex-wrap items-center gap-2 mt-1">
                                            <?php if ($letto['categoria_nome']): ?>
                                                <span class="px-2 py-1 text-xs rounded-full text-white" 
                                                      style="background-color: <?php echo htmlspecialchars($letto['categoria_colore'] ?? '#8B4513'); ?>">
                                                    <?php echo htmlspecialchars($letto['categoria_nome']); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="text-green-600 text-xs whitespace-nowrap">
                                                ✅ <?php echo date('d/m/Y', strtotime($letto['data_lettura'])); ?>
                                            </span>
                                        </div>
                                        <?php if ($letto['note']): ?>
                                            <p class="text-gray-500 text-xs mt-1 line-clamp-2">
                                                "<?php echo htmlspecialchars(substr($letto['note'], 0, 80)); ?><?php echo strlen($letto['note']) > 80 ? '...' : ''; ?>"
                                            </p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                <!-- Link per vedere tutti i libri letti -->
<div class="mt-4 text-center">
    <a href="dettaglio-fratello.php?fratello_id=<?= $user['id'] ?>" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
        📚 Vedi tutti i libri letti →
    </a>
</div>
                <?php endif; ?>
            </div>

            <!-- Ultime Recensioni -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    💬 Ultime Recensioni
                    <?php if (count($ultime_recensioni) > 0): ?>
                        <span class="ml-2 bg-purple-100 text-purple-600 text-sm px-2 py-1 rounded-full">
                            <?php echo count($ultime_recensioni); ?>
                        </span>
                    <?php endif; ?>
                </h2>
                
                <?php if (empty($ultime_recensioni)): ?>
                    <div class="text-center py-8">
                        <div class="text-gray-400 text-4xl mb-3">💬</div>
                        <p class="text-gray-500">Nessuna recensione ancora</p>
                        <p class="text-gray-400 text-sm">Sii il primo a recensire un libro!</p>
                        <a href="catalogo.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium mt-2 inline-block">
                            📖 Sfoglia il catalogo →
                        </a>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($ultime_recensioni as $recensione): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <!-- Libro e Valutazione -->
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex-1 min-w-0">
                                        <h3 class="font-semibold text-gray-800 hover:text-indigo-600 truncate">
                                            <a href="libro-dettaglio.php?id=<?php echo $recensione['libro_id']; ?>">
                                                <?php echo htmlspecialchars($recensione['titolo']); ?>
                                            </a>
                                        </h3>
                                        <p class="text-gray-600 text-sm truncate"><?php echo htmlspecialchars($recensione['autore']); ?></p>
                                    </div>
                                    
                                    <!-- Stelle -->
                                    <div class="flex ml-2">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <span class="text-sm <?php echo $i <= $recensione['valutazione'] ? 'text-yellow-400' : 'text-gray-300'; ?>">⭐</span>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                                
                                <!-- Fratello e Data -->
                                <div class="flex items-center justify-between text-sm text-gray-500 mb-2">
                                    <div class="flex items-center space-x-2">
                                        <span class="font-medium text-gray-700">
                                            <?php echo htmlspecialchars($recensione['fratello_nome']); ?>
                                        </span>
                                        <span class="text-gray-400">•</span>
                                        <span><?php echo htmlspecialchars($recensione['fratello_grado']); ?></span>
                                    </div>
                                    <span><?php echo date('d/m/Y', strtotime($recensione['created_at'])); ?></span>
                                </div>
                                
                                <!-- Titolo Recensione -->
                                <?php if ($recensione['recensione_titolo']): ?>
                                    <p class="text-gray-700 font-medium text-sm mb-1">
                                        "<?php echo htmlspecialchars($recensione['recensione_titolo']); ?>"
                                    </p>
                                <?php endif; ?>
                                
                                <!-- Contenuto (troncato) -->
                                <?php if ($recensione['contenuto']): ?>
                                    <p class="text-gray-600 text-sm line-clamp-2">
                                        <?php echo htmlspecialchars(substr($recensione['contenuto'], 0, 120)); ?>
                                        <?php echo strlen($recensione['contenuto']) > 120 ? '...' : ''; ?>
                                    </p>
                                <?php endif; ?>
                                
                                <!-- Badge Consigliato -->
                                <?php if ($recensione['consigliato']): ?>
                                    <div class="mt-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-700">
                                            👍 Consigliato
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Link per vedere tutte le recensioni -->
                    <div class="mt-4 text-center">
                        <a href="tutte-recensioni.php" class="text-indigo-600 hover:text-indigo-800 text-sm font-medium">
                            💬 Vedi tutte le recensioni →
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Libri più letti -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    🏆 Libri Più Letti
                </h2>
                <?php if (empty($libri_piu_letti)): ?>
                    <p class="text-gray-500 text-center py-4">Nessun dato disponibile</p>
                    <p class="text-gray-400 text-sm text-center">I fratelli devono segnare i libri come "letti"</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($libri_piu_letti as $index => $libro): ?>
                            <div class="flex items-center space-x-3">
                                <span class="text-2xl"><?php echo ['🥇', '🥈', '🥉'][$index]; ?></span>
                                <div class="flex-1">
                                    <h3 class="font-semibold text-gray-800 hover:text-indigo-600">
                                        <a href="libro-dettaglio.php?id=<?php echo $libro['id']; ?>">
                                            <?php echo htmlspecialchars($libro['titolo']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($libro['autore']); ?></p>
                                    <p class="text-indigo-600 text-sm font-medium">
                                        <?php echo $libro['volte_letto']; ?> 
                                        <?php echo $libro['volte_letto'] == 1 ? 'lettura' : 'letture'; ?>
                                    </p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Libri con voto più alto -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                    ⭐ Libri Più Apprezzati
                </h2>
                <?php if (empty($libri_voto_alto)): ?>
                    <p class="text-gray-500 text-center py-4">Nessun dato disponibile</p>
                    <p class="text-gray-400 text-sm text-center">I fratelli devono recensire i libri</p>
                <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($libri_voto_alto as $index => $libro): ?>
                            <div class="flex items-center space-x-3">
                                <span class="text-2xl"><?php echo ['🥇', '🥈', '🥉'][$index]; ?></span>
                                <div class="flex-1">
                                    <h3 class="font-semibold text-gray-800 hover:text-indigo-600">
                                        <a href="libro-dettaglio.php?id=<?php echo $libro['id']; ?>">
                                            <?php echo htmlspecialchars($libro['titolo']); ?>
                                        </a>
                                    </h3>
                                    <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($libro['autore']); ?></p>
                                    <div class="flex items-center space-x-2 mt-1">
                                        <div class="flex">
                                            <?php 
                                            $voto = round($libro['voto_medio'], 1);
                                            for ($i = 1; $i <= 5; $i++): 
                                            ?>
                                                <span class="text-sm <?php echo $i <= $voto ? 'text-yellow-400' : 'text-gray-300'; ?>">⭐</span>
                                            <?php endfor; ?>
                                        </div>
                                        <span class="text-sm text-gray-600">
                                            <?php echo number_format($libro['voto_medio'], 1); ?>/5
                                            (<?php echo $libro['num_recensioni']; ?> <?php echo $libro['num_recensioni'] == 1 ? 'voto' : 'voti'; ?>)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($is_admin && !empty($prestiti_scadenza)): ?>
        <!-- Prestiti in scadenza (solo admin) -->
        <div class="mt-8 bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                ⚠️ Prestiti in Scadenza
                <span class="ml-2 bg-red-100 text-red-600 text-sm px-2 py-1 rounded-full">
                    <?php echo count($prestiti_scadenza); ?>
                </span>
            </h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <?php foreach ($prestiti_scadenza as $prestito): ?>
                    <div class="border <?php echo $prestito['giorni_rimasti'] < 0 ? 'border-red-300 bg-red-50' : 'border-orange-300 bg-orange-50'; ?> rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800"><?php echo htmlspecialchars($prestito['titolo']); ?></h3>
                        <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($prestito['autore']); ?></p>
                        <div class="mt-2 space-y-1 text-sm">
                            <p class="text-gray-700">
                                👤 <strong><?php echo htmlspecialchars($prestito['fratello_nome']); ?></strong>
                            </p>
                            <?php if ($prestito['telefono']): ?>
                                <p class="text-gray-600">
                                    📞 <a href="tel:<?php echo $prestito['telefono']; ?>" class="hover:text-blue-600">
                                        <?php echo htmlspecialchars($prestito['telefono']); ?>
                                    </a>
                                </p>
                            <?php endif; ?>
                            <p class="<?php echo $prestito['giorni_rimasti'] < 0 ? 'text-red-600 font-semibold' : 'text-orange-600'; ?>">
                                ⏰ <?php 
                                if ($prestito['giorni_rimasti'] < 0) {
                                    echo 'Scaduto da ' . abs($prestito['giorni_rimasti']) . ' giorni';
                                } elseif ($prestito['giorni_rimasti'] == 0) {
                                    echo 'Scade oggi!';
                                } else {
                                    echo 'Scade tra ' . $prestito['giorni_rimasti'] . ' giorni';
                                }
                                ?>
                            </p>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Libri più recensiti -->
        <div class="mt-8 bg-white rounded-xl shadow-lg p-6">
            <h2 class="text-xl font-bold text-gray-800 mb-4 flex items-center">
                💬 Libri Più Recensiti
            </h2>
            <?php if (empty($libri_piu_recensiti)): ?>
                <p class="text-gray-500 text-center py-4">Nessun dato disponibile</p>
            <?php else: ?>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach ($libri_piu_recensiti as $libro): ?>
                        <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                            <h3 class="font-semibold text-gray-800 hover:text-indigo-600">
                                <a href="libro-dettaglio.php?id=<?php echo $libro['id']; ?>">
                                    <?php echo htmlspecialchars($libro['titolo']); ?>
                                </a>
                            </h3>
                            <p class="text-gray-600 text-sm"><?php echo htmlspecialchars($libro['autore']); ?></p>
                            <p class="text-indigo-600 text-sm font-medium mt-1">
                                💬 <?php echo $libro['num_recensioni']; ?> recensioni
                            </p>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- SEZIONE ADMIN -->
        <?php if ($is_admin): ?>
        <div class="mt-8">
            <h2 class="text-2xl font-bold text-white mb-6">⚙️ Pannello Amministratore</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                
                <!-- CARD: Richieste Prestito -->
                <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-orange-100 p-3 rounded-full">
                            <span class="text-2xl">📋</span>
                        </div>
                        <?php
                        // Conta richieste in attesa
                        $richieste_attesa = getSingleResult("SELECT COUNT(*) as count FROM richieste_prestito WHERE stato = 'in_attesa'");
                        $count_attesa = $richieste_attesa['count'] ?? 0;
                        ?>
                        <?php if ($count_attesa > 0): ?>
                            <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full"><?php echo $count_attesa; ?></span>
                        <?php endif; ?>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Richieste Prestito</h3>
                    <p class="text-gray-600 text-sm mb-4">
                        Gestisci le richieste di prestito dei fratelli
                        <?php if ($count_attesa > 0): ?>
                            <br><strong class="text-orange-600"><?php echo $count_attesa; ?> in attesa di approvazione</strong>
                        <?php endif; ?>
                    </p>
                    <a href="admin/richieste-prestito.php" 
                       class="inline-flex items-center text-orange-600 hover:text-orange-700 font-medium">
                        <span class="mr-1">📋</span>
                        Gestisci Richieste
                        <span class="ml-1">→</span>
                    </a>
                </div>
                
                <!-- CARD: Gestione Libri -->
                <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-blue-100 p-3 rounded-full">
                            <span class="text-2xl">📚</span>
                        </div>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Gestione Libri</h3>
                    <p class="text-gray-600 text-sm mb-4">Aggiungi, modifica ed elimina libri dal catalogo</p>
                    <a href="admin/gestione-libri.php" 
                       class="inline-flex items-center text-blue-600 hover:text-blue-700 font-medium">
                        <span class="mr-1">📚</span>
                        Vai alla Gestione
                        <span class="ml-1">→</span>
                    </a>
                </div>
                
                <!-- CARD: Gestione Prestiti -->
                <div class="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition-shadow">
                    <div class="flex items-center justify-between mb-4">
                        <div class="bg-green-100 p-3 rounded-full">
                            <span class="text-2xl">📖</span>
                        </div>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-800 mb-2">Gestione Prestiti</h3>
                    <p class="text-gray-600 text-sm mb-4">Gestisci prestiti attivi e scadenze</p>
                    <a href="admin/gestione-prestiti.php" 
                       class="inline-flex items-center text-green-600 hover:text-green-700 font-medium">
                        <span class="mr-1">📖</span>
                        Gestisci Prestiti
                        <span class="ml-1">→</span>
                    </a>
                </div>
                
            </div>
        </div>
        <?php endif; ?>

        <!-- Azioni rapide -->
        <div class="mt-8 bg-white/10 backdrop-blur-md rounded-xl p-6">
            <h2 class="text-xl font-bold text-white mb-4">🚀 Azioni Rapide</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <a href="catalogo.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">📖</div>
                    <p class="font-medium">Sfoglia Catalogo</p>
                </a>
                <a href="catalogo.php?disponibili=1" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                    <div class="text-2xl mb-2">✅</div>
                    <p class="font-medium">Libri Disponibili</p>
                </a>
                <?php if ($is_admin): ?>
                    <a href="admin/gestione-libri.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                        <div class="text-2xl mb-2">⚙️</div>
                        <p class="font-medium">Gestisci Libri</p>
                    </a>
                    <a href="admin/gestione-prestiti.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                        <div class="text-2xl mb-2">📋</div>
                        <p class="font-medium">Gestisci Prestiti</p>
                    </a>
                <?php else: ?>
                    <a href="prestiti.php" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                        <div class="text-2xl mb-2">📚</div>
                        <p class="font-medium">I Miei Libri</p>
                    </a>
                    <a href="catalogo.php?novita=1" class="bg-white/20 hover:bg-white/30 text-white rounded-lg p-4 text-center transition-all">
                        <div class="text-2xl mb-2">🆕</div>
                        <p class="font-medium">Novità</p>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Informazioni Biblioteca -->
        <div class="mt-8 bg-white/10 backdrop-blur-md rounded-xl p-6">
            <h2 class="text-xl font-bold text-white mb-4">ℹ️ Informazioni Biblioteca</h2>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6 text-white">
                <div>
                    <h3 class="font-semibold mb-2">📍 Sede della Biblioteca</h3>
                    <p class="text-white/80 text-sm">R∴ L∴ Kilwinning</p>
                    <p class="text-white/80 text-sm">📍 Via XX Settembre, 22 - Tolfa (RM)</p>
                    <p class="text-white/80 text-sm">✉️ segreteria@loggiakilwinning.com</p>
                </div>
                <div>
                    <h3 class="font-semibold mb-2">📚 Regole Prestiti</h3>
                    <p class="text-white/80 text-sm">⏰ Durata prestito: 30 giorni</p>
                    <p class="text-white/80 text-sm">📖 Max 3 libri contemporaneamente</p>
                    <p class="text-white/80 text-sm">🔄 Rinnovo possibile se non prenotato</p>
                </div>
                <div>
                    <h3 class="font-semibold mb-2">👥 Biblioteca Kilwinning</h3>
                    <p class="text-white/80 text-sm">📊 <?php echo $stats['totale_libri']; ?> libri nella collezione</p>
                    <p class="text-white/80 text-sm">👤 <?php echo $stats['fratelli_lettori']; ?> fratelli lettori attivi</p>
                    <p class="text-white/80 text-sm">🏛️ Oriente di Roma</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Script per aggiornamenti dinamici -->
    <script>
        // Aggiorna contatori ogni 5 minuti
        setInterval(function() {
            fetch('api/stats.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Aggiorna eventuali contatori dinamici
                        console.log('Stats aggiornate:', data);
                    }
                })
                .catch(error => console.error('Errore aggiornamento stats:', error));
        }, 300000); // 5 minuti

        // Evidenzia prestiti in scadenza
        document.addEventListener('DOMContentLoaded', function() {
            const prestiti = document.querySelectorAll('[data-giorni-rimasti]');
            prestiti.forEach(prestito => {
                const giorni = parseInt(prestito.dataset.giorniRimasti);
                if (giorni <= 3) {
                    prestito.classList.add('animate-pulse');
                }
            });
        });
    </script>
</body>
</html>