<?php
/**
 * Chunk uploader che agisce come proxy per process.php
 * Ricostruisce i dati POST e li inoltra a process.php tramite una richiesta cURL interna.
 * Compatibile con PHP >= 5.4 senza avvisi di deprecazione nelle versioni recenti.
 */
session_start();

// Leggi input JSON
$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);

// Controllo input valido
if (!$input || !isset($input['chunk']) || !isset($input['index']) || !isset($input['isLast'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid chunk data']);
    exit;
}

$chunk   = $input['chunk'];
$index   = (int)$input['index'];
$isLast  = (bool)$input['isLast'];

$tempDir = dirname(__DIR__) . '/temp';

// Crea cartella temp se non esiste
if (!is_dir($tempDir)) {
    // Usiamo 0775, un ottimo compromesso tra sicurezza e compatibilità server.
    mkdir($tempDir, 0775, true);
}

$tempFile = $tempDir . '/process_' . session_id() . '.tmp';

// Append chunk al file temporaneo
file_put_contents($tempFile, $chunk, FILE_APPEND | LOCK_EX);

// Se non è l’ultimo chunk → risposta OK e fine
if (!$isLast) {
    echo json_encode([
        'ok' => true,
        'chunk' => $index,
        'message' => 'Chunk ricevuto'
    ]);
    exit;
}

// Se siamo qui, è arrivato l’ultimo chunk.
$fullJson = file_get_contents($tempFile);
unlink($tempFile); // Cancella subito il file temporaneo

$fullPost = json_decode($fullJson, true);
if (!is_array($fullPost)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid reconstructed JSON', 'source' => $fullJson]);
    exit;
}

// Costruisci l'URL completo per raggiungere process.php
$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
$scheme = $isHttps ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$path = dirname($_SERVER['SCRIPT_NAME']);
$urlProcess = $scheme . '://' . $host . $path . '/process.php';

// Inizializza cURL
$ch = curl_init();

// Imposta le opzioni per la richiesta POST
curl_setopt($ch, CURLOPT_URL, $urlProcess);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fullPost));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-Requested-With: xmlhttprequest'
]);

// Mantenuto per compatibilità con ambienti di sviluppo o server senza SSL valido.
// AVVISO DI SICUREZZA: In produzione, la verifica non andrebbe disabilitata.
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

// Esegui la richiesta
$response = curl_exec($ch);

// Controlla eventuali errori di cURL
if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL Error: ' . curl_error($ch)]);
    
    // MODIFICA: Chiusura condizionale per retrocompatibilità
    if (PHP_VERSION_ID < 80000) {
        curl_close($ch);
    }
    exit;
}

// MODIFICA: Chiusura condizionale della sessione cURL.
// Viene eseguita solo su versioni di PHP < 8.0 per mantenere la
// retrocompatibilità ed evitare avvisi di deprecazione su PHP >= 8.0.
if (PHP_VERSION_ID < 80000) {
    curl_close($ch);
}

// Restituisci la risposta ottenuta da process.php al browser
header('Content-Type: application/json');
echo $response;

exit;
