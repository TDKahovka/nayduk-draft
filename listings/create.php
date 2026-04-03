<?php
/* ============================================
   НАЙДУК — Создание объявления (финальная версия)
   Версия 5.0 (апрель 2026)
   - Порядок полей: заголовок → описание → фото → блоки → динамические поля → город → телефон с чекбоксом → доп. блок → AI-импорт
   - Удалена кнопка геолокации
   - Добавлен чекбокс «Показывать телефон в объявлении» (по умолчанию из профиля)
   - Пояснение к деловому предложению
   - Полная безопасность, автосохранение, AI, импорт по ссылке
   ============================================ */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';
require_once __DIR__ . '/../services/GeoService.php';

if (!isset($_SESSION['user_id'])) {
    $_SESSION['redirect_after_auth'] = '/listings/create';
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = (int)$_SESSION['user_id'];
$user = $db->getUserById($userId);
if (!$user || !empty($user['deleted_at'])) {
    session_destroy();
    header('Location: /auth/login');
    exit;
}

// Проверка лимита объявлений для обычных пользователей (10 активных)
$activeListingsCount = $db->fetchCount(
    "SELECT COUNT(*) FROM listings WHERE user_id = ? AND status != 'archived'",
    [$userId]
);
$limitReached = (!$user['is_partner'] && $activeListingsCount >= 10);

$csrfToken = generateCsrfToken();

// ===== ОПРЕДЕЛЕНИЕ ГОРОДА (только из профиля, без геолокации) =====
$geo = new GeoService();
$currentCity = $geo->getUserCity($userId);
$defaultCity = $currentCity['city'] ?? ($user['city'] ?? '');
$defaultLat = $currentCity['lat'] ?? null;
$defaultLng = $currentCity['lng'] ?? null;

// Если город не определился, берём из профиля
if (empty($defaultCity) && !empty($user['city'])) {
    $defaultCity = $user['city'];
}

$pageTitle = 'Подать объявление — Найдук';
$pageDescription = 'Быстрая публикация объявления на Найдук. Выберите категорию и заполните поля.';

// Микроразметка Schema.org
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'WebPage',
    'name' => 'Подать объявление на Найдук',
    'description' => $pageDescription,
    'url' => 'https://' . $_SERVER['HTTP_HOST'] . '/listings/create',
    'potentialAction' => [
        '@type' => 'SearchAction',
        'target' => [
            '@type' => 'EntryPoint',
            'urlTemplate' => 'https://' . $_SERVER['HTTP_HOST'] . '/listings?q={search_term_string}'
        ],
        'query-input' => 'required name=search_term_string'
    ]
];

