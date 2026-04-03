<?php
/* ============================================
   НАЙДУК — Сервис отправки email (очередь + SMTP)
   ============================================ */

class MailService {
    private $queueDir;

    public function __construct() {
        $this->queueDir = __DIR__ . '/../storage/mail_queue/';
        if (!is_dir($this->queueDir)) {
            mkdir($this->queueDir, 0755, true);
        }
    }

    public function send($to, $subject, $message) {
        // Сохраняем в очередь (воркер отправит)
        $data = [
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
            'created_at' => time()
        ];
        $file = $this->queueDir . uniqid() . '.json';
        file_put_contents($file, json_encode($data), LOCK_EX);
        return true;
    }

    // Метод для воркера – реальная отправка
    public function processQueue() {
        $files = glob($this->queueDir . '*.json');
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data) {
                $headers = "From: noreply@nayduk.ru\r\n";
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
                @mail($data['to'], $data['subject'], $data['message'], $headers);
                unlink($file);
            }
        }
    }
}