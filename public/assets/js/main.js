// Main JavaScript - Gestione Associazioni
document.addEventListener('DOMContentLoaded', function() {

    // Mobile Sidebar
    initializeMobileSidebar();

    // Modali: focus sul primo campo, oppure sul primo campo con errore (scrollato in vista)
    document.querySelectorAll('.modal').forEach(function (modal) {
        modal.addEventListener('shown.bs.modal', function () {
            var errore = this.querySelector('.is-invalid, .invalid-feedback.d-block, .alert-danger');
            if (errore) {
                errore.scrollIntoView({ block: 'center', behavior: 'smooth' });
                if (errore.matches('input, select, textarea')) errore.focus({ preventScroll: true });
                return;
            }
            var primo = this.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea:not([disabled])');
            if (primo) primo.focus();
        });
    });

    // Form validation
    document.querySelectorAll('form[data-validate]').forEach(form => {
        form.addEventListener('submit', function(e) {
            let isValid = true;
            form.querySelectorAll('[required]').forEach(input => {
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    isValid = false;
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            if (!isValid) {
                e.preventDefault();
                showToast('Compila tutti i campi obbligatori', 'error');
            }
        });
    });

    // Email validation
    document.querySelectorAll('input[type="email"]').forEach(input => {
        input.addEventListener('blur', function() {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (this.value && !emailRegex.test(this.value)) {
                this.classList.add('is-invalid');
                showToast('Formato email non valido', 'error');
            } else {
                this.classList.remove('is-invalid');
            }
        });
    });

    // Confirm delete actions
    document.querySelectorAll('button[data-confirm], input[data-confirm]').forEach(button => {
        button.addEventListener('click', function(e) {
            const message = this.getAttribute('data-confirm') || 'Sei sicuro di voler procedere?';
            if (!confirm(message)) {
                e.preventDefault();
                return false;
            }
        });
    });

    // Auto-generate numero socio
    const numeroSocioInput = document.querySelector('input[name="numero_socio"]');
    if (numeroSocioInput && !numeroSocioInput.value) {
        const currentYear = new Date().getFullYear();
        const randomNum = Math.floor(Math.random() * 1000).toString().padStart(3, '0');
        numeroSocioInput.value = currentYear.toString() + randomNum;
    }

    // Search with debounce (skip inputs managed by AJAX filters)
    document.querySelectorAll('input[name="search"]').forEach(input => {
        if (input.closest('[data-ajax-filter]')) return;
        let timeout;
        input.addEventListener('input', function() {
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                if (this.value.length >= 3 || this.value.length === 0) {
                    this.form.submit();
                }
            }, 500);
        });
    });

    // Currency formatting
    document.querySelectorAll('input[data-currency]').forEach(input => {
        input.addEventListener('blur', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value)) this.value = value.toFixed(2);
        });
    });

    // Load notifications
    loadNotifications();
    setInterval(loadNotifications, 300000);
});

// Mobile Sidebar
function initializeMobileSidebar() {
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (!sidebarToggle || !sidebar || !overlay) return;

    sidebarToggle.addEventListener('click', toggleSidebar);
    overlay.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', function() {
            if (window.innerWidth < 992 && !this.hasAttribute("data-bs-toggle")) closeSidebar();
        });
    });

    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992) closeSidebar();
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
}

function toggleSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    if (sidebar.classList.contains('show')) {
        closeSidebar();
    } else {
        openSidebar();
    }
}

function openSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    sidebar.classList.add('show');
    overlay.classList.add('show');
    document.body.classList.add('sidebar-open');
}

function closeSidebar() {
    const sidebar = document.getElementById('mainSidebar');
    const overlay = document.getElementById('sidebarOverlay');
    if (!sidebar || !sidebar.classList.contains('show')) return;
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
    document.body.classList.remove('sidebar-open');
}

