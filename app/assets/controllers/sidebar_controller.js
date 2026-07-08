// assets/controllers/sidebar_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        this.sidebar = document.getElementById('sidebar');
        this.toggleMobile = document.getElementById('toggleSidebar');
        this.toggleDesktop = document.getElementById('toggleSidebarDesktop');

        this.isCollapsed = false;
        this.restoreSidebarState();
        this.registerListeners();
        this.registerOutsideClick();
    }

    restoreSidebarState() {
        if (window.innerWidth >= 768) {
            this.isCollapsed = this.getStoredCollapsedState();
            this.applyCollapsedState(this.isCollapsed);
        }
    }

    getStoredCollapsedState() {
        try {
            return localStorage.getItem('sidebar-collapsed') === 'true';
        } catch (e) {
            return false;
        }
    }

    persistCollapsedState(isCollapsed) {
        this.isCollapsed = isCollapsed;
        this.applyCollapsedState(isCollapsed);

        try {
            localStorage.setItem('sidebar-collapsed', String(isCollapsed));
        } catch (e) {}
    }

    applyCollapsedState(isCollapsed) {
        if (!this.sidebar) {
            return;
        }

        this.sidebar.classList.toggle('collapsed', isCollapsed);
        document.documentElement.classList.toggle('backend-sidebar-collapsed', isCollapsed && window.innerWidth >= 768);
    }

    registerListeners() {
        if (this.toggleDesktop) {
            this.toggleDesktop.addEventListener('click', () => {
                const nextCollapsed = !this.sidebar.classList.contains('collapsed');
                this.persistCollapsedState(nextCollapsed);
            });
        }

        if (this.toggleMobile) {
            this.toggleMobile.addEventListener('click', (e) => {
                e.stopPropagation();
                this.sidebar.classList.toggle('d-none');
            });
        }
    }

    registerOutsideClick() {
        document.addEventListener('click', (e) => {
            const isMobile = window.innerWidth < 768;

            if (
                isMobile &&
                !this.sidebar.classList.contains('d-none') &&
                !this.sidebar.contains(e.target) &&
                (!this.toggleMobile || !this.toggleMobile.contains(e.target))
            ) {
                this.sidebar.classList.add('d-none');
            }
        });
    }
}
