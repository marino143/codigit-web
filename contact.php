<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://codigit.hr');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Honeypot anti-spam
if (!empty($_POST['website'])) {
    echo json_encode(['success' => true]); // silently ignore bots
    exit;
}

// Sanitize inputs
$name    = trim(strip_tags($_POST['name']    ?? ''));
$company = trim(strip_tags($_POST['company'] ?? ''));
$email   = trim(strip_tags($_POST['email']   ?? ''));
$service = trim(strip_tags($_POST['service'] ?? ''));
$message = trim(strip_tags($_POST['message'] ?? ''));

// Validate
if (empty($name) || empty($email) || empty($message)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address.']);
    exit;
}

// Build email
$to      = 'marino@codigit.hr';
$subject = "New enquiry from {$name}" . ($company ? " ({$company})" : '');

$body  = "You have a new contact form submission from codigit.hr\n";
$body .= str_repeat('-', 50) . "\n\n";
$body .= "Name:    {$name}\n";
if ($company) $body .= "Company: {$company}\n";
$body .= "Email:   {$email}\n";
if ($service) $body .= "Service: {$service}\n";
$body .= "\nMessage:\n{$message}\n";
$body .= "\n" . str_repeat('-', 50) . "\n";
$body .= "Sent from codigit.hr contact form\n";

$headers  = "From: noreply@codigit.hr\r\n";
$headers .= "Reply-To: {$email}\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$sent = mail($to, $subject, $body, $headers);

if ($sent) {
    echo json_encode(['success' => true, 'message' => 'Message sent!']);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send. Please email us directly at marino@codigit.hr']);
}
