<?php
session_start();
$isFirstMessage = false;
if (!isset($_SESSION["darfi_presentado"]) || $_SESSION["darfi_presentado"] === false) {
    $isFirstMessage = true;
    $_SESSION["darfi_presentado"] = true;
}
session_write_close();


// ==============================================
// api.php — Backend entry point for Darfi chatbot
// Receives POST with user message, runs moderation,
// RAG context retrieval, and connects to OpenRouter.
// ==============================================

// Cargar variables de entorno desde el archivo .env
$envFile = __DIR__ . '/../.env';
$envVars = file_exists($envFile) ? parse_ini_file($envFile) : [];

// Configuration variables for OpenRouter API
$OPENROUTER_API_KEY = $envVars['OPENROUTER_API_KEY'] ?? getenv('OPENROUTER_API_KEY') ?: ""; // API key loaded from .env
// Models in priority order — fill in your preferred model identifiers
$MODELS = [
    "google/gemma-4-31b-it:free",  // ← Model A (primary)
    "openai/gpt-oss-120b:free",  // ← Model B (first fallback)
    "z-ai/glm-4.5-air:free",  // ← Model C (second fallback)
];

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

// Only accept POST requests
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Método no permitido. Usa POST."]);
    exit;
}

// Read the JSON input
$input = json_decode(file_get_contents("php://input"), true);

if (!$input || !isset($input["mensaje"])) {
    http_response_code(400);
    echo json_encode(["error" => "Falta el campo 'mensaje' en la solicitud."]);
    exit;
}

$userMessage = trim($input["mensaje"]);

if ($userMessage === "") {
    http_response_code(400);
    echo json_encode(["error" => "El mensaje no puede estar vacío."]);
    exit;
}

// =========================================
// 1a. Greeting detection — let greetings pass through to the model
// =========================================
$greetingPatterns = [
    "hola", "hello", "hi", "hey", "buenas", "buen día", "buen dia",
    "buenos días", "buenos dias", "buenas tardes", "buenas noches",
    "qué onda", "que onda", "qué tal", "que tal", "saludos",
    "me oyes", "me escuchas", "estás ahí", "estas ahi",
    "ey", "oye", "holi", "holaa", "hooola"
];

$messageLower = mb_strtolower($userMessage, "UTF-8");
$isGreeting = false;

foreach ($greetingPatterns as $greeting) {
    // Match if the entire message is basically just the greeting
    // (possibly with punctuation / emoji / whitespace around it)
    $pattern = '/^\s*[¡!¿?😊👋🎉]*\s*' . preg_quote($greeting, '/') . '[\s!.?¡¿😊👋🎉]*$/iu';
    if (preg_match($pattern, $messageLower)) {
        $isGreeting = true;
        break;
    }
}

// =========================================
// 1b. Moderation check (simulated server-side)
// =========================================
// Greetings skip moderation so the model can reply warmly.
// In a full implementation this would call the JS moderation module
// or a PHP equivalent. Here we do a simple keyword check.
$bannedKeywords = [
    "política", "elecciones", "partido", "candidato",
    "armas", "drogas", "narcotráfico",
    "pornografía", "sexo explícito",
    "apuestas", "casino"
];

$isBlocked = false;

if (!$isGreeting) {
    foreach ($bannedKeywords as $keyword) {
        if (mb_strpos($messageLower, $keyword) !== false) {
            $isBlocked = true;
            break;
        }
    }
}

if ($isBlocked) {
    echo json_encode([
        "respuesta" => "Entiendo tu curiosidad, pero solo puedo ayudarte con temas "
                      . "relacionados con los derechos de niñas, niños y adolescentes. "
                      . "¿Te gustaría saber algo sobre eso? 😊",
        "tts"       => "Entiendo tu curiosidad, pero solo puedo ayudarte con temas "
                      . "relacionados con los derechos de niñas, niños y adolescentes. "
                      . "¿Te gustaría saber algo sobre eso?"
    ]);
    exit;
}
// =========================================
// RAG semántico para SIPINNA (PHP)
// =========================================

function normalize_text($text) {
    // Convertir a minúsculas
    $text = mb_strtolower($text, "UTF-8");

    // Reemplazar acentos manualmente (sin iconv)
    $replacements = [
        'á' => 'a', 'é' => 'e', 'í' => 'i',
        'ó' => 'o', 'ú' => 'u', 'ñ' => 'n',
        'ü' => 'u'
    ];
    $text = strtr($text, $replacements);

    // Eliminar cualquier carácter que no sea letra, número o espacio
    $text = preg_replace("/[^a-z0-9\s]/u", " ", $text);

    // Normalizar espacios
    $text = preg_replace("/\s+/", " ", $text);

    return trim($text);
}


