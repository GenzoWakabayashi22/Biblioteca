<?php
session_start();
require_once '../config/database.php';

// Verifica autenticazione
verificaSessioneAttiva();

// Connessione database diretta (fix HTTP 500 error)
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

// Admin check
$is_admin = in_array($_SESSION['fratello_id'], ADMIN_IDS);

// Verifica ID libro
$libro_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$libro_id) {
    header('Location: catalogo.php');
    exit;
}

// Funzione per mostrare le stelle
function mostraStelle($voto, $classe = '') {
    if ($voto === 0 || $voto === 0.0) return '';
    
    $output = '<div class="flex items-center">';
    
    // Stelle piene
    $stelle_piene = floor($voto);
    for ($i = 1; $i <= $stelle_piene; $i++) {
        $output .= "<span class=\"text-yellow-500 {$classe}\">⭐</span>";
    }
    
    // Mezza stella se decimale >= 0.5 (rappresentata con una stella piena)
    $decimale = $voto - $stelle_piene;
    if ($decimale >= 0.5 && $stelle_piene < 5) {
        $output .= "<span class=\"text-yellow-500 {$classe}\">⭐</span>";
        $stelle_piene++;
    }
    
    // Stelle vuote per completare le 5
    $stelle_vuote = 5 - $stelle_piene;
    for ($i = 1; $i <= $stelle_vuote; $i++) {
        $output .= "<span class=\"text-gray-300 {$classe}\">☆</span>";
    }
    
    $output .= '</div>';
    return $output;
}

// Recupera dati completi del libro
// First get the book data
$libro_query = "
    SELECT l.*, c.nome as categoria_nome, c.colore as categoria_colore,
           f_prestato.nome as prestato_a_nome, f_prestato.telefono as prestato_telefono,
           f_proprietario.nome as proprietario_nome,
           f_aggiunto.nome as aggiunto_da_nome
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id
    LEFT JOIN fratelli f_prestato ON l.prestato_a_fratello_id = f_prestato.id
    LEFT JOIN fratelli f_proprietario ON l.proprietario_fratello_id = f_proprietario.id
    LEFT JOIN fratelli f_aggiunto ON l.aggiunto_da = f_aggiunto.id
    WHERE l.id = ?
";

$stmt = $conn->prepare($libro_query);
$stmt->bind_param("i", $libro_id);
$stmt->execute();
$result = $stmt->get_result();
$libro = $result->fetch_assoc();

// Then get the review statistics separately
if ($libro) {
    $stats_query = "
        SELECT COALESCE(AVG(r.valutazione), 0) as voto_medio,
               COUNT(r.id) as num_recensioni
        FROM recensioni_libri r
        WHERE r.libro_id = ?
    ";
    $stmt_stats = $conn->prepare($stats_query);
    $stmt_stats->bind_param("i", $libro_id);
    $stmt_stats->execute();
    $stats_result = $stmt_stats->get_result();
    $stats = $stats_result->fetch_assoc();
    
    // Merge the statistics into the libro array
    $libro['voto_medio'] = $stats['voto_medio'];
    $libro['num_recensioni'] = $stats['num_recensioni'];
}

if (!$libro) {
    header('Location: catalogo.php?error=libro_non_trovato');
    exit;
}

// Recupera recensioni del libro
$recensioni_query = "
    SELECT r.*, f.nome as fratello_nome, f.grado as fratello_grado
    FROM recensioni_libri r
    INNER JOIN fratelli f ON r.fratello_id = f.id
    WHERE r.libro_id = ?
    ORDER BY r.created_at DESC
";
$stmt_rec = $conn->prepare($recensioni_query);
$stmt_rec->bind_param("i", $libro_id);
$stmt_rec->execute();
$recensioni_result = $stmt_rec->get_result();
$recensioni = [];
if ($recensioni_result) {
    while ($row = $recensioni_result->fetch_assoc()) {
        $recensioni[] = $row;
    }
}

// Verifica se l'utente ha già recensito
$mia_recensione_query = "
    SELECT * FROM recensioni_libri 
    WHERE libro_id = ? AND fratello_id = ?
";
$stmt_mia = $conn->prepare($mia_recensione_query);
$stmt_mia->bind_param("ii", $libro_id, $_SESSION['fratello_id']);
$stmt_mia->execute();
$mia_recensione_result = $stmt_mia->get_result();
$mia_recensione = $mia_recensione_result->fetch_assoc();

// Verifica se l'utente ha letto il libro (tabella libri_letti)
// NOTA: La tabella libri_letti deve essere creata manualmente dal DBA se non esiste
// CREATE TABLE IF NOT EXISTS rimosso per evitare problemi di permessi in produzione

$libro_letto = null;
try {
    $libro_letto_query = "SELECT * FROM libri_letti WHERE fratello_id = ? AND libro_id = ?";
    $stmt_letto = $conn->prepare($libro_letto_query);
    if ($stmt_letto) {
        $stmt_letto->bind_param("ii", $_SESSION['fratello_id'], $libro_id);
        if ($stmt_letto->execute()) {
            $result_letto = $stmt_letto->get_result();
            if ($result_letto) {
                $libro_letto = $result_letto->fetch_assoc();
            }
        }
        $stmt_letto->close();
    }
} catch (Exception $e) {
    // Tabella potrebbe non esistere ancora - log dell'errore
    error_log("Errore query libri_letti per libro_id=$libro_id: " . $e->getMessage());
    $libro_letto = null;
}

