<?php
session_start();
require_once '../../config/database.php';

// Verifica sessione
verificaSessioneAttiva();

// Verifica admin
$admin_ids = [16, 9, 12, 11]; // Paolo Gazzano, Luca, Emiliano, Francesco
$is_admin = isset($_SESSION['fratello_id']) && in_array($_SESSION['fratello_id'], $admin_ids);

if (!$is_admin) {
    header('Location: ../dashboard.php');
    exit;
}

$libro_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$libro_id) {
    header('Location: ../catalogo.php');
    exit;
}

$message = '';
$error = '';

// DEBUG: Mostra struttura tabella se richiesto
if (isset($_GET['debug_db'])) {
    echo "<h2>STRUTTURA TABELLA LIBRI:</h2>";
    $desc = $conn->query("DESCRIBE libri");
    echo "<table border='1' style='margin:20px;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
    while ($row = $desc->fetch_assoc()) {
        echo "<tr>";
        foreach ($row as $col) {
            echo "<td>" . htmlspecialchars($col ?? 'NULL') . "</td>";
        }
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h2>DATI LIBRO CORRENTE:</h2>";
    $libro_debug = $conn->query("SELECT * FROM libri WHERE id = $libro_id")->fetch_assoc();
    echo "<pre>" . print_r($libro_debug, true) . "</pre>";
    exit;
}

// Gestione POST
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'salva_libro') {
        // Log per debug
        error_log("MODIFICA LIBRO: POST ricevuto per libro ID $libro_id");
        error_log("MODIFICA LIBRO: Dati POST: " . print_r($_POST, true));
        
        // Sanitizzazione dati di base
        $titolo = trim($_POST['titolo'] ?? '');
        $autore = trim($_POST['autore'] ?? '');
        $categoria_id = !empty($_POST['categoria_id']) ? (int)$_POST['categoria_id'] : null;
        $isbn = trim($_POST['isbn'] ?? '') ?: null;
        $anno = !empty($_POST['anno_pubblicazione']) ? (int)$_POST['anno_pubblicazione'] : null;
        $lingua = $_POST['lingua'] ?? 'italiano';
        $grado = $_POST['grado_minimo'] ?? 'pubblico';
        $descrizione = trim($_POST['descrizione'] ?? '') ?: null;
        $stato = $_POST['stato'] ?? 'disponibile';
        $condizioni = $_POST['condizioni'] ?? 'buono';
        $copertina_url = trim($_POST['copertina_url'] ?? '') ?: null;
        $note = trim($_POST['note'] ?? '') ?: null;
        
        if (empty($titolo)) {
            $error = 'Il titolo è obbligatorio';
        } else {
            try {
                // STEP 1: Aggiorna i campi base del libro (sempre sicuri)
                $update_base_query = "UPDATE libri SET 
                    titolo = ?, 
                    autore = ?, 
                    categoria_id = ?, 
                    isbn = ?, 
                    anno_pubblicazione = ?, 
                    lingua = ?, 
                    grado_minimo = ?,
                    descrizione = ?, 
                    condizioni = ?, 
                    copertina_url = ?, 
                    note = ?
                    WHERE id = ?";
                
                $stmt = $conn->prepare($update_base_query);
                $stmt->bind_param("ssissssssssi", 
                    $titolo, $autore, $categoria_id, $isbn, $anno,
                    $lingua, $grado, $descrizione, $condizioni, 
                    $copertina_url, $note, $libro_id
                );
                
                if (!$stmt->execute()) {
                    throw new Exception("Errore aggiornamento campi base: " . $stmt->error);
                }
                
                error_log("MODIFICA LIBRO: Campi base aggiornati OK");
                
                // STEP 2: Gestione stato e prestito
                $stato_precedente = $conn->query("SELECT stato FROM libri WHERE id = $libro_id")->fetch_assoc()['stato'];
                error_log("MODIFICA LIBRO: Stato precedente: $stato_precedente, Nuovo stato: $stato");
                
                if ($stato === 'prestato') {
                    // Gestione prestito
                    $prestato_a_fratello_id = !empty($_POST['prestato_a_fratello_id']) ? (int)$_POST['prestato_a_fratello_id'] : null;
                    $data_prestito_corrente = !empty($_POST['data_prestito_corrente']) ? $_POST['data_prestito_corrente'] : date('Y-m-d');
                    $data_scadenza_corrente = !empty($_POST['data_scadenza_corrente']) ? $_POST['data_scadenza_corrente'] : date('Y-m-d', strtotime('+30 days'));
                    
                    if (!$prestato_a_fratello_id) {
                        throw new Exception('Devi selezionare a chi è prestato il libro');
                    }
                    
                    // Aggiorna stato e prestito
                    $update_prestito_query = "UPDATE libri SET 
                        stato = 'prestato',
                        prestato_a_fratello_id = ?,
                        data_prestito_corrente = ?,
                        data_scadenza_corrente = ?
                        WHERE id = ?";
                    
                    $stmt2 = $conn->prepare($update_prestito_query);
                    $stmt2->bind_param("issi", $prestato_a_fratello_id, $data_prestito_corrente, $data_scadenza_corrente, $libro_id);
                    
                    if (!$stmt2->execute()) {
                        throw new Exception("Errore aggiornamento prestito: " . $stmt2->error);
                    }
                    
                    error_log("MODIFICA LIBRO: Prestito aggiornato - Fratello: $prestato_a_fratello_id");
                    
                } else {
                    // Se non è prestato, resetta i campi prestito
                    $update_stato_query = "UPDATE libri SET 
                        stato = ?,
                        prestato_a_fratello_id = NULL,
                        data_prestito_corrente = NULL,
                        data_scadenza_corrente = NULL
                        WHERE id = ?";
                    
                    $stmt3 = $conn->prepare($update_stato_query);
                    $stmt3->bind_param("si", $stato, $libro_id);
                    
                    if (!$stmt3->execute()) {
                        throw new Exception("Errore aggiornamento stato: " . $stmt3->error);
                    }
                    
                    error_log("MODIFICA LIBRO: Stato aggiornato a: $stato, prestito resettato");
                }
                
                $message = '✅ Libro aggiornato con successo!';
                error_log("MODIFICA LIBRO: Aggiornamento completato con successo");
                
            } catch (Exception $e) {
                $error = 'Errore: ' . $e->getMessage();
                error_log("MODIFICA LIBRO: ERRORE - " . $e->getMessage());
            }
        }
        
    } elseif ($_POST['action'] == 'elimina_recensione') {
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
                    error_log("ADMIN: Recensione ID $recensione_id eliminata da admin ID " . $_SESSION['fratello_id'] . " per libro ID $libro_id (da modifica-libro.php)");
                    
                    $message = '🗑️ Recensione eliminata con successo!';
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

// Recupera dati libro CON informazioni prestito
$stmt = $conn->prepare("
    SELECT l.*, c.nome as categoria_nome, f.nome as prestato_a_nome 
    FROM libri l 
    LEFT JOIN categorie_libri c ON l.categoria_id = c.id 
    LEFT JOIN fratelli f ON l.prestato_a_fratello_id = f.id
    WHERE l.id = ?
");
$stmt->bind_param("i", $libro_id);
$stmt->execute();
$libro = $stmt->get_result()->fetch_assoc();

if (!$libro) {
    header('Location: ../catalogo.php');
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

// Recupera categorie
$categorie = $conn->query("SELECT id, nome FROM categorie_libri WHERE attiva = 1 ORDER BY nome")->fetch_all(MYSQLI_ASSOC);

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
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica Libro - R∴ L∴ Kilwinning</title>
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
                    <a href="../dashboard.php" class="hover:text-primary">🏠 Dashboard</a>
                    <span class="mx-2">→</span>
                    <a href="gestione-libri.php" class="hover:text-primary">⚙️ Gestione Libri</a>
                    <span class="mx-2">→</span>
                    <span class="text-gray-800">Modifica Libro</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">✏️ Modifica Libro</h1>
                <p class="text-gray-600">Aggiorna informazioni, gestisci prestiti e recensioni</p>
                <p class="text-sm text-gray-500 mt-1">ID: <?= $libro['id'] ?> | Admin: <?= htmlspecialchars($_SESSION['fratello_nome'] ?? 'Admin') ?></p>
            </div>
            <div class="flex flex-wrap gap-2 mt-4 md:mt-0">
                <a href="gestione-libri.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg transition">
                    📚 Gestione Libri
                </a>
                <a href="../libro-dettaglio.php?id=<?= $libro['id'] ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    👁️ Anteprima
                </a>
                <a href="?id=<?= $libro['id'] ?>&debug_db=1" class="bg-yellow-500 hover:bg-yellow-600 text-white px-3 py-2 rounded-lg transition text-sm">
                    🔍 Debug DB
                </a>
            </div>
        </div>
    </div>

    <!-- Messaggi -->
    <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Form di modifica -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Informazioni libro -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-xl font-bold text-gray-800 mb-6">📝 Informazioni Libro</h2>
                
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="action" value="salva_libro">
                    
                    <!-- Informazioni base -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Titolo *</label>
                            <input type="text" name="titolo" value="<?= htmlspecialchars($libro['titolo']) ?>" 
                                   required class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Autore</label>
                            <input type="text" name="autore" value="<?= htmlspecialchars($libro['autore'] ?? '') ?>" 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Categoria</label>
                            <select name="categoria_id" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="">-- Nessuna categoria --</option>
                                <?php foreach ($categorie as $categoria): ?>
                                    <option value="<?= $categoria['id'] ?>" <?= $libro['categoria_id'] == $categoria['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($categoria['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">ISBN</label>
                            <input type="text" name="isbn" value="<?= htmlspecialchars($libro['isbn'] ?? '') ?>" 
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                    </div>

                    <!-- Copertina URL -->
                    <div class="bg-blue-50 p-4 rounded-lg border border-blue-200">
                        <h3 class="text-lg font-semibold text-blue-800 mb-3">🖼️ URL Copertina</h3>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">URL Immagine Copertina</label>
                            <input type="url" name="copertina_url" id="copertina_url" 
                                   value="<?= htmlspecialchars($libro['copertina_url'] ?? '') ?>" 
                                   placeholder="https://esempio.com/copertina.jpg"
                                   class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <div class="flex flex-wrap gap-2 mt-2">
                                <button type="button" onclick="cercaCopertinaGoogle()" 
                                        class="bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded text-sm transition">
                                    🔍 Cerca su Google
                                </button>
                                <button type="button" onclick="testCopertina()" 
                                        class="bg-green-500 hover:bg-green-600 text-white px-3 py-1 rounded text-sm transition">
                                    👁️ Anteprima
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Altri campi -->
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Anno Pubblicazione</label>
                            <input type="number" name="anno_pubblicazione" value="<?= $libro['anno_pubblicazione'] ?? '' ?>" 
                                   min="1000" max="2030" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Lingua</label>
                            <select name="lingua" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                                <option value="italiano" <?= ($libro['lingua'] ?? 'italiano') == 'italiano' ? 'selected' : '' ?>>Italiano</option>
                                <option value="inglese" <?= ($libro['lingua'] ?? '') == 'inglese' ? 'selected' : '' ?>>Inglese</option>
                                <option value="francese" <?= ($libro['lingua'] ?? '') == 'francese' ? 'selected' : '' ?>>Francese</option>
                                <option value="spagnolo" <?= ($libro['lingua'] ?? '') == 'spagnolo' ? 'selected' : '' ?>>Spagnolo</option>
                                <option value="tedesco" <?= ($libro['lingua'] ?? '') == 'tedesco' ? 'selected' : '' ?>>Tedesco</option>
                                <option value="altro" <?= ($libro['lingua'] ?? '') == 'altro' ? 'selected' : '' ?>>Altro</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Grado Minimo</label>
                        <select name="grado_minimo" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent">
                            <option value="pubblico" <?= ($libro['grado_minimo'] ?? 'pubblico') == 'pubblico' ? 'selected' : '' ?>>🌍 Pubblico</option>
                            <option value="Apprendista" <?= ($libro['grado_minimo'] ?? '') == 'Apprendista' ? 'selected' : '' ?>>🔺 Apprendista</option>
                            <option value="Compagno" <?= ($libro['grado_minimo'] ?? '') == 'Compagno' ? 'selected' : '' ?>>🔷 Compagno</option>
                            <option value="Maestro" <?= ($libro['grado_minimo'] ?? '') == 'Maestro' ? 'selected' : '' ?>>🔶 Maestro</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Descrizione</label>
                        <textarea name="descrizione" rows="3" 
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"><?= htmlspecialchars($libro['descrizione'] ?? '') ?></textarea>
                    </div>

                    <!-- SEZIONE STATO E PRESTITO -->
                    <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-200">
                        <h3 class="text-lg font-semibold text-yellow-800 mb-3">📊 Stato e Prestito</h3>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Stato Libro</label>
                                <select name="stato" onchange="togglePrestitoFields()" id="stato_select" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                    <option value="disponibile" <?= ($libro['stato'] ?? 'disponibile') == 'disponibile' ? 'selected' : '' ?>>📗 Disponibile</option>
                                    <option value="prestato" <?= ($libro['stato'] ?? '') == 'prestato' ? 'selected' : '' ?>>📘 Prestato</option>
                                    <option value="manutenzione" <?= ($libro['stato'] ?? '') == 'manutenzione' ? 'selected' : '' ?>>🔧 Manutenzione</option>
                                    <option value="perso" <?= ($libro['stato'] ?? '') == 'perso' ? 'selected' : '' ?>>❌ Perso</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Condizioni</label>
                                <select name="condizioni" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                    <option value="ottimo" <?= ($libro['condizioni'] ?? 'buono') == 'ottimo' ? 'selected' : '' ?>>⭐ Ottimo</option>
                                    <option value="buono" <?= ($libro['condizioni'] ?? 'buono') == 'buono' ? 'selected' : '' ?>>✅ Buono</option>
                                    <option value="discreto" <?= ($libro['condizioni'] ?? '') == 'discreto' ? 'selected' : '' ?>>⚠️ Discreto</option>
                                    <option value="da_riparare" <?= ($libro['condizioni'] ?? '') == 'da_riparare' ? 'selected' : '' ?>>🔧 Da riparare</option>
                                </select>
                            </div>
                        </div>

                        <!-- PRESTITO - Visibile solo se stato = prestato -->
                        <div id="prestito_section" class="<?= ($libro['stato'] ?? '') == 'prestato' ? '' : 'hidden' ?> bg-white p-4 rounded-lg border border-yellow-300">
                            <h4 class="font-semibold text-yellow-800 mb-3">📘 Informazioni Prestito</h4>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Prestato a *</label>
                                    <select name="prestato_a_fratello_id" class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                        <option value="">-- Seleziona fratello --</option>
                                        <?php
                                        // Recupera tutti i fratelli attivi
                                        $fratelli_query = "SELECT id, nome, grado FROM fratelli WHERE attivo = 1 ORDER BY nome";
                                        $fratelli_result = $conn->query($fratelli_query);
                                        if ($fratelli_result) {
                                            while ($fratello = $fratelli_result->fetch_assoc()) {
                                                $selected = ($libro['prestato_a_fratello_id'] ?? '') == $fratello['id'] ? 'selected' : '';
                                                echo "<option value=\"{$fratello['id']}\" $selected>";
                                                echo htmlspecialchars($fratello['nome']) . " (" . htmlspecialchars($fratello['grado']) . ")";
                                                echo "</option>";
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Prestito</label>
                                    <input type="date" name="data_prestito_corrente" 
                                           value="<?= ($libro['data_prestito_corrente'] ?? '') ? date('Y-m-d', strtotime($libro['data_prestito_corrente'])) : date('Y-m-d') ?>"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-2">Data Scadenza</label>
                                    <input type="date" name="data_scadenza_corrente" 
                                           value="<?= ($libro['data_scadenza_corrente'] ?? '') ? date('Y-m-d', strtotime($libro['data_scadenza_corrente'])) : date('Y-m-d', strtotime('+30 days')) ?>"
                                           class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-500 focus:border-transparent">
                                </div>
                                
                                <div class="flex items-end">
                                    <button type="button" onclick="calcolaScadenza()" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-3 rounded-lg text-sm transition">
                                        📅 +30 giorni
                                    </button>
                                </div>
                            </div>
                            
                            <?php if (($libro['stato'] ?? '') == 'prestato' && ($libro['prestato_a_nome'] ?? '')): ?>
                                <div class="mt-4 p-3 bg-blue-50 rounded-lg">
                                    <p class="text-sm text-blue-800">
                                        <strong>Attualmente prestato a:</strong> <?= htmlspecialchars($libro['prestato_a_nome']) ?>
                                        <?php if ($libro['data_scadenza_corrente'] ?? ''): ?>
                                            <br><strong>Scadenza:</strong> <?= date('d/m/Y', strtotime($libro['data_scadenza_corrente'])) ?>
                                            <?php 
                                            $giorni_rimasti = floor((strtotime($libro['data_scadenza_corrente']) - time()) / 86400);
                                            if ($giorni_rimasti < 0): ?>
                                                <span class="text-red-600 font-semibold"> (Scaduto da <?= abs($giorni_rimasti) ?> giorni)</span>
                                            <?php elseif ($giorni_rimasti <= 3): ?>
                                                <span class="text-orange-600 font-semibold"> (Scade tra <?= $giorni_rimasti ?> giorni)</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Note</label>
                        <textarea name="note" rows="2" 
                                  class="w-full p-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-primary focus:border-transparent"><?= htmlspecialchars($libro['note'] ?? '') ?></textarea>
                    </div>

                    <!-- Pulsanti -->
                    <div class="flex flex-wrap gap-3 pt-4 border-t">
                        <button type="submit" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition font-medium">
                            💾 Salva Tutte le Modifiche
                        </button>
                        <a href="gestione-libri.php" class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg transition">
                            ↩️ Torna alla Gestione
                        </a>
                        <a href="../libro-dettaglio.php?id=<?= $libro['id'] ?>" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                            👁️ Anteprima Pubblica
                        </a>
                    </div>
                </form>
            </div>

            <!-- NUOVA SEZIONE: Gestione Recensioni -->
            <div class="bg-white rounded-xl shadow-lg p-6">
                <div class="flex justify-between items-center mb-6">
                    <h2 class="text-xl font-bold text-gray-800">📝 Gestione Recensioni (<?= count($recensioni) ?>)</h2>
                    <div class="text-sm text-gray-600">
                        <span class="bg-red-100 text-red-800 px-2 py-1 rounded">Solo Admin</span>
                    </div>
                </div>

                <?php if (empty($recensioni)): ?>
                    <div class="text-center py-12 bg-gray-50 rounded-lg">
                        <div class="text-gray-500 text-lg mb-2">📝</div>
                        <p class="text-gray-600">Nessuna recensione disponibile per questo libro.</p>
                    </div>
                <?php else: ?>
                    <div class="space-y-4">
                        <?php foreach ($recensioni as $recensione): ?>
                            <div class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition">
                                <div class="flex justify-between items-start">
                                    <div class="flex-1">
                                        <div class="flex items-center justify-between mb-2">
                                            <h4 class="font-semibold text-gray-800 flex items-center">
                                                <?= htmlspecialchars($recensione['fratello_nome']) ?>
                                                <span class="ml-2 text-xs bg-blue-100 text-blue-800 px-2 py-1 rounded">
                                                    <?= htmlspecialchars($recensione['fratello_grado']) ?>
                                                </span>
                                            </h4>
                                            <div class="flex items-center space-x-2">
                                                <?= mostraStelle($recensione['valutazione'], 'text-sm') ?>
                                                <span class="text-gray-500 text-sm">
                                                    <?= date('d/m/Y', strtotime($recensione['created_at'])) ?>
                                                </span>
                                                <?php if ($recensione['consigliato']): ?>
                                                    <span class="text-green-600 text-sm">👍 Consigliato</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($recensione['titolo']): ?>
                                            <h5 class="font-medium text-gray-800 mb-2">
                                                "<?= htmlspecialchars($recensione['titolo']) ?>"
                                            </h5>
                                        <?php endif; ?>
                                        
                                        <?php if ($recensione['contenuto']): ?>
                                            <p class="text-gray-700 leading-relaxed text-sm">
                                                <?= nl2br(htmlspecialchars($recensione['contenuto'])) ?>
                                            </p>
                                        <?php endif; ?>
                                        
                                        <div class="mt-3 flex items-center justify-between">
                                            <div class="text-xs text-gray-500">
                                                ID Recensione: #<?= $recensione['id'] ?>
                                                <?php if ($recensione['stato_lettura'] == 'completato'): ?>
                                                    | <span class="text-green-600">📚 Ha letto il libro</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Pulsante eliminazione admin -->
                                    <div class="ml-4 flex-shrink-0">
                                        <form method="POST" class="inline recensione-delete-form">
                                            <input type="hidden" name="action" value="elimina_recensione">
                                            <input type="hidden" name="recensione_id" value="<?= $recensione['id'] ?>">
                                            <input type="hidden" name="fratello_recensore_id" value="<?= $recensione['fratello_id'] ?>">
                                            <input type="hidden" name="nome_utente" value="<?= htmlspecialchars($recensione['fratello_nome']) ?>">
                                            <input type="hidden" name="titolo_recensione" value="<?= htmlspecialchars($recensione['titolo'] ?? '') ?>">
                                            <button type="submit" 
                                                    class="bg-red-500 hover:bg-red-600 text-white px-3 py-2 rounded-lg text-sm transition flex items-center"
                                                    title="Elimina recensione (Solo Admin)">
                                                🗑️ Elimina
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <!-- Statistiche recensioni -->
                    <div class="mt-6 bg-gray-50 rounded-lg p-4">
                        <h3 class="font-semibold text-gray-800 mb-2">📊 Statistiche Recensioni</h3>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                            <div class="text-center">
                                <div class="text-lg font-bold text-blue-600"><?= count($recensioni) ?></div>
                                <div class="text-gray-600">Totale</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-green-600">
                                    <?= count(array_filter($recensioni, fn($r) => $r['consigliato'])) ?>
                                </div>
                                <div class="text-gray-600">Consigliate</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-yellow-600">
                                    <?= count($recensioni) > 0 ? number_format(array_sum(array_column($recensioni, 'valutazione')) / count($recensioni), 1) : '0' ?>
                                </div>
                                <div class="text-gray-600">Voto Medio</div>
                            </div>
                            <div class="text-center">
                                <div class="text-lg font-bold text-purple-600">
                                    <?= count(array_filter($recensioni, fn($r) => $r['valutazione'] >= 4)) ?>
                                </div>
                                <div class="text-gray-600">Positive (4-5★)</div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- Avviso per admin -->
                <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                    <h3 class="font-semibold text-yellow-800 mb-2">⚠️ Avviso Admin</h3>
                    <div class="text-sm text-yellow-700 space-y-1">
                        <p>• Puoi eliminare qualsiasi recensione usando il pulsante <strong class="text-red-600">🗑️ Elimina</strong></p>
                        <p>• Ogni eliminazione richiede doppia conferma per sicurezza</p>
                        <p>• Tutte le operazioni vengono registrate nei log di sistema</p>
                        <p>• Elimina solo recensioni inappropriate, spam o offensive</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Anteprima libro VERTICALE -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-lg p-6 sticky top-4">
                <h3 class="text-lg font-bold text-gray-800 mb-4">👁️ Anteprima Live</h3>
                
                <!-- Copertina VERTICALE -->
                <div class="mb-4 flex justify-center">
                    <?php if (!empty($libro['copertina_url'])): ?>
                        <div class="w-40 h-56 rounded-lg overflow-hidden shadow-lg border border-gray-200">
                            <img id="preview-cover" src="<?= htmlspecialchars($libro['copertina_url']) ?>" 
                                 alt="Copertina" class="w-full h-full object-cover"
                                 onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                            <div class="w-full h-full bg-gray-200 rounded-lg items-center justify-center hidden">
                                <div class="text-center text-gray-500">
                                    <div class="text-2xl mb-1">❌</div>
                                    <div class="text-xs">URL non valido</div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div id="preview-cover-placeholder" class="w-40 h-56 bg-gray-200 rounded-lg flex items-center justify-center shadow-lg border border-gray-200">
                            <div class="text-center text-gray-500">
                                <div class="text-2xl mb-1">📖</div>
                                <div class="text-xs">Nessuna copertina</div>
                            </div>
                        </div>
                        <img id="preview-cover" class="w-40 h-56 object-cover rounded-lg shadow-lg border border-gray-200 hidden">
                    <?php endif; ?>
                </div>
                
                <!-- Info libro -->
                <div class="space-y-2 text-sm">
                    <h4 class="font-semibold text-gray-900"><?= htmlspecialchars($libro['titolo']) ?></h4>
                    <?php if ($libro['autore']): ?>
                        <p class="text-gray-600">di <?= htmlspecialchars($libro['autore']) ?></p>
                    <?php endif; ?>
                    <?php if ($libro['categoria_nome']): ?>
                        <p class="text-gray-500">📚 <?= htmlspecialchars($libro['categoria_nome']) ?></p>
                    <?php endif; ?>
                    <?php if ($libro['anno_pubblicazione']): ?>
                        <p class="text-gray-500">📅 <?= $libro['anno_pubblicazione'] ?></p>
                    <?php endif; ?>
                    
                    <div class="pt-2 border-t">
                        <p class="text-xs text-gray-400">ID: <?= $libro['id'] ?></p>
                        <p class="text-xs text-gray-400">Stato: <?= ucfirst($libro['stato'] ?? 'disponibile') ?></p>
                        <p class="text-xs text-gray-400">Condizioni: <?= ucfirst($libro['condizioni'] ?? 'buono') ?></p>
                        <p class="text-xs text-gray-400">Recensioni: <?= count($recensioni) ?></p>
                        <?php if (($libro['stato'] ?? '') == 'prestato' && ($libro['prestato_a_nome'] ?? '')): ?>
                            <p class="text-xs text-orange-600 font-medium">
                                📘 Prestato a <?= htmlspecialchars($libro['prestato_a_nome']) ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Link rapidi -->
                <div class="mt-4 pt-4 border-t space-y-2">
                    <a href="../libro-dettaglio.php?id=<?= $libro['id'] ?>" 
                       class="block w-full bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg text-center text-sm transition">
                        👁️ Vedi Dettaglio Pubblico
                    </a>
                    <a href="gestione-prestiti.php" 
                       class="block w-full bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg text-center text-sm transition">
                        📋 Gestione Prestiti
                    </a>
                    <a href="../catalogo.php" 
                       class="block w-full bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-lg text-center text-sm transition">
                        📚 Vai al Catalogo
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script>
        function cercaCopertinaGoogle() {
            const titolo = document.querySelector('input[name="titolo"]').value;
            const autore = document.querySelector('input[name="autore"]').value;
            const query = `${titolo} ${autore} copertina libro`.trim();
            const url = `https://www.google.com/search?q=${encodeURIComponent(query)}&tbm=isch`;
            window.open(url, '_blank');
        }

        function testCopertina() {
            const url = document.getElementById('copertina_url').value.trim();
            if (!url) {
                alert('Inserisci prima un URL!');
                return;
            }
            
            if (!isValidUrl(url)) {
                alert('URL non valido!');
                return;
            }

            updatePreview(url);
            alert('✅ Anteprima aggiornata! Controlla la sidebar a destra.');
        }

        function updatePreview(url) {
            const previewImg = document.getElementById('preview-cover');
            const placeholder = document.getElementById('preview-cover-placeholder');
            
            if (url) {
                previewImg.src = url;
                previewImg.classList.remove('hidden');
                previewImg.className = 'w-40 h-56 object-cover rounded-lg shadow-lg border border-gray-200';
                if (placeholder) placeholder.classList.add('hidden');
            } else {
                previewImg.classList.add('hidden');
                if (placeholder) {
                    placeholder.classList.remove('hidden');
                    placeholder.className = 'w-40 h-56 bg-gray-200 rounded-lg flex items-center justify-center shadow-lg border border-gray-200';
                }
            }
        }

        function isValidUrl(string) {
            try {
                new URL(string);
                return true;
            } catch (_) {
                return false;
            }
        }

        // Gestione sezione prestito
        function togglePrestitoFields() {
            const stato = document.getElementById('stato_select').value;
            const prestitoSection = document.getElementById('prestito_section');
            
            if (stato === 'prestato') {
                prestitoSection.classList.remove('hidden');
                // Auto-compila date se vuote
                const dataOggi = new Date().toISOString().split('T')[0];
                const dataScadenza = new Date();
                dataScadenza.setDate(dataScadenza.getDate() + 30);
                const dataScadenzaStr = dataScadenza.toISOString().split('T')[0];
                
                const inputPrestito = document.querySelector('input[name="data_prestito_corrente"]');
                const inputScadenza = document.querySelector('input[name="data_scadenza_corrente"]');
                
                if (!inputPrestito.value) inputPrestito.value = dataOggi;
                if (!inputScadenza.value) inputScadenza.value = dataScadenzaStr;
            } else {
                prestitoSection.classList.add('hidden');
                // Resetta i campi del prestito
                document.querySelector('select[name="prestato_a_fratello_id"]').value = '';
                document.querySelector('input[name="data_prestito_corrente"]').value = '';
                document.querySelector('input[name="data_scadenza_corrente"]').value = '';
            }
        }

        function calcolaScadenza() {
            const dataPrestito = document.querySelector('input[name="data_prestito_corrente"]').value;
            if (dataPrestito) {
                const data = new Date(dataPrestito);
                data.setDate(data.getDate() + 30);
                document.querySelector('input[name="data_scadenza_corrente"]').value = data.toISOString().split('T')[0];
            } else {
                const oggi = new Date();
                oggi.setDate(oggi.getDate() + 30);
                document.querySelector('input[name="data_scadenza_corrente"]').value = oggi.toISOString().split('T')[0];
            }
        }

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

        // Auto-update preview
        document.getElementById('copertina_url').addEventListener('input', function() {
            const url = this.value.trim();
            if (url && isValidUrl(url)) {
                setTimeout(() => updatePreview(url), 1000);
            }
        });

        // Conferma salvataggio
        document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
            // Se è il form di salvataggio libro
            if (this.querySelector('input[name="action"][value="salva_libro"]')) {
                const titolo = document.querySelector('input[name="titolo"]').value;
                const stato = document.getElementById('stato_select').value;
                
                if (stato === 'prestato') {
                    const fratello = document.querySelector('select[name="prestato_a_fratello_id"]').value;
                    if (!fratello) {
                        alert('⚠️ ATTENZIONE: Se il libro è prestato, devi selezionare a chi è stato prestato!');
                        e.preventDefault();
                        return;
                    }
                }
                
                if (!confirm(`Confermi le modifiche al libro "${titolo}"?`)) {
                    e.preventDefault();
                }
            }
        });

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

        // Auto-hide messaggi
        setTimeout(function() {
            const successMsg = document.querySelector('.bg-green-100');
            if (successMsg) {
                successMsg.style.transition = 'opacity 0.5s';
                successMsg.style.opacity = '0';
                setTimeout(() => successMsg.remove(), 500);
            }
        }, 5000);

        // Inizializza stato prestito al caricamento
        document.addEventListener('DOMContentLoaded', function() {
            togglePrestitoFields();
        });

        console.log('✅ Modifica libro con gestione recensioni caricato - ID:', <?= $libro['id'] ?>);
        console.log('📝 Recensioni trovate:', <?= count($recensioni) ?>);
        console.log('🔧 Controlli admin attivi');
    </script>
</body>
</html>