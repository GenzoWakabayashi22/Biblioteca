<?php
session_start();
require_once '../config/database.php';

// Verifica autenticazione
verificaSessioneAttiva();

$user_id = $_SESSION['fratello_id'];
$user_name = $_SESSION['nome'] ?? 'Utente';

// Recupera le liste dell'utente
$query_liste = "
    SELECT ll.*, COUNT(DISTINCT llb.libro_id) as num_libri
    FROM liste_lettura ll
    LEFT JOIN lista_libri llb ON ll.id = llb.lista_id
    WHERE ll.fratello_id = ?
    GROUP BY ll.id
    ORDER BY ll.data_modifica DESC
";
$stmt = $conn->prepare($query_liste);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$liste = [];
while ($row = $result->fetch_assoc()) {
    $liste[] = $row;
}

// Se è specificata una lista, recupera i libri
$lista_selezionata = null;
$libri_lista = [];
if (isset($_GET['lista_id'])) {
    $lista_id = (int)$_GET['lista_id'];
    
    // Recupera dettagli lista
    $stmt = $conn->prepare("SELECT * FROM liste_lettura WHERE id = ? AND fratello_id = ?");
    $stmt->bind_param("ii", $lista_id, $user_id);
    $stmt->execute();
    $lista_selezionata = $stmt->get_result()->fetch_assoc();
    
    if ($lista_selezionata) {
        // Recupera libri della lista
        $query_libri = "
            SELECT llb.*, l.titolo, l.autore, l.stato, l.copertina_url,
                   c.nome as categoria_nome, c.colore as categoria_colore,
                   COALESCE(AVG(r.valutazione), 0) as voto_medio
            FROM lista_libri llb
            INNER JOIN libri l ON llb.libro_id = l.id
            LEFT JOIN categorie_libri c ON l.categoria_id = c.id
            LEFT JOIN recensioni_libri r ON l.id = r.libro_id
            WHERE llb.lista_id = ?
            GROUP BY llb.id
            ORDER BY llb.posizione ASC, llb.data_aggiunta DESC
        ";
        $stmt = $conn->prepare($query_libri);
        $stmt->bind_param("i", $lista_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $libri_lista[] = $row;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Le Mie Liste - Biblioteca R∴ L∴ Kilwinning</title>
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
                    <span class="text-gray-800">Le Mie Liste</span>
                </nav>
                <h1 class="text-3xl font-bold text-gray-800">📚 Le Mie Liste di Lettura</h1>
                <p class="text-gray-600">Gestisci le tue liste personali di libri</p>
            </div>
            <div class="flex gap-2 mt-4 md:mt-0">
                <a href="preferiti.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                    ⭐ Preferiti
                </a>
                <button onclick="mostraFormNuovaLista()" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                    ➕ Nuova Lista
                </button>
            </div>
        </div>
    </div>

    <?php if (empty($liste)): ?>
        <!-- Stato vuoto -->
        <div class="bg-white rounded-2xl shadow-xl p-12 text-center">
            <div class="text-6xl mb-4">📚</div>
            <h2 class="text-2xl font-bold text-gray-800 mb-4">Nessuna lista ancora</h2>
            <p class="text-gray-600 mb-6">
                Crea la tua prima lista personale per organizzare i libri che vuoi leggere.<br>
                Puoi creare liste come "Libri da leggere", "Esoterici", "Filosofia" e molto altro!
            </p>
            <button onclick="mostraFormNuovaLista()" class="bg-green-500 hover:bg-green-600 text-white px-6 py-3 rounded-lg transition">
                ➕ Crea la Tua Prima Lista
            </button>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Sidebar con lista delle liste -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-lg p-6 sticky top-4">
                    <h3 class="text-lg font-bold text-gray-800 mb-4">Le Tue Liste (<?= count($liste) ?>)</h3>
                    
                    <div class="space-y-2 mb-4">
                        <?php foreach ($liste as $lista): ?>
                            <a href="?lista_id=<?= $lista['id'] ?>" 
                               class="block p-4 rounded-lg border transition <?= $lista_selezionata && $lista_selezionata['id'] == $lista['id'] ? 'bg-purple-50 border-purple-300' : 'border-gray-200 hover:bg-gray-50' ?>">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center space-x-3 flex-1">
                                        <span class="text-2xl"><?= htmlspecialchars($lista['icona']) ?></span>
                                        <div class="flex-1 min-w-0">
                                            <div class="font-medium text-gray-900 truncate"><?= htmlspecialchars($lista['nome']) ?></div>
                                            <div class="text-sm text-gray-500"><?= $lista['num_libri'] ?> libri</div>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    
                    <button onclick="mostraFormNuovaLista()" class="w-full bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg transition">
                        ➕ Nuova Lista
                    </button>
                </div>
            </div>

            <!-- Contenuto principale -->
            <div class="lg:col-span-2">
                <?php if (!$lista_selezionata): ?>
                    <div class="bg-white rounded-xl shadow-lg p-12 text-center">
                        <div class="text-5xl mb-4">👈</div>
                        <h3 class="text-xl font-bold text-gray-800 mb-2">Seleziona una lista</h3>
                        <p class="text-gray-600">Clicca su una lista a sinistra per vedere i libri al suo interno</p>
                    </div>
                <?php else: ?>
                    <div class="bg-white rounded-xl shadow-lg">
                        <!-- Header lista -->
                        <div class="p-6 border-b border-gray-200">
                            <div class="flex items-center justify-between mb-4">
                                <div class="flex items-center space-x-4">
                                    <span class="text-4xl"><?= htmlspecialchars($lista_selezionata['icona']) ?></span>
                                    <div>
                                        <h2 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($lista_selezionata['nome']) ?></h2>
                                        <?php if ($lista_selezionata['descrizione']): ?>
                                            <p class="text-gray-600"><?= htmlspecialchars($lista_selezionata['descrizione']) ?></p>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="flex space-x-2">
                                    <button onclick="modificaLista(<?= $lista_selezionata['id'] ?>)" 
                                            class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded-lg transition">
                                        ✏️ Modifica
                                    </button>
                                    <button onclick="eliminaLista(<?= $lista_selezionata['id'] ?>, '<?= htmlspecialchars($lista_selezionata['nome']) ?>')" 
                                            class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg transition">
                                        🗑️ Elimina
                                    </button>
                                </div>
                            </div>
                            
                            <div class="flex items-center space-x-4 text-sm text-gray-500">
                                <span><?= count($libri_lista) ?> libri</span>
                                <?php if ($lista_selezionata['privata']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full bg-gray-100">
                                        🔒 Privata
                                    </span>
                                <?php endif; ?>
                                <span>Creata il <?= date('d/m/Y', strtotime($lista_selezionata['data_creazione'])) ?></span>
                            </div>
                        </div>

                        <!-- Libri nella lista -->
                        <div class="p-6">
                            <?php if (empty($libri_lista)): ?>
                                <div class="text-center py-12">
                                    <div class="text-5xl mb-4">📖</div>
                                    <h3 class="text-xl font-bold text-gray-800 mb-2">Lista vuota</h3>
                                    <p class="text-gray-600 mb-6">Aggiungi libri a questa lista dalla pagina dei dettagli libro</p>
                                    <a href="catalogo.php" class="inline-block bg-primary hover:bg-blue-600 text-white px-6 py-3 rounded-lg transition">
                                        📚 Vai al Catalogo
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="space-y-4">
                                    <?php foreach ($libri_lista as $libro): ?>
                                        <div class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition">
                                            <div class="flex gap-4">
                                                <!-- Copertina -->
                                                <div class="flex-shrink-0 w-20 h-28 bg-gray-200 rounded overflow-hidden">
                                                    <?php if ($libro['copertina_url']): ?>
                                                        <img src="<?= htmlspecialchars($libro['copertina_url']) ?>" 
                                                             alt="Copertina" class="w-full h-full object-cover">
                                                    <?php else: ?>
                                                        <div class="w-full h-full flex items-center justify-center text-gray-400">
                                                            📖
                                                        </div>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Dettagli libro -->
                                                <div class="flex-1 min-w-0">
                                                    <h4 class="text-lg font-semibold text-gray-800 truncate">
                                                        <?= htmlspecialchars($libro['titolo']) ?>
                                                    </h4>
                                                    <p class="text-gray-600"><?= htmlspecialchars($libro['autore'] ?? 'Autore sconosciuto') ?></p>
                                                    
                                                    <div class="flex items-center space-x-3 mt-2">
                                                        <?php if ($libro['categoria_nome']): ?>
                                                            <span class="px-2 py-1 rounded-full text-xs text-white" 
                                                                  style="background-color: <?= $libro['categoria_colore'] ?? '#6366f1' ?>">
                                                                <?= htmlspecialchars($libro['categoria_nome']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        
                                                        <span class="px-2 py-1 rounded-full text-xs <?= $libro['stato'] == 'disponibile' ? 'bg-green-100 text-green-700' : 'bg-orange-100 text-orange-700' ?>">
                                                            <?= ucfirst($libro['stato']) ?>
                                                        </span>
                                                        
                                                        <?php if ($libro['voto_medio'] > 0): ?>
                                                            <span class="text-yellow-500 text-sm">
                                                                ⭐ <?= number_format($libro['voto_medio'], 1) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <?php if ($libro['note']): ?>
                                                        <p class="text-sm text-gray-500 mt-2 italic">"<?= htmlspecialchars($libro['note']) ?>"</p>
                                                    <?php endif; ?>
                                                    
                                                    <div class="flex space-x-2 mt-3">
                                                        <a href="libro-dettaglio.php?id=<?= $libro['libro_id'] ?>" 
                                                           class="text-primary hover:text-blue-700 text-sm font-medium">
                                                            👁️ Dettagli
                                                        </a>
                                                        <button onclick="rimuoviDaLista(<?= $lista_selezionata['id'] ?>, <?= $libro['libro_id'] ?>)" 
                                                                class="text-red-600 hover:text-red-700 text-sm font-medium">
                                                            🗑️ Rimuovi
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal Form Nuova Lista -->
    <div id="modalNuovaLista" class="hidden fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-11/12 md:w-1/2 shadow-lg rounded-md bg-white">
            <div class="mt-3">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-900">➕ Crea Nuova Lista</h3>
                    <button onclick="closeModalNuovaLista()" class="text-gray-400 hover:text-gray-600">
                        <span class="text-2xl">×</span>
                    </button>
                </div>
                
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome Lista *</label>
                        <input type="text" id="nome-lista" placeholder="es. Libri da leggere, Esoterici..." 
                               class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Descrizione</label>
                        <textarea id="descrizione-lista" rows="3" placeholder="Descrizione opzionale..." 
                                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
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
                                <option value="🔑">🔑 Simbolici</option>
                                <option value="📜">📜 Storici</option>
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
                        <label for="privata-lista" class="text-sm text-gray-700">🔒 Lista privata (solo tu puoi vederla)</label>
                    </div>
                    <div class="flex space-x-2 pt-4">
                        <button onclick="creaLista()" class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-3 rounded-lg">
                            💾 Crea Lista
                        </button>
                        <button onclick="closeModalNuovaLista()" class="flex-1 bg-gray-500 hover:bg-gray-600 text-white px-4 py-3 rounded-lg">
                            ❌ Annulla
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function mostraFormNuovaLista() {
            document.getElementById('modalNuovaLista').classList.remove('hidden');
        }

        function closeModalNuovaLista() {
            document.getElementById('modalNuovaLista').classList.add('hidden');
            document.getElementById('nome-lista').value = '';
            document.getElementById('descrizione-lista').value = '';
        }

        async function creaLista() {
            const nome = document.getElementById('nome-lista').value.trim();
            const descrizione = document.getElementById('descrizione-lista').value.trim();
            const icona = document.getElementById('icona-lista').value;
            const colore = document.getElementById('colore-lista').value;
            const privata = document.getElementById('privata-lista').checked;

            if (!nome) {
                alert('❌ Inserisci un nome per la lista');
                return;
            }

            try {
                const response = await fetch('../api/liste.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'crea_lista',
                        nome: nome,
                        descrizione: descrizione,
                        icona: icona,
                        colore: colore,
                        privata: privata
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Errore nella creazione della lista'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }

        async function eliminaLista(listaId, nomeLista) {
            if (!confirm(`Sei sicuro di voler eliminare la lista "${nomeLista}"?\n\nQuesta azione non può essere annullata.`)) {
                return;
            }

            try {
                const response = await fetch('../api/liste.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'elimina_lista',
                        lista_id: listaId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alert('✅ ' + data.message);
                    location.href = 'liste.php';
                } else {
                    alert('❌ ' + (data.message || 'Errore nell\'eliminazione della lista'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }

        async function rimuoviDaLista(listaId, libroId) {
            if (!confirm('Vuoi rimuovere questo libro dalla lista?')) {
                return;
            }

            try {
                const response = await fetch('../api/liste.php', {
                    method: 'DELETE',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'rimuovi_libro',
                        lista_id: listaId,
                        libro_id: libroId
                    })
                });

                const data = await response.json();

                if (data.success) {
                    alert('✅ ' + data.message);
                    location.reload();
                } else {
                    alert('❌ ' + (data.message || 'Errore nella rimozione del libro'));
                }
            } catch (error) {
                console.error('Error:', error);
                alert('❌ Errore di connessione');
            }
        }

        function modificaLista(listaId) {
            alert('Funzionalità di modifica in arrivo!');
            // TODO: Implementare modal di modifica
        }

        // Close modal on outside click
        document.getElementById('modalNuovaLista').addEventListener('click', function(e) {
            if (e.target === this) closeModalNuovaLista();
        });

        // Close modal on ESC
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && !document.getElementById('modalNuovaLista').classList.contains('hidden')) {
                closeModalNuovaLista();
            }
        });
    </script>
</body>
</html>