// Verifica se il libro è già nei preferiti
$is_preferito = false;
try {
    $preferito_query = "SELECT * FROM preferiti WHERE fratello_id = ? AND libro_id = ?";
    $stmt_pref = $conn->prepare($preferito_query);
    if ($stmt_pref) {
        $stmt_pref->bind_param("ii", $_SESSION['fratello_id'], $libro_id);
        if ($stmt_pref->execute()) {
            $result_pref = $stmt_pref->get_result();
            if ($result_pref) {
                $is_preferito = $result_pref->num_rows > 0;
            }
        }
        $stmt_pref->close();
    }
} catch (Exception $e) {
    error_log("Errore query preferiti per libro_id=$libro_id: " . $e->getMessage());
    $is_preferito = false;
}

// Gestione form recensione
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'aggiungi_recensione') {
        $valutazione = (int)$_POST['valutazione'];
        $titolo_recensione = trim($_POST['titolo']);
        $contenuto = trim($_POST['contenuto']);
        $consigliato = isset($_POST['consigliato']) ? 1 : 0;
        
        if ($valutazione >= 1 && $valutazione <= 5) {
            if ($mia_recensione) {
                // Aggiorna recensione esistente
                $update_rec = "
                    UPDATE recensioni_libri 
                    SET valutazione = ?, titolo = ?, contenuto = ?, consigliato = ?
                    WHERE libro_id = ? AND fratello_id = ?
                ";
                $stmt_update = $conn->prepare($update_rec);
                $stmt_update->bind_param("issiii", $valutazione, $titolo_recensione, $contenuto, $consigliato, $libro_id, $_SESSION['fratello_id']);
                $stmt_update->execute();
            } else {
                // Inserisci nuova recensione
                $insert_rec = "
                    INSERT INTO recensioni_libri (libro_id, fratello_id, valutazione, titolo, contenuto, consigliato, stato_lettura)
                    VALUES (?, ?, ?, ?, ?, ?, 'completato')
                ";
                $stmt_insert = $conn->prepare($insert_rec);
                $stmt_insert->bind_param("iisisi", $libro_id, $_SESSION['fratello_id'], $valutazione, $titolo_recensione, $contenuto, $consigliato);
                $stmt_insert->execute();
            }
            
            header("Location: libro-dettaglio.php?id={$libro_id}&recensione_salvata=1");
            exit;
        }
    } elseif ($_POST['action'] == 'segna_come_letto') {
        $data_lettura = $_POST['data_lettura'] ?? date('Y-m-d');
        $note_lettura = trim($_POST['note_lettura'] ?? '');

        try {
            if ($libro_letto) {
                // Aggiorna
                $update_letto = "UPDATE libri_letti SET data_lettura = ?, note = ? WHERE fratello_id = ? AND libro_id = ?";
                $stmt_up = $conn->prepare($update_letto);
                if ($stmt_up) {
                    $stmt_up->bind_param("ssii", $data_lettura, $note_lettura, $_SESSION['fratello_id'], $libro_id);
                    if (!$stmt_up->execute()) {
                        throw new Exception("Errore nell'aggiornamento: " . $stmt_up->error);
                    }
                    $stmt_up->close();
                }
            } else {
                // Inserisci
                $insert_letto = "INSERT INTO libri_letti (fratello_id, libro_id, data_lettura, note) VALUES (?, ?, ?, ?)";
                $stmt_in = $conn->prepare($insert_letto);
                if ($stmt_in) {
                    $stmt_in->bind_param("iiss", $_SESSION['fratello_id'], $libro_id, $data_lettura, $note_lettura);
                    if (!$stmt_in->execute()) {
                        throw new Exception("Errore nell'inserimento: " . $stmt_in->error);
                    }
                    $stmt_in->close();
                }
            }

            header("Location: libro-dettaglio.php?id={$libro_id}&libro_letto_salvato=1");
            exit;
        } catch (Exception $e) {
            error_log("Errore segna_come_letto per libro_id=$libro_id: " . $e->getMessage());
            $error = "Errore nel salvataggio della lettura. Riprova o contatta l'amministratore.";
        }
    } elseif ($_POST['action'] == 'elimina_recensione' && $is_admin) {
        // NUOVA FUNZIONALITÀ: Eliminazione recensioni per admin
        $recensione_id = (int)$_POST['recensione_id'];
        $fratello_recensore_id = (int)$_POST['fratello_recensore_id'];
        
        if ($recensione_id && $fratello_recensore_id) {
            // Verifica che la recensione esista
            $check_rec = "SELECT id, fratello_id FROM recensioni_libri WHERE id = ? AND libro_id = ?";
            $stmt_check = $conn->prepare($check_rec);
            $stmt_check->bind_param("ii", $recensione_id, $libro_id);
            $stmt_check->execute();
            $recensione_da_eliminare = $stmt_check->get_result()->fetch_assoc();
            
            if ($recensione_da_eliminare) {
                // Elimina la recensione
                $delete_rec = "DELETE FROM recensioni_libri WHERE id = ? AND libro_id = ?";
                $stmt_delete = $conn->prepare($delete_rec);
                $stmt_delete->bind_param("ii", $recensione_id, $libro_id);
                
                if ($stmt_delete->execute()) {
                    // Log dell'operazione per audit
                    error_log("ADMIN: Recensione ID $recensione_id eliminata da admin ID " . $_SESSION['fratello_id'] . " per libro ID $libro_id");
                    
                    header("Location: libro-dettaglio.php?id={$libro_id}&recensione_eliminata=1");
                    exit;
                } else {
                    $error = "Errore nell'eliminazione della recensione";
                }
            } else {
                $error = "Recensione non trovata";
            }
        } else {
            $error = "Dati insufficienti per eliminare la recensione";
        }
    }
}

