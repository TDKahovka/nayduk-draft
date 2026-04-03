<?php
/**
 * НАЙДУК — Создание аукциона
 * Версия 2.1 (март 2026)
 */
session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);

// Проверка лимита черновиков (не более 2 неоплаченных)
$draftCount = $db->fetchCount("SELECT COUNT(*) FROM listings WHERE user_id = ? AND auction_type IN (1,2) AND listing_fee_paid = 0 AND auction_status = 'draft'", [$userId]);
if ($draftCount >= 2) {
    $error = 'У вас уже есть 2 неоплаченных аукциона. Оплатите или удалите один из них, чтобы создать новый.';
}

$csrfToken = generateCsrfToken();

$pageTitle = 'Создание аукциона — Найдук';
$pageDescription = 'Создайте прямой или обратный аукцион на Найдук. Настройте условия и оплатите размещение.';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .auction-container { max-width: 800px; margin: 0 auto; padding: 20px; }
    .form-group { margin-bottom: 20px; }
    .form-label { display: block; font-weight: 600; margin-bottom: 8px; }
    .form-input, .form-select, .form-textarea { width: 100%; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
    .form-error { color: var(--danger); font-size: 13px; margin-top: 4px; display: none; }
    .radio-group { display: flex; gap: 20px; margin-top: 8px; flex-wrap: wrap; }
    .price-hint { font-size: 12px; color: var(--text-secondary); margin-top: 4px; }
    .warning { background: rgba(255,149,0,0.1); padding: 12px; border-radius: var(--radius); margin-bottom: 20px; }
    .btn-submit { width: 100%; padding: 14px; background: var(--primary); color: white; border: none; border-radius: var(--radius-full); font-weight: 700; cursor: pointer; }
    .btn-submit:disabled { opacity: 0.6; cursor: not-allowed; }
    .photo-preview-grid { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }
    .photo-preview { width: 80px; height: 80px; object-fit: cover; border-radius: 8px; border: 1px solid var(--border); }
</style>

<div class="auction-container">
    <h1>Создать аукцион</h1>
    <?php if (isset($error)): ?>
        <div class="warning">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php else: ?>
    <form id="auction-form" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
        <input type="hidden" name="action" value="create">

        <div class="form-group">
            <label class="form-label">Тип аукциона</label>
            <div class="radio-group">
                <label><input type="radio" name="auction_type" value="1" checked> Прямой аукцион (продавец назначает стартовую цену, покупатели повышают)</label>
                <label><input type="radio" name="auction_type" value="2"> Обратный аукцион (покупатель ищет продавцов, которые предложат самую низкую цену)</label>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Заголовок</label>
            <input type="text" name="title" class="form-input" maxlength="100" required>
            <div class="form-error" id="title-error"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Описание</label>
            <textarea name="description" class="form-textarea" rows="5" maxlength="3000" required></textarea>
            <div class="form-error" id="description-error"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Фотографии (до 5, макс 2 МБ)</label>
            <input type="file" name="photos[]" multiple accept="image/*" id="photos" class="form-input">
            <div id="photo-preview" class="photo-preview-grid"></div>
            <div class="form-error" id="photos-error"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Категория</label>
            <select name="category_id" class="form-select" required>
                <option value="">Выберите категорию</option>
                <?php
                $cats = $db->fetchAll("SELECT id, name FROM listing_categories WHERE is_active = 1 ORDER BY name");
                foreach ($cats as $cat) {
                    echo '<option value="' . $cat['id'] . '">' . htmlspecialchars($cat['name']) . '</option>';
                }
                ?>
            </select>
            <div class="form-error" id="category-error"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Город</label>
            <input type="text" name="city" class="form-input" required>
            <div class="form-error" id="city-error"></div>
        </div>

        <!-- Поля для прямого аукциона (по умолчанию видны) -->
        <div id="direct-fields">
            <div class="form-group">
                <label class="form-label">Стартовая цена (₽)</label>
                <input type="number" name="start_bid" class="form-input" step="1" min="1">
                <div class="form-error" id="start_bid-error"></div>
            </div>
            <div class="form-group">
                <label class="form-label">Резервная цена (₽) <span class="price-hint">(необязательно)</span></label>
                <input type="number" name="reserve_price" class="form-input" step="1" min="0">
                <div class="form-hint">Если не указана, продажа состоится при любой ставке выше стартовой.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Минимальный шаг</label>
                <select name="min_bid_type" id="min_bid_type">
                    <option value="auto">Автоматический (1% от стартовой)</option>
                    <option value="fixed">Фиксированная сумма</option>
                </select>
                <input type="number" name="min_bid_fixed" id="min_bid_fixed" class="form-input" style="display: none;" step="1" placeholder="Сумма">
            </div>
            <div class="form-group">
                <label class="form-label">Длительность</label>
                <select name="duration">
                    <option value="1">1 день</option>
                    <option value="3">3 дня</option>
                    <option value="7" selected>7 дней</option>
                    <option value="14">14 дней</option>
                </select>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="hidden_bids" value="1"> Скрытые ставки</label>
                <div class="form-hint">Участники будут видеть только факт наличия ставки выше, но не сумму.</div>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="auto_offer_second" value="1" checked> Автоматически предлагать лот второму участнику при неоплате</label>
            </div>
            <div class="form-group">
                <label><input type="checkbox" name="enable_3d_effect" value="1"> 3D-эффект для фото</label>
            </div>
            <div class="form-group">
                <label class="form-label">Цена мгновенной покупки (₽) <span class="price-hint">(необязательно)</span></label>
                <input type="number" name="buy_now_price" class="form-input" step="1" min="0">
                <div class="form-hint">Покупатели смогут купить лот сразу по этой цене, минуя аукцион.</div>
            </div>
        </div>

        <!-- Поля для обратного аукциона (скрыты по умолчанию) -->
        <div id="reverse-fields" style="display: none;">
            <div class="form-group">
                <label class="form-label">Желаемая цена покупки (₽) <span class="price-hint">(необязательно)</span></label>
                <input type="number" name="desired_price" class="form-input" step="1" min="0">
                <div class="form-hint">Вы можете указать примерную цену, но продавцы будут предлагать свои варианты.</div>
            </div>
            <div class="form-group">
                <label class="form-label">Срок приёма предложений</label>
                <select name="duration">
                    <option value="3">3 дня</option>
                    <option value="7" selected>7 дней</option>
                    <option value="14">14 дней</option>
                </select>
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Телефон для связи</label>
            <input type="tel" name="phone" class="form-input" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
            <div class="form-hint">Будет виден после публикации.</div>
        </div>

        <div class="form-group">
            <label><input type="checkbox" name="phone_visible" value="1" checked> Показывать телефон в объявлении</label>
        </div>

        <div class="form-group">
            <div class="price-info">
                Стоимость размещения аукциона: <strong id="fee-display">0 ₽</strong>
            </div>
        </div>

        <button type="submit" class="btn-submit" id="submit-btn">Оплатить и опубликовать</button>
    </form>
    <?php endif; ?>
</div>

<script>
    const csrfToken = '<?= $csrfToken ?>';
    let auctionType = 1; // 1 - прямой, 2 - обратный

    // Переключение полей
    const radioDirect = document.querySelector('input[value="1"]');
    const radioReverse = document.querySelector('input[value="2"]');
    const directFields = document.getElementById('direct-fields');
    const reverseFields = document.getElementById('reverse-fields');

    radioDirect.addEventListener('change', () => {
        if (radioDirect.checked) {
            auctionType = 1;
            directFields.style.display = 'block';
            reverseFields.style.display = 'none';
            calculateFee();
        }
    });
    radioReverse.addEventListener('change', () => {
        if (radioReverse.checked) {
            auctionType = 2;
            directFields.style.display = 'none';
            reverseFields.style.display = 'block';
            calculateFee();
        }
    });

    // Поле фиксированного шага
    const minBidType = document.getElementById('min_bid_type');
    const minBidFixed = document.getElementById('min_bid_fixed');
    minBidType.addEventListener('change', () => {
        minBidFixed.style.display = minBidType.value === 'fixed' ? 'block' : 'none';
    });

    // Расчёт платы за размещение
    function calculateFee() {
        let startBid = parseFloat(document.querySelector('[name="start_bid"]').value) || 0;
        let fee = 30;
        if (startBid <= 20000) fee = 30;
        else if (startBid <= 50000) fee = 50;
        else if (startBid <= 100000) fee = 70;
        else fee = 100;
        document.getElementById('fee-display').innerText = fee + ' ₽';
    }
    document.querySelector('[name="start_bid"]').addEventListener('input', calculateFee);
    calculateFee();

    // Валидация и отправка
    document.getElementById('auction-form').addEventListener('submit', async (e) => {
        e.preventDefault();

        const formData = new FormData(e.target);
        formData.append('auction_type', auctionType);

        // Валидация
        const title = formData.get('title');
        if (!title || title.length < 5) {
            showError('title', 'Заголовок должен быть от 5 символов');
            return;
        }
        const desc = formData.get('description');
        if (!desc || desc.length < 50) {
            showError('description', 'Описание должно быть от 50 символов');
            return;
        }
        const city = formData.get('city');
        if (!city) {
            showError('city', 'Укажите город');
            return;
        }
        const category = formData.get('category_id');
        if (!category) {
            showError('category', 'Выберите категорию');
            return;
        }

        if (auctionType == 1) {
            const startBid = formData.get('start_bid');
            if (!startBid || startBid <= 0) {
                showError('start_bid', 'Укажите стартовую цену');
                return;
            }
        }

        // Отправка
        try {
            const response = await fetch('/api/auctions/create.php', {
                method: 'POST',
                body: formData
            });
            const data = await response.json();
            if (data.success) {
                window.location.href = data.payment_url;
            } else {
                alert(data.error || 'Ошибка создания');
            }
        } catch (err) {
            alert('Ошибка сети');
        }
    });

    function showError(field, message) {
        const errorDiv = document.getElementById(field + '-error');
        if (errorDiv) {
            errorDiv.textContent = message;
            errorDiv.style.display = 'block';
        }
    }

    // Предпросмотр фото
    document.getElementById('photos').addEventListener('change', function(e) {
        const preview = document.getElementById('photo-preview');
        preview.innerHTML = '';
        for (let i = 0; i < this.files.length && i < 5; i++) {
            const file = this.files[i];
            const reader = new FileReader();
            reader.onload = function(ev) {
                const img = document.createElement('img');
                img.src = ev.target.result;
                img.className = 'photo-preview';
                preview.appendChild(img);
            };
            reader.readAsDataURL(file);
        }
    });
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>