// Notifications
function loadNotifications() {
    const panel = document.getElementById('notificationsPanel');
    if (!panel) return;

    fetch('api/notifications.php')
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (data.success) displayNotifications(data.notifications, data.count);
        })
        .catch(function() {});
}

function displayNotifications(notifications, count) {
    const panel = document.getElementById('notificationsPanel');
    if (!panel) return;

    if (notifications.length === 0) {
        panel.textContent = '';
        var okDiv = document.createElement('div');
        okDiv.className = 'text-center text-success';
        var icon = document.createElement('i');
        icon.className = 'bi bi-check-circle';
        var text = document.createElement('div');
        text.className = 'small';
        text.textContent = 'Tutto ok!';
        okDiv.appendChild(icon);
        okDiv.appendChild(text);
        panel.appendChild(okDiv);
        return;
    }

    panel.textContent = '';
    notifications.forEach(function(n) {
        var badgeClass = { danger: 'bg-danger', warning: 'bg-warning', info: 'bg-info' }[n.urgency] || 'bg-secondary';

        var item = document.createElement('div');
        item.className = 'notification-item mb-2 p-2 border rounded' + (n.urgency === 'danger' ? ' border-danger' : '');

        var link = document.createElement('a');
        link.href = n.link;
        link.className = 'text-decoration-none';

        var row = document.createElement('div');
        row.className = 'd-flex align-items-start';

        var iconEl = document.createElement('i');
        iconEl.className = 'bi bi-' + n.icon + ' me-2 ' + (n.urgency === 'danger' ? 'text-danger' : 'text-muted');

        var content = document.createElement('div');
        content.className = 'flex-grow-1';

        var titleEl = document.createElement('div');
        titleEl.className = 'small fw-bold';
        titleEl.textContent = n.titolo;

        var msgEl = document.createElement('div');
        msgEl.className = 'small text-muted';
        msgEl.textContent = n.messaggio;

        var badge = document.createElement('span');
        badge.className = 'badge ' + badgeClass + ' small';
        badge.textContent = '!';

        content.appendChild(titleEl);
        content.appendChild(msgEl);
        row.appendChild(iconEl);
        row.appendChild(content);
        row.appendChild(badge);
        link.appendChild(row);
        item.appendChild(link);
        panel.appendChild(item);
    });
}