// Recupera storico prestiti (solo per admin)
$storico_prestiti = [];
if ($is_admin) {
    $storico_query = "
        SELECT sp.*, f.nome as fratello_nome
        FROM storico_prestiti sp
        INNER JOIN fratelli f ON sp.fratello_id = f.id
        WHERE sp.libro_id = ?
        ORDER BY sp.data_prestito DESC
        LIMIT 10
    ";
    $stmt_storico = $conn->prepare($storico_query);
    $stmt_storico->bind_param("i", $libro_id);
    $stmt_storico->execute();
    $storico_result = $stmt_storico->get_result();
    while ($row = $storico_result->fetch_assoc()) {
        $storico_prestiti[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($libro['titolo']) ?> - Biblioteca R∴ L∴ Kilwinning</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
        .rating-stars { display: flex; gap: 2px; }
        .copertina-placeholder { background: linear-gradient(45deg, #f3f4f6, #e5e7eb); }
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
                    <a href="catalogo.php" class="hover:text-primary">📚 Catalogo</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">Dettagli Libro</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">📖 <?= htmlspecialchars($libro['titolo']) ?></h1>
                <?php if ($libro['autore']): ?>
                    <p class="text-xl text-gray-600">di <?= htmlspecialchars($libro['autore']) ?></p>
                <?php endif; ?>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="catalogo.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    ↩️ Torna al Catalogo
                </a>
                <?php if ($is_admin): ?>
                    <a href="admin/modifica-libro.php?id=<?= $libro['id'] ?>" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition">
                        ✏️ Modifica
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Messaggi di feedback -->
    <?php if (isset($_GET['recensione_salvata'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <span class="mr-2">✅</span>
                <span>Recensione salvata con successo!</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['libro_letto_salvato'])): ?>
        <div class="bg-blue-100 border border-blue-400 text-blue-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <span class="mr-2">📚</span>
                <span>Libro segnato come letto!</span>
            </div>
        </div>
    <?php endif; ?>

    <!-- Messaggio recensione eliminata (solo admin) -->
    <?php if (isset($_GET['recensione_eliminata']) && $is_admin): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <span class="mr-2">🗑️</span>
                <span>Recensione eliminata con successo dall'amministratore.</span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (isset($error) && $error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <div class="flex items-center">
                <span class="mr-2">❌</span>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Colonna principale -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Dettagli libro -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <!-- Copertina -->
                    <div class="md:col-span-1">
                        <div class="w-full max-w-sm mx-auto">
                            <?php if ($libro['copertina_url']): ?>
                                <img src="<?= htmlspecialchars($libro['copertina_url']) ?>" 
                                     alt="Copertina di <?= htmlspecialchars($libro['titolo']) ?>" 
                                     class="w-full h-auto rounded-lg shadow-lg border border-gray-200"
                                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                                <div class="copertina-placeholder w-full aspect-[3/4] rounded-lg shadow-lg border border-gray-200 items-center justify-center text-gray-500 hidden">
                                    <div class="text-center">
                                        <div class="text-4xl mb-2">📖</div>
                                        <div class="text-sm">Copertina non disponibile</div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="copertina-placeholder w-full aspect-[3/4] rounded-lg shadow-lg border border-gray-200 flex items-center justify-center text-gray-500">
                                    <div class="text-center">
                                        <div class="text-4xl mb-2">📖</div>
                                        <div class="text-sm">Copertina non disponibile</div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Informazioni -->
                    <div class="md:col-span-2">
                        <h2 class="text-2xl font-bold text-gray-800 mb-4">ℹ️ Informazioni</h2>
                        
                        <div class="space-y-3">
                            <?php if ($libro['categoria_nome']): ?>
                                <div class="flex items-center">
                                    <span class="text-gray-600 w-24 text-sm">Categoria:</span>
                                    <span class="px-3 py-1 rounded-full text-sm text-white" 
                                          style="background-color: <?= $libro['categoria_colore'] ?? '#6366f1' ?>">
                                        <?= htmlspecialchars($libro['categoria_nome']) ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <?php if ($libro['isbn']): ?>
                                <div class="flex items-center">
                                    <span class="text-gray-600 w-24 text-sm">ISBN:</span>
                                    <span class="text-gray-800"><?= htmlspecialchars($libro['isbn']) ?></span>
                                </div>
                            <?php endif; ?>

                            <?php if ($libro['anno_pubblicazione']): ?>
                                <div class="flex items-center">
                                    <span class="text-gray-600 w-24 text-sm">Anno:</span>
                                    <span class="text-gray-800"><?= $libro['anno_pubblicazione'] ?></span>
                                </div>
                            <?php endif; ?>

                            <div class="flex items-center">
                                <span class="text-gray-600 w-24 text-sm">Lingua:</span>
                                <span class="text-gray-800"><?= ucfirst($libro['lingua'] ?? 'italiano') ?></span>
                            </div>

                            <div class="flex items-center">
                                <span class="text-gray-600 w-24 text-sm">Grado:</span>
                                <span class="text-gray-800">
                                    <?php
                                    $grado_icons = [
                                        'pubblico' => '🌍 Pubblico',
                                        'Apprendista' => '🔺 Apprendista',
                                        'Compagno' => '🔷 Compagno',
                                        'Maestro' => '🔶 Maestro'
                                    ];
                                    echo $grado_icons[$libro['grado_minimo']] ?? '🌍 Pubblico';
                                    ?>
                                </span>
                            </div>

                            <div class="flex items-center">
                                <span class="text-gray-600 w-24 text-sm">Stato:</span>
                                <span class="px-3 py-1 rounded-full text-sm text-white
                                    <?= $libro['stato'] == 'disponibile' ? 'bg-green-500' : 
                                        ($libro['stato'] == 'prestato' ? 'bg-orange-500' : 'bg-red-500') ?>">
                                    <?= ucfirst($libro['stato']) ?>
                                </span>
                            </div>

                            <?php if ($libro['num_recensioni'] > 0): ?>
                                <div class="flex items-center">
                                    <span class="text-gray-600 w-24 text-sm">Voto:</span>
                                    <div class="flex items-center space-x-2">
                                        <div class="rating-stars">
                                            <?= mostraStelle(round($libro['voto_medio'])) ?>
                                        </div>
                                        <span class="text-gray-600 text-sm">
                                            (<?= number_format($libro['voto_medio'], 1) ?>/5 - <?= $libro['num_recensioni'] ?> recensioni)
                                        </span>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($libro['prestato_a_nome']): ?>
                                <div class="flex items-center">
                                    <span class="text-gray-600 w-24 text-sm">Prestato a:</span>
                                    <span class="text-orange-600 font-medium"><?= htmlspecialchars($libro['prestato_a_nome']) ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Descrizione -->
                <?php if ($libro['descrizione']): ?>
                    <div class="mt-6 pt-6 border-t border-gray-200">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">📝 Descrizione</h3>
                        <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($libro['descrizione'])) ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Segna come letto -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">📚 Lettura</h2>
                    
                    <?php if (!$libro_letto): ?>
                        <button onclick="toggleLettoForm()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                            ✅ Segna come Letto
                        </button>
                    <?php else: ?>
                        <button onclick="toggleLettoForm()" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                            ✏️ Modifica Lettura
                        </button>
                    <?php endif; ?>
                </div>

                <?php if ($libro_letto): ?>
                    <div class="bg-green-50 rounded-lg p-4 mb-6">
                        <div class="flex items-center text-green-800">
                            <span class="mr-2">📖</span>
                            <span class="font-medium">Hai letto questo libro il <?= date('d/m/Y', strtotime($libro_letto['data_lettura'])) ?></span>
                        </div>
                        <?php if ($libro_letto['note']): ?>
                            <p class="text-green-700 mt-2"><?= nl2br(htmlspecialchars($libro_letto['note'])) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Form lettura -->
                <div id="letto-form" class="hidden bg-gray-50 rounded-lg p-4">
                    <form method="POST">
                        <input type="hidden" name="action" value="segna_come_letto">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 text-sm font-medium mb-2">Data di lettura</label>
                                <input type="date" name="data_lettura" 
                                       value="<?= $libro_letto ? date('Y-m-d', strtotime($libro_letto['data_lettura'])) : date('Y-m-d') ?>"
                                       class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-medium mb-2">Note personali</label>
                            <textarea name="note_lettura" rows="3" 
                                      placeholder="Cosa hai imparato? Cosa ti è piaciuto? (opzionale)"
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500"><?= $libro_letto ? htmlspecialchars($libro_letto['note']) : '' ?></textarea>
                        </div>
                        
                        <div class="flex space-x-2">
                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition">
                                💾 <?= $libro_letto ? 'Aggiorna' : 'Salva' ?>
                            </button>
                            <button type="button" onclick="toggleLettoForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition">
                                ❌ Annulla
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Recensioni -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-2xl font-bold text-gray-800">📝 Recensioni (<?= count($recensioni) ?>)</h2>
                    
                    <?php if (!$mia_recensione): ?>
                        <button onclick="toggleRecensioneForm()" class="bg-primary hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                            ✍️ Scrivi Recensione
                        </button>
                    <?php else: ?>
                        <button onclick="toggleRecensioneForm()" class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg transition">
                            ✏️ Modifica Recensione
                        </button>
                    <?php endif; ?>
                </div>

                <!-- Form recensione -->
                <div id="recensione-form" class="hidden bg-gray-50 rounded-lg p-4 mb-6">
                    <form method="POST">
                        <input type="hidden" name="action" value="aggiungi_recensione">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-gray-700 text-sm font-medium mb-2">Valutazione *</label>
                                <select name="valutazione" required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                                    <option value="">Seleziona voto</option>
                                    <option value="5" <?= $mia_recensione && $mia_recensione['valutazione'] == 5 ? 'selected' : '' ?>>⭐⭐⭐⭐⭐ Eccellente</option>
                                    <option value="4" <?= $mia_recensione && $mia_recensione['valutazione'] == 4 ? 'selected' : '' ?>>⭐⭐⭐⭐ Molto buono</option>
                                    <option value="3" <?= $mia_recensione && $mia_recensione['valutazione'] == 3 ? 'selected' : '' ?>>⭐⭐⭐ Buono</option>
                                    <option value="2" <?= $mia_recensione && $mia_recensione['valutazione'] == 2 ? 'selected' : '' ?>>⭐⭐ Discreto</option>
                                    <option value="1" <?= $mia_recensione && $mia_recensione['valutazione'] == 1 ? 'selected' : '' ?>>⭐ Scarso</option>
                                </select>
                            </div>
                            
                            <div class="flex items-center">
                                <label class="flex items-center">
                                    <input type="checkbox" name="consigliato" value="1" 
                                           <?= $mia_recensione && $mia_recensione['consigliato'] ? 'checked' : '' ?>
                                           class="mr-2 w-4 h-4 text-primary border-gray-300 rounded focus:ring-primary">
                                    <span class="text-sm text-gray-700">👍 Lo consiglio</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-medium mb-2">Titolo recensione (opzionale)</label>
                            <input type="text" name="titolo" 
                                   value="<?= $mia_recensione ? htmlspecialchars($mia_recensione['titolo']) : '' ?>"
                                   placeholder="Un titolo per la tua recensione"
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary">
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-gray-700 text-sm font-medium mb-2">Recensione</label>
                            <textarea name="contenuto" rows="4" 
                                      placeholder="Condividi la tua esperienza di lettura..."
                                      class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary"><?= $mia_recensione ? htmlspecialchars($mia_recensione['contenuto']) : '' ?></textarea>
                        </div>
                        
                        <div class="flex space-x-2">
                            <button type="submit" class="bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                                💾 Salva Recensione
                            </button>
                            <button type="button" onclick="toggleRecensioneForm()" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition">
                                ❌ Annulla
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Lista recensioni con controlli admin -->
                <div class="space-y-4">
                    <?php if (empty($recensioni)): ?>
                        <p class="text-gray-500 text-center py-8">Nessuna recensione disponibile. Sii il primo a recensire questo libro!</p>
                    <?php else: ?>
                        <?php foreach ($recensioni as $recensione): ?>
                            <div class="border border-gray-200 rounded-lg p-4 <?= $recensione['fratello_id'] == $_SESSION['fratello_id'] ? 'bg-blue-50 border-blue-200' : '' ?>">
                                <div class="flex justify-between items-start mb-2">
                                    <div>
                                        <h4 class="font-semibold text-gray-800">
                                            <?= htmlspecialchars($recensione['fratello_nome']) ?>
                                            <?php if ($recensione['fratello_id'] == $_SESSION['fratello_id']): ?>
                                                <span class="text-blue-600 text-sm">(La tua recensione)</span>
                                            <?php endif; ?>
                                        </h4>
                                        <div class="flex items-center space-x-2">
                                            <!-- Stelle della recensione -->
                                            <?= mostraStelle($recensione['valutazione'], 'text-sm') ?>
                                            <span class="text-gray-500 text-sm"><?= date('d/m/Y', strtotime($recensione['created_at'])) ?></span>
                                            <?php if ($recensione['consigliato']): ?>
                                                <span class="text-green-600 text-sm">👍 Consigliato</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Controlli admin per eliminare recensione -->
                                    <?php if ($is_admin): ?>
                                        <div class="flex space-x-2">
                                            <form method="POST" class="inline recensione-delete-form">
                                                <input type="hidden" name="action" value="elimina_recensione">
                                                <input type="hidden" name="recensione_id" value="<?= $recensione['id'] ?>">
                                                <input type="hidden" name="fratello_recensore_id" value="<?= $recensione['fratello_id'] ?>">
                                                <input type="hidden" name="nome_utente" value="<?= htmlspecialchars($recensione['fratello_nome']) ?>">
                                                <input type="hidden" name="titolo_recensione" value="<?= htmlspecialchars($recensione['titolo'] ?? '') ?>">
                                                <button type="submit" 
                                                        class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-sm transition"
                                                        title="Elimina recensione (Solo Admin)">
                                                    🗑️ Elimina
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($recensione['titolo']): ?>
                                    <h5 class="font-medium text-gray-800 mb-2"><?= htmlspecialchars($recensione['titolo']) ?></h5>
                                <?php endif; ?>
                                
                                <?php if ($recensione['contenuto']): ?>
                                    <p class="text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars($recensione['contenuto'])) ?></p>
                                <?php endif; ?>
                                
                                <!-- Indicatore se l'utente ha letto il libro -->
                                <?php if ($recensione['stato_lettura'] == 'completato'): ?>
                                    <div class="mt-2 inline-flex items-center text-green-600 text-sm">
                                        <span class="mr-1">📚</span>
                                        <span>Ha letto il libro</span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Sezione controlli admin -->
            <?php if ($is_admin): ?>
                <div class="bg-gray-50 rounded-xl p-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">⚙️ Controlli Admin</h3>
                    <div class="text-sm text-gray-600 space-y-2">
                        <div class="flex items-center space-x-2">
                            <span>•</span>
                            <span>Puoi eliminare qualsiasi recensione usando il pulsante <strong class="text-red-600">🗑️ Elimina</strong></span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span>•</span>
                            <span>Le eliminazioni vengono registrate nei log di sistema per audit</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span>•</span>
                            <span>Usa questa funzione solo per recensioni inappropriate o spam</span>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span>•</span>
                            <span>Ogni eliminazione richiede una doppia conferma</span>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="space-y-6">
            <!-- Azioni rapide -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">⚡ Azioni Rapide</h3>
                
                <div class="space-y-3">
                    <?php if ($is_preferito): ?>
                        <button id="btn-preferiti" onclick="rimuoviDaiPreferiti(<?= $libro['id'] ?>)" 
                                class="w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg transition">
                            ⭐ Nei Preferiti
                        </button>
                    <?php else: ?>
                        <button id="btn-preferiti" onclick="aggiungiAiPreferiti(<?= $libro['id'] ?>)" 
                                class="w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-3 rounded-lg transition">
                            ⭐ Aggiungi ai Preferiti
                        </button>
                    <?php endif; ?>
                    
                    <button onclick="mostraModalListe(<?= $libro['id'] ?>)" 
                            class="w-full bg-purple-500 hover:bg-purple-600 text-white px-4 py-3 rounded-lg transition">
                        📋 Aggiungi alla Lista
                    </button>
                </div>
            </div>

            <!-- Statistiche libro -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">📊 Statistiche</h3>
                
                <div class="space-y-3">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Volte prestato:</span>
                        <span class="font-medium"><?= $libro['volte_prestato'] ?? 0 ?></span>
                    </div>
                    
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Recensioni:</span>
                        <span class="font-medium"><?= $libro['num_recensioni'] ?></span>
                    </div>
                    
                    <?php if ($libro['num_recensioni'] > 0): ?>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-600">Voto medio:</span>
                            <span class="font-medium"><?= number_format($libro['voto_medio'], 1) ?>/5</span>
                        </div>
                    <?php endif; ?>
                    
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Condizioni:</span>
                        <span class="font-medium"><?= ucfirst($libro['condizioni']) ?></span>
                    </div>
                </div>
            </div>

            <!-- Informazioni aggiuntive -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">ℹ️ Dettagli</h3>
                
                <div class="space-y-3 text-sm">
                    <?php if ($libro['aggiunto_da_nome']): ?>
                        <div>
                            <span class="text-gray-600">Aggiunto da:</span><br>
                            <span class="font-medium"><?= htmlspecialchars($libro['aggiunto_da_nome']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($libro['proprietario_nome']): ?>
                        <div>
                            <span class="text-gray-600">Proprietario:</span><br>
                            <span class="font-medium"><?= htmlspecialchars($libro['proprietario_nome']) ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div>
                        <span class="text-gray-600">Aggiunto il:</span><br>
                        <span class="font-medium"><?= date('d/m/Y', strtotime($libro['created_at'])) ?></span>
                    </div>
                    
                    <div>
                        <span class="text-gray-600">ID Libro:</span><br>
                        <span class="font-mono text-xs">#<?= $libro['id'] ?></span>
                    </div>
                </div>
            </div>

            <!-- Storico prestiti (solo admin) -->
            <?php if ($is_admin && !empty($storico_prestiti)): ?>
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">📋 Storico Prestiti</h3>
                    
                    <div class="space-y-3">
                        <?php foreach ($storico_prestiti as $prestito): ?>
                            <div class="border-l-4 border-blue-500 pl-3 py-2">
                                <div class="font-medium text-sm"><?= htmlspecialchars($prestito['fratello_nome']) ?></div>
                                <div class="text-xs text-gray-600">
                                    <?= date('d/m/Y', strtotime($prestito['data_prestito'])) ?>
                                    <?php if ($prestito['data_restituzione']): ?>
                                        → <?= date('d/m/Y', strtotime($prestito['data_restituzione'])) ?>
                                    <?php else: ?>
                                        → <span class="text-orange-600">In corso</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal per Aggiungi alla Lista -->
    <div id="modalListe" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900">📋 Aggiungi alla Lista</h3>
                    <button onclick="closeModalListe()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="liste-container" class="space-y-3 mb-4 max-h-96 overflow-y-auto">
                    <!-- Liste verranno caricate dinamicamente -->
                </div>
                
                <div class="border-t pt-4">
                    <button onclick="mostraFormNuovaLista()" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg transition">
                        ➕ Crea Nuova Lista
                    </button>
                </div>
                
                <!-- Form per creare nuova lista -->
                <div id="form-nuova-lista" class="hidden mt-4 p-4 bg-gray-50 rounded-lg">
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Nome Lista *</label>
                            <input type="text" id="nome-lista" placeholder="es. Libri da leggere, Esoterici..." 
                                   class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Descrizione</label>
                            <textarea id="descrizione-lista" rows="2" placeholder="Descrizione opzionale..." 
                                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"></textarea>
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Icona</label>
                                <select id="icona-lista" class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                                    <option value="📚">📚 Libri</option>
                                    <option value="🔮">🔮 Esoterici</option>
                                    <option value="⭐">⭐ Preferiti</option>
                                    <option value="📖">📖 Da leggere</option>
                                    <option value="✨">✨ Speciali</option>
                                    <option value="🎯">🎯 Obiettivi</option>
                                    <option value="🏛️">🏛️ Massonici</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Colore</label>
                                <input type="color" id="colore-lista" value="#6366f1" 
                                       class="w-full h-10 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        <div class="flex items-center">
                            <input type="checkbox" id="privata-lista" class="mr-2 w-4 h-4 text-purple-600 border-gray-300 rounded">
                            <label for="privata-lista" class="text-sm text-gray-700">Lista privata (solo tu puoi vederla)</label>
                        </div>
                        <div class="flex space-x-2">
                            <button onclick="creaEAggiungiLista()" class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg">
                                💾 Crea e Aggiungi
                            </button>
                            <button onclick="nascondiFormNuovaLista()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg">
                                ❌ Annulla
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Variabile globale per l'ID del libro corrente
        let currentBookId = <?= $libro['id'] ?>;
        
        // Funzioni per toggle form
        function toggleRecensioneForm() {
            const form = document.getElementById('recensione-form');
            form.classList.toggle('hidden');
        }

        function toggleLettoForm() {
            const form = document.getElementById('letto-form');
            form.classList.toggle('hidden');
        }
        
        // Funzione per aggiungere ai preferiti
        async function aggiungiAiPreferiti(libroId) {
            try {
                const response = await fetch('../api/preferiti.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        libro_id: libroId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('⭐ ' + data.message);
                    // Cambia il pulsante in "Rimuovi dai preferiti"
                    const btn = document.getElementById('btn-preferiti');
                    btn.textContent = '⭐ Nei Preferiti';
                    btn.classList.remove('bg-blue-500', 'hover:bg-blue-600');
                    btn.classList.add('bg-gray-500', 'hover:bg-gray-600');
                    btn.onclick = () => rimuoviDaiPreferiti(libroId);
                } else {
                    alert('❌ ' + (data.message || 'Errore nell\'aggiunta ai preferiti'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }
        
        // Funzione per rimuovere dai preferiti
        async function rimuoviDaiPreferiti(libroId) {
            try {
                const response = await fetch('../api/preferiti.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        libro_id: libroId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('✅ ' + data.message);
                    // Ripristina il pulsante
                    const btn = document.getElementById('btn-preferiti');
                    btn.textContent = '⭐ Aggiungi ai Preferiti';
                    btn.classList.remove('bg-gray-500', 'hover:bg-gray-600');
                    btn.classList.add('bg-blue-500', 'hover:bg-blue-600');
                    btn.onclick = () => aggiungiAiPreferiti(libroId);
                } else {
                    alert('❌ ' + (data.message || 'Errore nella rimozione dai preferiti'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }
        
        // Funzione per mostrare modal liste
        async function mostraModalListe(libroId) {
            currentBookId = libroId;
            document.getElementById('modalListe').classList.remove('hidden');
            await caricaListe();
        }
        
        // Funzione per chiudere modal liste
        function closeModalListe() {
            document.getElementById('modalListe').classList.add('hidden');
            nascondiFormNuovaLista();
        }
        
        // Funzione per caricare le liste
        async function caricaListe() {
            console.log('🔄 Caricamento liste...');
            try {
                const response = await fetch('../api/liste.php');
                console.log('📡 Response status:', response.status);
                const data = await response.json();
                console.log('📦 Dati ricevuti:', data);

                if (data.success) {
                    const container = document.getElementById('liste-container');

                    if (data.liste.length === 0) {
                        console.log('ℹ️ Nessuna lista trovata');
                        container.innerHTML = '<p class="text-gray-500 text-center py-4">Non hai ancora creato nessuna lista. Crea la tua prima lista!</p>';
                    } else {
                        console.log('✅ Trovate', data.liste.length, 'liste');
                        container.innerHTML = data.liste.map(lista => `
                            <button onclick="aggiungiALista(${lista.id}, '${lista.nome.replace(/'/g, "\\'")}')"
                                    class="w-full text-left p-4 border border-gray-200 rounded-lg hover:bg-gray-50 transition">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3">
                                        <span class="text-2xl">${lista.icona}</span>
                                        <div>
                                            <div class="font-medium text-gray-900">${lista.nome}</div>
                                            <div class="text-sm text-gray-500">${lista.num_libri} libri</div>
                                        </div>
                                    </div>
                                    <span class="text-green-600">➕</span>
                                </div>
                            </button>
                        `).join('');
                    }
                } else {
                    console.error('❌ Errore API:', data.message);
                    alert('❌ Errore nel caricamento delle liste: ' + (data.message || 'Errore sconosciuto'));
                }
            } catch (error) {
                console.error('❌ Errore caricamento liste:', error);
                alert('❌ Errore di connessione: ' + error.message);
            }
        }
        
        // Funzione per aggiungere a una lista esistente
        async function aggiungiALista(listaId, nomeLista) {
            try {
                const response = await fetch('../api/liste.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'aggiungi_libro',
                        lista_id: listaId,
                        libro_id: currentBookId
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    alert('📋 ' + data.message);
                    closeModalListe();
                } else {
                    alert('❌ ' + (data.message || 'Errore nell\'aggiunta alla lista'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }
        
        // Funzione per mostrare form nuova lista
        function mostraFormNuovaLista() {
            document.getElementById('form-nuova-lista').classList.remove('hidden');
        }
        
        // Funzione per nascondere form nuova lista
        function nascondiFormNuovaLista() {
            document.getElementById('form-nuova-lista').classList.add('hidden');
            document.getElementById('nome-lista').value = '';
            document.getElementById('descrizione-lista').value = '';
        }
        
        // Funzione per creare nuova lista e aggiungere il libro
        async function creaEAggiungiLista() {
            const nome = document.getElementById('nome-lista').value.trim();
            const descrizione = document.getElementById('descrizione-lista').value.trim();
            const icona = document.getElementById('icona-lista').value;
            const colore = document.getElementById('colore-lista').value;
            const privata = document.getElementById('privata-lista').checked;

            console.log('📝 Tentativo creazione lista:', { nome, descrizione, icona, colore, privata });

            if (!nome) {
                alert('❌ Inserisci un nome per la lista');
                return;
            }

            try {
                // Prima crea la lista
                console.log('🔄 Step 1: Creazione lista...');
                const requestData = {
                    action: 'crea_lista',
                    nome: nome,
                    descrizione: descrizione,
                    icona: icona,
                    colore: colore,
                    privata: privata
                };
                console.log('📤 Dati inviati:', requestData);

                const responseCreate = await fetch('../api/liste.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(requestData)
                });

                console.log('📡 Response status (create):', responseCreate.status);
                const dataCreate = await responseCreate.json();
                console.log('📦 Response data (create):', dataCreate);

                if (dataCreate.success) {
                    console.log('✅ Lista creata con ID:', dataCreate.lista_id);

                    // Poi aggiungi il libro alla lista
                    console.log('🔄 Step 2: Aggiunta libro alla lista...');
                    const addRequestData = {
                        action: 'aggiungi_libro',
                        lista_id: dataCreate.lista_id,
                        libro_id: currentBookId
                    };
                    console.log('📤 Dati inviati:', addRequestData);

                    const responseAdd = await fetch('../api/liste.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(addRequestData)
                    });

                    console.log('📡 Response status (add):', responseAdd.status);
                    const dataAdd = await responseAdd.json();
                    console.log('📦 Response data (add):', dataAdd);

                    if (dataAdd.success) {
                        console.log('✅ Libro aggiunto alla lista con successo!');
                        alert('✅ Lista creata e libro aggiunto!');
                        closeModalListe();
                    } else {
                        console.warn('⚠️ Lista creata ma errore aggiunta libro:', dataAdd.message);
                        alert('⚠️ Lista creata ma errore nell\'aggiunta del libro: ' + dataAdd.message);
                        closeModalListe();
                    }
                } else {
                    console.error('❌ Errore creazione lista:', dataCreate.message);
                    alert('❌ ' + (dataCreate.message || 'Errore nella creazione della lista'));
                }
            } catch (error) {
                console.error('❌ Errore completo:', error);
                console.error('Stack trace:', error.stack);
                alert('❌ Errore di connessione: ' + error.message);
            }
        }
        
        // Chiudi modal cliccando fuori
        document.getElementById('modalListe').addEventListener('click', function(e) {
            if (e.target === this) closeModalListe();
        });
        
        // Chiudi modal con ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('modalListe').classList.contains('hidden')) {
                closeModalListe();
            }
        });

        // Funzione per confermare eliminazione recensione
        function confermaEliminazioneRecensione(nomeUtente, titoloRecensione) {
            const conferma = confirm(
                `⚠️ ELIMINAZIONE RECENSIONE\n\n` +
                `Utente: ${nomeUtente}\n` +
                `Recensione: ${titoloRecensione || 'Senza titolo'}\n\n` +
                `Sei sicuro di voler eliminare questa recensione?\n` +
                `Questa azione non può essere annullata.`
            );
            
            if (conferma) {
                const secondaConferma = confirm(
                    `🔒 CONFERMA FINALE\n\n` +
                    `Confermi definitivamente l'eliminazione della recensione?\n` +
                    `L'operazione verrà registrata nei log di sistema.`
                );
                return secondaConferma;
            }
            
            return false;
        }

        // Gestione form di eliminazione recensioni
        document.addEventListener('DOMContentLoaded', function() {
            const deleteRecensioneForms = document.querySelectorAll('.recensione-delete-form');
            
            deleteRecensioneForms.forEach(form => {
                form.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const nomeUtente = this.querySelector('input[name="nome_utente"]').value;
                    const titoloRecensione = this.querySelector('input[name="titolo_recensione"]').value;
                    
                    if (confermaEliminazioneRecensione(nomeUtente, titoloRecensione)) {
                        this.submit();
                    }
                });
            });
        });

        // Auto-hide messaggi di successo
        document.addEventListener('DOMContentLoaded', function() {
            const successMessages = document.querySelectorAll('.bg-green-100, .bg-blue-100, .bg-red-100');
            
            successMessages.forEach(message => {
                setTimeout(() => {
                    message.style.transition = 'opacity 0.5s ease-out';
                    message.style.opacity = '0';
                    setTimeout(() => {
                        if (message.parentNode) {
                            message.parentNode.removeChild(message);
                        }
                    }, 500);
                }, 5000);
            });
        });

        console.log('✅ Libro dettaglio caricato - ID:', <?= $libro['id'] ?>);
        console.log('🔧 Controlli admin:', <?= $is_admin ? 'true' : 'false' ?>);
    </script>
</body>
</html>