<?php
/* ============================================
   НАЙДУК — Общий подвал сайта
   Версия 2.3 (март 2026)
   - Исправлена геолокация браузера (один раз, без навязчивости)
   - Добавлено модальное окно для ручного выбора города
   - Автодополнение городов через API
   - Безопасное хранение города в localStorage и куках
   - Полная совместимость с исправленным GeoService
   ============================================ */
?>
    </main>

    <footer class="footer">
        <p>© <?= date('Y') ?> Найдук. Все права защищены. 
            <a href="/privacy">Политика конфиденциальности</a> | 
            <a href="/terms">Условия использования</a> | 
            <a href="/llms.txt">llms.txt</a>
        </p>
    </footer>

    <!-- Скрытый элемент для передачи текущего города из PHP в JS -->
    <div id="city-data" data-city='<?= htmlspecialchars(json_encode(get_current_city())) ?>' style="display: none;"></div>

    <!-- Модальное окно для выбора города -->
    <div id="city-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <div class="modal-header">
                <h3>📍 Выберите ваш город</h3>
                <button class="close-btn" onclick="closeCityModal()">✕</button>
            </div>
            <div class="city-search">
                <input type="text" id="city-search-input" class="form-input" placeholder="Начните вводить название города..." autocomplete="off">
                <div id="city-suggestions" class="city-suggestions"></div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeCityModal()">Закрыть</button>
            </div>
        </div>
    </div>

    <!-- Скрипты -->
    <script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>
    <script src="/js/premium.js"></script>
    <script src="/js/skeleton.js"></script>
    <script src="/js/theme-switch.js"></script>

    <script>
        // ===== ГЛОБАЛЬНЫЙ ОБЪЕКТ УВЕДОМЛЕНИЙ =====
        window.Notify = {
            success: function(message) {
                Toastify({
                    text: `✅ ${message}`,
                    duration: 3000,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #34C759, #2C9B4E)',
                    className: 'toastify-success'
                }).showToast();
            },
            error: function(message) {
                Toastify({
                    text: `❌ ${message}`,
                    duration: 5000,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #FF3B30, #C72A2A)',
                    className: 'toastify-error'
                }).showToast();
            },
            warning: function(message) {
                Toastify({
                    text: `⚠️ ${message}`,
                    duration: 4000,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #FF9500, #E68600)',
                    className: 'toastify-warning'
                }).showToast();
            },
            info: function(message) {
                Toastify({
                    text: `ℹ️ ${message}`,
                    duration: 3000,
                    gravity: 'top',
                    position: 'right',
                    backgroundColor: 'linear-gradient(135deg, #5A67D8, #4C51BF)'
                }).showToast();
            }
        };

        // ===== ГЕО-МОДУЛЬ =====
        const Geo = {
            // Элементы DOM
            cityDataElement: document.getElementById('city-data'),
            citySelector: document.getElementById('city-selector'),
            cityModal: document.getElementById('city-modal'),
            citySearchInput: document.getElementById('city-search-input'),
            citySuggestions: document.getElementById('city-suggestions'),
            
            // Текущий город
            currentCity: null,
            
            // Флаг, чтобы не запрашивать геолокацию повторно
            geoRequested: false,
            
            // Инициализация
            init: function() {
                // Получаем город из data-атрибута (если есть)
                if (this.cityDataElement && this.cityDataElement.dataset.city) {
                    try {
                        this.currentCity = JSON.parse(this.cityDataElement.dataset.city);
                        if (this.currentCity && this.currentCity.city) {
                            this.updateCityDisplay();
                        }
                    } catch(e) {}
                }
                
                // Если город не определён, пытаемся определить автоматически (один раз)
                if (!this.currentCity || !this.currentCity.city) {
                    this.autoDetect();
                }
                
                // Навешиваем обработчик на кнопку выбора города
                if (this.citySelector) {
                    this.citySelector.addEventListener('click', (e) => {
                        e.preventDefault();
                        this.showCityModal();
                    });
                }
                
                // Навешиваем обработчик на закрытие модалки (клик вне окна)
                window.addEventListener('click', (e) => {
                    if (e.target === this.cityModal) {
                        this.closeCityModal();
                    }
                });
                
                // Автодополнение при вводе
                if (this.citySearchInput) {
                    let debounceTimer;
                    this.citySearchInput.addEventListener('input', () => {
                        clearTimeout(debounceTimer);
                        const query = this.citySearchInput.value.trim();
                        if (query.length < 2) {
                            this.citySuggestions.innerHTML = '';
                            return;
                        }
                        debounceTimer = setTimeout(() => this.suggestCities(query), 300);
                    });
                }
            },
            
            // Автоматическое определение города (браузер → IP → fallback)
            autoDetect: function() {
                // Проверяем, не запрашивали ли уже геолокацию в этой сессии
                const geoAttempted = localStorage.getItem('geo_attempted');
                if (geoAttempted === 'true') {
                    // Уже пытались, больше не беспокоим
                    return;
                }
                
                // Запрашиваем геолокацию браузера (тихо, без баннера)
                if ('geolocation' in navigator) {
                    this.geoRequested = true;
                    localStorage.setItem('geo_attempted', 'true');
                    
                    navigator.geolocation.getCurrentPosition(
                        (position) => this.handleGeoSuccess(position),
                        (error) => this.handleGeoError(error),
                        { timeout: 5000, maximumAge: 60000, enableHighAccuracy: false }
                    );
                } else {
                    // Браузер не поддерживает геолокацию, пробуем по IP
                    this.detectByIp();
                }
            },
            
            handleGeoSuccess: function(position) {
                const lat = position.coords.latitude;
                const lng = position.coords.longitude;
                this.sendCoordinates(lat, lng);
            },
            
            handleGeoError: function(error) {
                // Пользователь запретил геолокацию или ошибка – пробуем по IP
                console.debug('Geolocation error:', error.message);
                this.detectByIp();
            },
            
            sendCoordinates: async function(lat, lng) {
                try {
                    const response = await fetch('/api/geo/city.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'detect_browser',
                            lat: lat,
                            lng: lng,
                            csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
                        })
                    });
                    const data = await response.json();
                    if (data.success && data.data && data.data.city) {
                        this.currentCity = data.data;
                        this.updateCityDisplay();
                        this.saveCityToStorage();
                    } else {
                        // Не удалось определить по координатам, пробуем по IP
                        this.detectByIp();
                    }
                } catch (e) {
                    console.debug('Failed to send coordinates:', e);
                    this.detectByIp();
                }
            },
            
            detectByIp: async function() {
                try {
                    const response = await fetch('/api/geo/city.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'detect',
                            csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
                        })
                    });
                    const data = await response.json();
                    if (data.success && data.data && data.data.city) {
                        this.currentCity = data.data;
                        this.updateCityDisplay();
                        this.saveCityToStorage();
                    } else {
                        // Если и IP не дал результата, оставляем пустым (будет предложено выбрать вручную)
                        this.currentCity = null;
                    }
                } catch (e) {
                    console.debug('IP detection failed:', e);
                    this.currentCity = null;
                }
            },
            
            saveCityToStorage: function() {
                if (!this.currentCity) return;
                // Сохраняем в localStorage для будущих сессий
                localStorage.setItem('user_city', JSON.stringify(this.currentCity));
                // Также устанавливаем куку (для PHP)
                document.cookie = `user_city=${encodeURIComponent(JSON.stringify(this.currentCity))}; path=/; max-age=2592000; SameSite=Lax`;
            },
            
            updateCityDisplay: function() {
                if (!this.citySelector) return;
                const citySpan = this.citySelector.querySelector('span');
                if (citySpan && this.currentCity && this.currentCity.city) {
                    citySpan.textContent = this.currentCity.city;
                } else if (citySpan) {
                    citySpan.textContent = 'Выбрать город';
                }
                // Обновляем скрытый элемент city-data
                if (this.cityDataElement) {
                    this.cityDataElement.dataset.city = JSON.stringify(this.currentCity);
                }
            },
            
            // ===== РУЧНОЙ ВЫБОР ГОРОДА =====
            showCityModal: function() {
                if (!this.cityModal) return;
                this.cityModal.style.display = 'flex';
                if (this.citySearchInput) {
                    this.citySearchInput.value = '';
                    this.citySuggestions.innerHTML = '';
                    this.citySearchInput.focus();
                }
            },
            
            closeCityModal: function() {
                if (this.cityModal) {
                    this.cityModal.style.display = 'none';
                }
            },
            
            suggestCities: async function(query) {
                try {
                    const response = await fetch('/api/geo/city.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'suggest',
                            query: query,
                            limit: 10,
                            csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
                        })
                    });
                    const data = await response.json();
                    if (data.success && data.data) {
                        this.renderSuggestions(data.data);
                    } else {
                        this.citySuggestions.innerHTML = '<div class="city-suggestion-empty">Ничего не найдено</div>';
                    }
                } catch (e) {
                    console.debug('Suggest failed:', e);
                    this.citySuggestions.innerHTML = '<div class="city-suggestion-empty">Ошибка загрузки</div>';
                }
            },
            
            renderSuggestions: function(cities) {
                if (!this.citySuggestions) return;
                if (!cities.length) {
                    this.citySuggestions.innerHTML = '<div class="city-suggestion-empty">Ничего не найдено</div>';
                    return;
                }
                this.citySuggestions.innerHTML = cities.map(c => `
                    <div class="city-suggestion" data-id="${c.id}" data-name="${c.city_name}">
                        ${c.city_name} ${c.region_name ? `(${c.region_name})` : ''}
                    </div>
                `).join('');
                
                // Навешиваем обработчики на предложения
                document.querySelectorAll('.city-suggestion').forEach(el => {
                    el.addEventListener('click', () => {
                        const cityId = el.dataset.id;
                        const cityName = el.dataset.name;
                        this.selectCity(cityId, cityName);
                    });
                });
            },
            
            selectCity: async function(cityId, cityName) {
                try {
                    const response = await fetch('/api/geo/city.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            action: 'set_city',
                            city_id: cityId,
                            csrf_token: document.querySelector('meta[name="csrf-token"]')?.content || ''
                        })
                    });
                    const data = await response.json();
                    if (data.success) {
                        // Обновляем локальные данные
                        this.currentCity = data.data || { id: cityId, city: cityName };
                        this.updateCityDisplay();
                        this.saveCityToStorage();
                        this.closeCityModal();
                        Notify.success(`Город изменён на ${cityName}`);
                        // Перезагружаем страницу, чтобы обновить содержимое (опционально)
                        // location.reload();
                    } else {
                        Notify.error(data.error || 'Не удалось сохранить город');
                    }
                } catch (e) {
                    Notify.error('Ошибка сети');
                }
            }
        };
        
        // Инициализация после загрузки DOM
        document.addEventListener('DOMContentLoaded', () => {
            Geo.init();
        });
        
        // Функции для вызова из HTML (кнопки)
        window.closeCityModal = function() {
            Geo.closeCityModal();
        };
    </script>
    
    <!-- Дополнительные стили для модального окна и подсказок (если не заданы в header) -->
    <style>
        .city-selector {
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 6px 12px;
            background: var(--bg-secondary);
            border-radius: var(--radius-full);
            font-size: 14px;
            transition: background var(--transition);
        }
        .city-selector:hover {
            background: var(--primary-light);
            color: white;
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10000;
        }
        .modal-content {
            background: var(--surface);
            border-radius: var(--radius-xl);
            max-width: 500px;
            width: 90%;
            padding: 24px;
            position: relative;
            box-shadow: var(--shadow-lg);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .close-btn {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: var(--text-secondary);
        }
        .city-search {
            margin: 20px 0;
        }
        .city-suggestions {
            margin-top: 12px;
            max-height: 300px;
            overflow-y: auto;
        }
        .city-suggestion {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-light);
            transition: background var(--transition);
        }
        .city-suggestion:hover {
            background: var(--bg-secondary);
        }
        .city-suggestion-empty {
            padding: 12px;
            text-align: center;
            color: var(--text-secondary);
        }
        .modal-footer {
            margin-top: 20px;
            text-align: right;
        }
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                padding: 16px;
            }
            .city-suggestion {
                font-size: 14px;
            }
        }
    </style>
</body>
</html>