$SEMANTIC_EXPANSIONS = [
    "sipinna"          => ["sipinna", "sistema de proteccion", "proteccion integral", "derechos de ninos"],
    "violencia"        => ["violencia", "maltrato", "abuso", "acoso", "bullying", "riesgos", "ciberacoso"],
    "participacion"    => ["participacion", "opinion", "voz", "expresion", "consulta infantil"],
    "crianza"          => ["crianza", "crianza positiva", "familia", "padres", "hijos", "comunicacion"],
    "servicios"        => ["servicios", "ayuda", "apoyo", "orientacion", "acompanamiento"],
    "liderazgo"        => ["titular", "directora", "encargada", "responsable", "coordinadora", "liderazgo", "lider", "laura monica marin", "promupinna"],
    "ejes"             => ["ejes", "lineas de accion", "acciones", "programas", "trabajo sipinna"],
    "sipinna_estatal"  => ["chihuahua", "estatal", "pepinna", "gobernador", "secretaria ejecutiva estatal", "programa estatal"],
    "sipinna_municipal"=> ["juarez", "municipal", "promupinna", "mision", "vision", "objetivos", "marco operativo"],
    "equipo_sipinna"   => ["equipo", "integrantes", "personal", "staff", "titular", "representante", "enlaces", "juridico", "vinculacion"],
];

$INTENT_MAP = [
    // --- Existing single-doc intents ---
    [ "id" => "que_es_sipinna",              "docId" => "que_es_sipinna",              "keys" => ["sipinna", "funcion", "que es", "para que sirve"] ],
    [ "id" => "ejes_accion",                 "docId" => "ejes_accion",                 "keys" => ["ejes", "acciones", "programas", "lineas de accion"] ],
    [ "id" => "coordinacion_interinstitucional","docId" => "coordinacion_interinstitucional","keys" => ["coordinacion", "instituciones", "dif", "organizaciones"] ],
    [ "id" => "prevencion_proteccion",       "docId" => "prevencion_proteccion",       "keys" => ["violencia", "riesgos", "proteccion", "prevencion"] ],
    [ "id" => "participacion_infantil",      "docId" => "participacion_infantil",      "keys" => ["participacion", "opinion", "consulta infantil"] ],
    [ "id" => "crianza_positiva",            "docId" => "crianza_positiva",            "keys" => ["crianza", "familia", "padres", "hijos"] ],
    [ "id" => "liderazgo_sipinna",           "docId" => "liderazgo_sipinna",           "keys" => ["titular", "directora", "lider", "laura monica marin"] ],
    [ "id" => "derechos_generales",          "docId" => "derechos_generales",          "keys" => ["derechos", "proteccion integral", "interes superior"] ],
    [ "id" => "prevencion_violencia",        "docId" => "prevencion_violencia",        "keys" => ["violencia", "maltrato", "abuso", "acoso"] ],
    [ "id" => "participacion",               "docId" => "participacion",               "keys" => ["participacion", "opinion", "voz"] ],
    [ "id" => "servicios_sipinna",           "docId" => "servicios_sipinna",           "keys" => ["servicios", "ayuda", "apoyo", "orientacion"] ],
    [ "id" => "faq",                         "docId" => "faq",                         "keys" => ["pregunta", "faq", "informacion general"] ],

    // --- SIPINNA Estatal (Chihuahua) — multi-doc intent ---
    [ "id" => "sipinna_estatal", "docIds" => [
        "sipinna_chihuahua_que_es",
        "sipinna_chihuahua_funciones",
        "sipinna_chihuahua_estructura",
        "sipinna_chihuahua_pepinna",
        "sipinna_chihuahua_equipo",
        "sipinna_chihuahua_prevencion",
        "sipinna_chihuahua_participacion",
        "sipinna_chihuahua_contacto"
      ],
      "keys" => ["chihuahua", "estatal", "pepinna", "estructura estatal", "equipo estatal", "zona norte", "secretaria ejecutiva estatal", "programa estatal", "sipinna_estatal"]
    ],

    // --- SIPINNA Municipal (Juárez) — multi-doc intent ---
    [ "id" => "sipinna_municipal", "docIds" => [
        "que_es_sipinna",
        "sipinna_juarez_marco_operativo",
        "promupinna",
        "ejes_accion",
        "liderazgo_sipinna",
        "servicios_sipinna"
      ],
      "keys" => ["juarez", "municipal", "promupinna", "mision", "vision", "objetivos", "marco operativo", "autoridades municipales", "sipinna_municipal"]
    ],

    // --- Equipo SIPINNA — single-doc intent ---
    [ "id" => "equipo_sipinna", "docId" => "sipinna_chihuahua_equipo",
      "keys" => ["equipo", "integrantes", "personal", "staff", "titular", "representante", "enlaces", "juridico", "vinculacion", "equipo_sipinna"]
    ],
];

