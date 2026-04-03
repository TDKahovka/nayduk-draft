<?php
/**
 * PaymentService — работа с ЮKassa API
 * - Создание платежа за размещение аукциона
 * - Создание платежа за покупку пакета дополнительных ставок
 */
require_once __DIR__ . '/../vendor/autoload.php';

class PaymentService
{
    private $client;
    private $shopId;
    private $secretKey;

    public function __construct()
    {
        $this->shopId = getenv('YOO_KASSA_SHOP_ID');
        $this->secretKey = getenv('YOO_KASSA_SECRET_KEY');
        $this->client = new \YooKassa\Client();
        $this->client->setAuth($this->shopId, $this->secretKey);
    }

    /**
     * Создание платежа за размещение аукциона
     * @param int $listingId ID объявления
     * @param float $amount Сумма платежа
     * @return string URL для редиректа на оплату
     */
    public function createAuctionListingPayment($listingId, $amount)
    {
        $payment = $this->client->createPayment([
            'amount' => [
                'value' => $amount,
                'currency' => 'RUB'
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/payment/success.php?type=listing&listing_id=' . $listingId
            ],
            'capture' => true,
            'description' => "Размещение аукциона #{$listingId}",
            'metadata' => [
                'listing_id' => $listingId,
                'type' => 'auction_listing',
                'user_id' => $_SESSION['user_id'] ?? 0
            ]
        ]);
        return $payment->getConfirmation()->getConfirmationUrl();
    }

    /**
     * Создание платежа за покупку пакета дополнительных ставок
     * @param int $userId ID пользователя
     * @param int $count Количество ставок в пакете (по умолчанию 10)
     * @return string URL для редиректа на оплату
     */
    public function createExtraBidsPayment($userId, $count = 10)
    {
        $amount = 50; // фиксированная цена за 10 ставок
        $payment = $this->client->createPayment([
            'amount' => [
                'value' => $amount,
                'currency' => 'RUB'
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/payment/success.php?type=extra_bids'
            ],
            'capture' => true,
            'description' => "Пакет {$count} ставок",
            'metadata' => [
                'type' => 'extra_bids',
                'user_id' => $userId,
                'count' => $count
            ]
        ]);
        return $payment->getConfirmation()->getConfirmationUrl();
    }
}