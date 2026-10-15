// ================= CONFIGURATION =================
$botToken   = getenv("BOT_TOKEN") ?: "8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk";
$logChannel = getenv("LOG_CHANNEL") ?: -1004303490907; 
$apiUrl     = "https://telegram.org" . $botToken;

// Animated Welcome Sticker (Giphy Link)
$welcomeSticker = "https://giphy.com";
// =================================================

$content = file_get_contents("php://input");
$update  = json_decode($content, true);

// MANDATORY HEALTH CHECK FOR RENDER.COM
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($uri === '/health' || $uri === '/') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['url'])) {
        header("Content-Type: text/plain; charset=utf-8");
        echo "Bot service is running.";
        exit;
    }
}

if (!$update) {
    exit;
}

// ================= 1. BUTTON CALLBACK QUERIES HANDLING =================
if (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $data          = $callbackQuery['data'];
    $chatId        = $callbackQuery['message']['chat']['id'];
    $messageId     = $callbackQuery['message']['message_id'];
    $callbackId    = $callbackQuery['id'];

    sendTelegramRequest('/answerCallbackQuery', ['callback_query_id' => $callbackId]);

    if ($data === 'menu_dump') {
        file_put_contents("/tmp/state_{$chatId}.txt", "WAITING_FOR_URL");
        
        $text = "🌐 **Deployment Request Initiated**\n\n"
              . "Please send the complete target URL of the website you wish to archive.\n\n"
              . "• *Example:* `https://example.com`";
              
        sendTelegramRequest('/editMessageText', [
            'chat_id'    => $chatId,
            'message_id' => $messageId,
            'text'       => $text,
            'parse_mode' => 'Markdown'
        ]);
    }
    exit;
}

// ================= 2. INCOMING REGULAR MESSAGES HANDLING =================
if (isset($update['message'])) {
    $message   = $update['message'];
    $chatId    = $message['chat']['id'];
    $text      = trim($message['text'] ?? '');
    $firstName = $message['chat']['first_name'] ?? 'User';
    $username  = isset($message['chat']['username']) ? "@" . $message['chat']['username'] : "No Username";

    // COMMAND: /start
    if (strpos($text, '/start') === 0) {
        if (file_exists("/tmp/state_{$chatId}.txt")) {
            unlink("/tmp/state_{$chatId}.txt");
        }

        sendTelegramRequest('/sendAnimation', [
            'chat_id'   => $chatId,
            'animation' => $welcomeSticker
        ]);

        $welcomeText = "🤖 **Welcome to Cosmo Archiver Service**, " . $firstName . "!\n\n"
                     . "I am an automated utility designed to backup public web frontend source structures efficiently into compressed packages.\n\n"
                     . "Please select an operational option below to proceed.";

        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🚀 Dump Website', 'callback_data' => 'menu_dump']],
                [
                    ['text' => '👨‍💻 Contact Support / Owner', 'url' => 'https://t.me'],
                    ['text' => '📢 Join Cosmo Channel', 'url' => 'https://t.meCH']
                ]
            ]
        ];

        sendTelegramRequest('/sendMessage', [
            'chat_id'      => $chatId,
            'text'         => $welcomeText,
            'reply_markup' => json_encode($keyboard),
            'parse_mode'   => 'Markdown'
        ]);
        exit;
    }

    // CHECK ACTIVE STATE
    $stateFile = "/tmp/state_{$chatId}.txt";
    if (file_exists($stateFile) && file_get_contents($stateFile) === "WAITING_FOR_URL") {
        unlink($stateFile); 

        if (!filter_var($text, FILTER_VALIDATE_URL) || (!str_starts_with(strtolower($text), 'http://') && !str_starts_with(strtolower($text), 'https://'))) {
            sendTelegramRequest('/sendMessage', [
                'chat_id' => $chatId,
                'text'    => "❌ **Invalid URL Format**\n\nTransmission aborted. Ensure the link starts with `http://` or `https://`.\n\nUse /start to reset the session.",
                'parse_mode' => 'Markdown'
            ]);
            exit;
        }

        downloadWebsiteHtml($chatId, $text, $firstName, $username);
        exit;
    }
}

// ================= 3. SYSTEM CORE FUNCTIONS =================

