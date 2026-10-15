
// ================= CONFIGURATION =================
$botToken = getenv('BOT_TOKEN') ?: '8659100597:AAF1ZYonJG_0y-PFrrtrQmsnAIdnR2t2Mgk';
$logChannel = getenv('LOG_CHANNEL') ?: '-1004303490907';
$apiUrl = 'https://api.telegram.org/bot' . $botToken;

// Gumamit ng direct animation URL o Telegram file_id.
// Iwanang walang laman kung wala ka pang valid animation.
$welcomeSticker = getenv('WELCOME_ANIMATION') ?: '';

if ($botToken === '') {
    http_response_code(500);
    exit('BOT_TOKEN is not configured.');
}

// ================= HEALTH CHECK =================
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (
    ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && in_array($uri, ['/', '/health'], true)
) {
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bot service is running.';
    exit;
}

// ================= READ TELEGRAM UPDATE =================
$content = file_get_contents('php://input');
$update = json_decode($content ?: '', true);

if (!is_array($update)) {
    http_response_code(200);
    exit('OK');
}

// ================= CALLBACK QUERIES =================
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $data = $callback['data'] ?? '';
    $callbackId = $callback['id'] ?? '';

    if ($callbackId !== '') {
        sendTelegramRequest('/answerCallbackQuery', [
            'callback_query_id' => $callbackId,
        ]);
    }

    $message = $callback['message'] ?? [];
    $chatId = $message['chat']['id'] ?? null;
    $messageId = $message['message_id'] ?? null;

    if ($chatId !== null && $messageId !== null && $data === 'menu_dump') {
        file_put_contents(stateFile($chatId), 'WAITING_FOR_URL');

        sendTelegramRequest('/editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' =>
                "🌐 Deployment Request Initiated\n\n"
                . "Send the complete URL of a website you own "
                . "or are authorized to archive.\n\n"
                . "Example: https://example.com",
        ]);
    }

    exit;
}

// ================= REGULAR MESSAGES =================
if (!isset($update['message'])) {
    exit;
}

$message = $update['message'];
$chatId = $message['chat']['id'] ?? null;
$text = trim($message['text'] ?? '');
$firstName = $message['from']['first_name'] ?? 'User';
$username = isset($message['from']['username'])
    ? '@' . $message['from']['username']
    : 'No Username';

if ($chatId === null) {
    exit;
}

// ================= /START COMMAND =================
if (preg_match('~^/start(?:@\w+)?(?:\s|$)~', $text)) {
    $state = stateFile($chatId);

    if (is_file($state)) {
        unlink($state);
    }

    if ($welcomeSticker !== '') {
        sendTelegramRequest('/sendAnimation', [
            'chat_id' => $chatId,
            'animation' => $welcomeSticker,
        ]);
    }

    $welcomeText =
        "🤖 Welcome to Cosmo Archiver Service, {$firstName}!\n\n"
        . "Archive the public frontend assets of websites you own "
        . "or have permission to back up.\n\n"
        . "Select an option below to continue.";

    $keyboard = [
        'inline_keyboard' => [
            [
                [
                    'text' => '🚀 Dump Website',
                    'callback_data' => 'menu_dump',
                ],
            ],
            [
                [
                    'text' => '👨‍💻 Contact Support',
                    'url' => 'https://t.me/cosmo64bit',
                ],
                [
                    'text' => '📢 Join Channel',
                    'url' => 'https://t.me/cosmo64bitCH',
                ],
            ],
        ],
    ];

    sendTelegramRequest('/sendMessage', [
        'chat_id' => $chatId,
        'text' => $welcomeText,
        'reply_markup' => json_encode($keyboard),
    ]);

    exit;
}

// ================= CHECK ACTIVE SESSION =================
$stateFile = stateFile($chatId);

if (
    is_file($stateFile)
    && trim((string) file_get_contents($stateFile)) === 'WAITING_FOR_URL'
) {
    unlink($stateFile);

    if (!isValidPublicUrl($text)) {
        sendTelegramRequest('/sendMessage', [
            'chat_id' => $chatId,
            'text' =>
                "❌ Invalid URL.\n\n"
                . "Send a valid public HTTP or HTTPS URL.\n"
                . "Use /start to begin again.",
        ]);

        exit;
    }

    downloadWebsiteHtml($chatId, $text, $firstName, $username);
}

http_response_code(200);
exit('OK');

// ================= HELPER FUNCTIONS =================

