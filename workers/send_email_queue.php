<?php
// workers/send_email_queue.php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/MailService.php';

$db = Database::getInstance();

$emails = $db->fetchAll("SELECT * FROM email_queue WHERE status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY created_at ASC LIMIT 50");
foreach ($emails as $email) {
    $success = MailService::send($email['to_email'], $email['subject'], $email['body']);
    $newStatus = $success ? 'sent' : 'failed';
    $db->update('email_queue', ['status' => $newStatus, 'sent_at' => $success ? date('Y-m-d H:i:s') : null], 'id = ?', [$email['id']]);
    if (!$success) {
        error_log("Failed to send email to {$email['to_email']}");
    }
}