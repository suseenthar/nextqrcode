<?php
/**
 * Chunk uploader per create.php
 * Ricostruisce il payload SVG in modo sicuro evitando blocchi firewall
 */

session_start();

// Leggi input JSON grezzo
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

// Controllo dati validi
if (!$input || !isset($input['chunk']) || !isset($input['index']) || !isset($input['isLast'])) {
    echo json_encode(['error' => 'Invalid chunk data']);
    exit;
}

$chunk   = $input['chunk'];
$index   = (int)$input['index'];
$isLast  = (bool)$input['isLast'];

// Directory temporanea
$tempDir = dirname(__DIR__) . '/temp';

// Crea directory se non esiste
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// File temporaneo per questa sessione
$tempFile = $tempDir . '/create_' . session_id() . '.tmp';

// Scrivi il chunk nel file
file_put_contents($tempFile, $chunk, FILE_APPEND);

// Se NON è l’ultimo chunk → invia OK e stop
if (!$isLast) {
    echo json_encode([
        'ok' => true,
        'chunk' => $index,
        'message' => 'Chunk ricevuto'
    ]);
    exit;
}

// --------------------------------------------------
// ULTIMO CHUNK e ricomponi il JSON completo
// --------------------------------------------------
$fullJson = file_get_contents($tempFile);
unlink($tempFile);

// Decodifica JSON completo
$payload = json_decode($fullJson, true);

if (!$payload || !isset($payload['create'])) {
    echo json_encode(['error' => 'Invalid reconstructed payload']);
    exit;
}

// Ricostruisci POST come richiesto da create.php
$_POST['create'] = json_encode($payload['create']); // deve essere un JSON string

// Spoof header AJAX per compatibilità
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';

// --------------------------------------------------
// Esegui create.php con i dati ricostruiti
// --------------------------------------------------
require __DIR__ . '/create.php';

exit;