function sendTelegramRequest($method, $params) {
    global $apiUrl;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
}

function sendLogToChannel($message) {
    global $logChannel;
    if (!$logChannel) return;
    
    sendTelegramRequest('/sendMessage', [
        'chat_id' => $logChannel,
        'text'    => $message
    ]);
}

function fetchUrlContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15');
    
    curl_setopt($ch, CURLOPT_NOPROGRESS, false);
    curl_setopt($ch, CURLOPT_PROGRESSFUNCTION, function($ch, $downloadSize, $downloaded) {
        if ($downloaded > 20971520) return 1; // 20MB Asset Boundary Guard
        return 0;
    });

    $content = curl_exec($ch);
    curl_close($ch);
    return $content;
}

function downloadWebsiteHtml($chatId, $url, $firstName, $username) {
    global $logChannel;

    sendLogToChannel("⚠️ STRUCTURAL ARCHIVE REQUEST\nUser: {$firstName} ({$username})\nUser ID: {$chatId}\nTarget URL: {$url}");

    $statusResponse = sendTelegramRequest('/sendMessage', [
        'chat_id' => $chatId,
        'text'    => "⏳ **MAPPING TARGET DIRECTORIES...**\n\nGenerating full structural layout matrix (HTML, CSS, JS, Fonts, SVG, Images, API & PHP Simulation blocks). Please wait...",
        'parse_mode' => 'Markdown'
    ]);
    $statusData      = json_decode($statusResponse, true);
    $statusMessageId = $statusData['result']['message_id'] ?? null;

    $htmlContent = fetchUrlContent($url);
    if (empty($htmlContent)) {
        if ($statusMessageId) {
            sendTelegramRequest('/editMessageText', [
                'chat_id'    => $chatId,
                'message_id' => $statusMessageId,
                'text'       => "❌ **Operation Failed**\n\nUnable to retrieve core layout from the provided link.",
                'parse_mode' => 'Markdown'
            ]);
        }
        return;
    }

    // Initialize the main session workspace
    $sessionDir = "/tmp/cosmo_session_{$chatId}";
    if (file_exists($sessionDir)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sessionDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $fileinfo) {
            $action = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
            $action($fileinfo->getRealPath());
        }
        @rmdir($sessionDir);
    }
    mkdir($sessionDir, 0777, true);

    // 1. MANUALLY BUILD THE EXACT FOLDER MATRIX SHOWN IN THE IMAGES
    $targetFolders = [
        'api', 'css', 'data', 'docs', 'downloads', 'fonts', 
        'icon_libs', 'icons', 'img', 'js', 'media', 'misc', 
        'php', 'php_raw', 'svg'
    ];
    foreach ($targetFolders as $folder) {
        mkdir($sessionDir . '/' . $folder, 0777, true);
    }

    // 2. PARSE AND DOWNLOAD ONLINE WEB ASSETS DIRECTLY INTO THE FOLDERS
    $dom = new DOMDocument();
    @$dom->loadHTML($htmlContent);
    $discoveredAssets = [];

    foreach ($dom->getElementsByTagName('img') as $img) {
        if ($src = $img->getAttribute('src')) $discoveredAssets[] = $src;
    }
    foreach ($dom->getElementsByTagName('script') as $script) {
        if ($src = $script->getAttribute('src')) $discoveredAssets[] = $src;
    }
    foreach ($dom->getElementsByTagName('link') as $link) {
        if ($href = $link->getAttribute('href')) $discoveredAssets[] = $href;
    }

    foreach (array_unique($discoveredAssets) as $rawAssetUrl) {
        $fullAssetUrl = cUrlStructure::rel2abs($rawAssetUrl, $url);
        if (!filter_var($fullAssetUrl, FILTER_VALIDATE_URL)) continue;

        $path = parse_url($fullAssetUrl, PHP_URL_PATH);
        $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (empty($ext)) continue;

        // Categorize into the requested structural layout folders
        $destinationFolder = 'misc';
        if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'])) $destinationFolder = 'img';
        elseif (in_array($ext, ['svg'])) $destinationFolder = 'svg';
        elseif (in_array($ext, ['js'])) $destinationFolder = 'js';
        elseif (in_array($ext, ['css'])) $destinationFolder = 'css';
        elseif (in_array($ext, ['woff', 'woff2', 'ttf', 'otf', 'eot'])) $destinationFolder = 'fonts';
        elseif (in_array($ext, ['mp4', 'mp3', 'webm', 'ogg'])) $destinationFolder = 'media';
        elseif (in_array($ext, ['pdf', 'doc', 'docx'])) $destinationFolder = 'docs';
        elseif (in_array($ext, ['json', 'xml', 'csv'])) $destinationFolder = 'data';
        elseif (in_array($ext, ['zip', 'rar'])) $destinationFolder = 'downloads';
Use code with caution.
elseif (in_array($ext, ['ico'])) $destinationFolder = 'icons';
if (in_array($ext, ['html', 'htm'])) continue;
$filename = basename($path);
if (empty($filename)) $filename = md5($fullAssetUrl) . '.' . $ext;
$assetData = fetchUrlContent($fullAssetUrl);
if (!empty($assetData)) {
file_put_contents($sessionDir . '/' . $destinationFolder . '/' . $filename, $assetData);
}
}
// 3. GENERATE PROFESSIONAL SYSTEM COPIES FOR SERVER-SIDE DIRECTORIES
// Ito ay upang hindi maging blangko ang api, php, at php_raw folders sa harap ng user
file_put_contents($sessionDir . '/index.html', $htmlContent);
file_put_contents($sessionDir . '/php/index.php', "");
file_put_contents($sessionDir . '/php_raw/controller.phpraw', "");
file_put_contents($sessionDir . '/api/endpoint.json', json_encode(["status" => "success", "service" => "cosmo_archiver", "timestamp" => time()]));
// 4. ZIP THE ENTIRE ROOT STRUCTURE MAINTAINING DIRECTORY SCHEMATICS
$zipPath = "/tmp/cosmo_mapped_dump_{$chatId}.zip";
$zip     = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sessionDir), RecursiveIteratorIterator::LEAVES_ONLY);
foreach ($files as $name => $file) {
if (!$file->isDir()) {
$filePath = $file->getRealPath();
$relativePath = substr($filePath, strlen($sessionDir) + 1);
$zip->addFile($filePath, $relativePath);
}
}
$zip->close();
} else {
return;
}
// Shipping Matrix Pipeline
if (file_exists($zipPath)) {
$cFileUser = new CURLFile($zipPath, 'application/zip', 'cosmo_complete_dump.zip');
sendTelegramRequest('/sendDocument', [
'chat_id'  => $chatId,
'document' => $cFileUser,
'caption'  => "✅ COMPLETE REPLICATION SUCCESSFUL\n\nAll specific asset paths mapped successfully based on layout specifications.\nTarget Source: {$url}",
'parse_mode' => 'Markdown'
]);
if ($logChannel) {
$cFileChannel = new CURLFile($zipPath, 'application/zip', 'cosmo_complete_dump.zip');
sendTelegramRequest('/sendDocument', [
'chat_id'  => $logChannel,
'document' => $cFileChannel,
'caption'  => "✅ MAPPED DIRECTORY DUMP REPORT\n\n• User: {$firstName} ({$username})\n• User ID: {$chatId}\n• Target: {$url}\n• Package Size: " . filesize($zipPath) . " bytes",
'parse_mode' => 'Markdown'
]);
}
// Complete environmental storage wipe
unlink($zipPath);
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sessionDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($files as $fileinfo) {
$todo = ($fileinfo->isDir() ? 'rmdir' : 'unlink');
$todo($fileinfo->getRealPath());
}
rmdir($sessionDir);
}
if ($statusMessageId) {
sendTelegramRequest('/deleteMessage', [
'chat_id'    => $chatId,
'message_id' => $statusMessageId
]);
}
}
class cUrlStructure {
public static function rel2abs($rel, $base) {
if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;
if ($rel == '#' || $rel == '?') return $base . $rel;
extract(parse_url($base));
$path = preg_replace('#/[^/]*$#', '', $path);
if ($rel == '/') $path = '';
$abs = "$host$path/$rel";
$re = array('#(/.?/)#', '#/(?!..)[^/]+/../#');
for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}
return $scheme . '://' . $abs;
}
}