// Подключаем общий header (стили и скрипты подключаются там)
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Дополнительные стили для страницы создания -->
<style>
    .create-container {
        max-width: 800px;
        margin: 0 auto;
        padding: 40px 20px;
    }
    .create-card {
        background: var(--surface);
        border-radius: var(--radius-2xl);
        padding: 30px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
    }
    .page-header {
        margin-bottom: 30px;
    }
    .page-header h1 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }
    .page-header p {
        color: var(--text-secondary);
    }
    .blocks-grid {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 30px;
        justify-content: center;
    }
    .block-btn {
        flex: 1;
        min-width: 100px;
        padding: 16px 12px;
        background: var(--bg-secondary);
        border: 2px solid var(--border);
        border-radius: var(--radius-lg);
        text-align: center;
        cursor: pointer;
        transition: all var(--transition);
        font-weight: 600;
        color: var(--text-secondary);
    }
    .block-btn i {
        font-size: 28px;
        display: block;
        margin-bottom: 8px;
    }
    .block-btn.active {
        border-color: var(--primary);
        background: var(--primary);
        color: white;
    }
    .block-btn.active i {
        color: white;
    }
    .form-group {
        margin-bottom: 24px;
    }
    .form-label {
        display: block;
        font-weight: 600;
        margin-bottom: 8px;
        font-size: 14px;
    }
    .form-label.required::after {
        content: " *";
        color: var(--danger);
    }
    .form-input, .form-select, .form-textarea {
        width: 100%;
        padding: 12px 16px;
        border: 1px solid var(--border);
        border-radius: var(--radius);
        background: var(--bg);
        color: var(--text);
        font-size: 15px;
        transition: all var(--transition);
    }
    .form-input:focus, .form-select:focus, .form-textarea:focus {
        border-color: var(--primary);
        outline: none;
        box-shadow: 0 0 0 3px rgba(74,144,226,0.1);
    }
    .form-error {
        color: var(--danger);
        font-size: 13px;
        margin-top: 5px;
        display: none;
    }
    .form-error.visible {
        display: block;
    }
    .form-hint {
        font-size: 12px;
        color: var(--text-secondary);
        margin-top: 6px;
    }
    .photo-dropzone {
        border: 2px dashed var(--border);
        border-radius: var(--radius-lg);
        padding: 40px;
        text-align: center;
        cursor: pointer;
        transition: all var(--transition);
        background: var(--bg);
        margin-bottom: 20px;
    }
    .photo-dropzone.dragover {
        border-color: var(--primary);
        background: rgba(74,144,226,0.05);
    }
    .dropzone-icon {
        font-size: 48px;
        margin-bottom: 12px;
    }
    .dropzone-text {
        font-weight: 600;
        margin-bottom: 4px;
    }
    .dropzone-hint {
        font-size: 13px;
        color: var(--text-secondary);
    }
    .photo-preview-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 12px;
        margin-bottom: 20px;
    }
    .photo-preview {
        position: relative;
        aspect-ratio: 1;
        border-radius: var(--radius);
        overflow: hidden;
        background: var(--bg-secondary);
        border: 1px solid var(--border);
    }
    .photo-preview img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }
    .remove-photo {
        position: absolute;
        top: 4px;
        right: 4px;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: rgba(0,0,0,0.6);
        color: white;
        border: none;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }
    .city-suggestions {
        position: absolute;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        max-height: 200px;
        overflow-y: auto;
        z-index: 1000;
        width: 100%;
    }
    .city-suggestion {
        padding: 10px 14px;
        cursor: pointer;
        transition: background var(--transition);
    }
    .city-suggestion:hover {
        background: var(--bg-secondary);
    }
    .btn-submit {
        width: 100%;
        padding: 16px;
        font-size: 16px;
        font-weight: 700;
        margin-top: 20px;
        background: linear-gradient(135deg, var(--primary), var(--primary-dark));
        color: white;
        border: none;
        border-radius: var(--radius-full);
        cursor: pointer;
        transition: all var(--transition);
    }
    .btn-submit:disabled {
        opacity: 0.7;
        cursor: not-allowed;
    }
    .btn-submit .spinner {
        display: inline-block;
        width: 18px;
        height: 18px;
        border: 2px solid rgba(255,255,255,0.3);
        border-radius: 50%;
        border-top-color: #fff;
        animation: spin 0.6s linear infinite;
        margin-right: 8px;
    }
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    .collapse-toggle {
        background: none;
        border: none;
        color: var(--primary);
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        padding: 8px 0;
        display: inline-flex;
        align-items: center;
        gap: 6px;
    }
    .collapse-content {
        display: none;
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px solid var(--border-light);
    }
    .collapse-content.open {
        display: block;
    }
    .warning-badge {
        background: rgba(255,149,0,0.1);
        color: var(--warning);
        padding: 10px;
        border-radius: var(--radius);
        margin-bottom: 20px;
        font-size: 14px;
        text-align: center;
    }
    .honeypot {
        position: absolute;
        left: -9999px;
        opacity: 0;
        pointer-events: none;
    }
    .slots-container {
        margin-top: 10px;
    }
    .slot-item {
        display: flex;
        gap: 12px;
        align-items: center;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .slot-item input {
        flex: 1;
        min-width: 150px;
    }
    .slot-item button {
        background: var(--danger);
        color: white;
        border: none;
        border-radius: var(--radius-full);
        padding: 6px 12px;
        cursor: pointer;
    }
    .add-slot-btn {
        margin-top: 8px;
        background: var(--bg-secondary);
        border: 1px solid var(--border);
        border-radius: var(--radius-full);
        padding: 8px 16px;
        cursor: pointer;
        font-size: 14px;
    }
    .ai-btn {
        background: linear-gradient(135deg, #6366f1, #8b5cf6);
        color: white;
        border: none;
        border-radius: var(--radius-full);
        padding: 8px 16px;
        cursor: pointer;
        font-size: 14px;
        margin-left: 12px;
        transition: all var(--transition);
    }
    .ai-btn:hover {
        opacity: 0.9;
        transform: translateY(-1px);
    }
    .ai-btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
    }
    .url-import-group {
        display: flex;
        gap: 8px;
        margin-bottom: 16px;
    }
    .url-import-group .form-input {
        flex: 1;
    }
    @media (max-width: 768px) {
        .create-container {
            padding: 20px;
        }
        .create-card {
            padding: 20px;
        }
        .blocks-grid {
            gap: 8px;
        }
        .block-btn {
            min-width: 80px;
            padding: 12px 8px;
        }
        .block-btn i {
            font-size: 24px;
        }
        .slot-item {
            flex-direction: column;
            align-items: stretch;
        }
        .ai-btn {
            margin-left: 0;
            margin-top: 8px;
        }
        .url-import-group {
            flex-direction: column;
        }
    }
</style>

<!-- Микроразметка -->
<script type="application/ld+json">
<?= json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>
</script>

<div class="create-container">
    <div class="create-card">
        <div class="page-header">
            <h1>➕ Подать объявление</h1>
            <p>Заполните форму. Это займёт меньше минуты.</p>
            <?php if ($limitReached): ?>
            <div class="warning-badge">
                ⚠️ Вы достигли лимита бесплатных объявлений (10). Удалите старые или перейдите на <a href="/partner">партнёрский тариф</a>.
            </div>
            <?php endif; ?>
        </div>

        <form id="listing-form" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="lat" id="lat" value="<?= htmlspecialchars($defaultLat) ?>">
            <input type="hidden" name="lng" id="lng" value="<?= htmlspecialchars($defaultLng) ?>">
            <input type="hidden" name="fill_time" id="fill_time" value="0">
            <input type="hidden" name="type" id="listing-type" value="housing">
            <input type="hidden" name="slots_json" id="slots_json" value="[]">

            <!-- ЗАГОЛОВОК -->
            <div class="form-group">
                <label class="form-label required" for="title">🏷️ Заголовок</label>
                <div style="display: flex; align-items: center; flex-wrap: wrap;">
                    <input type="text" id="title" name="title" class="form-input" maxlength="100" required style="flex:1">
                    <button type="button" id="ai-improve-btn" class="ai-btn">✨ Улучшить с помощью AI</button>
                </div>
                <div class="form-error" id="title-error"></div>
                <div class="form-hint">Кратко опишите то, что вы предлагаете или ищете. 5–100 символов.</div>
            </div>

            <!-- ОПИСАНИЕ -->
            <div class="form-group">
                <label class="form-label required" for="description">📄 Описание</label>
                <textarea id="description" name="description" class="form-textarea" rows="5" maxlength="3000" required></textarea>
                <div class="form-error" id="description-error"></div>
                <div class="form-hint">Подробное описание. Минимум 50 символов. Не указывайте телефон или ссылки в тексте.</div>
            </div>

            <!-- ФОТО (перенесено после описания) -->
            <div class="form-group">
                <div class="photo-dropzone" id="photo-dropzone">
                    <div class="dropzone-icon">📷</div>
                    <div class="dropzone-text">Перетащите фото сюда</div>
                    <div class="dropzone-hint">или нажмите для выбора</div>
                    <div class="dropzone-hint">До 5 фото, макс. 2 МБ каждое</div>
                    <input type="file" id="photo-input" name="photos[]" multiple accept="image/*" hidden>
                </div>
                <div class="photo-preview-grid" id="photo-preview-grid"></div>
                <div class="form-error" id="photos-error"></div>
            </div>

            <!-- БЛОКИ (выбор типа объявления) -->
            <div class="blocks-grid" id="blocks-grid">
                <div class="block-btn" data-block="housing">
                    <i class="hgi hgi-stroke-home-01"></i> Жильё
                </div>
                <div class="block-btn" data-block="job">
                    <i class="hgi hgi-stroke-briefcase-01"></i> Работа
                </div>
                <div class="block-btn" data-block="goods">
                    <i class="hgi hgi-stroke-shopping-bag-01"></i> Товары
                </div>
                <div class="block-btn" data-block="services">
                    <i class="hgi hgi-stroke-tool-01"></i> Услуги
                </div>
                <div class="block-btn" data-block="community">
                    <i class="hgi hgi-stroke-community"></i> Сообщество
                </div>
            </div>

            <!-- ДИНАМИЧЕСКИЕ ПОЛЯ (заполняются через JS) -->
            <div id="dynamic-fields"></div>

            <!-- ГОРОД (ручной ввод, без геолокации) -->
            <div class="form-group">
                <label class="form-label required" for="city">📍 Город</label>
                <input type="text" id="city" name="city" class="form-input" autocomplete="off" required value="<?= htmlspecialchars($defaultCity) ?>">
                <div id="city-suggestions" class="city-suggestions" style="display: none;"></div>
                <div class="form-error" id="city-error"></div>
                <div class="form-hint">Введите город вручную (обязательно).</div>
            </div>

            <!-- ТЕЛЕФОН (подтягивается из профиля) с чекбоксом видимости -->
            <div class="form-group">
                <label class="form-label" for="phone">📱 Телефон для связи</label>
                <input type="tel" id="phone" name="phone" class="form-input" placeholder="+7 (999) 000-00-00" value="<?= htmlspecialchars($user['phone'] ?? '') ?>">
                <div class="form-hint">Будет виден только после публикации. Можно изменить.</div>
                <div class="form-error" id="phone-error"></div>
                <div style="margin-top: 8px;">
                    <label>
                        <input type="checkbox" name="phone_visible" value="1" <?= !empty($user['phone_visible']) ? 'checked' : '' ?>>
                        Показывать телефон в объявлении
                    </label>
                </div>
            </div>

            <!-- ДОПОЛНИТЕЛЬНО (свёрнутый блок) -->
            <button type="button" id="toggle-collapse" class="collapse-toggle">
                <i class="hgi hgi-stroke-chevron-down"></i> Дополнительно
            </button>
            <div id="collapse-content" class="collapse-content">
                <div id="extra-fields"></div>
                <!-- Слоты встреч (добавляются через JS) -->
                <div id="slots-container" class="slots-container" style="display: none;">
                    <label class="form-label">📅 Слоты для встреч</label>
                    <div id="slot-list"></div>
                    <button type="button" id="add-slot-btn" class="add-slot-btn">+ Добавить слот</button>
                    <div class="form-hint">Укажите время, когда вы готовы встретиться с покупателем.</div>
                </div>
            </div>

            <!-- AI-импорт по ссылке -->
            <div class="form-group">
                <label class="form-label">🔗 Импортировать товар по ссылке (Ozon, Wildberries, Яндекс.Маркет)</label>
                <div class="url-import-group">
                    <input type="url" id="product-url" class="form-input" placeholder="https://...">
                    <button type="button" id="fetch-product-btn" class="ai-btn">Загрузить</button>
                </div>
                <div id="fetch-loading" style="display: none;">⏳ Загрузка...</div>
                <div id="fetch-error" style="color: var(--danger); display: none;"></div>
            </div>

            <!-- Honeypot -->
            <div class="honeypot">
                <input type="text" name="website_url" tabindex="-1" autocomplete="off">
                <input type="text" name="phone_fake" tabindex="-1" autocomplete="off">
            </div>

            <button type="submit" class="btn-submit" id="submit-btn">✅ Опубликовать объявление</button>
        </form>
    </div>
</div>

<script>
    // ===== ГЛОБАЛЬНЫЕ ПЕРЕМЕННЫЕ =====
    const csrfToken = '<?= $csrfToken ?>';
    let uploadedFiles = [];
    let startTime = Date.now();
    let isSubmitting = false;
    let currentBlock = 'housing';
    let autoSaveTimer = null;
    let slots = [];
    let lastSubmitTime = 0;
    let citySuggestDebounceTimer = null;

    // ===== МАППИНГ БЛОКОВ В ТИПЫ API =====
    const blockToApiType = {
        'housing': 'sell',
        'job': 'resume',
        'goods': 'sell',
        'services': 'service',
        'community': 'service'
    };

    // ===== ВСПОМОГАТЕЛЬНЫЕ ФУНКЦИИ =====
    function showToast(message, type = 'success') {
        const colors = {
            success: 'linear-gradient(135deg, #34C759, #2C9B4E)',
            error: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
            warning: 'linear-gradient(135deg, #FF9500, #E68600)',
            info: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
        };
        Toastify({
            text: message,
            duration: 4000,
            gravity: 'top',
            position: 'right',
            backgroundColor: colors[type] || colors.info
        }).showToast();
    }

    function clearError(fieldId) {
        const err = document.getElementById(fieldId + '-error');
        if (err) err.classList.remove('visible');
        const input = document.getElementById(fieldId);
        if (input) input.classList.remove('error');
    }

    function showError(fieldId, message) {
        const err = document.getElementById(fieldId + '-error');
        if (err) {
            err.textContent = message;
            err.classList.add('visible');
        }
        const input = document.getElementById(fieldId);
        if (input) input.classList.add('error');
    }

    // ===== ФОТО (drag & drop, до 5 шт) =====
    const dropzone = document.getElementById('photo-dropzone');
    const photoInput = document.getElementById('photo-input');
    const previewGrid = document.getElementById('photo-preview-grid');
    const maxPhotos = 5;
    const maxSizeMB = 2;
    const allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    dropzone.addEventListener('click', () => photoInput.click());
    dropzone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropzone.classList.add('dragover');
    });
    dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropzone.classList.remove('dragover');
        handleFiles(e.dataTransfer.files);
    });
    photoInput.addEventListener('change', (e) => handleFiles(e.target.files));

    function handleFiles(files) {
        for (const file of files) {
            if (uploadedFiles.length >= maxPhotos) {
                showToast(`Максимум ${maxPhotos} фото`, 'warning');
                break;
            }
            if (!allowedTypes.includes(file.type)) {
                showToast('Недопустимый формат. Только JPEG, PNG, WebP, GIF', 'error');
                continue;
            }
            if (file.size > maxSizeMB * 1024 * 1024) {
                showToast(`Файл "${file.name}" превышает ${maxSizeMB} МБ`, 'error');
                continue;
            }
            uploadedFiles.push(file);
            const reader = new FileReader();
            reader.onload = (e) => {
                const div = document.createElement('div');
                div.className = 'photo-preview';
                div.innerHTML = `
                    <img src="${e.target.result}" alt="preview">
                    <button type="button" class="remove-photo" data-index="${uploadedFiles.length-1}">✕</button>
                `;
                previewGrid.appendChild(div);
                div.querySelector('.remove-photo').addEventListener('click', (ev) => {
                    const idx = parseInt(ev.target.dataset.index);
                    uploadedFiles.splice(idx, 1);
                    ev.target.closest('.photo-preview').remove();
                    document.querySelectorAll('.remove-photo').forEach((btn, i) => btn.dataset.index = i);
                });
            };
            reader.readAsDataURL(file);
        }
    }

    // ===== СЛОТЫ =====
    function renderSlots() {
        const container = document.getElementById('slot-list');
        if (!container) return;
        container.innerHTML = '';
        slots.forEach((slot, idx) => {
            const div = document.createElement('div');
            div.className = 'slot-item';
            div.innerHTML = `
                <input type="datetime-local" class="slot-start" value="${slot.start}" data-idx="${idx}">
                <span>—</span>
                <input type="datetime-local" class="slot-end" value="${slot.end}" data-idx="${idx}">
                <button class="remove-slot" data-idx="${idx}">✕</button>
            `;
            container.appendChild(div);
        });
        document.querySelectorAll('.slot-start').forEach(input => {
            input.addEventListener('change', (e) => {
                const idx = e.target.dataset.idx;
                slots[idx].start = e.target.value;
                updateSlotsJson();
            });
        });
        document.querySelectorAll('.slot-end').forEach(input => {
            input.addEventListener('change', (e) => {
                const idx = e.target.dataset.idx;
                slots[idx].end = e.target.value;
                updateSlotsJson();
            });
        });
        document.querySelectorAll('.remove-slot').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const idx = btn.dataset.idx;
                slots.splice(idx, 1);
                renderSlots();
                updateSlotsJson();
            });
        });
    }

    function addSlot() {
        const today = new Date();
        const start = new Date(today);
        start.setHours(18, 0, 0, 0);
        const end = new Date(today);
        end.setHours(19, 0, 0, 0);
        const startStr = start.toISOString().slice(0, 16);
        const endStr = end.toISOString().slice(0, 16);
        slots.push({ start: startStr, end: endStr });
        renderSlots();
        updateSlotsJson();
    }

    function updateSlotsJson() {
        document.getElementById('slots_json').value = JSON.stringify(slots);
    }

    // ===== ДИНАМИЧЕСКИЕ ПОЛЯ ДЛЯ БЛОКОВ =====
    const fieldTemplates = {
        housing: {
            main: `
                <div class="form-group">
                    <label class="form-label required">Тип операции</label>
                    <select name="operation_type" class="form-select" required>
                        <option value="rent">Сдам</option>
                        <option value="rent_room">Сдам комнату</option>
                        <option value="rent_short">Сдам посуточно</option>
                        <option value="sale">Продам</option>
                        <option value="buy">Сниму</option>
                    </select>
                    <div class="form-error" id="operation-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label required">Цена (₽/мес)</label>
                    <input type="number" name="price" class="form-input" min="0" step="1" required>
                    <div class="form-error" id="price-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Комнат</label>
                    <input type="number" name="rooms" class="form-input" min="0" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label">Этаж / этажность</label>
                    <input type="text" name="floor" class="form-input" placeholder="3/9">
                </div>
                <div class="form-group">
                    <label class="form-label">Площадь (м²)</label>
                    <input type="number" name="area" class="form-input" min="0" step="0.1">
                </div>
            `,
            extra: `
                <div class="form-group">
                    <label class="form-label">Адрес (метро/район)</label>
                    <input type="text" name="address" class="form-input" maxlength="200">
                </div>
            `
        },
        job: {
            main: `
                <div class="form-group">
                    <label class="form-label required">Тип</label>
                    <select name="job_type" class="form-select" required>
                        <option value="vacancy">Вакансия (ищу сотрудника)</option>
                        <option value="resume">Резюме (ищу работу)</option>
                        <option value="internship">Стажировка</option>
                    </select>
                    <div class="form-error" id="job-type-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Зарплата (₽/мес)</label>
                    <input type="number" name="salary" class="form-input" min="0" step="1">
                </div>
                <div class="form-group">
                    <label class="form-label required">График работы</label>
                    <select name="schedule" class="form-select" required>
                        <option value="full">Полный день</option>
                        <option value="part">Частичная занятость</option>
                        <option value="remote">Удалённая работа</option>
                        <option value="flexible">Гибкий график</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Опыт работы</label>
                    <input type="text" name="experience" class="form-input" placeholder="от 1 года, без опыта">
                </div>
            `,
            extra: `
                <div class="form-group">
                    <label class="form-label">Контактное лицо</label>
                    <input type="text" name="contact_person" class="form-input">
                </div>
            `
        },
        goods: {
            main: `
                <div class="form-group">
                    <label class="form-label required">Категория товара</label>
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
                    <label class="form-label required">Цена</label>
                    <div class="price-row" style="display: flex; gap: 12px;">
                        <input type="number" name="price" class="form-input" min="0" step="1" style="flex:1" required>
                        <select name="currency" class="form-select" style="width: 100px;">
                            <option value="RUB">₽</option>
                            <option value="USD">$</option>
                            <option value="EUR">€</option>
                            <option value="TON">TON</option>
                        </select>
                    </div>
                    <div class="price-options" style="margin-top: 12px;">
                        <label class="checkbox-label"><input type="checkbox" name="price_negotiable" value="1"> Торг</label>
                        <label class="checkbox-label"><input type="checkbox" name="price_exchange" value="1"> Обмен</label>
                    </div>
                    <div class="form-error" id="price-error"></div>
                </div>
            `,
            extra: `
                <div class="form-group">
                    <label class="form-label">Состояние</label>
                    <select name="condition" class="form-select">
                        <option value="new">Новое</option>
                        <option value="like_new">Как новое</option>
                        <option value="used">Б/у, хорошее</option>
                        <option value="fair">Б/у, удовлетворительное</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_sealed" value="1" id="is_sealed_goods"> 🔄 Деловое предложение (обратный аукцион)
                    </label>
                    <div class="form-hint">Покупатели смогут предлагать свою цену. Вы ничем не обязаны.</div>
                </div>
                <div class="form-group" id="min_offer_group_goods" style="display: none;">
                    <label class="form-label">Минимальная скидка для предложений</label>
                    <select name="min_offer_percent" class="form-select">
                        <option value="2">2%</option>
                        <option value="5">5%</option>
                        <option value="10">10%</option>
                        <option value="15">15%</option>
                        <option value="20">20%</option>
                    </select>
                    <div class="form-hint">Предложения с большей скидкой не будут приниматься.</div>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="video_call_enabled" value="1"> 🎥 Разрешить видео-звонок
                    </label>
                </div>
            `
        },
        services: {
            main: `
                <div class="form-group">
                    <label class="form-label required">Категория услуги</label>
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
                    <label class="form-label">Цена</label>
                    <div class="price-row" style="display: flex; gap: 12px;">
                        <input type="number" name="price" class="form-input" min="0" step="1" style="flex:1">
                        <select name="currency" class="form-select" style="width: 100px;">
                            <option value="RUB">₽</option>
                            <option value="USD">$</option>
                            <option value="EUR">€</option>
                            <option value="TON">TON</option>
                        </select>
                    </div>
                    <label class="checkbox-label"><input type="checkbox" name="price_negotiable" value="1"> Договорная</label>
                    <div class="form-error" id="price-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Опыт (лет)</label>
                    <input type="number" name="experience" class="form-input" min="0" step="1">
                </div>
            `,
            extra: `
                <div class="form-group">
                    <label class="form-label">Выезд к клиенту</label>
                    <label class="checkbox-label"><input type="checkbox" name="onsite" value="1"> Да</label>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="is_sealed" value="1" id="is_sealed_services"> 🔄 Деловое предложение (обратный аукцион)
                    </label>
                    <div class="form-hint">Покупатели смогут предлагать свою цену. Вы ничем не обязаны.</div>
                </div>
                <div class="form-group" id="min_offer_group_services" style="display: none;">
                    <label class="form-label">Минимальная скидка для предложений</label>
                    <select name="min_offer_percent" class="form-select">
                        <option value="2">2%</option>
                        <option value="5">5%</option>
                        <option value="10">10%</option>
                        <option value="15">15%</option>
                        <option value="20">20%</option>
                    </select>
                    <div class="form-hint">Предложения с большей скидкой не будут приниматься.</div>
                </div>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="video_call_enabled" value="1"> 🎥 Разрешить видео-звонок
                    </label>
                </div>
            `
        },
        community: {
            main: `
                <div class="form-group">
                    <label class="form-label required">Тип</label>
                    <select name="event_type" class="form-select" required>
                        <option value="event">Событие / Мероприятие</option>
                        <option value="ride">Попутчик</option>
                        <option value="lost">Потеряно / Найдено</option>
                        <option value="volunteer">Волонтёрство</option>
                    </select>
                    <div class="form-error" id="event-type-error"></div>
                </div>
                <div class="form-group">
                    <label class="form-label">Дата события (если применимо)</label>
                    <input type="date" name="event_date" class="form-input">
                </div>
                <div class="form-group">
                    <label class="form-label">Контакт (Telegram / WhatsApp)</label>
                    <input type="text" name="contact" class="form-input">
                </div>
            `,
            extra: ``
        }
    };

    function renderFields(block) {
        const container = document.getElementById('dynamic-fields');
        const extraContainer = document.getElementById('extra-fields');
        if (fieldTemplates[block]) {
            container.innerHTML = fieldTemplates[block].main;
            extraContainer.innerHTML = fieldTemplates[block].extra || '';
        } else {
            container.innerHTML = '';
            extraContainer.innerHTML = '';
        }
        const slotsDiv = document.getElementById('slots-container');
        if (block === 'goods' || block === 'services') {
            slotsDiv.style.display = 'block';
            const isSealedCheck = document.getElementById(`is_sealed_${block}`);
            const minGroup = document.getElementById(`min_offer_group_${block}`);
            if (isSealedCheck) {
                isSealedCheck.addEventListener('change', () => {
                    minGroup.style.display = isSealedCheck.checked ? 'block' : 'none';
                });
            }
        } else {
            slotsDiv.style.display = 'none';
        }
    }

    // ===== БЛОКИ: переключение =====
    const blockBtns = document.querySelectorAll('.block-btn');
    function setActiveBlock(block) {
        currentBlock = block;
        blockBtns.forEach(btn => {
            if (btn.dataset.block === block) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });
        document.getElementById('listing-type').value = block;
        renderFields(block);
        clearError('category');
        clearError('price');
        clearError('operation');
        clearError('job-type');
        clearError('event-type');
    }
    blockBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            setActiveBlock(btn.dataset.block);
            saveDraft();
        });
    });
    setActiveBlock('housing');

    // ===== АВТОДОПОЛНЕНИЕ ГОРОДА (через наш API) =====
    const cityInput = document.getElementById('city');
    const citySuggestions = document.getElementById('city-suggestions');

    cityInput.addEventListener('input', () => {
        clearTimeout(citySuggestDebounceTimer);
        const query = cityInput.value.trim();
        if (query.length < 2) {
            citySuggestions.style.display = 'none';
            return;
        }
        citySuggestDebounceTimer = setTimeout(async () => {
            try {
                const response = await fetch('/api/geo/city.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'suggest',
                        query: query,
                        limit: 10,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                if (data.success && data.data && data.data.length) {
                    citySuggestions.innerHTML = data.data.map(city => `
                        <div class="city-suggestion" data-id="${city.id}" data-lat="${city.latitude}" data-lon="${city.longitude}">
                            ${city.city_name} ${city.region_name ? `(${city.region_name})` : ''}
                        </div>
                    `).join('');
                    citySuggestions.style.display = 'block';
                    document.querySelectorAll('.city-suggestion').forEach(el => {
                        el.addEventListener('click', () => {
                            cityInput.value = el.textContent.split(' (')[0];
                            document.getElementById('lat').value = el.dataset.lat;
                            document.getElementById('lng').value = el.dataset.lon;
                            citySuggestions.style.display = 'none';
                        });
                    });
                } else {
                    citySuggestions.style.display = 'none';
                }
            } catch (e) {
                console.debug('City suggest failed:', e);
                citySuggestions.style.display = 'none';
            }
        }, 300);
    });

    document.addEventListener('click', (e) => {
        if (!cityInput.contains(e.target) && !citySuggestions.contains(e.target)) {
            citySuggestions.style.display = 'none';
        }
    });

    // ===== ДОПОЛНИТЕЛЬНЫЙ БЛОК =====
    const toggleBtn = document.getElementById('toggle-collapse');
    const collapseDiv = document.getElementById('collapse-content');
    let isCollapsed = true;
    toggleBtn.addEventListener('click', () => {
        isCollapsed = !isCollapsed;
        if (isCollapsed) {
            collapseDiv.classList.remove('open');
            toggleBtn.innerHTML = '<i class="hgi hgi-stroke-chevron-down"></i> Дополнительно';
        } else {
            collapseDiv.classList.add('open');
            toggleBtn.innerHTML = '<i class="hgi hgi-stroke-chevron-up"></i> Скрыть дополнительные';
        }
    });

    // ===== ЧЕРНОВИК =====
    async function saveDraft() {
        const formData = new FormData(document.getElementById('listing-form'));
        const data = {};
        for (let [key, val] of formData.entries()) {
            data[key] = val;
        }
        try {
            const response = await fetch('/api/drafts/save', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken, data: data })
            });
            const result = await response.json();
            if (!result.success) console.warn('Ошибка сохранения черновика');
        } catch (e) {
            console.error(e);
        }
    }
    function scheduleAutoSave() {
        if (autoSaveTimer) clearTimeout(autoSaveTimer);
        autoSaveTimer = setTimeout(() => saveDraft(), 30000);
    }
    document.querySelectorAll('#listing-form input, #listing-form textarea, #listing-form select').forEach(field => {
        field.addEventListener('input', scheduleAutoSave);
        field.addEventListener('change', scheduleAutoSave);
    });
    async function loadDraft() {
        try {
            const response = await fetch('/api/drafts/load', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });
            const result = await response.json();
            if (result.success && result.data) {
                const data = result.data;
                for (const [key, value] of Object.entries(data)) {
                    const field = document.querySelector(`[name="${key}"]`);
                    if (field) {
                        if (field.type === 'checkbox') field.checked = value == 1;
                        else field.value = value;
                    }
                }
                if (data.type && data.type !== currentBlock) setActiveBlock(data.type);
                if (data.slots_json) {
                    slots = JSON.parse(data.slots_json);
                    renderSlots();
                    updateSlotsJson();
                }
            }
        } catch (e) { console.error(e); }
    }

    // ===== ВАЛИДАЦИЯ =====
    function validateForm() {
        let valid = true;
        clearError('title');
        clearError('description');
        clearError('city');
        clearError('photos');

        const title = document.getElementById('title').value.trim();
        if (title.length < 5 || title.length > 100) {
            showError('title', 'Заголовок должен быть от 5 до 100 символов');
            valid = false;
        }
        const description = document.getElementById('description').value.trim();
        if (description.length < 50 || description.length > 3000) {
            showError('description', 'Описание должно быть от 50 до 3000 символов');
            valid = false;
        }
        const city = document.getElementById('city').value.trim();
        if (!city) {
            showError('city', 'Укажите город');
            valid = false;
        }

        const block = currentBlock;
        if (block === 'housing') {
            const operation = document.querySelector('[name="operation_type"]');
            const price = document.querySelector('[name="price"]');
            if (!operation?.value) { showError('operation', 'Выберите тип операции'); valid = false; }
            if (!price?.value || price.value <= 0) { showError('price', 'Укажите цену'); valid = false; }
        } else if (block === 'job') {
            const jobType = document.querySelector('[name="job_type"]');
            if (!jobType?.value) { showError('job-type', 'Выберите тип'); valid = false; }
        } else if (block === 'goods') {
            const category = document.querySelector('[name="category_id"]');
            const price = document.querySelector('[name="price"]');
            if (!category?.value) { showError('category', 'Выберите категорию'); valid = false; }
            if (!price?.value || price.value <= 0) { showError('price', 'Укажите цену'); valid = false; }
            const isSealed = document.querySelector('[name="is_sealed"]')?.checked;
            if (isSealed) {
                const minOffer = document.querySelector('[name="min_offer_percent"]')?.value;
                if (!minOffer) { showError('min_offer_percent', 'Укажите минимальную скидку'); valid = false; }
            }
        } else if (block === 'services') {
            const category = document.querySelector('[name="category_id"]');
            if (!category?.value) { showError('category', 'Выберите категорию'); valid = false; }
            const isSealed = document.querySelector('[name="is_sealed"]')?.checked;
            if (isSealed) {
                const minOffer = document.querySelector('[name="min_offer_percent"]')?.value;
                if (!minOffer) { showError('min_offer_percent', 'Укажите минимальную скидку'); valid = false; }
            }
        } else if (block === 'community') {
            const eventType = document.querySelector('[name="event_type"]');
            if (!eventType?.value) { showError('event-type', 'Выберите тип'); valid = false; }
        }
        return valid;
    }

    // ===== AI-УЛУЧШИТЕЛЬ ТЕКСТА =====
    const improveBtn = document.getElementById('ai-improve-btn');
    if (improveBtn) {
        improveBtn.addEventListener('click', async () => {
            const title = document.getElementById('title').value;
            const description = document.getElementById('description').value;
            const categorySelect = document.querySelector('[name="category_id"]');
            const category = categorySelect ? categorySelect.options[categorySelect.selectedIndex]?.text : '';
            const price = document.querySelector('[name="price"]')?.value || 0;

            if (!title && !description) {
                alert('Заполните заголовок или описание для улучшения');
                return;
            }

            improveBtn.disabled = true;
            const originalText = improveBtn.innerText;
            improveBtn.innerText = '⏳ Обработка...';

            try {
                const response = await fetch('/api/ai/improve-listing.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        title: title,
                        description: description,
                        category: category,
                        price: price,
                        csrf_token: csrfToken
                    })
                });
                const data = await response.json();
                if (data.success) {
                    if (data.title) document.getElementById('title').value = data.title;
                    if (data.description) document.getElementById('description').value = data.description;
                    showToast('Текст успешно улучшен!', 'success');
                } else {
                    showToast('Ошибка: ' + (data.error || 'Неизвестная ошибка'), 'error');
                }
            } catch (err) {
                console.error(err);
                showToast('Ошибка сети. Попробуйте позже.', 'error');
            } finally {
                improveBtn.disabled = false;
                improveBtn.innerText = originalText;
            }
        });
    }

    // ===== AI-ИМПОРТ ПО ССЫЛКЕ =====
    const fetchBtn = document.getElementById('fetch-product-btn');
    if (fetchBtn) {
        fetchBtn.addEventListener('click', async () => {
            const url = document.getElementById('product-url').value.trim();
            if (!url) {
                alert('Введите ссылку');
                return;
            }
            const loading = document.getElementById('fetch-loading');
            const errorDiv = document.getElementById('fetch-error');
            fetchBtn.disabled = true;
            loading.style.display = 'inline-block';
            errorDiv.style.display = 'none';

            try {
                const response = await fetch('/api/ai/fetch-product.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url: url, csrf_token: csrfToken })
                });
                const data = await response.json();
                if (data.success) {
                    if (data.title) document.getElementById('title').value = data.title;
                    if (data.description) document.getElementById('description').value = data.description;
                    showToast('Данные успешно загружены!', 'success');
                } else {
                    errorDiv.textContent = data.error;
                    errorDiv.style.display = 'block';
                }
            } catch (err) {
                errorDiv.textContent = 'Ошибка сети';
                errorDiv.style.display = 'block';
            } finally {
                fetchBtn.disabled = false;
                loading.style.display = 'none';
            }
        });
    }

    // ===== ОТПРАВКА ФОРМЫ =====
    const form = document.getElementById('listing-form');
    const submitBtn = document.getElementById('submit-btn');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (isSubmitting) return;
        const now = Date.now();
        if (now - lastSubmitTime < 5000) {
            showToast('Пожалуйста, подождите перед повторной отправкой', 'warning');
            return;
        }
        lastSubmitTime = now;

        if (!validateForm()) return;

        const website = form.querySelector('input[name="website_url"]').value;
        const phoneFake = form.querySelector('input[name="phone_fake"]').value;
        if (website || phoneFake) {
            showToast('Пожалуйста, не используйте автоматические формы', 'error');
            return;
        }

        isSubmitting = true;
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<span class="spinner"></span> Публикация...';
        submitBtn.disabled = true;

        const formData = new FormData(form);
        formData.append('action', 'create');
        formData.append('csrf_token', csrfToken);
        const apiType = blockToApiType[currentBlock] || 'sell';
        formData.append('type', apiType);
        formData.append('fill_time', Math.floor((Date.now() - startTime) / 1000));
        formData.append('slots_json', JSON.stringify(slots));

        try {
            const controller = new AbortController();
            const timeoutId = setTimeout(() => controller.abort(), 15000);
            const response = await fetch('/api/listings.php', {
                method: 'POST',
                body: formData,
                signal: controller.signal
            });
            clearTimeout(timeoutId);
            const result = await response.json();
            if (!result.success) throw new Error(result.error || 'Ошибка создания объявления');
            const listingId = result.listing_id;

            if (uploadedFiles.length > 0) {
                for (const file of uploadedFiles) {
                    const photoForm = new FormData();
                    photoForm.append('action', 'upload');
                    photoForm.append('listing_id', listingId);
                    photoForm.append('csrf_token', csrfToken);
                    photoForm.append('file', file);
                    await fetch('/api/listings/photos.php', { method: 'POST', body: photoForm });
                }
            }

            await fetch('/api/drafts/delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: csrfToken })
            });

            showToast('Объявление опубликовано!');
            setTimeout(() => window.location.href = '/listing?id=' + listingId, 1500);
        } catch (err) {
            console.error(err);
            if (err.name === 'AbortError') {
                showToast('Сервер не отвечает. Попробуйте позже.', 'error');
            } else {
                showToast(err.message || 'Ошибка при публикации', 'error');
            }
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
            isSubmitting = false;
        }
    });

    // ===== ИНИЦИАЛИЗАЦИЯ =====
    document.addEventListener('DOMContentLoaded', () => {
        loadDraft();
        document.getElementById('fill_time').value = Math.floor((Date.now() - startTime) / 1000);
        const addSlotBtn = document.getElementById('add-slot-btn');
        if (addSlotBtn) addSlotBtn.addEventListener('click', addSlot);
    });
</script>

<?php
// Подключаем общий footer
require_once __DIR__ . '/../includes/footer.php';
?>