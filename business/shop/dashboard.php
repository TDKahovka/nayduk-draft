<?php
/* ============================================
   НАЙДУК — Личный кабинет владельца магазина (v3.0)
   - Управление товарами, вариантами, отзывами
   - Конструктор блоков (drag‑and‑drop)
   - Импорт из соцсетей, FAQ
   ============================================ */

session_start();
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$userId = $_SESSION['user_id'];

$shop = $db->fetchOne("SELECT * FROM shops WHERE user_id = ?", [$userId]);
if (!$shop) {
    header('Location: /business/shop/create');
    exit;
}

$csrfToken = csrf_token();
$layout = json_decode($shop['layout'] ?? '[]', true);
$faq = json_decode($shop['faq'] ?? '[]', true);
$availableBlocks = [
    ['type' => 'hero', 'name' => 'Заголовок', 'icon' => '🏠'],
    ['type' => 'products', 'name' => 'Товары', 'icon' => '📦'],
    ['type' => 'reviews', 'name' => 'Отзывы', 'icon' => '⭐'],
    ['type' => 'faq', 'name' => 'Вопросы-ответы', 'icon' => '❓'],
    ['type' => 'map', 'name' => 'Карта', 'icon' => '🗺️'],
    ['type' => 'contacts', 'name' => 'Контакты', 'icon' => '📞']
];
$pageTitle = 'Управление магазином — Найдук';
?>
<!DOCTYPE html>
<html lang="ru" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <link rel="stylesheet" href="/css/themes.css">
    <style>
        .dashboard-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--border); margin-bottom: 30px; flex-wrap: wrap; }
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
        }
        .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .block-list { display: flex; flex-direction: column; gap: 12px; }
        .block-item {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            cursor: move;
        }
        .block-actions { display: flex; gap: 8px; }
        .block-actions button { background: none; border: none; cursor: pointer; font-size: 18px; }
        .faq-item { margin-bottom: 16px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius); }
        .modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; visibility: hidden; opacity: 0; transition: all 0.2s; }
        .modal.active { visibility: visible; opacity: 1; }
        .modal-content { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; max-width: 500px; width: 90%; }
        .form-group { margin-bottom: 16px; }
        .form-input, .form-textarea, .form-select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--bg); color: var(--text); }
        .btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 16px; border-radius: var(--radius-full); font-weight: 600; cursor: pointer; border: none; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
        .btn-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
        .product-table { width: 100%; border-collapse: collapse; }
        .product-table th, .product-table td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-light); }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .page-btn { width: 36px; height: 36px; border-radius: var(--radius-full); border: 1px solid var(--border); background: var(--surface); cursor: pointer; }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        @media (max-width: 768px) {
            .dashboard-container { padding: 20px; }
            .product-table, .product-table tbody, .product-table tr, .product-table td { display: block; }
            .product-table thead { display: none; }
            .product-table td { display: flex; justify-content: space-between; align-items: center; padding: 8px; border-bottom: 1px solid var(--border-light); }
            .product-table td::before { content: attr(data-label); font-weight: 600; width: 40%; }
        }
    </style>
