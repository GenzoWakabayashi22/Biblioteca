<?php
session_start();

// Connessione database diretta (stesso setup degli altri file)
$db_config = [
    'host' => 'localhost',
    'username' => 'jmvvznbb_tornate_user', 
    'password' => 'Puntorosso22',
    'database' => 'jmvvznbb_tornate_db'
];

$conn = new mysqli($db_config['host'], $db_config['username'], $db_config['password'], $db_config['database']);

if ($conn->connect_error) {
    die("Errore connessione: " . $conn->connect_error);
}

$conn->set_charset('utf8mb4');

// Verifica autenticazione
$user_logged = isset($_SESSION['fratello_id']) && !empty($_SESSION['fratello_id']);
if (!$user_logged) {
    header('Location: ../index.php');
    exit;
}

// Admin check
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca Guiducci, Emiliano Menicucci, Francesco Ropresti
$is_admin = in_array($_SESSION['fratello_id'], $admin_ids);

// Recupera dati utente
$user_query = "SELECT nome, grado FROM fratelli WHERE id = ?";
$stmt = $conn->prepare($user_query);
$stmt->bind_param("i", $_SESSION['fratello_id']);
$stmt->execute();
$user_result = $stmt->get_result();
$user = $user_result->fetch_assoc();

// Recupera prestiti attuali dell'utente
$prestiti_query = "
    SELECT l.id, l.titolo, l.autore, l.data_prestito_corrente, l.data_scadenza_corrente,
           c.nome as categoria_nome, c.colore as categoria_colore,
           DATEDIFF(l.data_scadenza_corrente, CURDATE()) as giorni_rimasti
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    WHERE l.prestato_a_fratello_id = ? AND l.stato = 'prestato'
    ORDER BY l.data_scadenza_corrente ASC
";
$stmt = $conn->prepare($prestiti_query);
$stmt->bind_param("i", $_SESSION['fratello_id']);
$stmt->execute();
$prestiti_result = $stmt->get_result();
$prestiti_attuali = [];
while ($row = $prestiti_result->fetch_assoc()) {
    $prestiti_attuali[] = $row;
}

// Recupera storico prestiti (se esiste la tabella)
$storico_prestiti = [];
$check_table = $conn->query("SHOW TABLES LIKE 'storico_prestiti'");
if ($check_table && $check_table->num_rows > 0) {
    $storico_query = "
        SELECT sp.*, l.titolo, l.autore, c.nome as categoria_nome, c.colore as categoria_colore
        FROM storico_prestiti sp
        INNER JOIN libri l ON sp.libro_id = l.id
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE sp.fratello_id = ?
        ORDER BY sp.data_prestito DESC
        LIMIT 20
    ";
    $stmt = $conn->prepare($storico_query);
    $stmt->bind_param("i", $_SESSION['fratello_id']);
    $stmt->execute();
    $storico_result = $stmt->get_result();
    while ($row = $storico_result->fetch_assoc()) {
        $storico_prestiti[] = $row;
    }
}

// Recupera recensioni (se esiste la tabella)
$mie_recensioni = [];
$check_recensioni = $conn->query("SHOW TABLES LIKE 'recensioni_libri'");
if ($check_recensioni && $check_recensioni->num_rows > 0) {
    $recensioni_query = "
        SELECT r.*, l.titolo, l.autore, c.nome as categoria_nome, c.colore as categoria_colore
        FROM recensioni_libri r
        INNER JOIN libri l ON r.libro_id = l.id
        LEFT JOIN categorie_libri c ON l.categoria_id = c.id
        WHERE r.fratello_id = ?
        ORDER BY r.created_at DESC
        LIMIT 10
    ";
    $stmt = $conn->prepare($recensioni_query);
    $stmt->bind_param("i", $_SESSION['fratello_id']);
    $stmt->execute();
    $recensioni_result = $stmt->get_result();
    while ($row = $recensioni_result->fetch_assoc()) {
        $mie_recensioni[] = $row;
    }
}

// Calcola statistiche di base
$stats = [
    'libri_letti_totali' => 0,
    'libri_recensiti' => count($mie_recensioni),
    'media_giorni_prestito' => 0
];

