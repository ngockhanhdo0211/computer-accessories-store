import './bootstrap';

const toggle = document.querySelector('[data-nav-toggle]');
const mobileNav = document.querySelector('#mobile-nav');

if (toggle && mobileNav) {
    const closeMenu = () => {
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-label', 'Mở menu');
        mobileNav.hidden = true;
    };

    toggle.addEventListener('click', () => {
        const isOpen = toggle.getAttribute('aria-expanded') === 'true';
        toggle.setAttribute('aria-expanded', String(!isOpen));
        toggle.setAttribute('aria-label', isOpen ? 'Mở menu' : 'Đóng menu');
        mobileNav.hidden = isOpen;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !mobileNav.hidden) {
            closeMenu();
            toggle.focus();
        }
    });

    window.matchMedia('(min-width: 52.001rem)').addEventListener('change', closeMenu);
}
document.querySelectorAll('[data-confirm-delete]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.dataset.confirmDeleteMessage
            ?? 'Xóa danh mục này? Thao tác chỉ thành công khi danh mục chưa được sử dụng.';

        if (!window.confirm(message)) {
            event.preventDefault();
        }
    });
});
const imageInput = document.querySelector('[data-product-image-input]');
const imagePreview = document.querySelector('[data-product-image-preview]');
let productPreviewUrls = [];

if (imageInput && imagePreview) {
    imageInput.addEventListener('change', () => {
        productPreviewUrls.forEach((url) => URL.revokeObjectURL(url));
        productPreviewUrls = [];
        imagePreview.replaceChildren();

        Array.from(imageInput.files ?? []).slice(0, 8).forEach((file, index) => {
            const item = document.createElement('div');
            const preview = document.createElement('img');
            const details = document.createElement('span');
            const altInput = document.createElement('input');
            const url = URL.createObjectURL(file);

            productPreviewUrls.push(url);
            item.className = 'product-upload-preview__item';
            preview.src = url;
            preview.alt = '';
            details.textContent = `${file.name} · ${Math.ceil(file.size / 1024)} KB`;
            altInput.type = 'text';
            altInput.name = `image_alt_texts[${index}]`;
            altInput.maxLength = 255;
            altInput.placeholder = 'Mô tả ảnh (không bắt buộc)';
            altInput.setAttribute('aria-label', `Mô tả cho ảnh ${file.name}`);
            details.append(document.createElement('br'), altInput);
            item.append(preview, details);
            imagePreview.append(item);
        });
    });
}

const imageSortList = document.querySelector('[data-image-sort-list]');

if (imageSortList) {
    imageSortList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-image-move]');
        const item = button?.closest('li');

        if (!button || !item) {
            return;
        }

        if (button.dataset.imageMove === 'up' && item.previousElementSibling) {
            imageSortList.insertBefore(item, item.previousElementSibling);
            button.focus();
        }

        if (button.dataset.imageMove === 'down' && item.nextElementSibling) {
            imageSortList.insertBefore(item.nextElementSibling, item);
            button.focus();
        }
    });
}

document.querySelectorAll('[data-image-fallback]').forEach((image) => {
    const showFallback = () => {
        image.hidden = true;
        const fallback = image.nextElementSibling;

        if (fallback?.classList.contains('product-image-placeholder')) {
            fallback.hidden = false;
        }
    };

    image.addEventListener('error', showFallback, { once: true });

    if (image.complete && image.naturalWidth === 0) {
        showFallback();
    }
});
document.querySelectorAll('[data-submit-once]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        const message = form.dataset.confirmAction;

        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.setAttribute('aria-disabled', 'true');
        });
    });
});
const workspaceShell = document.querySelector('[data-workspace-shell]');