function stateFile($chatId): string
{
    return '/tmp/cosmo_state_' . preg_replace('/[^0-9-]/', '', (string) $chatId) . '.txt';
}

function sendTelegramRequest(string $method, array $params): ?array
{
    global $apiUrl;

    $ch = curl_init($apiUrl . $method);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $status < 200 || $status >= 300) {
        error_log("Telegram API error: HTTP {$status}; {$error}");
        return null;
    }

    $decoded = json_decode($response, true);

    if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
        error_log('Telegram API returned an unsuccessful response.');
        return null;
    }

    return $decoded;
}

function sendLogToChannel(string $message): void
{
    global $logChannel;

    if ($logChannel === '') {
        return;
    }

    sendTelegramRequest('/sendMessage', [
        'chat_id' => $logChannel,
        'text' => $message,
    ]);
}

function isValidPublicUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $parts = parse_url($url);

    if (!is_array($parts)) {
        return false;
    }

    $scheme = strtolower($parts['scheme'] ?? '');
    $host = $parts['host'] ?? '';

    if (
        !in_array($scheme, ['http', 'https'], true)
        || $host === ''
        || isset($parts['user'])
        || isset($parts['pass'])
    ) {
        return false;
    }

    // Basic IP-address protection.
    // DNS resolution and every redirect must also be checked
    // before allowing arbitrary URL fetching in production.
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    return !in_array(
        strtolower(rtrim($host, '.')),
        ['localhost', 'localhost.localdomain'],
        true
    );
}

function fetchUrlContent(string $url): string|false
{
    if (!isValidPublicUrl($url)) {
        return false;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'CosmoArchiver/1.0',
        CURLOPT_MAXFILESIZE => 20971520,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
    ]);

    $content = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);

    if (
        $content === false
        || $status < 200
        || $status >= 300
        || $size > 20971520
    ) {
        return false;
    }

    return $content;
}

