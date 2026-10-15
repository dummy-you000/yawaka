<?php
// Tiyaking nakuha ang Bot Token mula sa Render Environment Variables
$botToken = getenv("BOT_TOKEN") ?: "8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk";
$apiUrl = "https://telegram.org" . $botToken;

// Basahin ang pumapasok na payload mula sa Telegram
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// Health Check para sa Render Render deployment
if ($_SERVER['REQUEST_URI'] === '/health' || $_SERVER['REQUEST_URI'] === '/') {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header("Content-Type: text/plain; charset=utf-8");
        echo "Bot service is running.";
        exit;
    }
}

if (!$update) {
    exit;
}

// 1. PROSESO NG INLINE BUTTON CALLBACKS
if (isset($update['callback_query'])) {
    $callbackQuery = $update['callback_query'];
    $data = $callbackQuery['data'];
    $chatId = $callbackQuery['message']['chat']['id'];
    $messageId = $callbackQuery['message']['message_id'];
    $callbackId = $callbackQuery['id'];

    // Sagutin ang callback query para mawala ang loading state sa Telegram
    sendTelegramRequest('/answerCallbackQuery', ['callback_query_id' => $callbackId]);

    if ($data === 'menu_owner') {
        $text = "System Support & Owner Information\n\nAdministrator: @cosmo64bit\n\nSend /start to return to the main menu.";
        sendTelegramRequest('/editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text
        ]);
    } elseif ($data === 'menu_dump') {
        // I-save ang user state sa temporary file dahil walang continuous memory ang stateless PHP
        file_put_contents("/tmp/state_{$chatId}.txt", "WAITING_FOR_URL");
        $text = "Send the URL of a website you want to archive.\n\nExample: https://example.com";
        sendTelegramRequest('/editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text
        ]);
    }
    exit;
}

// 2. PROSESO NG REGULAR MESSAGES
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $firstName = $message['chat']['first_name'] ?? 'User';

    // COMMAND: /start
    if (strpos($text, '/start') === 0) {
        // Tanggalin ang nakaraang state kung mayroon man
        if (file_exists("/tmp/state_{$chatId}.txt")) {
            unlink("/tmp/state_{$chatId}.txt");
        }

        // Ipadala muna ang Welcome Sticker (Giphy Link) tulad sa Python code
        $welcomeSticker = "https://giphy.com";
        sendTelegramRequest('/sendAnimation', [
            'chat_id' => $chatId,
            'animation' => $welcomeSticker
        ]);

        // Keyboard Markup para sa Inline Buttons
        $keyboard = [
            'inline_keyboard' => [
                [['text' => 'Dump Website', 'callback_data' => 'menu_dump']],
                [['text' => 'View Owner / Support', 'callback_data' => 'menu_owner']]
            ]
        ];

        sendTelegramRequest('/sendMessage', [
            'chat_id' => $chatId,
            'text' => "Welcome, {$firstName}!\n\nSelect an option below.",
            'reply_markup' => json_encode($keyboard)
        ]);
        exit;
    }

    // CHECK STATE: Kung naghihintay ang bot ng URL galing sa user
    $stateFile = "/tmp/state_{$chatId}.txt";
    if (file_exists($stateFile) && file_get_contents($stateFile) === "WAITING_FOR_URL") {
        unlink($stateFile); // Burahin agad ang state

        if (!filter_var($text, FILTER_VALIDATE_URL) || (!str_starts_with(strtolower($text), 'http://') && !str_starts_with(strtolower($text), 'https://'))) {
            sendTelegramRequest('/sendMessage', [
                'chat_id' => $chatId,
                'text' => "Invalid link format. Ensure it begins with http:// or https://.\n\nUse /start to try again."
            ]);
            exit;
        }

        // Simulan ang Website Download Processing
        downloadWebsiteHtml($chatId, $text);
        exit;
    }
}

// FUNCTION: Helper para sa pakikipag-usap sa Telegram API
function sendTelegramRequest($method, $params) {
    global $apiUrl;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl . $method);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// FUNCTION: Core Scraping at ZIP Sender Framework
function downloadWebsiteHtml($chatId, $url) {
    // 1. Alert status msg sa user
    $statusResponse = sendTelegramRequest('/sendMessage', [
        'chat_id' => $chatId,
        'text' => "DUMPING PLEASE WAIT...\n\nProcessing the target web frontend code framework framework..."
    ]);
    $statusData = json_decode($statusResponse, true);
    $statusMessageId = $statusData['result']['message_id'] ?? null;

    // 2. Seguridad: I-verify ang IP resolver address ng domain
    $host = parse_url($url, PHP_URL_HOST);
    $ips = gethostbynamel($host);
    if ($ips) {
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                if ($statusMessageId) {
                    sendTelegramRequest('/editMessageText', [
                        'chat_id' => $chatId,
                        'message_id' => $statusMessageId,
                        'text' => "Download rejected: the website resolves to a non-public IP address."
                    ]);
                }
                return;
            }
        }
    }

    // 3. Simulan ang cURL Fetching at file structure layout
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15');
    
    $htmlContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($htmlContent)) {
        if ($statusMessageId) {
            sendTelegramRequest('/editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $statusMessageId,
                'text' => "No files were downloaded. Check the URL or connection."
            ]);
        }
        return;
    }

    // 4. Gumawa ng ZIP programmatically gamit ang native standard library Extensions
    $zipPath = "/tmp/dump_{$chatId}.zip";
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === TRUE) {
        $zip->addFromString("index.html", $htmlContent);
        $zip->close();
    } else {
        return;
    }

    // 5. Ipadala ang natapos na Zip Archive pabalik sa user chat session
    if (file_exists($zipPath)) {
        $cFile = new CURLFile($zipPath, 'application/zip', 'dump.zip');
        sendTelegramRequest('/sendDocument', [
            'chat_id' => $chatId,
            'document' => $cFile,
            'caption' => "DUMP SUCCESSFUL\n\nTarget: {$url}"
        ]);
        unlink($zipPath); // Clean space
    }

    // Burahin ang pansamantalang loading text
    if ($statusMessageId) {
        sendTelegramRequest('/deleteMessage', [
            'chat_id' => $chatId,
            'message_id' => $statusMessageId
        ]);
    }
}