function fuzzy_score($queryNorm, $keywordNorm) {
    if (strpos($queryNorm, $keywordNorm) !== false) return 3;
    if (str_starts_with($queryNorm, $keywordNorm)) return 2;
    if (str_ends_with($queryNorm, $keywordNorm)) return 2;

    $qWords = explode(" ", $queryNorm);
    foreach ($qWords as $w) {
        if (strlen($w) > 3 && strpos($keywordNorm, $w) !== false) {
            return 1;
        }
    }
    return 0;
}

function score_intent_php($queryNorm, $intent, $SEMANTIC_EXPANSIONS) {
    $score = 0;

    foreach ($intent["keys"] as $key) {
        $keyNorm = normalize_text($key);
        $score += fuzzy_score($queryNorm, $keyNorm);

        if (isset($SEMANTIC_EXPANSIONS[$key])) {
            foreach ($SEMANTIC_EXPANSIONS[$key] as $syn) {
                $synNorm = normalize_text($syn);
                $score += fuzzy_score($queryNorm, $synNorm);
            }
        }
    }

    return $score;
}

function buscarContextoSipinnaPHP($userMessage, $knowledge, $INTENT_MAP, $SEMANTIC_EXPANSIONS) {
    if (!$knowledge || !isset($knowledge["documents"])) {
        error_log("⚠️ RAG-PHP — knowledge vacío");
        return "";
    }

    $qNorm = normalize_text($userMessage);
    error_log("🔍 RAG-PHP — Consulta normalizada: " . $qNorm);

    $scored = [];

    foreach ($INTENT_MAP as $intent) {
        $score = score_intent_php($qNorm, $intent, $SEMANTIC_EXPANSIONS);
        if ($score > 0) {
            $scored[] = [
                "intent" => $intent,
                "score"  => $score
            ];
        }
    }

    usort($scored, function ($a, $b) {
        return $b["score"] <=> $a["score"];
    });

    $scored = array_slice($scored, 0, 5);

    error_log("📊 RAG-PHP — Intents detectados: " . json_encode($scored, JSON_UNESCAPED_UNICODE));

    if (count($scored) === 0) {
        error_log("⚠️ RAG-PHP — Ningún intent detectado");
        return "";
    }

    // Collect unique document IDs from the top intents
    $docIdsOrdered = [];
    $seenDocIds = [];

    foreach ($scored as $s) {
        $intent = $s["intent"];

        // Support both single docId and multi docIds
        $ids = [];
        if (isset($intent["docIds"]) && is_array($intent["docIds"])) {
            $ids = $intent["docIds"];
        } elseif (isset($intent["docId"])) {
            $ids = [$intent["docId"]];
        }

        foreach ($ids as $did) {
            if (!isset($seenDocIds[$did])) {
                $seenDocIds[$did] = true;
                $docIdsOrdered[] = $did;
            }
        }
    }

    // Retrieve the actual documents in order
    $blocks = [];

    foreach ($docIdsOrdered as $docId) {
        $found = null;

        foreach ($knowledge["documents"] as $doc) {
            if (isset($doc["id"]) && $doc["id"] === $docId) {
                $found = $doc;
                break;
            }
        }

        if ($found) {
            error_log("📄 RAG-PHP — Documento usado: " . $found["id"] . " (" . $found["title"] . ")");
            $blocks[] = $found["title"] . ": " . $found["text"];
        } else {
            error_log("⚠️ RAG-PHP — Documento NO encontrado: " . $docId);
        }
    }

    $context = implode("\n\n", $blocks);
    error_log("📚 RAG-PHP — Contexto final (" . count($blocks) . " docs):\n" . $context);

    return $context;
}

// =========================================
// 2. RAG context retrieval (semántico en PHP)
// =========================================
$knowledgePath = __DIR__ . "/../data/knowledge.json";
$context = "";

if (file_exists($knowledgePath)) {
    $knowledge = json_decode(file_get_contents($knowledgePath), true);
    $context = buscarContextoSipinnaPHP($userMessage, $knowledge, $INTENT_MAP, $SEMANTIC_EXPANSIONS);
} else {
    error_log("⚠️ RAG-PHP — knowledge.json no encontrado en: " . $knowledgePath);
}


