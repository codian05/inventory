/* ============================================================
   Inventory Management System - Users Module
   Global JS: theme, sidebar toggle, toasts, password & username
   live validation, image preview, AJAX delete modal.
   ============================================================ */

(function () {
    'use strict';

    /* ---------- Theme toggle (persisted in localStorage) ---------- */
    const savedTheme = localStorage.getItem('inv-theme');
    if (savedTheme) document.documentElement.setAttribute('data-bs-theme', savedTheme);

    const themeBtn = document.getElementById('themeToggle');
    if (themeBtn) {
        const syncIcon = () => {
            const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            themeBtn.innerHTML = dark
                ? '<i class="bi bi-sun"></i>'
                : '<i class="bi bi-moon-stars"></i>';
        };
        syncIcon();
        themeBtn.addEventListener('click', () => {
            const dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            const next = dark ? 'light' : 'dark';
            document.documentElement.setAttribute('data-bs-theme', next);
            localStorage.setItem('inv-theme', next);
            syncIcon();
        });
    }

    /* ---------- Sidebar toggle (mobile) — Phase 6.5: overlay, ESC, scroll-lock ---------- */
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function openSidebar() {
        sidebar.classList.add('show');
        if (sidebarOverlay) sidebarOverlay.classList.add('show');
        document.body.classList.add('sidebar-open-lock');
        document.body.classList.add('sidebar-open');
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'true');
    }
    function closeSidebar() {
        sidebar.classList.remove('show');
        if (sidebarOverlay) sidebarOverlay.classList.remove('show');
        document.body.classList.remove('sidebar-open-lock');
        document.body.classList.remove('sidebar-open');
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', 'false');
    }

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', () => {
            sidebar.classList.contains('show') ? closeSidebar() : openSidebar();
        });

        // Close on outside click (overlay or anywhere outside the sidebar on mobile)
        document.addEventListener('click', (e) => {
            if (window.innerWidth < 992 &&
                sidebar.classList.contains('show') &&
                !sidebar.contains(e.target) &&
                !sidebarToggle.contains(e.target)) {
                closeSidebar();
            }
        });

        // Close on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && sidebar.classList.contains('show')) {
                closeSidebar();
                sidebarToggle.focus();
            }
        });

        // Close automatically if the viewport is resized up to desktop width
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 992 && sidebar.classList.contains('show')) {
                closeSidebar();
            }
        });
    }

    /* ---------- Floating Action Button (mobile quick actions) ---------- */
    const fabToggle = document.getElementById('fabToggle');
    const fabWrap = document.getElementById('fabWrap');
    const fabBackdrop = document.getElementById('fabBackdrop');
    if (fabToggle && fabWrap) {
        fabToggle.addEventListener('click', () => {
            const open = fabWrap.classList.toggle('open');
            fabToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (fabBackdrop) fabBackdrop.classList.toggle('show', open);
        });
        document.addEventListener('click', (e) => {
            if (fabWrap.classList.contains('open') && !fabWrap.contains(e.target)) {
                fabWrap.classList.remove('open');
                fabToggle.setAttribute('aria-expanded', 'false');
                if (fabBackdrop) fabBackdrop.classList.remove('show');
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && fabWrap.classList.contains('open')) {
                fabWrap.classList.remove('open');
                fabToggle.setAttribute('aria-expanded', 'false');
                if (fabBackdrop) fabBackdrop.classList.remove('show');
            }
        });
    }

    /* ---------- Toast helper ---------- */
    window.showToast = function (message, type) {
        type = type || 'primary';
        const container = document.getElementById('toastContainer');
        if (!container) return;
        const id = 't' + Date.now();
        const html =
            '<div id="' + id + '" class="toast align-items-center text-bg-' + type +
            ' border-0" role="alert">' +
            '<div class="d-flex"><div class="toast-body">' + escapeHtml(message) + '</div>' +
            '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div></div>';
        container.insertAdjacentHTML('beforeend', html);
        const el = document.getElementById(id);
        const toast = new bootstrap.Toast(el, { delay: 4000 });
        toast.show();
        el.addEventListener('hidden.bs.toast', () => el.remove());
    };

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c =>
            ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])
        );
    }
    window.escapeHtml = escapeHtml;

    /* ---------- Password visibility toggle ---------- */
    window.togglePw = function (id, btn) {
        const field = document.getElementById(id);
        if (!field) return;
        field.type = field.type === 'password' ? 'text' : 'password';
        const icon = btn.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-eye');
            icon.classList.toggle('bi-eye-slash');
        }
    };

    /* ---------- Password strength / rule validation ---------- */
    window.checkPassword = function (input) {
        const fb = document.getElementById('pwFeedback');
        if (!fb) return;
        const v = input.value;
        const rules = [
            { ok: v.length >= 12,         label: 'At least 12 characters' },
            { ok: /[A-Z]/.test(v),        label: 'Uppercase letter' },
            { ok: /[a-z]/.test(v),        label: 'Lowercase letter' },
            { ok: /[0-9]/.test(v),        label: 'Number' },
            { ok: /[^A-Za-z0-9]/.test(v), label: 'Special character' }
        ];
        fb.innerHTML = rules.map(r =>
            '<div class="' + (r.ok ? 'text-success' : 'text-muted') + '">' +
            '<i class="bi ' + (r.ok ? 'bi-check-circle-fill' : 'bi-circle') + '"></i> ' +
            escapeHtml(r.label) + '</div>'
        ).join('');
        if (typeof window.checkConfirm === 'function') window.checkConfirm();
    };

    window.checkConfirm = function () {
        const fb = document.getElementById('confirmFeedback');
        const nw = document.getElementById('new_password');
        const cf = document.getElementById('confirm_password');
        if (!fb || !nw || !cf) return;
        if (cf.value === '') { fb.innerHTML = ''; return; }
        if (cf.value === nw.value) {
            fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> Passwords match</span>';
        } else {
            fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> Passwords do not match</span>';
        }
    };

    /* ---------- Username live check (AJAX) ---------- */
    let usernameTimer = null;
    window.checkUsername = function (input, excludeId) {
        const fb = document.getElementById('usernameFeedback');
        if (!fb) return;
        const v = input.value.trim();
        if (v.length < 3) {
            fb.innerHTML = '<span class="text-muted">Username must be 3–50 characters.</span>';
            return;
        }
        clearTimeout(usernameTimer);
        usernameTimer = setTimeout(() => {
            const url = '../ajax/check_username.php?username=' + encodeURIComponent(v) +
                        (excludeId ? '&id=' + excludeId : '');
            fetch(url)
                .then(r => r.json())
                .then(d => {
                    if (d.available) {
                        fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else {
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    }
                })
                .catch(() => {
                    fb.innerHTML = '<span class="text-muted">Could not verify username.</span>';
                });
        }, 400);
    };

    /* ---------- Category name live check (AJAX) — Phase 3 ---------- */
    let categoryNameTimer = null;
    window.checkCategoryName = function (input, excludeId) {
        const fb = document.getElementById('categoryNameFeedback');
        if (!fb) return;
        const v = input.value.trim();
        if (v.length < 2) {
            fb.innerHTML = '<span class="text-muted">Category name must be at least 2 characters.</span>';
            return;
        }
        clearTimeout(categoryNameTimer);
        categoryNameTimer = setTimeout(() => {
            const url = '../ajax/check_category_name.php?name=' + encodeURIComponent(v) +
                        (excludeId ? '&id=' + excludeId : '');
            fetch(url)
                .then(r => r.json())
                .then(d => {
                    if (d.available) {
                        fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else {
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    }
                })
                .catch(() => {
                    fb.innerHTML = '<span class="text-muted">Could not verify category name.</span>';
                });
        }, 400);
    };

    /* ---------- Branch code live check (AJAX) — Multi-Branch ---------- */
    let branchCodeTimer = null;
    window.checkBranchCode = function (input, excludeId) {
        const fb = document.getElementById('branchCodeFeedback');
        if (!fb) return;
        const v = input.value.trim();
        if (v.length < 2) {
            fb.innerHTML = '<span class="text-muted">Branch code must be 2–20 characters.</span>';
            return;
        }
        clearTimeout(branchCodeTimer);
        branchCodeTimer = setTimeout(() => {
            const url = '../ajax/check_branch_code.php?code=' + encodeURIComponent(v) +
                        (excludeId ? '&id=' + excludeId : '');
            fetch(url)
                .then(r => r.json())
                .then(d => {
                    if (d.available) {
                        fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else {
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    }
                })
                .catch(() => {
                    fb.innerHTML = '<span class="text-muted">Could not verify branch code.</span>';
                });
        }, 400);
    };

    /* ---------- Supplier name live check (AJAX) — Phase 4 ---------- */
    let supplierNameTimer = null;
    window.checkSupplierName = function (input, excludeId) {
        const fb = document.getElementById('supplierNameFeedback');
        if (!fb) return;
        const v = input.value.trim();
        if (v.length < 2) {
            fb.innerHTML = '<span class="text-muted">Supplier name must be at least 2 characters.</span>';
            return;
        }
        clearTimeout(supplierNameTimer);
        supplierNameTimer = setTimeout(() => {
            const url = '../ajax/check_supplier_name.php?name=' + encodeURIComponent(v) +
                        (excludeId ? '&id=' + excludeId : '');
            fetch(url)
                .then(r => r.json())
                .then(d => {
                    if (d.available) {
                        fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else {
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    }
                })
                .catch(() => {
                    fb.innerHTML = '<span class="text-muted">Could not verify supplier name.</span>';
                });
        }, 400);
    };

    /* ---------- Barcode live check (AJAX) — Phase 5 ---------- */
    let barcodeTimer = null;
    window.checkBarcode = function (input, excludeId) {
        const fb = document.getElementById('barcodeFeedback');
        if (!fb) return;
        const v = input.value.trim();
        if (v.length < 3) {
            fb.innerHTML = '<span class="text-muted">Barcode must be at least 3 characters.</span>';
            return;
        }
        clearTimeout(barcodeTimer);
        barcodeTimer = setTimeout(() => {
            const url = '../ajax/check_barcode.php?barcode=' + encodeURIComponent(v) +
                        (excludeId ? '&id=' + excludeId : '');
            fetch(url)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) {
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else if (d.available) {
                        fb.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> ' +
                                       escapeHtml(d.message) + '</span>';
                    } else if (d.found) {
                        const viewLink = d.product.deleted
                            ? ''
                            : ' &mdash; <a href="product_view.php?id=' + d.product.id + '" target="_blank">view it</a>';
                        fb.innerHTML = '<span class="text-danger"><i class="bi bi-upc-scan"></i> ' +
                                       escapeHtml(d.message) + viewLink + '</span>';
                    }
                })
                .catch(() => {
                    fb.innerHTML = '<span class="text-muted">Could not verify barcode.</span>';
                });
        }, 400);
    };

    /* ---------- Profile image preview ---------- */
    window.previewImage = function (input, imgId) {
        const img = document.getElementById(imgId);
        if (!img || !input.files || !input.files[0]) return;
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; };
        reader.readAsDataURL(input.files[0]);
    };

    /* ---------- Loading spinner on form submit ---------- */
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', () => {
            const sp = document.getElementById('globalSpinner');
            if (sp) sp.style.display = 'flex';
        });
    });

    /* ---------- Auto-dismiss alerts after 5s ---------- */
    document.querySelectorAll('.alert:not(.alert-persistent)').forEach(a => {
        setTimeout(() => {
            a.style.transition = 'opacity .5s';
            a.style.opacity = '0';
            setTimeout(() => a.remove(), 500);
        }, 5000);
    });

})();
