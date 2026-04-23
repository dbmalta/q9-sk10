/**
 * ScoutKeeper — Client-Side Application
 *
 * Handles theme toggling, session timeout detection,
 * and HTMX configuration. Uses Alpine.js stores for state.
 */

document.addEventListener('alpine:init', () => {
    // --- Theme Store ---
    Alpine.store('theme', {
        mode: localStorage.getItem('sk-theme') || (
            window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
        ),

        get icon() {
            return this.mode === 'dark' ? 'bi-moon-fill' : 'bi-sun-fill';
        },

        toggle() {
            this.mode = this.mode === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', this.mode);
            localStorage.setItem('sk-theme', this.mode);
        },

        init() {
            document.documentElement.setAttribute('data-bs-theme', this.mode);
        }
    });

    // --- Sidebar Store ---
    Alpine.store('sidebar', {
        collapsed: localStorage.getItem('sk-sidebar-collapsed') === 'true',

        toggle() {
            this.collapsed = !this.collapsed;
            localStorage.setItem('sk-sidebar-collapsed', this.collapsed);
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.toggle('sidebar-collapsed', this.collapsed);
            }
        }
    });
});

// --- HTMX: Handle 401 (session expired) ---
document.addEventListener('htmx:responseError', (event) => {
    if (event.detail.xhr.status === 401) {
        const modal = document.getElementById('sessionTimeoutModal');
        if (modal) {
            const bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        }
    }
});

// --- HTMX: Add loading class to body during requests ---
document.addEventListener('htmx:beforeRequest', () => {
    document.body.classList.add('htmx-requesting');
});

document.addEventListener('htmx:afterRequest', () => {
    document.body.classList.remove('htmx-requesting');
});

// --- View switcher: dirty-form confirm + mobile scope submission ---
window.viewSwitcher = function () {
    return {
        maybeConfirm(event) {
            const dirty = document.querySelector('form[data-dirty="true"]');
            if (dirty && !confirm('You have unsaved changes. Switch anyway?')) {
                event.preventDefault();
            }
        },
        submitScope(event) {
            const form = document.getElementById('view-scope-mobile-form');
            if (!form) return;
            form.querySelector('input[name=node_id]').value = event.target.value;
            // Reuse maybeConfirm logic before submit
            if (!event.target.dispatchEvent(new Event('will-submit', { cancelable: true }))) return;
            const dirty = document.querySelector('form[data-dirty="true"]');
            if (dirty && !confirm('You have unsaved changes. Switch anyway?')) return;
            form.submit();
        }
    };
};

// Mark a form as dirty on first interactive change so the switcher can warn.
document.addEventListener('change', (e) => {
    const form = e.target.closest('form');
    if (form && !form.matches('[action^="/context/"], [action^="/language/"]')) {
        form.dataset.dirty = 'true';
    }
});