// =========================================
// 3. System Prompt for Darfi & User Prompt
// =========================================
$systemPrompt = "Eres Darfi, la mascota oficial de SIPINNA. 
NUNCA saludes al usuario, NO te presentes, y NUNCA digas 'Hola' ni 'Soy Darfi'. Entra directamente a responder la pregunta.
Responde de forma natural, cálida y fluida. NO uses encabezados Markdown (#, ##, ###) en tus respuestas.
Tu misión es explicar y promover los derechos de niñas, niños y adolescentes. 
Solo puedes hablar de temas relacionados con derechos, protección, bienestar, participación, educación, salud, identidad y prevención de riesgos. 
Si el usuario pregunta algo fuera de estos temas, responde amablemente que solo puedes hablar de derechos y protección. 
Habla con un tono cálido, protector y sencillo.";


// Include RAG context in the user prompt if available
if (!empty($context)) {
    $userPrompt = "El siguiente es contexto oficial de SIPINNA:\n$context\n\nPregunta del usuario: $userMessage";
} else {
    $userPrompt = $userMessage;
}

// =========================================
// 4. OpenRouter API Connection with Model Fallback
// =========================================

/**
 * Attempts a single chat completion request to OpenRouter.
 *
 * @param  string      $model      Model identifier.
 * @param  string      $systemPrompt
 * @param  string      $userPrompt
 * @param  string      $apiKey
 * @return string|null The assistant reply, or null on failure.
 */
function callOpenRouter($model, $systemPrompt, $userPrompt, $apiKey) {
    $postData = [
        "model" => $model,
        "messages" => [
            ["role" => "system",  "content" => $systemPrompt],
            ["role" => "user",    "content" => $userPrompt]
        ],
        "temperature" => 0.4
    ];

    $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $apiKey",
        "HTTP-Referer: https://tudominio.com", // TODO: Reemplaza con tu dominio real
        "X-Title: Darfi-SIPINNA-Chatbot",
        "Content-Type: application/json"
    ]);

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    // — cURL transport error
    if ($raw === false) {
        error_log("❌ OpenRouter — cURL error for model [$model]: $curlError");
        return null;
    }

    // — JSON decode failure
    $json = json_decode($raw, true);
    if ($json === null) {
        error_log("❌ OpenRouter — JSON decode failed for model [$model]: " . substr($raw, 0, 300));
        return null;
    }

    // — API-level error returned by OpenRouter
    if (isset($json["error"])) {
        $errMsg = is_array($json["error"]) ? ($json["error"]["message"] ?? json_encode($json["error"])) : $json["error"];
        error_log("❌ OpenRouter — API error for model [$model]: $errMsg");
        return null;
    }

    // — Extract the assistant content
    $content = $json["choices"][0]["message"]["content"] ?? null;
    if ($content === null || trim($content) === "") {
        error_log("❌ OpenRouter — Empty response for model [$model]");
        return null;
    }

    return $content;
}

// =========================================
// 5. Retry loop — try each model in order
// =========================================
$respuesta = "";
$modelUsed = "none";

foreach ($MODELS as $index => $model) {
    $label = chr(65 + $index); // A, B, C
    error_log("🔄 OpenRouter — Trying Model $label: $model");

    $result = callOpenRouter($model, $systemPrompt, $userPrompt, $OPENROUTER_API_KEY);

    if ($result !== null) {
        $respuesta = $result;
        $modelUsed = $model;
        error_log("✅ OpenRouter — Success with Model $label: $model");
        break;
    }

    error_log("⚠️ OpenRouter — Model $label failed, " . ($index < count($MODELS) - 1 ? "retrying next model..." : "no more models to try."));
}

// Fallback if every model failed
if ($respuesta === "") {
    error_log("🚨 OpenRouter — All models failed. Returning fallback message.");
    $respuesta = "Lo siento, en este momento tengo dificultades para procesar tu consulta. Por favor, intenta de nuevo en unos momentos.";
}

// Log the final model used (visible in PHP error log)
error_log("[API] Final model used: $modelUsed");
// =========================================
// 5.1 Control de presentación y formato
// =========================================
// Eliminar saludos iniciales y presentaciones
$respuesta = preg_replace("/^#*\s*¡?(hola|saludos|buenos d[ií]as|buenas tardes|buenas noches)[^\n]*/iu", "", $respuesta);
$respuesta = preg_replace("/^#*\s*(soy|aqu[ií] es)\s+darfi[^\n]*/iu", "", $respuesta);

// Eliminar símbolos de encabezado Markdown (#, ##, ###) de todas las líneas
$respuesta = preg_replace("/^#{1,6}\s+/m", "", $respuesta);
$respuesta = trim($respuesta);


// =========================================
// 6. Return JSON response
// =========================================
$responsePayload = [
    "respuesta"   => $respuesta,
    "tts"         => $respuesta,
    "_model_used" => $modelUsed  // Debug field — visible in browser console via response JSON
];

echo json_encode($responsePayload);