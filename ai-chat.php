<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);

$message = trim((string)($data['message'] ?? ''));
$history = is_array($data['history'] ?? null) ? $data['history'] : [];

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message is empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

/*
 * IMPORTANT:
 * Keep the API key on the server. Never put it inside index.html/JavaScript.
 * Preferred: environment variable OPENAI_API_KEY.
 * For simple PHP hosting, create ai-config.php from ai-config.example.php
 * and put the key there.
 */
$apiKey = getenv('OPENAI_API_KEY') ?: '';
$configFile = __DIR__ . '/ai-config.php';
if ($apiKey === '' && is_file($configFile)) {
    $config = require $configFile;
    if (is_array($config)) {
        $apiKey = (string)($config['api_key'] ?? '');
    }
}

if ($apiKey === '' || $apiKey === 'PASTE_YOUR_API_KEY_HERE') {
    http_response_code(503);
    echo json_encode([
        'error' => 'AI is not configured. Add OPENAI_API_KEY on the server or configure ai-config.php.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$history = array_slice($history, -8);
$input = [];
foreach ($history as $item) {
    $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
    $content = trim((string)($item['content'] ?? ''));
    if ($content !== '') {
        $input[] = ['role' => $role, 'content' => $content];
    }
}
$input[] = ['role' => 'user', 'content' => $message];

$instructions = <<<TXT
You are "Imtiaz Bot", the cute female-voiced AI mascot inside a personal developer portfolio.
Be friendly, warm, concise, playful, and natural. You can speak Bengali, Banglish, or English.
If the visitor asks casual questions like how you are or what you are doing, answer naturally.
You know this portfolio contains developer projects and browser games, including Zombie Rush and other mini-games.
You can explain the portfolio, projects, games, skills, and contact area, but do not invent personal facts that are not provided.
Keep normal replies short enough to sound good when read aloud, usually 1-4 sentences.
Do not claim to have real-world feelings, a physical location, or access to private information.
If asked who you are, say you are the portfolio's cute AI robot assistant.
TXT;

$payload = [
    'model' => 'gpt-5-mini',
    'instructions' => $instructions,
    'input' => $input,
    'max_output_tokens' => 220
];

$ch = curl_init('https://api.openai.com/v1/responses');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)
]);

$result = curl_exec($ch);
$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($result === false || $curlError !== '') {
    http_response_code(502);
    echo json_encode(['error' => 'AI server connection failed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$response = json_decode($result, true);
if (!is_array($response) || $http < 200 || $http >= 300) {
    http_response_code(502);
    echo json_encode(['error' => 'AI provider returned an error.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply = trim((string)($response['output_text'] ?? ''));

if ($reply === '' && isset($response['output']) && is_array($response['output'])) {
    foreach ($response['output'] as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (($content['type'] ?? '') === 'output_text' && isset($content['text'])) {
                $reply .= $content['text'];
            }
        }
    }
    $reply = trim($reply);
}

if ($reply === '') {
    http_response_code(502);
    echo json_encode(['error' => 'AI returned an empty response.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['reply' => $reply], JSON_UNESCAPED_UNICODE);
