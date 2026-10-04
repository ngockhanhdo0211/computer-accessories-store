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

const supportChats = document.querySelectorAll('[data-support-chat]');

supportChats.forEach((chat) => {
    const toggleButton = chat.querySelector('[data-support-toggle]');
    const externalToggle = document.querySelector('[data-open-support-chat]');
    const panel = chat.querySelector('[data-support-panel]');
    const closeButton = chat.querySelector('[data-support-close]');
    const list = chat.querySelector('[data-support-messages]');
    const empty = chat.querySelector('[data-support-empty]');
    const olderButton = chat.querySelector('[data-support-older]');
    const feedback = chat.querySelector('[data-support-feedback]');
    const form = chat.querySelector('[data-support-form]');
    const textarea = form?.querySelector('textarea[name="content"]');
    const keyInput = form?.querySelector('input[name="client_message_key"]');
    const error = form?.querySelector('[data-support-error]');
    const submit = form?.querySelector('button[type="submit"]');
    const csrf = form?.querySelector('input[name="_token"]')?.value;
    const renderedIds = new Set();
    let pollTimer = null;
    let activeRequest = null;
    let polling = false;
    let retryDelay = 5000;

    if (!list || !form || !textarea || !keyInput || !feedback || !empty || !olderButton || !submit || !csrf) {
        return;
    }

    const isOpen = () => chat.hasAttribute('data-workspace-thread') || (panel && !panel.hidden);
    const makeKey = () => window.crypto?.randomUUID?.() ?? 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (character) => {
        const value = Math.random() * 16 | 0;
        return (character === 'x' ? value : (value & 0x3 | 0x8)).toString(16);
    });
    const nearBottom = () => list.scrollHeight - list.scrollTop - list.clientHeight < 72;
    const messageNode = (message) => {
        const article = document.createElement('article');
        const label = document.createElement('strong');
        const content = document.createElement('p');
        const time = document.createElement('time');
        article.className = `support-message${message.mine ? ' support-message--mine' : ''}`;
        article.dataset.messageId = String(message.id);
        label.textContent = message.sender_label;
        content.textContent = message.content;
        time.textContent = message.created_at;
        article.append(label, content, time);
        return article;
    };
    const markRead = async (messageId) => {
        try {
            await fetch(chat.dataset.readUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ message_id: messageId }) });
        } catch (_) {
            // The next successful poll retries with the newest visible message.
        }
    };
    const render = (messages, prepend = false) => {
        if (!Array.isArray(messages) || messages.length === 0) {
            return;
        }
        const shouldScroll = nearBottom();
        const previousHeight = list.scrollHeight;
        const fragment = document.createDocumentFragment();
        messages.forEach((message) => {
            if (!renderedIds.has(message.id)) {
                renderedIds.add(message.id);
                fragment.append(messageNode(message));
            }
        });
        if (prepend) {
            list.prepend(fragment);
            list.scrollTop += list.scrollHeight - previousHeight;
        } else {
            list.append(fragment);
            if (shouldScroll) {
                list.scrollTop = list.scrollHeight;
            }
        }
        empty.hidden = renderedIds.size > 0;
        const last = list.lastElementChild?.dataset.messageId;
        if (last) {
            markRead(Number(last));
        }
    };
    const fetchMessages = async (mode = 'initial') => {
        if (polling || !isOpen() || document.hidden) {
            return;
        }
        polling = true;
        let catchUp = false;
        const url = new URL(chat.dataset.messagesUrl, window.location.origin);
        if (mode === 'new' && list.lastElementChild) {
            url.searchParams.set('after_id', list.lastElementChild.dataset.messageId);
        } else if (mode === 'older' && list.firstElementChild) {
            url.searchParams.set('before_id', list.firstElementChild.dataset.messageId);
        }
        activeRequest = new AbortController();
        try {
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: activeRequest.signal });
            if (!response.ok) {
                throw new Error('Không thể tải tin nhắn.');
            }
            const data = await response.json();
            render(data.messages, mode === 'older');
            olderButton.hidden = !(data.has_more || (mode === 'initial' && data.messages.length === 50));
            catchUp = mode === 'new' && data.has_more === true;
            feedback.textContent = '';
            retryDelay = 5000;
        } catch (requestError) {
            if (requestError.name !== 'AbortError') {
                feedback.textContent = 'Kết nối gián đoạn. Hệ thống sẽ tự thử lại.';
                retryDelay = Math.min(retryDelay * 2, 30000);
            }
        } finally {
            polling = false;
            activeRequest = null;
            if (catchUp && isOpen() && !document.hidden) {
                fetchMessages('new');
            } else {
                schedulePoll();
            }
        }
    };
    const schedulePoll = () => {
        window.clearTimeout(pollTimer);
        if (isOpen() && !document.hidden) {
            pollTimer = window.setTimeout(() => fetchMessages('new'), retryDelay);
        }
    };
    const open = () => {
        if (panel) {
            panel.hidden = false;
            toggleButton?.setAttribute('aria-expanded', 'true');
        }
        feedback.textContent = renderedIds.size === 0 ? 'Đang tải tin nhắn…' : '';
        fetchMessages(renderedIds.size === 0 ? 'initial' : 'new');
        window.setTimeout(() => textarea.focus(), 0);
    };
    const close = () => {
        if (!panel) {
            return;
        }
        panel.hidden = true;
        toggleButton?.setAttribute('aria-expanded', 'false');
        activeRequest?.abort();
        window.clearTimeout(pollTimer);
        toggleButton?.focus();
    };

    toggleButton?.addEventListener('click', () => panel?.hidden ? open() : close());
    externalToggle?.addEventListener('click', open);
    closeButton?.addEventListener('click', close);
    olderButton.addEventListener('click', () => fetchMessages('older'));
    textarea.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing && event.keyCode !== 229) {
            event.preventDefault();
            form.requestSubmit();
        }
    });
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (submit.disabled) {
            return;
        }
        submit.disabled = true;
        error.textContent = '';
        feedback.textContent = 'Đang gửi…';
        try {
            const response = await fetch(chat.dataset.sendUrl, { method: 'POST', headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ client_message_key: keyInput.value, content: textarea.value }) });
            const data = await response.json();
            if (!response.ok) {
                const firstError = Object.values(data.errors ?? {}).flat().at(0);
                throw new Error(firstError ?? (response.status === 429 ? 'Bạn đang gửi quá nhanh. Vui lòng chờ một chút.' : 'Không thể gửi tin nhắn.'));
            }
            render([data.message]);
            textarea.value = '';
            keyInput.value = makeKey();
            feedback.textContent = 'Đã gửi tin nhắn.';
            retryDelay = 5000;
            schedulePoll();
        } catch (sendError) {
            error.textContent = sendError.message;
            feedback.textContent = 'Tin nhắn chưa được gửi. Nội dung vẫn được giữ để bạn thử lại.';
            textarea.focus();
        } finally {
            submit.disabled = false;
        }
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panel && !panel.hidden) {
            close();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            activeRequest?.abort();
            window.clearTimeout(pollTimer);
        } else if (isOpen()) {
            fetchMessages(renderedIds.size === 0 ? 'initial' : 'new');
        }
    });
    window.addEventListener('pagehide', () => {
        activeRequest?.abort();
        window.clearTimeout(pollTimer);
    });
    if (chat.hasAttribute('data-workspace-thread')) {
        open();
    }
});
