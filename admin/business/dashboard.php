<?php
/* ============================================
   НАЙДУК — Бизнес-кабинет (административная панель)
   Версия 2.0 (март 2026)
   - Полностью адаптивный интерфейс
   - Вкладки: Обзор, Офферы, Импорт, Вебхуки, Выплаты
   - Импорт: загрузка файла/URL, настройка маппинга, отслеживание прогресса
   ============================================ */

session_start();
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../services/Database.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /auth/login');
    exit;
}

$db = Database::getInstance();
$user = $db->getUserById($_SESSION['user_id']);
if (!$user || $user['role'] !== 'admin') {
    header('Location: /');
    exit;
}

$csrfToken = generateCsrfToken();
$pageTitle = 'Бизнес-кабинет — Найдук';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= $csrfToken ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="https://cdn.hugeicons.com/font/hgi-stroke-rounded.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">
    <link rel="stylesheet" href="/css/business.css">
    <link rel="stylesheet" href="/css/premium.css">
    <link rel="stylesheet" href="/css/theme-switch.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ===== ГЛОБАЛЬНЫЕ СТИЛИ ===== */
        .admin-container { max-width: 1400px; margin: 0 auto; padding: 40px 20px; }
        .page-header { margin-bottom: 30px; }
        .tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--border); margin-bottom: 30px; overflow-x: auto; }
        .tab-btn {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            color: var(--text-secondary);
            transition: all 0.2s;
            white-space: nowrap;
        }
        .tab-btn.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        .stats-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 24px;
            transition: all 0.2s;
        }
        .stat-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .stat-label { font-size: 14px; color: var(--text-secondary); margin-bottom: 8px; }
        .stat-value { font-size: 36px; font-weight: 700; color: var(--primary); margin-bottom: 4px; }
        .stat-change { font-size: 13px; color: var(--text-secondary); }

        .filters-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 20px;
            align-items: flex-end;
        }
        .filter-group { flex: 1; min-width: 150px; }
        .filter-label { font-size: 12px; font-weight: 600; margin-bottom: 4px; color: var(--text-secondary); }
        .filter-select, .filter-input {
            width: 100%;
            padding: 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--bg);
            color: var(--text);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: var(--radius-full);
            font-weight: 600;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-primary { background: var(--primary); color: white; }
        .btn-primary:hover { transform: translateY(-2px); }
        .btn-secondary { background: var(--bg-secondary); border: 1px solid var(--border); color: var(--text); }
        .btn-danger { background: rgba(255,59,48,0.1); color: var(--danger); }
        .btn-success { background: rgba(52,199,89,0.1); color: var(--success); }
        .btn-small { padding: 4px 12px; font-size: 13px; }
        .table-container { overflow-x: auto; background: var(--surface); border-radius: var(--radius-xl); border: 1px solid var(--border); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 16px; text-align: left; border-bottom: 1px solid var(--border-light); }
        th { background: var(--bg-secondary); font-weight: 600; font-size: 13px; color: var(--text-secondary); }
        tr:hover td { background: var(--bg-secondary); }
        .pagination { display: flex; justify-content: center; gap: 8px; margin-top: 20px; }
        .page-btn {
            width: 36px; height: 36px;
            border-radius: var(--radius-full);
            border: 1px solid var(--border);
            background: var(--surface);
            cursor: pointer;
        }
        .page-btn.active { background: var(--primary); color: white; border-color: var(--primary); }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }
        .offer-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-xl);
            padding: 20px;
            transition: all 0.2s;
        }
        .offer-card:hover { transform: translateY(-4px); box-shadow: var(--shadow-md); }
        .offer-name { font-size: 18px; font-weight: 700; margin-bottom: 8px; }
        .offer-stats { display: flex; gap: 16px; margin: 12px 0; font-size: 14px; color: var(--text-secondary); }
        .offer-actions { display: flex; gap: 8px; margin-top: 12px; }
        .copy-link { background: none; border: none; cursor: pointer; color: var(--primary); }

        /* ===== ИМПОРТ ===== */
        .import-form-card { margin-bottom: 30px; }
        .preview-table { overflow-x: auto; margin-top: 20px; }
        .preview-table table { font-size: 13px; }
        .mapping-controls { margin: 20px 0; padding: 16px; background: var(--bg-secondary); border-radius: var(--radius); }
        .mapping-row { display: flex; gap: 16px; align-items: center; margin-bottom: 12px; flex-wrap: wrap; }
        .mapping-label { width: 150px; font-weight: 600; }
        .progress-bar { height: 8px; background: var(--bg-secondary); border-radius: var(--radius-full); overflow: hidden; margin: 16px 0; }
        .progress-fill { height: 100%; background: var(--primary); width: 0%; transition: width 0.3s; }
        .import-status { margin-top: 20px; padding: 16px; border-radius: var(--radius); background: var(--bg-secondary); display: none; }
        .spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid rgba(0,0,0,0.1); border-top-color: var(--primary); border-radius: 50%; animation: spin 0.8s linear infinite; }
        @keyframes spin { to { transform: rotate(360deg); } }

        .modal {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: all 0.2s;
        }
        .modal.active { visibility: visible; opacity: 1; }
        .modal-content {
            background: var(--surface);
            border-radius: var(--radius-xl);
            padding: 24px;
            max-width: 600px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header { display: flex; justify-content: space-between; margin-bottom: 20px; }
        .close-modal { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-secondary); }

        @media (max-width: 768px) {
            .admin-container { padding: 20px; }
            .tab-btn { padding: 8px 16px; font-size: 14px; }
            .stats-cards { grid-template-columns: 1fr; }
            .filters-bar { flex-direction: column; }
            .filter-group { width: 100%; }
            .mapping-row { flex-direction: column; align-items: flex-start; }
            .mapping-label { width: auto; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <div class="page-header">
        <h1>📊 Бизнес-кабинет Найдук</h1>
        <p>Управляйте офферами, смотрите статистику, настраивайте вебхуки и выплаты.</p>
    </div>

    <!-- Вкладки -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="overview">📈 Обзор</button>
        <button class="tab-btn" data-tab="offers">🎯 Офферы</button>
        <button class="tab-btn" data-tab="import">📥 Импорт</button>
        <button class="tab-btn" data-tab="webhooks">🔗 Вебхуки</button>
        <button class="tab-btn" data-tab="payouts">💰 Выплаты</button>
    </div>

    <!-- Вкладка: Обзор -->
    <div id="tab-overview" class="tab-content active">
        <div class="stats-cards" id="stats-cards">
            <div class="stat-card"><div class="stat-label">Загрузка...</div><div class="stat-value">—</div></div>
        </div>
        <div class="card">
            <h3>📈 Динамика за 30 дней</h3>
            <canvas id="statsChart" style="height: 300px;"></canvas>
        </div>
        <div class="card" style="margin-top: 20px;">
            <h3>🏆 Топ-5 офферов по доходу</h3>
            <div id="top-offers-list"></div>
        </div>
    </div>

    <!-- Вкладка: Офферы (карточки) -->
    <div id="tab-offers" class="tab-content">
        <div class="filters-bar">
            <div class="filter-group">
                <label class="filter-label">Поиск по названию</label>
                <input type="text" id="offer-search" class="filter-input" placeholder="Введите название">
            </div>
            <div class="filter-group">
                <label class="filter-label">Статус</label>
                <select id="offer-status" class="filter-select">
                    <option value="">Все</option>
                    <option value="1">Активные</option>
                    <option value="0">Неактивные</option>
                </select>
            </div>
            <div class="filter-group">
                <label class="filter-label">Сортировка</label>
                <select id="offer-sort" class="filter-select">
                    <option value="revenue_desc">По доходу (сначала высокие)</option>
                    <option value="revenue_asc">По доходу (сначала низкие)</option>
                    <option value="clicks_desc">По кликам</option>
                    <option value="name_asc">По названию</option>
                </select>
            </div>
            <div class="filter-group">
                <button id="add-offer-btn" class="btn btn-primary">➕ Добавить оффер</button>
            </div>
        </div>
        <div id="offers-container" class="card-grid"></div>
        <div id="offers-pagination" class="pagination"></div>
    </div>

    <!-- Вкладка: Импорт -->
    <div id="tab-import" class="tab-content">
        <div class="card import-form-card">
            <h3>📤 Импорт офферов</h3>
            <form id="import-form" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <div class="form-group">
                    <label>Загрузить файл (CSV, Excel, JSON, XML, ZIP)</label>
                    <input type="file" name="import_file" accept=".csv,.xls,.xlsx,.json,.xml,.zip,.gz" class="form-input">
                </div>
                <div class="form-group">
                    <label>Или укажите URL файла</label>
                    <input type="url" name="import_url" class="form-input" placeholder="https://example.com/offers.csv">
                </div>
                <div class="form-group">
                    <label>Провайдер (опционально, для предустановленных маппингов)</label>
                    <select name="provider" class="form-select">
                        <option value="">Автоопределение</option>
                        <option value="leads.su">Leads.su</option>
                        <option value="admitad">Admitad</option>
                        <option value="actionpay">Actionpay</option>
                    </select>
                </div>
                <div class="form-checkbox">
                    <input type="checkbox" name="dry_run" id="dry_run" value="1">
                    <label for="dry_run">Тестовый импорт (без сохранения)</label>
                </div>
                <button type="submit" class="btn btn-primary" id="import-submit">📤 Загрузить и обработать</button>
            </form>
        </div>

        <!-- Область предварительного просмотра и настройки маппинга -->
        <div id="import-preview" style="display: none;">
            <div class="card">
                <h3>📋 Предварительный просмотр</h3>
                <div id="preview-table" class="preview-table"></div>
                <div id="mapping-config" class="mapping-controls"></div>
                <button id="confirm-import" class="btn btn-primary">✅ Подтвердить импорт</button>
            </div>
        </div>

        <!-- Статус текущего импорта -->
        <div id="import-status-container" class="card" style="display: none;">
            <h3>📊 Статус импорта</h3>
            <div class="progress-bar"><div id="import-progress" class="progress-fill" style="width: 0%;"></div></div>
            <div id="import-status-text"></div>
        </div>

        <!-- История импортов -->
        <div class="card">
            <h3>📜 Последние импорты</h3>
            <div id="import-history"></div>
        </div>
    </div>

    <!-- Вкладка: Вебхуки -->
    <div id="tab-webhooks" class="tab-content">
        <div class="filters-bar">
            <div class="filter-group">
                <button id="add-webhook-btn" class="btn btn-primary">➕ Добавить вебхук</button>
            </div>
        </div>
        <div id="webhooks-container"></div>
    </div>

    <!-- Вкладка: Выплаты -->
    <div id="tab-payouts" class="tab-content">
        <div class="filters-bar">
            <div class="filter-group">
                <button id="create-payout-btn" class="btn btn-primary">💰 Создать выплату</button>
            </div>
        </div>
        <div id="payouts-container"></div>
        <div id="payouts-pagination" class="pagination"></div>
    </div>
</div>

<!-- Модальные окна (офферы, вебхуки, выплаты) -->
<div id="offer-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="offer-modal-title">Добавление оффера</h3>
            <button class="close-modal" onclick="closeModal('offer-modal')">✕</button>
        </div>
        <form id="offer-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="offer_id" id="offer-id">
            <div class="form-group"><label>Название оффера</label><input type="text" name="name" class="form-input" required></div>
            <div class="form-group"><label>Партнёр (бренд)</label><input type="text" name="partner_name" class="form-input" required></div>
            <div class="form-group"><label>Категория</label><input type="text" name="category" class="form-input" placeholder="финансы, товары, услуги..."></div>
            <div class="form-group"><label>Тип комиссии</label>
                <select name="commission_type" class="form-select">
                    <option value="percent">Процент (%)</option>
                    <option value="fixed">Фиксированная (₽)</option>
                </select>
            </div>
            <div class="form-group"><label>Комиссия</label><input type="number" name="commission_value" step="0.01" class="form-input" required></div>
            <div class="form-group"><label>Шаблон ссылки</label>
                <input type="text" name="url_template" class="form-input" placeholder="https://example.com/?ref={user_id}&offer={offer_id}" required>
                <div class="form-hint">Используйте {user_id} и {offer_id} для подстановки</div>
            </div>
            <div class="form-group"><label>Город (оставьте пустым для всех)</label><input type="text" name="city" class="form-input"></div>
            <div class="form-group"><label>Порядок сортировки</label><input type="number" name="sort_order" class="form-input" value="0"></div>
            <div class="form-checkbox"><label><input type="checkbox" name="is_active" value="1" checked> Активен</label></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить</button></div>
        </form>
    </div>
</div>

<div id="webhook-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="webhook-modal-title">Добавление вебхука</h3>
            <button class="close-modal" onclick="closeModal('webhook-modal')">✕</button>
        </div>
        <form id="webhook-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="webhook_id" id="webhook-id">
            <div class="form-group"><label>Название</label><input type="text" name="name" class="form-input" required></div>
            <div class="form-group"><label>URL</label><input type="url" name="url" class="form-input" required></div>
            <div class="form-group"><label>События</label>
                <label><input type="checkbox" name="events[]" value="conversion"> Конверсия</label>
                <label><input type="checkbox" name="events[]" value="click"> Клик</label>
                <label><input type="checkbox" name="events[]" value="payout"> Выплата</label>
            </div>
            <div class="form-checkbox"><label><input type="checkbox" name="is_active" value="1" checked> Активен</label></div>
            <div class="form-actions"><button type="submit" class="btn btn-primary">Сохранить</button></div>
        </form>
    </div>
</div>

<div id="payout-modal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Создание выплаты</h3>
            <button class="close-modal" onclick="closeModal('payout-modal')">✕</button>
        </div>
        <form id="payout-form">
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
            <div class="form-group"><label>Партнёр</label>
                <select name="user_id" class="form-select" required></select>
            </div>
            <div class="form-group"><label>Сумма (₽)</label><input type="number" name="amount" step="0.01" class="form-input" required></div>
            <div class="form-group"><label>Комментарий</label><textarea name="comment" class="form-textarea"></textarea></div>
            <button type="submit" class="btn btn-primary">Создать выплату</button>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';
    let currentTab = 'overview';
    let offersPage = 1;
    let payoutsPage = 1;
    let currentJobId = null;
    let pollInterval = null;

    // ===== УНИВЕРСАЛЬНЫЙ API-ЗАПРОС =====
    async function apiRequest(endpoint, data, method = 'POST') {
        try {
            const response = await fetch(endpoint, {
                method: method,
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });
            return await response.json();
        } catch (e) {
            showToast('Ошибка сети: ' + e.message, 'error');
            return null;
        }
    }

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

    // ===== ВКЛАДКИ =====
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const tab = btn.dataset.tab;
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            document.getElementById(`tab-${tab}`).classList.add('active');
            currentTab = tab;
            if (tab === 'overview') loadOverview();
            else if (tab === 'offers') loadOffers(1);
            else if (tab === 'webhooks') loadWebhooks();
            else if (tab === 'payouts') loadPayouts(1);
            else if (tab === 'import') loadImportHistory();
        });
    });

    // ===== ОБЗОР =====
    async function loadOverview() {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'stats' });
        if (res && res.success) {
            const stats = res.data;
            document.getElementById('stats-cards').innerHTML = `
                <div class="stat-card"><div class="stat-label">💰 Доход за месяц</div><div class="stat-value">${formatPrice(stats.month_revenue)} ₽</div><div class="stat-change">↑ ${stats.month_change}% за месяц</div></div>
                <div class="stat-card"><div class="stat-label">🖱️ Клики за месяц</div><div class="stat-value">${stats.month_clicks}</div><div class="stat-change">↑ ${stats.clicks_change}%</div></div>
                <div class="stat-card"><div class="stat-label">✅ Конверсии за месяц</div><div class="stat-value">${stats.month_conversions}</div><div class="stat-change">↑ ${stats.conversions_change}%</div></div>
                <div class="stat-card"><div class="stat-label">🏆 Всего офферов</div><div class="stat-value">${stats.total_offers}</div><div class="stat-change">активных: ${stats.active_offers}</div></div>
            `;
            const ctx = document.getElementById('statsChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: stats.chart_labels,
                    datasets: [
                        { label: 'Клики', data: stats.chart_clicks, borderColor: '#4A90E2', fill: false },
                        { label: 'Доход (₽)', data: stats.chart_revenue, borderColor: '#34C759', fill: false }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false }
            });
            const topDiv = document.getElementById('top-offers-list');
            if (stats.top_offers && stats.top_offers.length) {
                topDiv.innerHTML = stats.top_offers.map(o => `
                    <div class="offer-card" style="margin-bottom: 12px;">
                        <div class="offer-name">${escapeHtml(o.name)} (${escapeHtml(o.partner_name)})</div>
                        <div class="offer-stats">💰 ${formatPrice(o.revenue)} ₽ | 🖱️ ${o.clicks} кликов | ✅ ${o.conversions} конв.</div>
                    </div>
                `).join('');
            } else {
                topDiv.innerHTML = '<p>Нет данных</p>';
            }
        }
    }

    // ===== ОФФЕРЫ =====
    async function loadOffers(page = 1) {
        offersPage = page;
        const search = document.getElementById('offer-search').value;
        const status = document.getElementById('offer-status').value;
        const sort = document.getElementById('offer-sort').value;
        const res = await apiRequest('/api/admin/business/manage.php', {
            action: 'offers_list',
            page: page,
            search: search,
            status: status,
            sort: sort
        });
        if (res && res.success) {
            const offers = res.data;
            const container = document.getElementById('offers-container');
            if (!offers.length) {
                container.innerHTML = '<div class="empty-state">Нет офферов</div>';
            } else {
                container.innerHTML = offers.map(o => `
                    <div class="offer-card">
                        <div class="offer-name">${escapeHtml(o.name)}</div>
                        <div class="offer-stats">
                            <span>💰 ${formatPrice(o.revenue)} ₽</span>
                            <span>🖱️ ${o.clicks}</span>
                            <span>✅ ${o.conversions}</span>
                        </div>
                        <div class="offer-stats">
                            <span>Комиссия: ${o.commission_type === 'percent' ? o.commission_value + '%' : formatPrice(o.commission_value) + ' ₽'}</span>
                            <span>${o.is_active ? '🟢 Активен' : '🔴 Неактивен'}</span>
                        </div>
                        <div class="offer-actions">
                            <button class="btn btn-secondary btn-small" onclick="editOffer(${o.id})">✏️ Редактировать</button>
                            <button class="btn btn-success btn-small copy-link" onclick="copyLink('${escapeHtml(o.url_template)}', ${o.id})">📋 Скопировать ссылку</button>
                            <button class="btn btn-danger btn-small" onclick="deleteOffer(${o.id})">🗑️ Удалить</button>
                        </div>
                    </div>
                `).join('');
            }
            renderPagination('offers-pagination', res.meta.total, res.meta.page, res.meta.pages, loadOffers);
        }
    }

    function renderPagination(containerId, total, currentPage, pages, callback) {
        const container = document.getElementById(containerId);
        if (!container) return;
        if (pages <= 1) { container.innerHTML = ''; return; }
        let html = '';
        for (let i = 1; i <= pages; i++) {
            html += `<button class="page-btn ${i === currentPage ? 'active' : ''}" onclick="callback(${i})">${i}</button>`;
        }
        container.innerHTML = html;
    }

    async function editOffer(id) {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'offer_get', id: id });
        if (res && res.success) {
            const o = res.data;
            document.getElementById('offer-modal-title').innerText = 'Редактирование оффера';
            document.getElementById('offer-id').value = o.id;
            document.querySelector('#offer-form [name="name"]').value = o.name;
            document.querySelector('#offer-form [name="partner_name"]').value = o.partner_name;
            document.querySelector('#offer-form [name="category"]').value = o.category || '';
            document.querySelector('#offer-form [name="commission_type"]').value = o.commission_type;
            document.querySelector('#offer-form [name="commission_value"]').value = o.commission_value;
            document.querySelector('#offer-form [name="url_template"]').value = o.url_template;
            document.querySelector('#offer-form [name="city"]').value = o.city || '';
            document.querySelector('#offer-form [name="sort_order"]').value = o.sort_order;
            document.querySelector('#offer-form [name="is_active"]').checked = o.is_active == 1;
            document.querySelector('#offer-form [name="action"]').value = 'offer_update';
            openModal('offer-modal');
        }
    }

    async function deleteOffer(id) {
        if (!confirm('Удалить оффер? Это действие нельзя отменить.')) return;
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'offer_delete', id: id });
        if (res && res.success) {
            showToast('Оффер удалён');
            loadOffers(offersPage);
        } else {
            showToast(res?.error || 'Ошибка удаления', 'error');
        }
    }

    function copyLink(template, offerId) {
        const url = template.replace('{user_id}', '{{user_id}}').replace('{offer_id}', offerId);
        navigator.clipboard.writeText(url);
        showToast('Ссылка скопирована!', 'info');
    }

    // ===== ВЕБХУКИ =====
    async function loadWebhooks() {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'webhooks_list' });
        if (res && res.success) {
            const hooks = res.data;
            const container = document.getElementById('webhooks-container');
            if (!hooks.length) {
                container.innerHTML = '<div class="empty-state">Нет вебхуков</div>';
            } else {
                container.innerHTML = `
                    <table>
                        <thead><tr><th>Название</th><th>URL</th><th>События</th><th>Статус</th><th>Действия</th></tr></thead>
                        <tbody>
                            ${hooks.map(h => `
                                <tr>
                                    <td data-label="Название">${escapeHtml(h.name)}</td>
                                    <td data-label="URL">${escapeHtml(h.url)}</td>
                                    <td data-label="События">${h.events ? JSON.parse(h.events).join(', ') : '—'}</td>
                                    <td data-label="Статус">${h.is_active ? '🟢 Активен' : '🔴 Неактивен'}</td>
                                    <td data-label="Действия">
                                        <button class="btn btn-secondary btn-small" onclick="editWebhook(${h.id})">✏️</button>
                                        <button class="btn btn-danger btn-small" onclick="deleteWebhook(${h.id})">🗑️</button>
                                        <button class="btn btn-primary btn-small" onclick="testWebhook(${h.id})">🧪 Тест</button>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        }
    }

    async function editWebhook(id) {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'webhook_get', id: id });
        if (res && res.success) {
            const w = res.data;
            document.getElementById('webhook-modal-title').innerText = 'Редактирование вебхука';
            document.getElementById('webhook-id').value = w.id;
            document.querySelector('#webhook-form [name="name"]').value = w.name;
            document.querySelector('#webhook-form [name="url"]').value = w.url;
            const events = w.events ? JSON.parse(w.events) : [];
            document.querySelectorAll('#webhook-form [name="events[]"]').forEach(cb => {
                cb.checked = events.includes(cb.value);
            });
            document.querySelector('#webhook-form [name="is_active"]').checked = w.is_active == 1;
            document.querySelector('#webhook-form [name="action"]').value = 'webhook_update';
            openModal('webhook-modal');
        }
    }

    async function deleteWebhook(id) {
        if (!confirm('Удалить вебхук?')) return;
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'webhook_delete', id: id });
        if (res && res.success) {
            showToast('Вебхук удалён');
            loadWebhooks();
        } else {
            showToast(res?.error || 'Ошибка', 'error');
        }
    }

    async function testWebhook(id) {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'webhook_test', id: id });
        if (res && res.success) {
            showToast('Тестовое уведомление отправлено', 'success');
        } else {
            showToast(res?.error || 'Ошибка отправки', 'error');
        }
    }

    // ===== ВЫПЛАТЫ =====
    async function loadPayouts(page = 1) {
        payoutsPage = page;
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'payouts_list', page: page });
        if (res && res.success) {
            const payouts = res.data;
            const container = document.getElementById('payouts-container');
            if (!payouts.length) {
                container.innerHTML = '<div class="empty-state">Нет выплат</div>';
            } else {
                container.innerHTML = `
                    <table>
                        <thead><tr><th>ID</th><th>Партнёр</th><th>Сумма</th><th>Статус</th><th>Дата</th><th>Действия</th></tr></thead>
                        <tbody>
                            ${payouts.map(p => `
                                <tr>
                                    <td data-label="ID">${p.id}</td>
                                    <td data-label="Партнёр">${escapeHtml(p.user_name || p.user_id)}</td>
                                    <td data-label="Сумма">${formatPrice(p.amount)} ₽</td>
                                    <td data-label="Статус">${p.status === 'paid' ? '✅ Выплачено' : '⏳ Ожидает'}</td>
                                    <td data-label="Дата">${new Date(p.created_at).toLocaleDateString()}</td>
                                    <td data-label="Действия">
                                        ${p.status !== 'paid' ? `<button class="btn btn-primary btn-small" onclick="markPaid(${p.id})">✅ Отметить выплаченным</button>` : ''}
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
            renderPagination('payouts-pagination', res.meta.total, res.meta.page, res.meta.pages, loadPayouts);
        }
    }

    async function markPaid(id) {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'payout_mark_paid', id: id });
        if (res && res.success) {
            showToast('Выплата отмечена как выполненная');
            loadPayouts(payoutsPage);
        } else {
            showToast(res?.error || 'Ошибка', 'error');
        }
    }

    // ===== ИМПОРТ =====
    async function loadImportHistory() {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'import_history' });
        if (res && res.success) {
            const history = res.data;
            const container = document.getElementById('import-history');
            if (!history.length) {
                container.innerHTML = '<p>Нет данных об импортах</p>';
            } else {
                container.innerHTML = `
                    <table>
                        <thead><tr><th>Дата</th><th>Статус</th><th>Обработано</th><th>Ошибки</th></tr></thead>
                        <tbody>
                            ${history.map(h => `
                                <tr>
                                    <td>${new Date(h.created_at).toLocaleString()}</td>
                                    <td>${h.status}</td>
                                    <td>${h.processed_rows || 0} / ${h.total_rows || 0}</td>
                                    <td>${h.errors ? (JSON.parse(h.errors).length || '—') : '—'}</td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
            }
        }
    }

    document.getElementById('import-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        formData.append('csrf_token', csrfToken);
        const submitBtn = document.getElementById('import-submit');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner"></span> Загрузка...';
        try {
            const response = await fetch('/api/admin/business/import.php', {
                method: 'POST',
                body: formData
            });
            const result = await response.json();
            if (result.success) {
                if (result.preview) {
                    // Показываем предварительный просмотр и настройку маппинга
                    showPreview(result);
                } else if (result.job_id) {
                    // Импорт сразу без маппинга
                    currentJobId = result.job_id;
                    showToast(result.message, 'info');
                    startPolling();
                }
            } else {
                showToast(result.error || 'Ошибка загрузки', 'error');
            }
        } catch (err) {
            showToast('Ошибка сети', 'error');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '📤 Загрузить и обработать';
        }
    });

    function showPreview(data) {
        const previewDiv = document.getElementById('import-preview');
        const previewTableDiv = document.getElementById('preview-table');
        const mappingDiv = document.getElementById('mapping-config');
        previewDiv.style.display = 'block';
        // Таблица предпросмотра
        let tableHtml = '<table><thead><tr>';
        data.columns.forEach(col => {
            tableHtml += `<th>${escapeHtml(col)}</th>`;
        });
        tableHtml += '</tr></thead><tbody>';
        data.preview.forEach(row => {
            tableHtml += '<tr>';
            row.forEach(cell => {
                tableHtml += `<td>${escapeHtml(String(cell))}</td>`;
            });
            tableHtml += '</tr>';
        });
        tableHtml += '</tbody></table>';
        previewTableDiv.innerHTML = tableHtml;

        // Форма маппинга
        let mappingHtml = '<h4>Настройка соответствия полей</h4>';
        const fields = [
            { name: 'name', label: 'Название оффера' },
            { name: 'partner_name', label: 'Партнёр' },
            { name: 'url_template', label: 'Ссылка (url_template)' },
            { name: 'commission_value', label: 'Комиссия (число)' },
            { name: 'commission_type', label: 'Тип комиссии (percent/fixed)' },
            { name: 'category', label: 'Категория' },
            { name: 'city', label: 'Город' },
            { name: 'external_id', label: 'Внешний ID' }
        ];
        mappingHtml += '<div class="mapping-controls">';
        fields.forEach(field => {
            mappingHtml += `
                <div class="mapping-row">
                    <div class="mapping-label">${field.label}</div>
                    <select name="mapping[${field.name}]" class="form-select mapping-select">
                        <option value="">— не использовать —</option>
                        ${data.columns.map((col, idx) => `<option value="${idx}">${escapeHtml(col)}</option>`).join('')}
                    </select>
                </div>
            `;
        });
        mappingHtml += '</div>';
        mappingDiv.innerHTML = mappingHtml;

        // Кнопка подтверждения
        const confirmBtn = document.getElementById('confirm-import');
        confirmBtn.onclick = async () => {
            const mapping = {};
            document.querySelectorAll('.mapping-select').forEach(select => {
                const fieldName = select.name.replace('mapping[', '').replace(']', '');
                if (select.value !== '') mapping[fieldName] = parseInt(select.value);
            });
            const provider = document.querySelector('[name="provider"]').value;
            const dryRun = document.getElementById('dry_run').checked ? 1 : 0;
            const fileInput = document.querySelector('[name="import_file"]');
            const urlInput = document.querySelector('[name="import_url"]');
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('mapping', JSON.stringify(mapping));
            formData.append('provider', provider);
            formData.append('dry_run', dryRun);
            if (fileInput.files[0]) {
                formData.append('import_file', fileInput.files[0]);
            } else if (urlInput.value) {
                formData.append('import_url', urlInput.value);
            }
            confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner"></span> Импорт...';
            try {
                const response = await fetch('/api/admin/business/import.php', {
                    method: 'POST',
                    body: formData
                });
                const result = await response.json();
                if (result.success && result.job_id) {
                    currentJobId = result.job_id;
                    showToast('Импорт запущен', 'success');
                    previewDiv.style.display = 'none';
                    startPolling();
                } else {
                    showToast(result.error || 'Ошибка', 'error');
                }
            } catch (err) {
                showToast('Ошибка сети', 'error');
            } finally {
                confirmBtn.disabled = false;
                confirmBtn.innerHTML = '✅ Подтвердить импорт';
            }
        };
    }

    function startPolling() {
        const statusContainer = document.getElementById('import-status-container');
        statusContainer.style.display = 'block';
        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(async () => {
            if (!currentJobId) return;
            const res = await apiRequest('/api/admin/business/manage.php', { action: 'import_status', job_id: currentJobId });
            if (res && res.success) {
                const job = res.data;
                const total = job.total_rows || 0;
                const processed = job.processed_rows || 0;
                const percent = total > 0 ? (processed / total * 100) : 0;
                document.getElementById('import-progress').style.width = percent + '%';
                document.getElementById('import-status-text').innerHTML = `
                    <p><strong>Статус:</strong> ${job.status}</p>
                    <p>Обработано строк: ${processed} из ${total}</p>
                    <p>Ошибок: ${job.failed_rows || 0}</p>
                    ${job.errors ? `<details><summary>Ошибки</summary><pre>${escapeHtml(JSON.stringify(JSON.parse(job.errors), null, 2))}</pre></details>` : ''}
                `;
                if (job.status === 'completed' || job.status === 'completed_with_errors' || job.status === 'failed') {
                    clearInterval(pollInterval);
                    pollInterval = null;
                    currentJobId = null;
                    loadImportHistory();
                    showToast('Импорт завершён', 'info');
                }
            }
        }, 2000);
    }

    // ===== МОДАЛЬНЫЕ ОКНА =====
    function openModal(id) { document.getElementById(id).classList.add('active'); }
    function closeModal(id) { document.getElementById(id).classList.remove('active'); }

    document.getElementById('add-offer-btn').addEventListener('click', () => {
        document.getElementById('offer-modal-title').innerText = 'Добавление оффера';
        document.getElementById('offer-form').reset();
        document.getElementById('offer-id').value = '';
        document.querySelector('#offer-form [name="action"]').value = 'offer_create';
        openModal('offer-modal');
    });
    document.getElementById('offer-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        data.events = data.events ? data.events.split(',') : [];
        const res = await apiRequest('/api/admin/business/manage.php', data);
        if (res && res.success) {
            showToast(res.message);
            closeModal('offer-modal');
            loadOffers(offersPage);
        } else {
            showToast(res?.error || 'Ошибка', 'error');
        }
    });

    document.getElementById('add-webhook-btn').addEventListener('click', () => {
        document.getElementById('webhook-modal-title').innerText = 'Добавление вебхука';
        document.getElementById('webhook-form').reset();
        document.getElementById('webhook-id').value = '';
        document.querySelector('#webhook-form [name="action"]').value = 'webhook_create';
        openModal('webhook-modal');
    });
    document.getElementById('webhook-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        data.events = formData.getAll('events[]');
        const res = await apiRequest('/api/admin/business/manage.php', data);
        if (res && res.success) {
            showToast(res.message);
            closeModal('webhook-modal');
            loadWebhooks();
        } else {
            showToast(res?.error || 'Ошибка', 'error');
        }
    });

    document.getElementById('create-payout-btn').addEventListener('click', async () => {
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'partners_list' });
        if (res && res.success) {
            const select = document.querySelector('#payout-form select[name="user_id"]');
            select.innerHTML = '<option value="">Выберите партнёра</option>' + res.data.map(p => `<option value="${p.id}">${escapeHtml(p.name)} (${p.email})</option>`).join('');
            openModal('payout-modal');
        } else {
            showToast('Не удалось загрузить список партнёров', 'error');
        }
    });
    document.getElementById('payout-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(e.target);
        const data = Object.fromEntries(formData.entries());
        const res = await apiRequest('/api/admin/business/manage.php', { action: 'payout_create', ...data });
        if (res && res.success) {
            showToast(res.message);
            closeModal('payout-modal');
            loadPayouts(1);
        } else {
            showToast(res?.error || 'Ошибка', 'error');
        }
    });

    function formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price); }
    function escapeHtml(str) { return str ? str.replace(/[&<>]/g, m => ({ '&':'&amp;', '<':'&lt;', '>':'&gt;' }[m] || m)) : ''; }

    // Загружаем начальную вкладку
    loadOverview();
</script>
</body>
</html>