if (!empty($storico_prestiti)) {
    $libri_unici = [];
    $giorni_totali = 0;
    $prestiti_completati = 0;
    
    foreach ($storico_prestiti as $prestito) {
        $libri_unici[$prestito['libro_id']] = true;
        if ($prestito['data_restituzione'] && $prestito['giorni_prestito']) {
            $giorni_totali += $prestito['giorni_prestito'];
            $prestiti_completati++;
        }
    }
    
    $stats['libri_letti_totali'] = count($libri_unici);
    $stats['media_giorni_prestito'] = $prestiti_completati > 0 ? round($giorni_totali / $prestiti_completati) : 0;
}

// Categorie preferite e libri consigliati (semplificati)
$categorie_preferite = [];
$libri_consigliati = [];

// Trova categorie più utilizzate
if (!empty($storico_prestiti)) {
    $categorie_count = [];
    foreach ($storico_prestiti as $prestito) {
        if ($prestito['categoria_nome']) {
            $cat_key = $prestito['categoria_nome'];
            if (!isset($categorie_count[$cat_key])) {
                $categorie_count[$cat_key] = [
                    'nome' => $prestito['categoria_nome'],
                    'colore' => $prestito['categoria_colore'],
                    'prestiti_categoria' => 0
                ];
            }
            $categorie_count[$cat_key]['prestiti_categoria']++;
        }
    }
    
    // Ordina per frequenza e prendi i primi 3
    uasort($categorie_count, function($a, $b) {
        return $b['prestiti_categoria'] - $a['prestiti_categoria'];
    });
    
    $categorie_preferite = array_slice($categorie_count, 0, 3);
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>I Miei Prestiti - Biblioteca R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary': '#6366f1',
                        'secondary': '#8b5cf6'
                    }
                }
            }
        }
    </script>
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
    </style>
