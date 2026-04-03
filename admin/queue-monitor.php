<?php
/* ============================================
   НАЙДУК — Админка: мониторинг очередей
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
$pageTitle = 'Мониторинг очередей — Найдук';
?>
<!DOCTYPE html>
<html lang="ru">
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
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        .admin-container { max-width: 1200px; margin: 0 auto; padding: 40px 20px; }
        .card { background: var(--surface); border-radius: var(--radius-xl); padding: 24px; margin-bottom: 30px; border: 1px solid var(--border); }
        .queue-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid var(--border-light); }
        .queue-name { font-weight: 600; }
        .queue-length { font-size: 24px; font-weight: 700; }
        .worker-item { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid var(--border-light); }
        .worker-status { display: inline-block; width: 12px; height: 12px; border-radius: 50%; margin-right: 8px; }
        .status-alive { background: var(--success); }
        .status-dead { background: var(--danger); }
        .btn { padding: 8px 16px; border-radius: var(--radius-full); cursor: pointer; }
        .btn-primary { background: var(--primary); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .refresh-btn { margin-bottom: 20px; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px; margin-top: 20px; }
        canvas { max-height: 300px; width: 100%; }
        @media (max-width: 768px) {
            .queue-item, .worker-item { flex-direction: column; align-items: flex-start; gap: 8px; }
        }
    </style>
</head>
<body>
<div class="admin-container">
    <h1>📊 Мониторинг очередей и воркеров</h1>
    <button id="refresh-btn" class="btn btn-primary refresh-btn">🔄 Обновить</button>

    <div class="card">
        <h2>📋 Очереди</h2>
        <div id="queues-list"></div>
    </div>

    <div class="card">
        <h2>⚙️ Воркеры</h2>
        <div id="workers-list"></div>
    </div>

    <div class="card">
        <h2>📈 Статистика по очередям (последние 7 дней)</h2>
        <div id="charts-container" class="stats-grid"></div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
<script>
    const csrfToken = '<?= $csrfToken ?>';

    async function fetchData() {
        try {
            const [queuesRes, workersRes, statsRes] = await Promise.all([
                fetch('/api/admin/queue-monitor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_queues', csrf_token: csrfToken })
                }),
                fetch('/api/admin/queue-monitor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'list_workers', csrf_token: csrfToken })
                }),
                fetch('/api/admin/queue-monitor.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'get_stats', days: 7, csrf_token: csrfToken })
                })
            ]);

            const queues = await queuesRes.json();
            const workers = await workersRes.json();
            const stats = await statsRes.json();

            if (queues.success) renderQueues(queues.data);
            if (workers.success) renderWorkers(workers.data);
            if (stats.success) renderStats(stats.data);
        } catch (e) {
            showToast('Ошибка загрузки', 'error');
        }
    }

    function renderQueues(queues) {
        const container = document.getElementById('queues-list');
        if (!queues.length) {
            container.innerHTML = '<p>Нет данных</p>';
            return;
        }
        container.innerHTML = queues.map(q => `
            <div class="queue-item">
                <div class="queue-name">${escapeHtml(q.name)}</div>
                <div class="queue-length">${q.length} задач</div>
                <button class="btn btn-danger btn-small" onclick="clearQueue('${q.name}')">Очистить</button>
            </div>
        `).join('');
    }

    function renderWorkers(workers) {
        const container = document.getElementById('workers-list');
        if (!workers.length) {
            container.innerHTML = '<p>Нет данных</p>';
            return;
        }
        container.innerHTML = workers.map(w => `
            <div class="worker-item">
                <div>
                    <span class="worker-status ${w.is_alive ? 'status-alive' : 'status-dead'}"></span>
                    <strong>${escapeHtml(w.name)}</strong>
                </div>
                <div>Последний heartbeat: ${w.last_heartbeat || '—'}</div>
                <div>Статус: ${w.is_alive ? '🟢 Активен' : '🔴 Не отвечает'}</div>
            </div>
        `).join('');
    }

    function renderStats(stats) {
        const container = document.getElementById('charts-container');
        // Группируем по очереди
        const byQueue = {};
        stats.forEach(s => {
            if (!byQueue[s.queue_name]) byQueue[s.queue_name] = [];
            byQueue[s.queue_name].push(s);
        });
        container.innerHTML = '';
        for (const [queueName, data] of Object.entries(byQueue)) {
            const canvasId = `chart-${queueName.replace(/[^a-z0-9]/gi, '_')}`;
            const div = document.createElement('div');
            div.className = 'chart-card';
            div.innerHTML = `<h3>${escapeHtml(queueName)}</h3><canvas id="${canvasId}" style="height:250px;"></canvas>`;
            container.appendChild(div);
            const labels = data.map(d => d.date).reverse();
            const values = data.map(d => Math.round(d.avg_length)).reverse();
            new Chart(document.getElementById(canvasId), {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: [{
                        label: 'Средняя длина очереди',
                        data: values,
                        borderColor: '#4A90E2',
                        fill: false
                    }]
                },
                options: { responsive: true, maintainAspectRatio: true }
            });
        }
    }

    async function clearQueue(queueName) {
        if (!confirm(`Очистить очередь "${queueName}"?`)) return;
        try {
            const res = await fetch('/api/admin/queue-monitor.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'clear_queue', queue: queueName, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Очередь очищена');
                fetchData();
            } else {
                showToast(data.error, 'error');
            }
        } catch (e) {
            showToast('Ошибка', 'error');
        }
    }

    function showToast(msg, type = 'success') {
        Toastify({
            text: msg,
            duration: 3000,
            gravity: 'top',
            position: 'right',
            backgroundColor: type === 'error' ? '#FF3B30' : '#34C759'
        }).showToast();
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[m] || m));
    }

    document.getElementById('refresh-btn').addEventListener('click', fetchData);
    document.addEventListener('DOMContentLoaded', fetchData);
</script>
</body>
</html>