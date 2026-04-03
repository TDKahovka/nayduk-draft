<?php
global $pageTitle, $pageDescription;
$slug = $_GET['slug'] ?? '';
if (empty($slug)) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../services/Database.php';

$db = Database::getInstance();
$pdo = $db->getPdo();

// Получаем категорию по slug
$stmt = $pdo->prepare("SELECT id, name, parent_id FROM listing_categories WHERE slug = ?");
$stmt->execute([$slug]);
$category = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$category) {
    http_response_code(404);
    include __DIR__ . '/../404.php';
    exit;
}

$pageTitle = $category['name'] . ' — Найдук';
$pageDescription = 'Объявления в категории ' . $category['name'];
?>
<div class="container category-page">
    <h1 class="category-title"><?= htmlspecialchars($category['name']) ?></h1>
    <div id="listings-container" class="listings-grid"></div>
    <div id="pagination" class="pagination"></div>
</div>

<script>
    const categoryId = <?= $category['id'] ?>;
    let currentPage = 1;
    const limit = 20;

    function loadListings() {
        fetch('/api/listings/listings.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'list',
                page: currentPage,
                limit: limit,
                category_id: categoryId,
                sort: 'next_auto_boost_at',
                order: 'DESC'
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                renderListings(data.data);
                renderPagination(data.meta);
            } else {
                document.getElementById('listings-container').innerHTML = '<p>Объявления не найдены</p>';
            }
        })
        .catch(err => console.error(err));
    }

    function renderListings(listings) {
        const container = document.getElementById('listings-container');
        if (!listings.length) {
            container.innerHTML = '<p>В этой категории пока нет объявлений.</p>';
            return;
        }
        let html = '';
        listings.forEach(item => {
            html += `
                <a href="/listing/${item.id}" class="listing-card">
                    <div class="listing-image">
                        <img src="${item.photo || '/assets/img/no-image.png'}" alt="${escapeHtml(item.title)}">
                    </div>
                    <div class="listing-info">
                        <div class="listing-price">${item.price ? item.price.toLocaleString() + ' ₽' : 'Цена не указана'}</div>
                        <div class="listing-title">${escapeHtml(item.title)}</div>
                        <div class="listing-meta">
                            <span>${escapeHtml(item.city || 'Город не указан')}</span>
                            <span>${new Date(item.created_at).toLocaleDateString()}</span>
                        </div>
                    </div>
                </a>
            `;
        });
        container.innerHTML = html;
    }

    function renderPagination(meta) {
        const container = document.getElementById('pagination');
        if (meta.pages <= 1) {
            container.innerHTML = '';
            return;
        }
        let html = '<div class="pagination">';
        if (meta.page > 1) {
            html += `<button data-page="${meta.page - 1}">← Предыдущая</button>`;
        }
        for (let i = 1; i <= meta.pages; i++) {
            if (i === meta.page) {
                html += `<span class="current">${i}</span>`;
            } else {
                html += `<button data-page="${i}">${i}</button>`;
            }
        }
        if (meta.page < meta.pages) {
            html += `<button data-page="${meta.page + 1}">Следующая →</button>`;
        }
        html += '</div>';
        container.innerHTML = html;

        container.querySelectorAll('button[data-page]').forEach(btn => {
            btn.addEventListener('click', () => {
                currentPage = parseInt(btn.dataset.page);
                loadListings();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>]/g, function(m) {
            if (m === '&') return '&amp;';
            if (m === '<') return '&lt;';
            if (m === '>') return '&gt;';
            return m;
        });
    }

    loadListings();
</script>

<style>
    .category-page {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    .category-title {
        margin-bottom: 30px;
        font-size: 28px;
        font-weight: 700;
    }
    .listings-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 16px;
    }
    .listing-card {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        transition: transform 0.2s;
        text-decoration: none;
        color: inherit;
    }
    .listing-card:hover {
        transform: translateY(-4px);
        box-shadow: var(--shadow-md);
    }
    .listing-image img {
        width: 100%;
        height: 150px;
        object-fit: cover;
        background: var(--bg-secondary);
    }
    .listing-info {
        padding: 12px;
    }
    .listing-price {
        font-size: 18px;
        font-weight: 700;
        color: var(--primary);
    }
    .listing-title {
        font-size: 14px;
        font-weight: 600;
        margin: 4px 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .listing-meta {
        font-size: 12px;
        color: var(--text-secondary);
        display: flex;
        justify-content: space-between;
        margin-top: 8px;
    }
    .pagination {
        display: flex;
        justify-content: center;
        gap: 8px;
        margin-top: 30px;
    }
    .pagination button, .pagination span {
        padding: 8px 12px;
        border: 1px solid var(--border);
        background: var(--surface);
        cursor: pointer;
        border-radius: var(--radius);
    }
    .pagination span.current {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }
    @media (min-width: 768px) {
        .listings-grid {
            grid-template-columns: repeat(3, 1fr);
        }
    }
    @media (min-width: 1024px) {
        .listings-grid {
            grid-template-columns: repeat(4, 1fr);
        }
    }
</style>