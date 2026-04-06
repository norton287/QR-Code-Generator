<?php
/**
 * QR Code Generator — Backend API
 * Enhanced: structured logging, log rotation, robust validation, ECC level support
 */

declare(strict_types=1);

use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRImagick;
use chillerlan\QRCode\Common\EccLevel;

require_once __DIR__ . '/vendor/autoload.php';

// ─── Configuration ─────────────────────────────────────────────────────────────
const LOG_DIR       = __DIR__ . '/logs';
const LOG_FILE      = LOG_DIR . '/qr.log';
const LOG_MAX_SIZE  = 10 * 1024 * 1024; // 10 MB per file before rotation
const LOG_MAX_FILES = 5;                // Number of rotated files to keep
const MAX_INPUT_LEN = 4000;            // Hard limit on QR content length

// ─── Security Headers ──────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ─── Per-request ID for tracing ────────────────────────────────────────────────
$reqId = sprintf('%08x', crc32(uniqid('', true)));

// ─── Logging ───────────────────────────────────────────────────────────────────

function ensureLogDir(): void {
    if (!is_dir(LOG_DIR)) {
        @mkdir(LOG_DIR, 0755, true);
    }
}

/**
 * Rotate logs when LOG_FILE exceeds LOG_MAX_SIZE.
 * Keeps up to LOG_MAX_FILES rotated copies (qr.log.1 … qr.log.N).
 */
function rotateLogs(): void {
    if (!file_exists(LOG_FILE)) return;
    if (@filesize(LOG_FILE) < LOG_MAX_SIZE) return;

    for ($i = LOG_MAX_FILES - 1; $i >= 1; $i--) {
        $src  = LOG_FILE . '.' . $i;
        $dest = LOG_FILE . '.' . ($i + 1);
        if (file_exists($src)) {
            if (file_exists($dest)) @unlink($dest);
            @rename($src, $dest);
        }
    }
    @rename(LOG_FILE, LOG_FILE . '.1');
}

/**
 * Write a structured log line.
 *
 * @param string  $level   INFO | WARN | ERROR
 * @param string  $msg     Human-readable message
 * @param array   $ctx     Optional key-value context
 */
function logMsg(string $level, string $msg, array $ctx = []): void {
    global $reqId;
    ensureLogDir();
    rotateLogs();

    $ts     = date('Y-m-d H:i:s');
    $ctxStr = $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '';
    $line   = "[{$ts}] [{$level}] [req:{$reqId}] {$msg}{$ctxStr}" . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

// ─── Response Helpers ──────────────────────────────────────────────────────────

function sendResponse(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendError(string $msg, int $status = 400, array $fields = []): never {
    logMsg('ERROR', $msg, array_merge(['status' => $status], $fields));
    $body = ['error' => $msg, 'status' => $status];
    if ($fields) $body['fields'] = $fields;
    sendResponse($body, $status);
}

// ─── Validation ────────────────────────────────────────────────────────────────

function isHex6(string $c): bool {
    return (bool) preg_match('/^[0-9a-fA-F]{6}$/', $c);
}

/**
 * Validate all POST fields.
 * Returns an associative array of field => error message (empty = valid).
 */
function validateRequest(array $d): array {
    $e = [];

    $input = trim($d['inputstring'] ?? '');
    if ($input === '') {
        $e['inputstring'] = 'QR content is required';
    } elseif (mb_strlen($input) > MAX_INPUT_LEN) {
        $e['inputstring'] = 'Input too long (max ' . MAX_INPUT_LEN . ' characters)';
    }

    $fmt = strtolower($d['output_type'] ?? '');
    if (!in_array($fmt, ['png', 'jpg', 'gif'], true)) {
        $e['output_type'] = 'Format must be one of: png, jpg, gif';
    }

    $ecc = strtoupper($d['ecc_level'] ?? '');
    if (!in_array($ecc, ['L', 'M', 'Q', 'H'], true)) {
        $e['ecc_level'] = 'ECC level must be one of: L, M, Q, H';
    }

    $scale = $d['scale'] ?? '';
    if (!is_numeric($scale) || (int)$scale < 1 || (int)$scale > 20) {
        $e['scale'] = 'Scale must be 1–20';
    }

    $qz = $d['quietzonesize'] ?? '';
    if (!is_numeric($qz) || (int)$qz < 0 || (int)$qz > 50) {
        $e['quietzonesize'] = 'Border size must be 0–50';
    }

    $mp = $d['maskpattern'] ?? '';
    if (!is_numeric($mp) || (int)$mp < -1 || (int)$mp > 7) {
        $e['maskpattern'] = 'Mask pattern must be -1–7';
    }

    $colorFields = [
        'm_finder_dark'    => 'Finder border color',
        'm_finder_dot_dark' => 'Finder center color',
        'm_alignment_dark' => 'Alignment color',
        'm_data_dark'      => 'Data dark color',
        'm_data_light'     => 'Data light color',
        'm_quietzone_light' => 'Border/quiet zone color',
    ];
    foreach ($colorFields as $field => $label) {
        $val = $d[$field] ?? '';
        if ($val === '') {
            $e[$field] = "{$label} is required";
        } elseif (!isHex6($val)) {
            $e[$field] = "{$label} must be a valid 6-character hex color";
        }
    }

    return $e;
}

// ─── Main Handler ──────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendError('Method not allowed', 405);
}

logMsg('INFO', 'Request received', [
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '-',
    'ua' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100),
]);

