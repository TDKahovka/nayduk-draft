(function() {
    const THEME_KEY = 'nayduk_theme';
    function setTheme(theme) {
        if (theme === 'dark') {
            document.documentElement.classList.add('dark');
        } else if (theme === 'light') {
            document.documentElement.classList.remove('dark');
        } else {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (prefersDark) {
                document.documentElement.classList.add('dark');
            } else {
                document.documentElement.classList.remove('dark');
            }
        }
        localStorage.setItem(THEME_KEY, theme);
    }
    function initThemeSwitch() {
        const saved = localStorage.getItem(THEME_KEY) || 'auto';
        setTheme(saved);
        const btns = document.querySelectorAll('.theme-option');
        btns.forEach(btn => {
            btn.addEventListener('click', () => {
                const theme = btn.dataset.theme;
                setTheme(theme);
                btns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
            if (btn.dataset.theme === saved) {
                btn.classList.add('active');
            }
        });
    }
    document.addEventListener('DOMContentLoaded', initThemeSwitch);
})();