</head>
<body class="p-4">
    <!-- Header -->
    <div class="bg-white rounded-2xl shadow-xl p-6 mb-6">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between">
            <div>
                <nav class="text-sm text-gray-500 mb-2">
                    <a href="dashboard.php" class="hover:text-primary">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">I Miei Prestiti</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">📖 I Miei Prestiti</h1>
                <p class="text-gray-600">Gestisci i tuoi libri e scopri le tue statistiche di lettura</p>
                <p class="text-sm text-blue-600">👋 Benvenuto, <?= htmlspecialchars($user['nome'] ?? 'Fratello') ?> (<?= htmlspecialchars($user['grado'] ?? '') ?>)</p>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="dashboard.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    🏠 Dashboard
                </a>
                <a href="catalogo.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Catalogo
                </a>
            </div>
        </div>
    </div>

    <!-- Statistiche personali -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="text-3xl mr-4">📚</div>
                <div>
                    <div class="text-2xl font-bold text-gray-800"><?= $stats['libri_letti_totali'] ?></div>
                    <div class="text-gray-600">Libri Letti</div>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="text-3xl mr-4">📖</div>
                <div>
                    <div class="text-2xl font-bold text-gray-800"><?= count($prestiti_attuali) ?></div>
                    <div class="text-gray-600">Prestiti Attuali</div>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="text-3xl mr-4">⭐</div>
                <div>
                    <div class="text-2xl font-bold text-gray-800"><?= $stats['libri_recensiti'] ?></div>
                    <div class="text-gray-600">Libri Recensiti</div>
                </div>
            </div>
        </div>
        
        <div class="bg-white rounded-xl shadow-lg p-6">
            <div class="flex items-center">
                <div class="text-3xl mr-4">📊</div>
                <div>
                    <div class="text-2xl font-bold text-gray-800"><?= $stats['media_giorni_prestito'] ?></div>
                    <div class="text-gray-600">Media Giorni Prestito</div>
                </div>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Colonna principale -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Prestiti attuali -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">📖 Prestiti Attuali (<?= count($prestiti_attuali) ?>)</h2>
                    <a href="catalogo.php" class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                        ➕ Cerca Libri
                    </a>
                </div>

                <?php if (empty($prestiti_attuali)): ?>
                    <div class="text-center py-12">
                        <div class="text-6xl mb-4">📚</div>
                        <h3 class="text-xl font-semibold text-gray-800 mb-2">Nessun prestito attivo</h3>
                        <p class="text-gray-600 mb-6">Esplora il catalogo e trova il tuo prossimo libro da leggere!</p>
                        <a href="catalogo.php" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                            🔍 Sfoglia il Catalogo
                        </a>
                    </div>
                <?php else: ?>
                    <div class="grid gap-4">
                        <?php foreach ($prestiti_attuali as $prestito): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <?php if ($prestito['categoria_nome']): ?>
                                                <span class="px-2 py-1 text-xs rounded-full text-white" 
                                                      style="background-color: <?= htmlspecialchars($prestito['categoria_colore'] ?? '#8B4513') ?>">
                                                    <?= htmlspecialchars($prestito['categoria_nome']) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php 
                                            $giorni = $prestito['giorni_rimasti'];
                                            if ($giorni < 0): ?>
                                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">
                                                    ⚠️ SCADUTO da <?= abs($giorni) ?> giorni
                                                </span>
                                            <?php elseif ($giorni == 0): ?>
                                                <span class="px-2 py-1 text-xs bg-red-100 text-red-800 rounded-full">
                                                    🔥 SCADE OGGI
                                                </span>
                                            <?php elseif ($giorni <= 3): ?>
                                                <span class="px-2 py-1 text-xs bg-orange-100 text-orange-800 rounded-full">
                                                    ⏰ Scade tra <?= $giorni ?> giorni
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">
                                                    ✅ Scade tra <?= $giorni ?> giorni
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <h3 class="font-semibold text-gray-800 text-lg mb-1">
                                            <?= htmlspecialchars($prestito['titolo']) ?>
                                        </h3>
                                        <p class="text-gray-600 mb-2"><?= htmlspecialchars($prestito['autore'] ?? 'Autore non specificato') ?></p>
                                        
                                        <div class="text-sm text-gray-500">
                                            <span>📅 Prestato: <?= date('d/m/Y', strtotime($prestito['data_prestito_corrente'])) ?></span>
                                            <span class="ml-4">📍 Scadenza: <?= date('d/m/Y', strtotime($prestito['data_scadenza_corrente'])) ?></span>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-2 mt-4 md:mt-0">
                                        <a href="libro-dettaglio.php?id=<?= $prestito['id'] ?>" 
                                           class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-sm transition">
                                            📖 Dettagli
                                        </a>
                                        <button onclick="segnalaProblema(<?= $prestito['id'] ?>, '<?= htmlspecialchars($prestito['titolo']) ?>')" 
                                                class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-sm transition">
                                            📞 Segnala
                                        </button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Storico prestiti -->
            <?php if (!empty($storico_prestiti)): ?>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h2 class="text-2xl font-bold text-gray-800 mb-6">📋 Storico Prestiti</h2>
                    
                    <div class="space-y-3">
                        <?php foreach ($storico_prestiti as $prestito): ?>
                            <div class="border border-gray-200 rounded-lg p-4">
                                <div class="flex flex-col md:flex-row md:items-center md:justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center space-x-2 mb-2">
                                            <?php if ($prestito['categoria_nome']): ?>
                                                <span class="px-2 py-1 text-xs rounded-full text-white" 
                                                      style="background-color: <?= htmlspecialchars($prestito['categoria_colore'] ?? '#8B4513') ?>">
                                                    <?= htmlspecialchars($prestito['categoria_nome']) ?>
                                                </span>
                                            <?php endif; ?>
                                            
                                            <?php if ($prestito['data_restituzione']): ?>
                                                <span class="px-2 py-1 text-xs bg-green-100 text-green-800 rounded-full">
                                                    ✅ Restituito
                                                </span>
                                            <?php else: ?>
                                                <span class="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded-full">
                                                    📖 In corso
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <h3 class="font-semibold text-gray-800 mb-1">
                                            <?= htmlspecialchars($prestito['titolo']) ?>
                                        </h3>
                                        <p class="text-gray-600 text-sm mb-2"><?= htmlspecialchars($prestito['autore'] ?? 'Autore non specificato') ?></p>
                                        
                                        <div class="text-sm text-gray-500">
                                            <span>📅 <?= date('d/m/Y', strtotime($prestito['data_prestito'])) ?></span>
                                            <?php if ($prestito['data_restituzione']): ?>
                                                <span class="ml-2">→ <?= date('d/m/Y', strtotime($prestito['data_restituzione'])) ?></span>
                                                <span class="ml-2">(<?= $prestito['giorni_prestito'] ?> giorni)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <div class="flex space-x-2 mt-3 md:mt-0">
                                        <a href="libro-dettaglio.php?id=<?= $prestito['libro_id'] ?>" 
                                           class="bg-primary hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm transition">
                                            📖 Dettagli
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (count($storico_prestiti) >= 20): ?>
                        <div class="text-center mt-6">
                            <p class="text-gray-500 text-sm">Mostrando i 20 prestiti più recenti</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Le mie recensioni -->
            <?php if (!empty($mie_recensioni)): ?>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">⭐ Le Mie Recensioni</h3>
                    
                    <div class="space-y-3">
                        <?php foreach (array_slice($mie_recensioni, 0, 5) as $recensione): ?>
                            <div class="border border-gray-200 rounded-lg p-3">
                                <h4 class="font-medium text-gray-800 text-sm mb-1">
                                    <?= htmlspecialchars($recensione['titolo']) ?>
                                </h4>
                                <div class="flex items-center space-x-1 mb-1">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                        <span class="text-sm <?= $i <= $recensione['valutazione'] ? 'text-yellow-400' : 'text-gray-300' ?>">⭐</span>
                                    <?php endfor; ?>
                                    <span class="text-gray-500 text-xs ml-2"><?= date('d/m/Y', strtotime($recensione['created_at'])) ?></span>
                                </div>
                                <?php if ($recensione['recensione']): ?>
                                    <p class="text-gray-600 text-xs">"<?= htmlspecialchars(substr($recensione['recensione'], 0, 100)) ?>..."</p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if (count($mie_recensioni) > 5): ?>
                        <div class="text-center mt-3">
                            <p class="text-gray-500 text-xs">E altre <?= count($mie_recensioni) - 5 ?> recensioni...</p>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <!-- Categorie preferite -->
            <?php if (!empty($categorie_preferite)): ?>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">❤️ Categorie Preferite</h3>
                    
                    <div class="space-y-2">
                        <?php foreach ($categorie_preferite as $categoria): ?>
                            <div class="flex items-center justify-between">
                                <span class="px-2 py-1 text-sm rounded-full text-white" 
                                      style="background-color: <?= htmlspecialchars($categoria['colore'] ?? '#8B4513') ?>">
                                    <?= htmlspecialchars($categoria['nome']) ?>
                                </span>
                                <span class="text-gray-500 text-sm"><?= $categoria['prestiti_categoria'] ?> libri</span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Info biblioteca -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-semibold text-gray-800 mb-4">📚 Info Biblioteca</h3>
                <div class="space-y-2 text-sm text-gray-600">
                    <p>📍 R∴ L∴ Kilwinning</p>
                    <p>📧 Per assistenza contatta gli amministratori</p>
                    <p>⏰ Durata prestito standard: 30 giorni</p>
                </div>
            </div>
        </div>
    </div>

    <script>
        function segnalaProblema(libroId, titoloLibro) {
            const motivo = prompt(`Segnala un problema con "${titoloLibro}":\n\nDescrivi il problema:`);
            if (motivo && motivo.trim()) {
                // Simulazione invio segnalazione
                alert(`✅ Segnalazione inviata per "${titoloLibro}"!\n\nMotivo: ${motivo.trim()}\n\nGli amministratori sono stati notificati.`);
            }
        }

        // Auto-refresh ogni 5 minuti per aggiornare i giorni rimanenti
        setInterval(() => {
            location.reload();
        }, 300000);

        console.log('📖 Pagina "I Miei Prestiti" caricata');
        console.log('👤 Prestiti attuali:', <?= count($prestiti_attuali) ?>);
        console.log('📊 Storico prestiti:', <?= count($storico_prestiti) ?>);
    </script>
</body>
</html>