$validationErrors = validateRequest($_POST);
if ($validationErrors) {
    sendError('Validation failed', 400, $validationErrors);
}

// Sanitised inputs
$input   = trim($_POST['inputstring']);
$fmt     = strtolower($_POST['output_type']);
$ecc     = strtoupper($_POST['ecc_level'] ?? 'H');
$scale   = (int)$_POST['scale'];
$qzSize  = (int)$_POST['quietzonesize'];
$maskPat = (int)$_POST['maskpattern'];

$eccMap = [
    'L' => EccLevel::L,
    'M' => EccLevel::M,
    'Q' => EccLevel::Q,
    'H' => EccLevel::H,
];

// Build module-value map (raw hex strings first)
$rawColors = [
    QRMatrix::M_FINDER_DARK    => $_POST['m_finder_dark'],
    QRMatrix::M_FINDER_DOT     => $_POST['m_finder_dot_dark'],
    QRMatrix::M_FINDER         => 'ffffff',
    QRMatrix::M_ALIGNMENT_DARK => $_POST['m_alignment_dark'],
    QRMatrix::M_ALIGNMENT      => 'ffffff',
    QRMatrix::M_TIMING_DARK    => '000000',
    QRMatrix::M_TIMING         => 'ffffff',
    QRMatrix::M_FORMAT_DARK    => '000000',
    QRMatrix::M_FORMAT         => 'ffffff',
    QRMatrix::M_VERSION_DARK   => '000000',
    QRMatrix::M_VERSION        => 'ffffff',
    QRMatrix::M_DATA_DARK      => $_POST['m_data_dark'],
    QRMatrix::M_DATA           => $_POST['m_data_light'],
    QRMatrix::M_DARKMODULE     => '000000',
    QRMatrix::M_SEPARATOR      => $_POST['m_quietzone_light'],
    QRMatrix::M_QUIETZONE      => $_POST['m_quietzone_light'],
    QRMatrix::M_LOGO           => 'ffffff',
];

// Convert to RGB arrays for raster (Imagick) output
$moduleValues = array_map(function (string $hex): ?array {
    return preg_match('/^[0-9a-fA-F]{6}$/', $hex)
        ? array_map('hexdec', str_split($hex, 2))
        : null;
}, $rawColors);

try {
    logMsg('INFO', 'Generating QR code', [
        'format' => $fmt,
        'ecc'    => $ecc,
        'scale'  => $scale,
        'len'    => mb_strlen($input),
    ]);

    $options = new QROptions([
        'version'             => 5,
        'eccLevel'            => $eccMap[$ecc],
        'maskPattern'         => $maskPat,
        'addQuietzone'        => true,
        'quietzoneSize'       => $qzSize,
        'scale'               => $scale,
        'outputBase64'        => true,
        'imageTransparent'    => false,
        'drawCircularModules' => true,
        'drawLightModules'    => true,
        'circleRadius'        => 0.4,
        'keepAsSquare'        => [
            QRMatrix::M_FINDER_DARK,
            QRMatrix::M_FINDER_DOT,
            QRMatrix::M_ALIGNMENT_DARK,
        ],
        'moduleValues'        => $moduleValues,
        'outputInterface'     => QRImagick::class,
        'outputType'          => $fmt,
    ]);

    $dataUri = (new QRCode($options))->render($input);

    $mimeMap = [
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'gif' => 'image/gif',
    ];

    logMsg('INFO', 'QR code generated successfully', ['format' => $fmt]);

    sendResponse([
        'success'   => true,
        'qrcode'    => "<img alt=\"QR Code\" src=\"{$dataUri}\" style=\"max-width:100%;height:auto;display:block;\" />",
        'imageData' => $dataUri,
        'format'    => $fmt,
        'mime'      => $mimeMap[$fmt],
    ]);

} catch (Throwable $e) {
    logMsg('ERROR', 'Generation failed', [
        'exception' => get_class($e),
        'message'   => $e->getMessage(),
        'file'      => basename($e->getFile()),
        'line'      => $e->getLine(),
    ]);
    sendError('QR code generation failed: ' . $e->getMessage(), 500);
}