// Toast Notification System
function showToast(message, type, options) {
    type = type || 'info';
    options = options || {};
    var title = options.title || null;
    var duration = options.duration !== undefined ? options.duration : 5000;
    var showProgress = options.showProgress !== undefined ? options.showProgress : true;

    var icons = {
        success: 'bi-check-circle-fill',
        error: 'bi-exclamation-triangle-fill',
        danger: 'bi-exclamation-triangle-fill',
        warning: 'bi-exclamation-triangle-fill',
        info: 'bi-info-circle-fill',
        primary: 'bi-info-circle-fill'
    };

    var toastIcon = icons[type] || 'bi-info-circle-fill';
    var toastId = 'toast_' + Date.now();

    var toastEl = document.createElement('div');
    toastEl.className = 'toast toast-' + type;
    toastEl.setAttribute('role', 'alert');
    toastEl.setAttribute('aria-live', 'assertive');
    toastEl.setAttribute('aria-atomic', 'true');
    toastEl.id = toastId;

    if (title) {
        var header = document.createElement('div');
        header.className = 'toast-header';

        var iconI = document.createElement('i');
        iconI.className = 'toast-icon ' + toastIcon;
        header.appendChild(iconI);

        var strong = document.createElement('strong');
        strong.className = 'me-auto';
        strong.textContent = title;
        header.appendChild(strong);

        var small = document.createElement('small');
        small.className = 'toast-time';
        small.textContent = new Date().toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        header.appendChild(small);

        var closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close';
        closeBtn.setAttribute('data-bs-dismiss', 'toast');
        closeBtn.setAttribute('aria-label', 'Chiudi');
        header.appendChild(closeBtn);

        toastEl.appendChild(header);

        var body = document.createElement('div');
        body.className = 'toast-body';
        body.textContent = message;
        toastEl.appendChild(body);
    } else {
        var flex = document.createElement('div');
        flex.className = 'd-flex align-items-center';

        var bodyDiv = document.createElement('div');
        bodyDiv.className = 'toast-body flex-grow-1';

        var inner = document.createElement('div');
        inner.className = 'd-flex align-items-start gap-2';

        var iconI2 = document.createElement('i');
        iconI2.className = 'toast-icon ' + toastIcon + ' mt-1';
        inner.appendChild(iconI2);

        var msgDiv = document.createElement('div');
        msgDiv.textContent = message;
        inner.appendChild(msgDiv);

        bodyDiv.appendChild(inner);
        flex.appendChild(bodyDiv);

        var closeBtn2 = document.createElement('button');
        closeBtn2.type = 'button';
        closeBtn2.className = 'btn-close me-2';
        closeBtn2.setAttribute('data-bs-dismiss', 'toast');
        closeBtn2.setAttribute('aria-label', 'Chiudi');
        flex.appendChild(closeBtn2);

        toastEl.appendChild(flex);
    }

    if (showProgress && duration > 0) {
        var progressBar = document.createElement('div');
        progressBar.className = 'toast-progress';
        progressBar.style.width = '100%';
        progressBar.style.transition = 'width ' + duration + 'ms linear';
        toastEl.appendChild(progressBar);
    }

    var container = document.querySelector('.toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    container.appendChild(toastEl);

    var toast = new bootstrap.Toast(toastEl, { autohide: duration > 0, delay: duration });

    if (showProgress && duration > 0) {
        var bar = toastEl.querySelector('.toast-progress');
        if (bar) requestAnimationFrame(function() { bar.style.width = '0%'; });
    }

    toastEl.addEventListener('hidden.bs.toast', function() { this.remove(); });
    toast.show();
    return toastEl;
}

function showSuccessToast(message, options) { return showToast(message, 'success', Object.assign({ title: 'Successo' }, options || {})); }
function showErrorToast(message, options) { return showToast(message, 'error', Object.assign({ title: 'Errore' }, options || {})); }
function showWarningToast(message, options) { return showToast(message, 'warning', Object.assign({ title: 'Attenzione' }, options || {})); }
function showInfoToast(message, options) { return showToast(message, 'info', Object.assign({ title: 'Informazione' }, options || {})); }

function formatCurrency(amount) {
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(amount);
}

function formatDate(dateString) {
    return new Date(dateString).toLocaleDateString('it-IT');
}

// PWA Install Prompt Handler
(function() {
    var deferredPrompt = null;

    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;

        // Don't show if user already dismissed
        if (localStorage.getItem('pwa_install_dismissed')) return;

        showInstallToast();
    });

    function showInstallToast() {
        // Build install toast via DOM methods
        var container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        var toast = document.createElement('div');
        toast.className = 'toast toast-info';
        toast.setAttribute('role', 'alert');

        var body = document.createElement('div');
        body.className = 'toast-body';

        var flex = document.createElement('div');
        flex.className = 'd-flex align-items-center gap-2';

        var icon = document.createElement('i');
        icon.className = 'bi bi-download';
        icon.style.fontSize = '1.25rem';
        icon.style.color = '#0D9488';

        var text = document.createElement('span');
        text.className = 'flex-grow-1';
        text.textContent = 'Installa l\'app per un accesso rapido';

        var btnInstall = document.createElement('button');
        btnInstall.className = 'btn btn-sm btn-primary';
        btnInstall.textContent = 'Installa';
        btnInstall.style.whiteSpace = 'nowrap';

        var btnDismiss = document.createElement('button');
        btnDismiss.className = 'btn btn-sm btn-outline-secondary';
        btnDismiss.textContent = 'No';

        flex.appendChild(icon);
        flex.appendChild(text);
        flex.appendChild(btnInstall);
        flex.appendChild(btnDismiss);
        body.appendChild(flex);
        toast.appendChild(body);
        container.appendChild(toast);

        btnInstall.addEventListener('click', function() {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function() {
                    deferredPrompt = null;
                });
            }
            toast.remove();
        });

        btnDismiss.addEventListener('click', function() {
            localStorage.setItem('pwa_install_dismissed', '1');
            toast.remove();
        });

        // Auto-dismiss after 15 seconds
        setTimeout(function() {
            if (toast.parentNode) toast.remove();
        }, 15000);
    }
})();

