<?php
/**
 * GlobalGo contact form handler (XServer)
 * Receives the form on https://globalgo.co.jp/ and emails it to info@globalgo.co.jp.
 * A copy of every submission is also saved outside the web root as a backup.
 */

declare(strict_types=1);

// ───────── Settings ─────────
const MAIL_TO        = 'info@globalgo.co.jp';
const MAIL_FROM      = 'info@globalgo.co.jp';          // must be an address on this domain
const MAIL_FROM_NAME = 'GlobalGo Webサイト';
const ALLOWED_HOSTS  = ['globalgo.co.jp', 'www.globalgo.co.jp'];
const MAX_PER_HOUR   = 5;                              // per IP address
const MIN_SECONDS    = 3;                              // faster than this = bot
const DATA_DIR       = __DIR__ . '/../contact-data';  // not reachable from the web

const SERVICES = [
    ''           => '（未選択）',
    'education'  => '国際教育 / International Education',
    'business'   => '国際ビジネス開発 / Business Development',
    'trade'      => '輸出入コンサルティング / Import & Export',
    'realestate' => '国際不動産 / International Real Estate',
    'abroad'     => '留学・海外就労 / Study & Work Abroad',
    'other'      => 'その他 / General Inquiry',
];

// ───────── Helpers ─────────
$wantsJson = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function respond(bool $ok, string $error = '', int $status = 200): void
{
    global $wantsJson;
    http_response_code($status);
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => $ok, 'error' => $error], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Fallback for browsers without JavaScript
    header('Content-Type: text/html; charset=UTF-8');
    $title = $ok ? '送信完了 / Message sent' : '送信エラー / Error';
    $body  = $ok
        ? '<p>お問い合わせありがとうございます。1営業日以内にご返信いたします。</p><p>Thank you — we will reply within one business day.</p>'
        : '<p>送信できませんでした。入力内容をご確認のうえ、もう一度お試しいただくか、<a href="mailto:info@globalgo.co.jp">info@globalgo.co.jp</a> まで直接ご連絡ください。</p><p>Your message could not be sent. Please go back and check the form, or email <a href="mailto:info@globalgo.co.jp">info@globalgo.co.jp</a>.</p>';
    echo '<!DOCTYPE html><html lang="ja"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>' . $title . ' | GlobalGo</title><link rel="icon" href="/favicon.ico"><style>body{font-family:"Noto Sans JP",system-ui,sans-serif;background:#F5F5F0;color:#080808;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}main{background:#fff;max-width:520px;padding:40px;border-top:3px solid #C41A1A;line-height:1.9}a{color:#C41A1A}</style></head><body><main><h1 style="font-size:20px">' . $title . '</h1>' . $body . '<p><a href="/#contact">← サイトに戻る / Back to site</a></p></main></body></html>';
    exit;
}

function clean_line(string $s, int $max): string
{
    $s = trim(preg_replace('/[\r\n\t\0]+/u', ' ', $s) ?? '');
    return mb_substr($s, 0, $max);
}

// ───────── Checks ─────────
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /#contact', true, 303);
    exit;
}

// Only accept submissions coming from our own pages
$origin = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if ($origin !== '') {
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if (!in_array($host, ALLOWED_HOSTS, true)) {
        respond(false, 'fail', 403);
    }
}

$name    = clean_line((string) ($_POST['name'] ?? ''), 100);
$company = clean_line((string) ($_POST['company'] ?? ''), 150);
$email   = clean_line((string) ($_POST['email'] ?? ''), 254);
$service = (string) ($_POST['service'] ?? '');
$message = trim(mb_substr(str_replace("\0", '', (string) ($_POST['message'] ?? '')), 0, 5000));
$consent = ($_POST['consent'] ?? '') === '1';
$honey   = trim((string) ($_POST['website'] ?? ''));
$started = (int) ($_POST['t'] ?? 0);

// Bots: filled the hidden field, or submitted impossibly fast. Pretend it worked.
if ($honey !== '' || ($started > 0 && time() - $started < MIN_SECONDS)) {
    respond(true);
}

if ($name === '' || $email === '' || $message === '') respond(false, 'required', 422);
if (!filter_var($email, FILTER_VALIDATE_EMAIL))        respond(false, 'email', 422);
if (!$consent)                                         respond(false, 'consent', 422);
if (!array_key_exists($service, SERVICES))             $service = 'other';

// Simple rate limit per IP
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0700, true);
}
$rateFile = DATA_DIR . '/rate.json';
$now   = time();
$rates = [];
if (is_file($rateFile)) {
    $rates = json_decode((string) file_get_contents($rateFile), true) ?: [];
}
foreach ($rates as $k => $times) {
    $rates[$k] = array_values(array_filter((array) $times, fn($t) => $t > $now - 3600));
    if (!$rates[$k]) unset($rates[$k]);
}
$key = hash('sha256', $ip);
if (count($rates[$key] ?? []) >= MAX_PER_HOUR) {
    respond(false, 'fail', 429);
}
$rates[$key][] = $now;
@file_put_contents($rateFile, json_encode($rates), LOCK_EX);

// ───────── Build the email ─────────
mb_language('uni');
mb_internal_encoding('UTF-8');

$ua          = clean_line((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300);
$date        = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y年n月j日 H:i');
$serviceText = SERVICES[$service];
$subject     = '【お問い合わせ】' . $name . ' 様' . ($service !== '' ? ' — ' . explode(' / ', $serviceText)[0] : '');

$body = <<<TXT
GlobalGo ウェブサイトからお問い合わせが届きました。
（このメールに返信すると、お客様に直接届きます）

────────────────────────
お名前 / Name      : {$name}
会社名 / Company   : {$company}
メール / Email     : {$email}
サービス / Service : {$serviceText}
────────────────────────
メッセージ / Message:

{$message}

────────────────────────
受信日時: {$date}（日本時間）
送信元IP: {$ip}
ブラウザ: {$ua}
TXT;

$fromName = mb_encode_mimeheader(MAIL_FROM_NAME, 'UTF-8');
$headers  = implode("\r\n", [
    'From: ' . $fromName . ' <' . MAIL_FROM . '>',
    'Reply-To: ' . $email,
    'X-Mailer: GlobalGo-ContactForm',
]);

$sent = mb_send_mail(MAIL_TO, $subject, $body, $headers, '-f' . MAIL_FROM);

// Backup copy (kept outside public_html)
$log = [
    'time' => $date, 'sent' => $sent, 'name' => $name, 'company' => $company,
    'email' => $email, 'service' => $serviceText, 'message' => $message, 'ip' => $ip,
];
@file_put_contents(DATA_DIR . '/submissions.jsonl', json_encode($log, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

$sent ? respond(true) : respond(false, 'fail', 500);
