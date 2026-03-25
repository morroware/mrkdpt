/**
 * SPA page router — hash-based navigation and tab switching.
 */

import { $ } from './utils.js';

const pageModules = {};
let currentPage = null;

export function registerPage(name, mod) {
  pageModules[name] = mod;
}

export function navigate(page) {
  window.location.hash = '#' + page;
}

export function currentPageName() {
  return currentPage;
}

function showPage(page) {
  // Hide all pages
  document.querySelectorAll('.content-area > .page').forEach((el) => {
    el.classList.remove('active');
    el.style.display = 'none';
  });

  // Show target page
  const target = $('page-' + page);
  if (target) {
    target.classList.add('active');
    target.style.display = '';
  }

  // Update nav highlight
  document.querySelectorAll('.sidebar-nav a').forEach((a) => {
    a.classList.toggle('active', a.dataset.page === page);
  });

  // Update page title
  const titleEl = $('pageTitle');
  if (titleEl) {
    const link = document.querySelector(`.sidebar-nav a[data-page="${page}"]`);
    titleEl.textContent = link ? link.textContent.trim() : page;
  }

  currentPage = page;

  // Call the page module's refresh
  const mod = pageModules[page];
  if (mod && typeof mod.refresh === 'function') {
    mod.refresh();
  }
}

export function initRouter() {
  // Sidebar navigation
  document.querySelectorAll('.sidebar-nav a[data-page]').forEach((link) => {
    link.addEventListener('click', (e) => {
      e.preventDefault();
      navigate(link.dataset.page);
    });
  });

  // Tab switching
  document.querySelectorAll('.tab-btn[data-tab]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const tabId = btn.dataset.tab;
      const container = btn.closest('section') || btn.closest('.page');
      if (!container) return;

      // Deactivate all tabs + panels in this container
      container.querySelectorAll('.tab-btn').forEach((b) => b.classList.remove('active'));
      container.querySelectorAll('.tab-panel').forEach((p) => {
        p.classList.remove('active');
        p.style.display = 'none';
      });

      // Activate selected
      btn.classList.add('active');
      const panel = $(tabId);
      if (panel) {
        panel.classList.add('active');
        panel.style.display = '';
      }
    });
  });

  // Mobile menu toggle
  const menuToggle = $('menuToggle');
  const sidebar = $('sidebar');
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      sidebar.classList.toggle('sidebar-open');
    });
  }

  // Theme toggle
  const themeToggle = $('themeToggle');
  if (themeToggle) {
    const saved = localStorage.getItem('theme');
    if (saved === 'light') document.body.classList.add('light');
    themeToggle.addEventListener('click', () => {
      document.body.classList.toggle('light');
      localStorage.setItem('theme', document.body.classList.contains('light') ? 'light' : 'dark');
    });
  }

  // Hash change handler
  window.addEventListener('hashchange', () => {
    const page = window.location.hash.replace('#', '') || 'dashboard';
    showPage(page);
  });

  // Initial page
  const initial = window.location.hash.replace('#', '') || 'dashboard';
  showPage(initial);
}