</head>
<body>
<div class="dashboard-container">
    <h1>Управление магазином «<?= htmlspecialchars($shop['name']) ?>»</h1>
    <div class="tabs">
        <button class="tab-btn active" data-tab="overview">Обзор</button>
        <button class="tab-btn" data-tab="products">Товары</button>
        <button class="tab-btn" data-tab="design">Дизайн</button>
        <button class="tab-btn" data-tab="settings">Настройки</button>
        <button class="tab-btn" data-tab="faq">Вопросы</button>
        <button class="tab-btn" data-tab="tariff">Тариф</button>
    </div>

    <!-- Обзор -->
    <div id="tab-overview" class="tab-content active">
        <div class="stats-cards">
            <div class="stat-card"><div class="stat-label">Товаров</div><div class="stat-value" id="stat-products">—</div></div>
            <div class="stat-card"><div class="stat-label">Заказов (мес)</div><div class="stat-value" id="stat-orders">—</div></div>
            <div class="stat-card"><div class="stat-label">Выручка</div><div class="stat-value" id="stat-revenue">— ₽</div></div>
            <div class="stat-card"><div class="stat-label">Просмотры</div><div class="stat-value" id="stat-views">—</div></div>
        </div>
        <canvas id="chart" height="200"></canvas>
    </div>

    <!-- Товары -->
    <div id="tab-products" class="tab-content">
        <div style="margin-bottom: 20px;">
            <button class="btn btn-primary" onclick="openProductModal()">➕ Добавить товар</button>
            <button class="btn btn-secondary" onclick="importProducts()">📥 Импорт из соцсетей</button>
        </div>
        <div class="table-container">
            <table class="product-table" id="products-table">
                <thead><tr><th>ID</th><th>Название</th><th>Цена</th><th>Варианты</th><th>Статус</th><th>Действия</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
        <div id="products-pagination" class="pagination"></div>
    </div>

    <!-- Дизайн (конструктор блоков) -->
    <div id="tab-design" class="tab-content">
        <h3>Доступные блоки</h3>
        <div id="available-blocks" class="block-list" style="margin-bottom: 20px;">
            <?php foreach ($availableBlocks as $b): ?>
            <div class="block-item" draggable="true" data-type="<?= $b['type'] ?>">
                <span><strong><?= $b['icon'] ?> <?= $b['name'] ?></strong></span>
                <button class="add-block" data-type="<?= $b['type'] ?>">➕ Добавить</button>
            </div>
            <?php endforeach; ?>
        </div>
        <h3>Макет страницы</h3>
        <div id="layout-list" class="block-list" data-layout='<?= json_encode($layout) ?>'>
            <?php foreach ($layout as $index => $block): ?>
            <div class="block-item" data-index="<?= $index ?>">
                <span><strong><?= $availableBlocks[array_search($block['type'], array_column($availableBlocks, 'type'))]['icon'] ?? '📦' ?> <?= ucfirst($block['type']) ?></strong></span>
                <div class="block-actions">
                    <button class="edit-block" data-index="<?= $index ?>">✏️</button>
                    <button class="remove-block" data-index="<?= $index ?>">🗑️</button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <button id="save-layout" class="btn btn-primary">Сохранить макет</button>
    </div>

    <!-- Настройки магазина -->
    <div id="tab-settings" class="tab-content">
        <form id="shop-settings-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group"><label>Название</label><input type="text" name="name" class="form-input" value="<?= htmlspecialchars($shop['name']) ?>" required></div>
            <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea" rows="4"><?= htmlspecialchars($shop['description']) ?></textarea></div>
            <div class="form-group"><label>Телефон</label><input type="tel" name="contact_phone" class="form-input" value="<?= htmlspecialchars($shop['contact_phone']) ?>"></div>
            <div class="form-group"><label>Email</label><input type="email" name="contact_email" class="form-input" value="<?= htmlspecialchars($shop['contact_email']) ?>"></div>
            <div class="form-group"><label>Telegram</label><input type="text" name="contact_telegram" class="form-input" value="<?= htmlspecialchars($shop['contact_telegram']) ?>"></div>
            <div class="form-group"><label>WhatsApp</label><input type="text" name="contact_whatsapp" class="form-input" value="<?= htmlspecialchars($shop['contact_whatsapp']) ?>"></div>
            <div class="form-group"><label>Адрес</label><input type="text" name="address" class="form-input" value="<?= htmlspecialchars($shop['address']) ?>"></div>
            <div class="form-group"><label>Тема</label><select name="theme" class="form-select">
                <option value="light" <?= ($shop['theme'] ?? 'light') === 'light' ? 'selected' : '' ?>>Светлая</option>
                <option value="dark" <?= ($shop['theme'] ?? 'light') === 'dark' ? 'selected' : '' ?>>Тёмная</option>
                <option value="neutral" <?= ($shop['theme'] ?? 'light') === 'neutral' ? 'selected' : '' ?>>Нейтральная</option>
                <option value="contrast" <?= ($shop['theme'] ?? 'light') === 'contrast' ? 'selected' : '' ?>>Контрастная</option>
                <option value="nature" <?= ($shop['theme'] ?? 'light') === 'nature' ? 'selected' : '' ?>>Природная</option>
            </select></div>
            <div class="form-group"><label>Логотип</label><input type="file" id="logo-file" accept="image/*"></div>
            <div class="form-group"><label>Баннер</label><input type="file" id="banner-file" accept="image/*"></div>
            <button type="submit" class="btn btn-primary">Сохранить</button>
        </form>
    </div>

    <!-- FAQ -->
    <div id="tab-faq" class="tab-content">
        <button id="add-faq-btn" class="btn btn-primary">➕ Добавить вопрос</button>
        <div id="faq-list">
            <?php foreach ($faq as $index => $item): ?>
            <div class="faq-item" data-index="<?= $index ?>">
                <div class="form-group"><label>Вопрос</label><input type="text" class="faq-question-input form-input" value="<?= htmlspecialchars($item['question']) ?>"></div>
                <div class="form-group"><label>Ответ</label><textarea class="faq-answer-input form-textarea" rows="2"><?= htmlspecialchars($item['answer']) ?></textarea></div>
                <button class="remove-faq btn btn-danger btn-small">Удалить</button>
            </div>
            <?php endforeach; ?>
        </div>
        <button id="save-faq" class="btn btn-primary">Сохранить FAQ</button>
    </div>

    <!-- Тариф -->
    <div id="tab-tariff" class="tab-content">
        <div class="stats-cards">
            <div class="stat-card"><div class="stat-label">Текущий тариф</div><div class="stat-value"><?= ucfirst($shop['plan']) ?></div></div>
            <div class="stat-card"><div class="stat-label">Товаров / лимит</div><div class="stat-value" id="products-limit">— / —</div></div>
        </div>
        <button class="btn btn-primary" onclick="upgradePlan()">🆙 Перейти на платный тариф</button>
    </div>
