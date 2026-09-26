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