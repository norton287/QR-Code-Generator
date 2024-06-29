<?php
use chillerlan\QRCode\{QRCode, QROptions};
use chillerlan\QRCode\Data\QRMatrix;
use chillerlan\QRCode\Output\QRImagick;
use chillerlan\QRCode\Common\EccLevel;

require_once __DIR__ . '/vendor/autoload.php';

function logMessage($message) {
    $logFile = __DIR__ . '/logfile.log';
    if (!file_exists($logFile)) {
        touch($logFile);
        chown($logFile, 'www-data');
    }
    // Format the log message with a timestamp
    $logMessage = ('Log Entry [' . date('Y-m-d H:i:s') . '] ' . $message) . PHP_EOL;
    // Append the log message to the log file
    file_put_contents($logFile, $logMessage, FILE_APPEND);
}

logMessage("New Connection");

function sendResponse(array $response){
    logMessage("Returning QR Code!");
    header('Content-type: application/json;charset=utf-8;');
    echo json_encode($response);
    exit;
}

function validateHexColor($color) {
    return preg_match('/^[a-fA-F0-9]{6}$/', $color) === 1;
}

function validatePostData($postData) {
    logMessage("In Validate Post Data");
    $requiredFields = [
        'm_finder_dark', 'm_finder_dot_dark', 'm_alignment_dark', 'm_data_dark',
        'm_data_light', 'm_quietzone_light', 'output_type', 'maskpattern',
        'quietzonesize', 'scale', 'inputstring'
    ];

    foreach ($requiredFields as $field) {
        if (!isset($postData[$field])) {
	    logMessage("Not A Valid Field");
            return false;
        }
    }

    $colorFields = [
        'm_finder_dark', 'm_finder_dot_dark', 'm_alignment_dark',
        'm_data_dark', 'm_data_light', 'm_quietzone_light'
    ];

    foreach ($colorFields as $field) {
        if (!validateHexColor($postData[$field])) {
            logMessage("Bad Color Field Data");
            return false;
        }
    }

    if (!in_array($postData['output_type'], ['png', 'jpg', 'gif', 'svg', 'text', 'json'])) {
    	logMessage("Bad POST Data for Output Type");
        return false;
    }

    if (!is_numeric($postData['maskpattern']) || !is_numeric($postData['quietzonesize']) || !is_numeric($postData['scale'])) {
    	logMessage("Bad Post Data for either Mask Pattern or Quiet Zone or Scale");
        return false;
    }
	
	logMessage("Was Valid Post Data Returning To Script!");
    return true;
}

if (!validatePostData($_POST)) {
    logMessage("Sending a 400 response from Server");
    header('HTTP/1.1 400 Bad Request');
    sendResponse(['error' => 'Invalid input data']);
    logMessage("Sending a 400 response from Server!");
    exit;
}

try {
    $moduleValues = [
        // finder
        QRMatrix::M_FINDER_DARK    => $_POST['m_finder_dark'],
        QRMatrix::M_FINDER_DOT     => $_POST['m_finder_dot_dark'],
        QRMatrix::M_FINDER         => 'ffffff',

        // alignment
        QRMatrix::M_ALIGNMENT_DARK => $_POST['m_alignment_dark'],
        QRMatrix::M_ALIGNMENT      => 'ffffff',

        // timing
        QRMatrix::M_TIMING_DARK    => '000000',
        QRMatrix::M_TIMING         => 'ffffff',

        // format
        QRMatrix::M_FORMAT_DARK    => '000000',  // Black
        QRMatrix::M_FORMAT         => 'ffffff',  // White

        // version
        QRMatrix::M_VERSION_DARK    => '000000',
        QRMatrix::M_VERSION         => 'ffffff',

        // data
        QRMatrix::M_DATA_DARK      => $_POST['m_data_dark'],
        QRMatrix::M_DATA           => $_POST['m_data_light'],

        // darkmodule
        QRMatrix::M_DARKMODULE      => '000000',

        // separator
        QRMatrix::M_SEPARATOR      => '008000',

        // quietzone
        QRMatrix::M_QUIETZONE      => $_POST['m_quietzone_light'],

        // logo
        QRMatrix::M_LOGO           => 'ffffff',
    ];

    $moduleValues = array_map(function($v) {
        if (preg_match('/[a-f\d]{6}/i', $v) === 1) {
            return in_array($_POST['output_type'], ['png', 'jpg', 'gif'])
                ? array_map('hexdec', str_split($v, 2))
                : '#' . $v ;
        }
        return null;
    }, $moduleValues);

    $options = new QROptions([
        'version'          => 5,
        'eccLevel'         => EccLevel::H,
        'maskPattern'      => (int)$_POST['maskpattern'],
        'addQuietzone'     => true,
        'outputInterface'  => QRImagick::class,
        'quietzoneSize'    => (int)$_POST['quietzonesize'],
        'moduleValues'     => $moduleValues,
        'outputType'       => $_POST['output_type'],
        'drawCircularModules' => true,
        'scale'            => (int)$_POST['scale'],
        'outputBase64'     => true,
        'imageTransparent' => false,
        'drawLightModules' => true,
        'circleRadius'     => 0.4,
        'keepAsSquare'     => [
            QRMatrix::M_FINDER_DARK,
            QRMatrix::M_FINDER_DOT,
            QRMatrix::M_ALIGNMENT_DARK,
        ],
    ]);

    $qrcode = (new QRCode($options))->render($_POST['inputstring']);

    $imageData = $qrcode; // Store the raw image data

    if (in_array($_POST['output_type'], ['png', 'jpg', 'gif'])) {
        $qrcode = '<img alt="qrcode" src="' . $qrcode . '" />';
    }

    logMessage("Built QR Code Now Sending It Back To The Client!");
	sendResponse(['qrcode' => $qrcode, 'imageData' => $imageData]); // Send both HTML and image data
} catch (Throwable $e) {
    header('HTTP/1.1 500 Internal Server Error');
    logMessage("Error: " . $e->getMessage());
    sendResponse(['error' => $e->getMessage()]);
}

exit;
?>
