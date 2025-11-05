<?php
/**
 * API Statistiche - Sistema Biblioteca
 * R∴ L∴ Kilwinning
 *
 * Endpoint per recuperare statistiche generali del sistema
 */

session_start();
require_once '../config/database.php';

// Headers per JSON
header('Content-Type: application/json');

// Verifica autenticazione
try {
    verificaSessioneAttiva();
} catch (Exception $e) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Sessione non valida o scaduta'
    ]);
    exit;
}

// Verifica metodo HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Metodo non consentito. Usare GET.'
    ]);
    exit;
}

try {
    // Recupera statistiche generali
    $stats_query = "
        SELECT
            (SELECT COUNT(*) FROM libri WHERE stato = 'disponibile') as libri_disponibili,
            (SELECT COUNT(*) FROM libri WHERE stato = 'prestato') as libri_prestati,
            (SELECT COUNT(*) FROM libri) as totale_libri,
            (SELECT COUNT(DISTINCT fratello_id) FROM (
                SELECT fratello_id FROM storico_prestiti WHERE data_restituzione IS NOT NULL
                UNION
                SELECT prestato_a_fratello_id as fratello_id FROM libri WHERE prestato_a_fratello_id IS NOT NULL
            ) as lettori) as fratelli_lettori,
            (SELECT COUNT(*) FROM categorie_libri WHERE attiva = 1) as categorie_attive,
            (SELECT COUNT(*) FROM recensioni_libri) as totale_recensioni
    ";

    $stats = getSingleResult($stats_query);

    // Statistiche aggiuntive per admin
    $is_admin = $_SESSION['is_admin'] ?? false;
    $admin_stats = null;

    if ($is_admin) {
        $admin_stats_query = "
            SELECT
                (SELECT COUNT(*) FROM libri WHERE DATEDIFF(data_scadenza_corrente, CURDATE()) <= 3 AND stato = 'prestato') as prestiti_in_scadenza,
                (SELECT COUNT(*) FROM libri WHERE stato = 'manutenzione') as libri_manutenzione,
                (SELECT COUNT(*) FROM libri WHERE data_prestito_corrente IS NOT NULL) as prestiti_attivi
        ";
        $admin_stats = getSingleResult($admin_stats_query);
    }

    // Libri più popolari (ultimi 30 giorni)
    $libri_popolari = getAllResults("
        SELECT l.id, l.titolo, l.autore, COUNT(ll.id) as letture_recenti
        FROM libri l
        INNER JOIN libri_letti ll ON l.id = ll.libro_id
        WHERE ll.data_lettura >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY l.id, l.titolo, l.autore
        ORDER BY letture_recenti DESC
        LIMIT 5
    ");

    // Prepara risposta
    $response = [
        'success' => true,
        'data' => [
            'general' => [
                'libri_disponibili' => (int)$stats['libri_disponibili'],
                'libri_prestati' => (int)$stats['libri_prestati'],
                'totale_libri' => (int)$stats['totale_libri'],
                'fratelli_lettori' => (int)$stats['fratelli_lettori'],
                'categorie_attive' => (int)$stats['categorie_attive'],
                'totale_recensioni' => (int)$stats['totale_recensioni']
            ],
            'libri_popolari' => $libri_popolari,
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ];

    // Aggiungi statistiche admin se disponibili
    if ($admin_stats) {
        $response['data']['admin'] = [
            'prestiti_in_scadenza' => (int)$admin_stats['prestiti_in_scadenza'],
            'libri_manutenzione' => (int)$admin_stats['libri_manutenzione'],
            'prestiti_attivi' => (int)$admin_stats['prestiti_attivi']
        ];
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Errore durante il recupero delle statistiche',
        'error' => $e->getMessage()
    ]);
}
