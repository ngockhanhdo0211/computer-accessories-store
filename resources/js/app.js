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