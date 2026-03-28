/**
 * SPA page router — hash-based navigation and tab switching.
 */

import { $, copyToClipboard } from './utils.js';
import { api } from './api.js';

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
  const resolvedPage = $('page-' + page) ? page : 'dashboard';

  // Hide all pages
  document.querySelectorAll('.content-area > .page').forEach((el) => {
    el.classList.remove('active');
    el.style.display = 'none';
  });

  // Show target page
  const target = $('page-' + resolvedPage);
  if (target) {
    target.classList.add('active');
    target.style.display = '';
  }

  // Update nav highlight
  document.querySelectorAll('.sidebar-nav a').forEach((a) => {
    a.classList.toggle('active', a.dataset.page === resolvedPage);
  });

  // Update page title
  const titleEl = $('pageTitle');
  if (titleEl) {
    const link = document.querySelector(`.sidebar-nav a[data-page="${resolvedPage}"]`);
    titleEl.textContent = link ? link.textContent.trim() : resolvedPage;
  }

  currentPage = resolvedPage;

  // Call the page module's refresh
  const mod = pageModules[resolvedPage];
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
      // Close mobile sidebar
      const sidebar = $('sidebar');
      if (sidebar) sidebar.classList.remove('sidebar-open');
      const backdrop = $('sidebarBackdrop');
      if (backdrop) backdrop.classList.remove('visible');
      document.body.style.overflow = '';
    });
  });

  // Sidebar section toggles
  document.querySelectorAll('.nav-section-toggle').forEach((btn) => {
    btn.addEventListener('click', () => {
      const section = btn.closest('.nav-section');
      if (section) {
        section.classList.toggle('collapsed');
        // Save state
        const key = 'nav_' + btn.dataset.section;
        localStorage.setItem(key, section.classList.contains('collapsed') ? '1' : '0');
      }
    });
    // Restore saved state
    const key = 'nav_' + btn.dataset.section;
    const saved = localStorage.getItem(key);
    if (saved === '1') {
      const section = btn.closest('.nav-section');
      if (section) section.classList.add('collapsed');
    }
  });

  // Auto-expand section containing active page
  function expandActiveSection() {
    const activePage = window.location.hash.replace('#', '') || 'dashboard';
    const activeLink = document.querySelector(`.sidebar-nav a[data-page="${activePage}"]`);
    if (activeLink) {
      const section = activeLink.closest('.nav-section');
      if (section) section.classList.remove('collapsed');
    }
  }

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
  const backdrop = $('sidebarBackdrop');
  function setMobileSidebarState(open) {
    if (!sidebar) return;
    sidebar.classList.toggle('sidebar-open', open);
    if (backdrop) backdrop.classList.toggle('visible', open);
    document.body.style.overflow = open ? 'hidden' : '';
  }
  if (menuToggle && sidebar) {
    menuToggle.addEventListener('click', () => {
      setMobileSidebarState(!sidebar.classList.contains('sidebar-open'));
    });
    if (backdrop) {
      backdrop.addEventListener('click', () => setMobileSidebarState(false));
    }
  }

  // Theme toggle
  const themeToggle = $('themeToggle');
  if (themeToggle) {
    const saved = localStorage.getItem('theme');
    if (saved === 'light') document.body.classList.add('light');
    function updateThemeIcon() {
      themeToggle.innerHTML = document.body.classList.contains('light') ? '&#9728;' : '&#9790;';
      themeToggle.title = document.body.classList.contains('light') ? 'Switch to dark mode' : 'Switch to light mode';
    }
    updateThemeIcon();
    themeToggle.addEventListener('click', () => {
      document.body.classList.toggle('light');
      localStorage.setItem('theme', document.body.classList.contains('light') ? 'light' : 'dark');
      updateThemeIcon();
    });
  }

  // Global AI Command Bar
  initAiCommandBar();

  // Hash change handler
  window.addEventListener('hashchange', () => {
    const page = window.location.hash.replace('#', '') || 'dashboard';
    showPage(page);
    expandActiveSection();
  });

  // Initial page
  const initial = window.location.hash.replace('#', '') || 'dashboard';
  showPage(initial);
  expandActiveSection();
}