function downloadWebsiteHtml(
    $chatId,
    string $url,
    string $firstName,
    string $username
): void {
    global $logChannel;

    sendLogToChannel(
        "Archive request\n"
        . "User: {$firstName} ({$username})\n"
        . "User ID: {$chatId}\n"
        . "Target: {$url}"
    );

    $statusResponse = sendTelegramRequest('/sendMessage', [
        'chat_id' => $chatId,
        'text' => '⏳ Fetching the authorized website and collecting frontend assets...',
    ]);

    $statusMessageId = $statusResponse['result']['message_id'] ?? null;

    $finish = static function (string $message) use ($chatId, $statusMessageId): void {
        if ($statusMessageId !== null) {
            sendTelegramRequest('/editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $statusMessageId,
                'text' => $message,
            ]);
        } else {
            sendTelegramRequest('/sendMessage', [
                'chat_id' => $chatId,
                'text' => $message,
            ]);
        }
    };

    $html = fetchUrlContent($url);

    if ($html === false || $html === '') {
        $finish('❌ Unable to fetch the website. Check the URL and try again.');
        return;
    }

    if (!class_exists('DOMDocument') || !class_exists('ZipArchive')) {
        $finish('❌ Required PHP extensions DOM or ZIP are unavailable.');
        return;
    }

    $sessionDir = sys_get_temp_dir() . '/cosmo_' . bin2hex(random_bytes(8));
    $zipPath = $sessionDir . '.zip';

    $folders = [
        'api', 'css', 'data', 'docs', 'downloads', 'fonts',
        'icon_libs', 'icons', 'img', 'js', 'media', 'misc',
        'php', 'php_raw', 'svg',
    ];

    try {
        if (!mkdir($sessionDir, 0700, true)) {
            throw new RuntimeException('Unable to create temporary directory.');
        }

        foreach ($folders as $folder) {
            if (!mkdir($sessionDir . '/' . $folder, 0700, true)) {
                throw new RuntimeException('Unable to create asset directory.');
            }
        }

        file_put_contents($sessionDir . '/index.html', $html);

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $assets = [];

        foreach (['img' => 'src', 'script' => 'src', 'link' => 'href'] as $tag => $attribute) {
            foreach ($dom->getElementsByTagName($tag) as $element) {
                $asset = trim($element->getAttribute($attribute));

                if ($asset !== '') {
                    $assets[] = $asset;
                }
            }
        }

        $base = parse_url($url);
        $origin = ($base['scheme'] ?? 'https') . '://' . ($base['host'] ?? '');

        if (isset($base['port'])) {
            $origin .= ':' . $base['port'];
        }

        $basePath = $base['path'] ?? '/';
        $baseDir = preg_replace('~/[^/]*$~', '/', $basePath) ?: '/';

        $count = 0;

        foreach (array_unique($assets) as $asset) {
            if (++$count > 100) {
                break;
            }

            if (
                str_starts_with($asset, 'data:')
                || str_starts_with($asset, '#')
                || str_starts_with($asset, 'javascript:')
            ) {
                continue;
            }

            if (preg_match('~^https?://~i', $asset)) {
                $assetUrl = $asset;
            } elseif (str_starts_with($asset, '//')) {
                $assetUrl = ($base['scheme'] ?? 'https') . ':' . $asset;
            } elseif (str_starts_with($asset, '/')) {
                $assetUrl = $origin . $asset;
            } else {
                $assetUrl = $origin . $baseDir . $asset;
            }

            // Restrict downloads to the original host.
            if (strtolower((string) parse_url($assetUrl, PHP_URL_HOST)) !== strtolower((string) ($base['host'] ?? ''))) {
                continue;
            }

            $path = parse_url($assetUrl, PHP_URL_PATH) ?? '';
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

            $map = [
                'png' => 'img', 'jpg' => 'img', 'jpeg' => 'img',
                'gif' => 'img', 'webp' => 'img', 'bmp' => 'img',
                'svg' => 'svg', 'js' => 'js', 'css' => 'css',
                'woff' => 'fonts', 'woff2' => 'fonts',
                'ttf' => 'fonts', 'otf' => 'fonts', 'eot' => 'fonts',
                'mp4' => 'media', 'mp3' => 'media', 'webm' => 'media',
                'ogg' => 'media', 'pdf' => 'docs', 'doc' => 'docs',
                'docx' => 'docs', 'json' => 'data', 'xml' => 'data',
                'csv' => 'data', 'zip' => 'downloads', 'rar' => 'downloads',
                'ico' => 'icons',
            ];

            if (!isset($map[$ext])) {
                continue;
            }

            $assetData = fetchUrlContent($assetUrl);

            if ($assetData === false || strlen($assetData) > 20971520) {
                continue;
            }

            $filename = basename($path);

            if ($filename === '' || $filename === '.' || $filename === '..') {
                continue;
            }

            // Prefix prevents different asset paths overwriting each other.
            $filename = substr(hash('sha256', $assetUrl), 0, 12) . '_' . $filename;

            file_put_contents(
                $sessionDir . '/' . $map[$ext] . '/' . $filename,
                $assetData
            );
        }

        file_put_contents(
            $sessionDir . '/api/endpoint.json',
            json_encode([
                'status' => 'success',
                'service' => 'cosmo_archiver',
                'timestamp' => time(),
            ], JSON_PRETTY_PRINT)
        );

        file_put_contents(
            $sessionDir . '/README.txt',
            "Frontend archive only.\n"
            . "Server-side PHP source, private APIs, and databases are not replicated.\n"
        );

        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create ZIP archive.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sessionDir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = substr($file->getPathname(), strlen($sessionDir) + 1);
                $zip->addFile($file->getPathname(), $relative);
            }
        }

        $zip->close();

        $result = sendTelegramRequest('/sendDocument', [
            'chat_id' => $chatId,
            'document' => new CURLFile($zipPath, 'application/zip', 'cosmo_frontend_archive.zip'),
            'caption' => "✅ Frontend archive created.\nTarget: {$url}",
        ]);

        if ($result === null) {
            $finish('❌ The archive was created, but Telegram could not deliver the file.');
            return;
        }

        if ($logChannel !== '') {
            sendTelegramRequest('/sendMessage', [
                'chat_id' => $logChannel,
                'text' => "Archive delivered\nUser: {$firstName} ({$username})\n"
                    . "User ID: {$chatId}\nTarget: {$url}\n"
                    . 'Package size: ' . filesize($zipPath) . ' bytes',
            ]);
        }

        $finish('✅ Archive created and sent successfully.');

    } catch (Throwable $e) {
        error_log('Archive error: ' . $e->getMessage());
        $finish('❌ Archive failed. Check the Render logs for details.');

    } finally {
        if (is_file($zipPath)) {
            unlink($zipPath);
        }

        if (is_dir($sessionDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sessionDir, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                $file->isDir()
                    ? @rmdir($file->getPathname())
                    : @unlink($file->getPathname());
            }

            @rmdir($sessionDir);
        }
    }
}