</div>

<!-- Модальные окна -->
<div id="product-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><h3 id="product-modal-title">Добавление товара</h3><button class="close-modal" onclick="closeModal('product-modal')">✕</button></div>
        <form id="product-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="product_id" id="product-id">
            <div class="form-group"><label>Название</label><input type="text" name="title" class="form-input" required></div>
            <div class="form-group"><label>Цена</label><input type="number" name="price" step="0.01" class="form-input" required></div>
            <div class="form-group"><label>Описание</label><textarea name="description" class="form-textarea" rows="3"></textarea></div>
            <div class="form-group"><label>Фото</label><div id="photo-dropzone" class="dropzone">Нажмите или перетащите фото</div><input type="file" id="photo-input" multiple style="display: none;"></div>
            <div id="photo-preview" class="photo-preview-grid"></div>
            <div class="form-group"><label>Варианты (цвет/размер)</label><div id="options-container"></div><button type="button" id="add-option-btn" class="btn btn-secondary btn-small">+ Добавить вариант</button></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить</button></div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    const shopId = <?= $shop['id'] ?>;
    let currentProductsPage = 1;
    let uploadedFiles = [];
    let layout = <?= json_encode($layout) ?>;

    // ===== ОБЩИЕ =====
    function showToast(msg, type = 'success') {
        const colors = { success: '#34C759', error: '#FF3B30', warning: '#FF9500', info: '#5A67D8' };
        Toastify({ text: msg, duration: 3000, backgroundColor: colors[type] || colors.info }).showToast();
    }
    async function apiRequest(endpoint, data) {
        const response = await fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(data) });
        return await response.json();
    }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }
    function openModal(id) { document.getElementById(id).classList.add('active'); }

    // ===== ВКЛАДКИ =====
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`tab-${tab}`).classList.add('active');
            if (tab === 'overview') loadOverview();
            if (tab === 'products') loadProducts(1);
        });
    });

    // ===== ОБЗОР =====
    async function loadOverview() {
        const res = await apiRequest('/api/shop/manage.php', { action: 'stats', csrf_token: csrfToken });
        if (res.success) {
            document.getElementById('stat-products').innerText = res.stats.products;
            document.getElementById('stat-views').innerText = res.stats.views;
            document.getElementById('stat-orders').innerText = res.stats.orders || 0;
            document.getElementById('stat-revenue').innerHTML = (res.stats.revenue || 0) + ' ₽';
            const limit = { free: 10, premium: 100, business: 1000, corporate: 1000000 }['<?= $shop['plan'] ?>'] || 10;
            document.getElementById('products-limit').innerHTML = `${res.stats.products} / ${limit}`;
        }
        const analytics = await apiRequest('/api/shop/manage.php', { action: 'get_analytics', period: 'month', csrf_token: csrfToken });
        if (analytics.success && analytics.stats) {
            const ctx = document.getElementById('chart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: { labels: analytics.stats.map(s => s.date), datasets: [{ label: 'Просмотры', data: analytics.stats.map(s => s.views), borderColor: '#4A90E2' }] }
            });
        }
    }

    // ===== ТОВАРЫ =====
    async function loadProducts(page = 1) {
        currentProductsPage = page;
        const res = await apiRequest('/api/shop/manage.php', { action: 'get_products', page: page, limit: 20, csrf_token: csrfToken });
        if (res.success) {
            const tbody = document.querySelector('#products-table tbody');
            tbody.innerHTML = res.products.map(p => `
                <tr>
                    <td data-label="ID">${p.id}</td>
                    <td data-label="Название">${escapeHtml(p.title)}</td>
                    <td data-label="Цена">${formatPrice(p.price)} ₽</td>
                    <td data-label="Варианты">${p.options ? JSON.parse(p.options).length : 0}</td>
                    <td data-label="Статус">${p.is_active ? 'Активен' : 'Неактивен'}</td>
                    <td data-label="Действия">
                        <button class="btn btn-secondary btn-small" onclick="editProduct(${p.id})">✏️</button>
                        <button class="btn btn-danger btn-small" onclick="deleteProduct(${p.id})">🗑️</button>
                    </td>
                </tr>
            `).join('');
            renderPagination(res.total, res.page, res.pages);
        }
    }
    function renderPagination(total, current, pages) {
        const container = document.getElementById('products-pagination');
        if (pages <= 1) { container.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= pages; i++) html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="loadProducts(${i})">${i}</button>`;
        container.innerHTML = html;
    }
    async function editProduct(id) {
        const res = await apiRequest('/api/shop/manage.php', { action: 'get_products', csrf_token: csrfToken, product_id: id });
        if (res.success && res.products.length) {
            const p = res.products[0];
            document.getElementById('product-modal-title').innerText = 'Редактирование товара';
            document.getElementById('product-id').value = p.id;
            document.querySelector('#product-form [name="title"]').value = p.title;
            document.querySelector('#product-form [name="price"]').value = p.price;
            document.querySelector('#product-form [name="description"]').value = p.description;
            // Загрузить варианты (пока заглушка)
            openModal('product-modal');
        }
    }
    async function deleteProduct(id) {
        if (!confirm('Удалить товар?')) return;
        const res = await apiRequest('/api/shop/manage.php', { action: 'delete_product', product_id: id, csrf_token: csrfToken });
        if (res.success) { showToast('Товар удалён'); loadProducts(currentProductsPage); }
        else showToast(res.error || 'Ошибка', 'error');
    }

    // ===== КОНСТРУКТОР БЛОКОВ =====
    function renderLayout() {
        const container = document.getElementById('layout-list');
        container.innerHTML = layout.map((block, idx) => `
            <div class="block-item" data-index="${idx}">
                <span><strong>${block.type}</strong></span>
                <div class="block-actions">
                    <button class="edit-block" data-index="${idx}">✏️</button>
                    <button class="remove-block" data-index="${idx}">🗑️</button>
                </div>
            </div>
        `).join('');
        document.querySelectorAll('.edit-block').forEach(btn => btn.addEventListener('click', () => editBlock(parseInt(btn.dataset.index))));
        document.querySelectorAll('.remove-block').forEach(btn => btn.addEventListener('click', () => { layout.splice(parseInt(btn.dataset.index), 1); renderLayout(); }));
    }
    function editBlock(index) {
        const block = layout[index];
        const newParams = prompt('Параметры блока (JSON):', JSON.stringify(block));
        if (newParams) { layout[index] = JSON.parse(newParams); renderLayout(); }
    }
    document.querySelectorAll('.add-block').forEach(btn => {
        btn.addEventListener('click', () => {
            const type = btn.dataset.type;
            layout.push({ type: type });
            renderLayout();
        });
    });
    document.getElementById('save-layout').addEventListener('click', async () => {
        const res = await apiRequest('/api/shop/manage.php', { action: 'update_layout', layout: JSON.stringify(layout), csrf_token: csrfToken });
        if (res.success) showToast('Макет сохранён');
        else showToast('Ошибка', 'error');
    });
    renderLayout();

    // ===== НАСТРОЙКИ =====
    document.getElementById('shop-settings-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        const res = await apiRequest('/api/shop/manage.php', { ...data, action: 'update', csrf_token: csrfToken });
        if (res.success) showToast('Настройки сохранены');
        else showToast(res.error || 'Ошибка', 'error');
    });

    // ===== FAQ =====
    document.getElementById('add-faq-btn').addEventListener('click', () => {
        const container = document.getElementById('faq-list');
        const div = document.createElement('div');
        div.className = 'faq-item';
        div.innerHTML = `
            <div class="form-group"><label>Вопрос</label><input type="text" class="faq-question-input form-input" placeholder="Вопрос"></div>
            <div class="form-group"><label>Ответ</label><textarea class="faq-answer-input form-textarea" rows="2" placeholder="Ответ"></textarea></div>
            <button class="remove-faq btn btn-danger btn-small">Удалить</button>
        `;
        container.appendChild(div);
        div.querySelector('.remove-faq').addEventListener('click', () => div.remove());
    });
    document.getElementById('save-faq').addEventListener('click', async () => {
        const faq = [];
        document.querySelectorAll('.faq-item').forEach(item => {
            const question = item.querySelector('.faq-question-input').value;
            const answer = item.querySelector('.faq-answer-input').value;
            if (question && answer) faq.push({ question, answer });
        });
        const res = await apiRequest('/api/shop/manage.php', { action: 'update_faq', faq: JSON.stringify(faq), csrf_token: csrfToken });
        if (res.success) showToast('FAQ сохранён');
        else showToast('Ошибка', 'error');
    });

    // ===== ДРОПЗОН =====
    const dropzone = document.getElementById('photo-dropzone');
    const photoInput = document.getElementById('photo-input');
    dropzone.addEventListener('click', () => photoInput.click());
    dropzone.addEventListener('dragover', (e) => e.preventDefault());
    dropzone.addEventListener('drop', (e) => {
        e.preventDefault();
        handleFiles(e.dataTransfer.files);
    });
    photoInput.addEventListener('change', (e) => handleFiles(e.target.files));
    function handleFiles(files) {
        for (let file of files) {
            const reader = new FileReader();
            reader.onload = (ev) => {
                const div = document.createElement('div');
                div.className = 'photo-preview';
                div.innerHTML = `<img src="${ev.target.result}" style="width:80px;height:80px;object-fit:cover;"><button class="remove-photo">✕</button>`;
                document.getElementById('photo-preview').appendChild(div);
                div.querySelector('.remove-photo').addEventListener('click', () => div.remove());
                uploadedFiles.push(ev.target.result);
            };
            reader.readAsDataURL(file);
        }
    }

    function escapeHtml(str) { return str ? str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m)) : ''; }
    function formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price); }
    function importProducts() { alert('Импорт из соцсетей будет доступен в следующей версии'); }
    function upgradePlan() { alert('Переход на платный тариф (заглушка)'); }

    loadOverview();
    loadProducts(1);
</script>
</body>
</html>