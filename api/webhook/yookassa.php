<?php
$source = file_get_contents('php://input');
$data = json_decode($source, true);
if ($data['event'] === 'payment.succeeded') {
    $payment = $data['object'];
    $metadata = $payment['metadata'];
    if ($metadata['type'] === 'auction_listing') {
        $listingId = $metadata['listing_id'];
        $db = Database::getInstance();
        $db->update('listings', [
            'listing_fee_paid' => 1,
            'auction_status' => 'active',
            'status' => 'active'
        ], 'id = ?', [$listingId]);
        // Логируем
        $db->insert('audit_log', [
            'event_type' => 'auction_paid',
            'listing_id' => $listingId,
            'details' => json_encode($payment)
        ]);
    }
    http_response_code(200);
}