// --- Toggle tema chiaro/scuro (persistito in localStorage) ---
document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('themeToggle');
    if (!toggle) return;
    toggle.addEventListener('click', function () {
        var html = document.documentElement;
        var next = html.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        html.setAttribute('data-bs-theme', next);
        localStorage.setItem('theme', next);
    });
});

// --- Selettore associazione (super admin): filtro per nome/città ---
document.addEventListener('DOMContentLoaded', function () {
    var input = document.querySelector('[data-tenant-filter]');
    if (!input) return;
    var opzioni = document.querySelectorAll('.tenant-list .tenant-option[data-nome]');
    var vuoto = document.querySelector('.tenant-list .tenant-empty');
    input.addEventListener('input', function () {
        var q = input.value.trim().toLowerCase();
        var visibili = 0;
        opzioni.forEach(function (o) {
            var ok = q === '' || o.getAttribute('data-nome').indexOf(q) !== -1;
            o.hidden = !ok;
            if (ok) visibili++;
        });
        if (vuoto) vuoto.hidden = visibili > 0;
    });
    var toggle = input.closest('.dropdown').querySelector('[data-bs-toggle="dropdown"]');
    toggle.addEventListener('shown.bs.dropdown', function () { input.focus(); });
});

// --- Grafici Chart.js allineati al design system ---
// I grafici vengono creati dagli script inline delle pagine; qui si
// impostano i default e si ritoccano le istanze già create.
document.addEventListener('DOMContentLoaded', function () {
    if (!window.Chart) return;
    var css = getComputedStyle(document.documentElement);
    var colore = function (nome, riserva) { return (css.getPropertyValue(nome) || '').trim() || riserva; };
    var testo = colore('--ds-text-muted', '#8A90A0');
    var griglia = colore('--ds-line', '#ECEEF1');
    var C = window.Chart;
    C.defaults.font.family = colore('--ds-font', 'system-ui');
    C.defaults.font.size = 12;
    C.defaults.color = testo;
    C.defaults.borderColor = griglia;
    C.defaults.plugins.legend.labels.usePointStyle = true;
    C.defaults.plugins.legend.labels.boxWidth = 8;
    C.defaults.plugins.tooltip.backgroundColor = colore('--ds-text', '#2B2F38');
    C.defaults.plugins.tooltip.padding = 10;
    C.defaults.plugins.tooltip.cornerRadius = 8;
    C.defaults.plugins.tooltip.displayColors = false;
    if (C.defaults.datasets && C.defaults.datasets.bar) {
        C.defaults.datasets.bar.borderRadius = 6;
        C.defaults.datasets.bar.maxBarThickness = 34;
    }
    var istanze = C.instances ? Object.values(C.instances) : [];
    istanze.forEach(function (grafico) {
        (grafico.data.datasets || []).forEach(function (ds) {
            if (grafico.config.type === 'bar') {
                if (ds.borderRadius === undefined) ds.borderRadius = 6;
                if (ds.maxBarThickness === undefined) ds.maxBarThickness = 34;
            }
        });
        Object.values(grafico.options.scales || {}).forEach(function (scala) {
            if (scala.grid) { scala.grid.color = griglia; scala.grid.drawTicks = false; }
            if (scala.border) scala.border.display = false;
            if (scala.ticks) { scala.ticks.color = testo; scala.ticks.padding = 8; }
        });
        grafico.update('none');
    });
});
