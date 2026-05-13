// assets/controllers/sidebar_controller.js
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    connect() {
        //console.log('[SidebarController] conectado');

        this.sidebar = document.getElementById('sidebar');
        this.toggleMobile = document.getElementById('toggleSidebar');
        this.toggleDesktop = document.getElementById('toggleSidebarDesktop');

        this.restoreSidebarState();
        this.registerListeners();
        this.registerOutsideClick();
    }

    restoreSidebarState() {
        if (window.innerWidth >= 768) {
            const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
            if (isCollapsed) {
                this.sidebar.classList.add('collapsed');
            }
        }
    }

    registerListeners() {
        if (this.toggleDesktop) {
            this.toggleDesktop.addEventListener('click', () => {
                console.log('Toggling desktop sidebar');
                this.sidebar.classList.toggle('collapsed');
                const isCollapsed = this.sidebar.classList.contains('collapsed');
                localStorage.setItem('sidebar-collapsed', isCollapsed);
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