function initAiCommandBar() {
  const modal = $('aiCommandModal');
  const input = $('aiCommandInput');
  const globalBtn = $('globalAiBtn');
  const suggestionsEl = modal?.querySelector('.ai-command-suggestions');
  const outputEl = $('aiCommandOutput');

  if (!modal || !input) return;

  function openCommandBar() {
    modal.classList.add('visible');
    input.value = '';
    input.focus();
    if (suggestionsEl) suggestionsEl.classList.remove('hidden');
    if (outputEl) outputEl.classList.add('hidden');
  }

  function closeCommandBar() {
    modal.classList.remove('visible');
  }

  // Open with button or Ctrl+K
  if (globalBtn) globalBtn.addEventListener('click', openCommandBar);
  document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
      e.preventDefault();
      openCommandBar();
    }
    if (e.key === 'Escape' && modal.classList.contains('visible')) {
      closeCommandBar();
    }
  });

  // Close on overlay click
  modal.addEventListener('click', (e) => {
    if (e.target === modal) closeCommandBar();
  });

  // Quick action buttons
  modal.querySelectorAll('.ai-command-item').forEach((btn) => {
    btn.addEventListener('click', () => {
      const action = btn.dataset.aiQuick;
      const typeMap = {
        social_post: { type: 'social_post', platform: 'instagram' },
        blog_post: { type: 'blog_post', platform: 'blog' },
        email: { type: 'email', platform: 'email' },
        ad_copy: { type: 'ad_copy', platform: 'facebook' },
        video_script: { type: 'video_script', platform: 'tiktok' },
      };

      // Navigate-only actions
      if (['ideas', 'strategy', 'brief', 'calendar_month', 'headlines'].includes(action)) {
        closeCommandBar();
        navigate('ai');
        if (action === 'brief') {
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="creation"]')?.click();
            document.getElementById('aiBriefTopic')?.focus();
          }, 150);
        } else if (action === 'calendar_month') {
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="analytics"]')?.click();
            document.getElementById('aiCalMonthInput')?.focus();
          }, 150);
        } else if (action === 'headlines') {
          setTimeout(() => {
            document.querySelector('.ai-cat-btn[data-ai-cat="creation"]')?.click();
            document.getElementById('aiHeadlineText')?.focus();
          }, 150);
        }
        return;
      }

      const config = typeMap[action] || { type: 'social_post', platform: 'instagram' };
      generateFromCommandBar(input.value || btn.textContent.replace(/^[^\s]+\s/, ''), config.type, config.platform);
    });
  });

  // Enter key in command bar
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && input.value.trim()) {
      generateFromCommandBar(input.value.trim(), 'social_post', 'instagram');
    }
  });

  async function generateFromCommandBar(topic, contentType, platform) {
    if (suggestionsEl) suggestionsEl.classList.add('hidden');
    if (outputEl) outputEl.classList.remove('hidden');

    const resultEl = $('aiCommandResult');
    const metaEl = $('aiCommandMeta');
    if (resultEl) resultEl.textContent = 'Generating...';
    if (metaEl) metaEl.textContent = 'Working on it...';

    try {
      const { item } = await api('/api/ai/content', {
        method: 'POST',
        body: JSON.stringify({ content_type: contentType, platform, topic, tone: 'professional', goal: 'engage audience' }),
      });

      if (item?.content) {
        if (resultEl) resultEl.textContent = item.content;
        if (metaEl) metaEl.textContent = `Provider: ${item.provider || 'default'}  |  ${new Date().toLocaleTimeString()}`;
      }
    } catch (err) {
      if (resultEl) resultEl.textContent = 'Error: ' + err.message;
    }
  }

  // Copy from command bar
  const copyBtn = $('aiCommandCopy');
  if (copyBtn) {
    copyBtn.addEventListener('click', () => {
      const text = $('aiCommandResult')?.textContent || '';
      copyToClipboard(text, copyBtn);
    });
  }

  // Use in post from command bar
  const useBtn = $('aiCommandUsePost');
  if (useBtn) {
    useBtn.addEventListener('click', () => {
      const text = $('aiCommandResult')?.textContent || '';
      if (text) {
        sessionStorage.setItem('ai_generated_content', text);
        closeCommandBar();
        navigate('content');
        setTimeout(() => {
          document.querySelector('[data-tab="content-create"]')?.click();
          const bodyField = document.querySelector('#postForm [name="body"]');
          if (bodyField) bodyField.value = text;
        }, 200);
      }
    });
  }
}
