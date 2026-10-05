<?php
// ==============================================
// tts.php — Generador de Audio Asíncrono
// Recibe texto, lo recorta si es muy largo,
// y llama a OpenRouter TTS.
// ==============================================

// Cargar variables de entorno desde el archivo .env
$envFile = __DIR__ . '/../.env';
$envVars = file_exists($envFile) ? parse_ini_file($envFile) : [];
$OPENROUTER_API_KEY = $envVars['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: "";

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Usa POST."]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input["text"])) {
    http_response_code(400);
    echo json_encode(["error" => "Falta 'text'."]);
    exit;
}

$text = trim($input["text"]);

// 1. Limpiar texto
$clean = $text;
$clean = preg_replace('/[\x{1F300}-\x{1FAFF}]/u', '', $clean);
$clean = preg_replace('/[\x{2600}-\x{26FF}]/u', '', $clean);
$clean = preg_replace('/\*\*(.*?)\*\*/', '$1', $clean);
$clean = preg_replace('/[^\w\s\p{L}.,!?¿¡]/u', '', $clean);
$clean = preg_replace('/\s+/', ' ', trim($clean));

// =====================================================================
// 2. Truncar texto para evitar Timeouts 
// =====================================================================
if (mb_strlen($clean) > 1000) {
    // Buscar el último punto antes de los 1000 caracteres
    $sub = mb_substr($clean, 0, 1000);
    $lastPeriod = mb_strrpos($sub, '.');
    // Buscamos que el punto esté idealmente adelante de los 500 caracteres para un buen corte
    if ($lastPeriod !== false && $lastPeriod > 500) {
        $clean = mb_substr($sub, 0, $lastPeriod + 1);
    } else {
        $clean = $sub . "...";
    }
}

if (empty($clean) || empty($OPENROUTER_API_KEY)) {
    echo json_encode(["audio" => null]);
    exit;
}

$endpoint = "https://openrouter.ai/api/v1/audio/speech";
$instructions = "Eres Darfi, un zorro mascota, un animador infantil súper alegre, entusiasta y divertido. Tu tono de voz debe ser muy dinámico, teatral y expresivo. Habla con mucha energía, transmite pura felicidad y sorpresa. ¡Mantén un ambiente festivo y mágico para los niños!";

$postData = [
    "model" => "openai/gpt-4o-mini-tts-2025-12-15",
    "voice" => "fable",
    "input" => $clean,
    "response_format" => "mp3",
    "instructions" => $instructions
];

$ch = curl_init($endpoint);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_TIMEOUT, 20); // 20s timeout max
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Authorization: Bearer ' . $OPENROUTER_API_KEY,
    'Content-Type: application/json',
    'HTTP-Referer: https://tudominio.com', 
    'X-Title: Darfi-SIPINNA-Chatbot'
]);

$audioData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($audioData === false || $httpCode !== 200) {
    echo json_encode(["audio" => null, "error" => "TTS API Failed"]);
    exit;
}

$audioBase64 = 'data:audio/mp3;base64,' . base64_encode($audioData);
echo json_encode(["audio" => $audioBase64]);