if (workspaceShell) {
    document.documentElement.classList.add('workspace-enhanced');
    window.requestAnimationFrame(() => document.documentElement.classList.add('workspace-motion-ready'));

    const workspaceSidebar = workspaceShell.querySelector('[data-workspace-sidebar]');
    const workspaceToggle = workspaceShell.querySelector('[data-workspace-toggle]');
    const workspaceClose = workspaceShell.querySelector('[data-workspace-close]');
    const workspaceOverlay = workspaceShell.querySelector('[data-workspace-overlay]');
    const workspaceDesktop = window.matchMedia('(min-width: 64.001rem)');
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

    const setWorkspaceDrawer = (isOpen, restoreFocus = false) => {
        const drawerOpen = !workspaceDesktop.matches && isOpen;

        workspaceSidebar.dataset.open = String(drawerOpen);
        workspaceToggle.setAttribute('aria-expanded', String(drawerOpen));
        workspaceToggle.setAttribute('aria-label', drawerOpen ? 'Đóng menu điều hướng' : 'Mở menu điều hướng');
        workspaceOverlay.hidden = !drawerOpen;
        workspaceSidebar.inert = !workspaceDesktop.matches && !drawerOpen;
        document.body.classList.toggle('workspace-drawer-open', drawerOpen);

        if (drawerOpen) {
            workspaceClose.focus();
        } else if (restoreFocus && !workspaceDesktop.matches) {
            workspaceToggle.focus();
        }
    };

    workspaceToggle.addEventListener('click', () => {
        setWorkspaceDrawer(workspaceToggle.getAttribute('aria-expanded') !== 'true');
    });
    workspaceClose.addEventListener('click', () => setWorkspaceDrawer(false, true));
    workspaceOverlay.addEventListener('click', () => setWorkspaceDrawer(false, true));
    workspaceSidebar.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => setWorkspaceDrawer(false));
    });

    document.addEventListener('keydown', (event) => {
        if (workspaceToggle.getAttribute('aria-expanded') !== 'true') {
            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            setWorkspaceDrawer(false, true);
            return;
        }

        if (event.key === 'Tab') {
            const focusable = Array.from(workspaceSidebar.querySelectorAll(focusableSelector));
            const first = focusable.at(0);
            const last = focusable.at(-1);

            if (!first || !last) {
                return;
            }

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        }
    });

    workspaceDesktop.addEventListener('change', () => setWorkspaceDrawer(false));
    window.addEventListener('pageshow', () => setWorkspaceDrawer(false));
    window.addEventListener('pagehide', () => document.body.classList.remove('workspace-drawer-open'));
    setWorkspaceDrawer(false);
}


const couponFieldGrid = document.querySelector('[data-coupon-form]');

if (couponFieldGrid) {
    const couponEditor = couponFieldGrid.closest('form');
    const typeSelect = couponEditor?.querySelector('[data-coupon-type]');
    const valueInput = couponEditor?.querySelector('[data-coupon-value]');
    const valueSuffix = couponEditor?.querySelector('[data-coupon-value-suffix]');
    const valueHelp = couponEditor?.querySelector('[data-coupon-value-help]');
    const scopeSelect = couponEditor?.querySelector('[data-coupon-scope]');
    const targetGroups = Array.from(couponEditor?.querySelectorAll('[data-coupon-target-group]') ?? []);
    let previousType = typeSelect?.value;

    const syncCouponValue = () => {
        if (!typeSelect || !valueInput || !valueSuffix || !valueHelp) {
            return;
        }

        if (typeSelect.value === 'free_shipping') {
            if (previousType !== 'free_shipping' && valueInput.value !== '0') {
                valueInput.dataset.previousValue = valueInput.value;
            }

            valueInput.value = '0';
            valueInput.readOnly = true;
            valueSuffix.textContent = 'VND';
            valueHelp.textContent = 'Miễn phí vận chuyển luôn có giá trị 0 VND.';
        } else {
            if (previousType === 'free_shipping' && valueInput.dataset.previousValue) {
                valueInput.value = valueInput.dataset.previousValue;
            }

            valueInput.readOnly = false;
            valueSuffix.textContent = typeSelect.value === 'percent' ? '%' : 'VND';
            valueHelp.textContent = typeSelect.value === 'percent'
                ? 'Chỉ nhập số nguyên từ 1 đến 100.'
                : 'Nhập số tiền giảm bằng VND, không dùng dấu phân cách.';
        }

        previousType = typeSelect.value;
    };

    const syncCouponTargets = (clearInactive = false) => {
        if (!scopeSelect) {
            return;
        }

        targetGroups.forEach((group) => {
            const active = group.dataset.couponTargetGroup === scopeSelect.value;
            group.hidden = !active;

            group.querySelectorAll('input[type="checkbox"]').forEach((input) => {
                input.disabled = !active;

                if (!active && clearInactive) {
                    input.checked = false;
                }
            });
        });
    };

    typeSelect?.addEventListener('change', syncCouponValue);
    scopeSelect?.addEventListener('change', () => syncCouponTargets(true));
    syncCouponValue();
    syncCouponTargets();
}
