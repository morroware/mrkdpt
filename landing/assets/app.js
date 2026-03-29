const features = [
  { group: 'ai', name: 'AI Studio + Chat + Brain', desc: 'Generate, refine, analyze, and plan with context-aware memory that improves over time.' },
  { group: 'ai', name: 'Agentic Workflows', desc: 'Plan multi-step growth tasks with specialized agents and explicit approval gates.' },
  { group: 'ai', name: 'Model Routing', desc: 'Map task types to providers/models so writing, strategy, and analysis use the right stack.' },
  { group: 'acquisition', name: 'Social Publishing', desc: 'Publish across 15 platforms with queueing, best-time guidance, and post tracking.' },
  { group: 'acquisition', name: 'Email Campaigns', desc: 'Build newsletters and lifecycle campaigns with AI copy tools and performance metrics.' },
  { group: 'acquisition', name: 'Funnels + Landing + Forms', desc: 'Capture demand and optimize conversions with built-in pages, forms, and A/B tests.' },
  { group: 'operations', name: 'CRM + Segmentation', desc: 'Manage contacts, activities, and dynamic audience segments for targeted campaigns.' },
  { group: 'operations', name: 'Automations', desc: 'Trigger recurring marketing actions with workflow rules and scheduled execution.' },
  { group: 'operations', name: 'Analytics + Attribution', desc: 'Track ROI, UTM data, and campaign outcomes from one operational dashboard.' },
  { group: 'operations', name: 'Developer API', desc: 'Integrate cleanly using a broad REST surface organized by domain-specific routes.' }
];

const endpoints = [
  { method: 'GET', path: '/api/ai/providers', group: 'ai', summary: 'List configured AI providers and models.' },
  { method: 'POST', path: '/api/ai/chat', group: 'ai', summary: 'Run conversational AI with project context.' },
  { method: 'GET', path: '/api/ai/brain/status', group: 'ai', summary: 'Read AI Brain self-reflection and coverage.' },
  { method: 'GET', path: '/api/posts', group: 'content', summary: 'List content items with status and scheduling metadata.' },
  { method: 'POST', path: '/api/posts', group: 'content', summary: 'Create a draft, scheduled, or published content record.' },
  { method: 'GET', path: '/api/templates', group: 'content', summary: 'Fetch reusable templates and brand assets.' },
  { method: 'GET', path: '/api/campaigns', group: 'marketing', summary: 'List campaign definitions with budget and objective data.' },
  { method: 'GET', path: '/api/campaigns/{id}/summary', group: 'marketing', summary: 'Read campaign performance and ROI summary.' },
  { method: 'POST', path: '/api/email-campaigns/{id}/send', group: 'marketing', summary: 'Send a prepared email campaign to its selected list.' },
  { method: 'GET', path: '/api/contacts', group: 'audience', summary: 'List contacts with lifecycle stage and attributes.' },
  { method: 'POST', path: '/api/contacts/import', group: 'audience', summary: 'Bulk import contacts from CSV or mapped payloads.' },
  { method: 'GET', path: '/api/segments', group: 'audience', summary: 'List dynamic audience segment definitions.' },
  { method: 'GET', path: '/api/automations', group: 'operations', summary: 'Read trigger-action workflow definitions.' },
  { method: 'POST', path: '/api/automations/{id}/run', group: 'operations', summary: 'Manually execute a workflow run for testing.' },
  { method: 'GET', path: '/api/social-queue/best-times', group: 'operations', summary: 'Retrieve platform-specific posting-time suggestions.' },
  { method: 'POST', path: '/api/autopilot/launch', group: 'operations', summary: 'Start onboarding autopilot for business context setup.' }
];

const docs = [
  { title: 'Quick Start', path: '../docs/quick-start.md', desc: 'Install and first-run setup.' },
  { title: 'Configuration', path: '../docs/configuration.md', desc: 'Environment variables and runtime config.' },
  { title: 'AI System', path: '../docs/ai-system.md', desc: 'Providers, tools, and orchestration.' },
  { title: 'API Reference', path: '../docs/api-reference.md', desc: 'Endpoint reference and payload format.' },
  { title: 'Content Management', path: '../docs/content-management.md', desc: 'Editorial workflows and publishing.' },
  { title: 'Email Marketing', path: '../docs/email-marketing.md', desc: 'Lists, campaigns, and delivery.' },
  { title: 'Social Publishing', path: '../docs/social-publishing.md', desc: 'Account connections and queueing.' },
  { title: 'Forms + Landing Pages', path: '../docs/forms-landing-pages.md', desc: 'Lead capture and hosted pages.' },
  { title: 'Automations + Workflows', path: '../docs/automations-workflows.md', desc: 'Trigger/action lifecycle and execution.' },
  { title: 'CRM + Contacts', path: '../docs/crm-contacts.md', desc: 'Contact records, scoring, and activities.' },
  { title: 'Campaigns + Analytics', path: '../docs/campaigns-analytics.md', desc: 'Tracking ROI and campaign health.' },
  { title: 'WordPress Plugin', path: '../docs/wordpress-plugin.md', desc: 'WordPress integration details.' }
];

const featureGrid = document.getElementById('featureGrid');
const filters = document.getElementById('featureFilters');
const apiSearch = document.getElementById('apiSearch');
const apiGroup = document.getElementById('apiGroup');
const apiResults = document.getElementById('apiResults');
const themeToggle = document.getElementById('themeToggle');
const docsSearch = document.getElementById('docsSearch');
const docsList = document.getElementById('docsList');
const docTitle = document.getElementById('docTitle');
const docContent = document.getElementById('docContent');
const docOpenLink = document.getElementById('docOpenLink');

function escapeHtml(text) {
  return text
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;');
}

function markdownToHtml(md) {
  const blocks = md.replace(/\r/g, '').split('\n');
  let html = '';
  let inCode = false;
  let inUl = false;
  let inOl = false;

  const closeLists = () => {
    if (inUl) { html += '</ul>'; inUl = false; }
    if (inOl) { html += '</ol>'; inOl = false; }
  };

  for (const raw of blocks) {
    const line = raw.trimEnd();
    if (line.startsWith('```')) {
      closeLists();
      if (!inCode) {
        html += '<pre><code>';
      } else {
        html += '</code></pre>';
      }
      inCode = !inCode;
      continue;
    }

    if (inCode) {
      html += `${escapeHtml(line)}\n`;
      continue;
    }

    if (!line.trim()) {
      closeLists();
      continue;
    }

    const heading = line.match(/^(#{1,4})\s+(.*)$/);
    if (heading) {
      closeLists();
      const level = heading[1].length;
      html += `<h${level}>${escapeHtml(heading[2])}</h${level}>`;
      continue;
    }

    const ordered = line.match(/^\d+\.\s+(.*)$/);
    if (ordered) {
      if (!inOl) {
        closeLists();
        html += '<ol>';
        inOl = true;
      }
      html += `<li>${escapeHtml(ordered[1])}</li>`;
      continue;
    }

    const bullet = line.match(/^[-*]\s+(.*)$/);
    if (bullet) {
      if (!inUl) {
        closeLists();
        html += '<ul>';
        inUl = true;
      }
      html += `<li>${escapeHtml(bullet[1])}</li>`;
      continue;
    }

    closeLists();
    const inlineCode = escapeHtml(line).replace(/`([^`]+)`/g, '<code>$1</code>');
    html += `<p>${inlineCode}</p>`;
  }

  closeLists();
  if (inCode) html += '</code></pre>';
  return html || '<p class="doc-placeholder">No content to display.</p>';
}

function renderFeatures(filter = 'all') {
  const list = filter === 'all' ? features : features.filter((f) => f.group === filter);
  featureGrid.innerHTML = list.map((f) => `
    <article class="card">
      <span class="tag">${f.group.toUpperCase()}</span>
      <h3>${f.name}</h3>
      <p>${f.desc}</p>
    </article>
  `).join('');
}

function renderEndpoints() {
  const q = apiSearch.value.trim().toLowerCase();
  const g = apiGroup.value;
  const filtered = endpoints.filter((e) => {
    const matchesText = !q || `${e.method} ${e.path} ${e.summary}`.toLowerCase().includes(q);
    const matchesGroup = g === 'all' || e.group === g;
    return matchesText && matchesGroup;
  });

  if (!filtered.length) {
    apiResults.innerHTML = '<p class="empty">No endpoints matched your filters.</p>';
    return;
  }

  apiResults.innerHTML = filtered.map((e) => `
    <article class="endpoint">
      <span class="group">${e.group}</span>
      <div><code>${e.method} ${e.path}</code></div>
      <p>${e.summary}</p>
    </article>
  `).join('');
}

function renderDocs(query = '') {
  const q = query.trim().toLowerCase();
  const list = q
    ? docs.filter((d) => `${d.title} ${d.desc}`.toLowerCase().includes(q))
    : docs;

  docsList.innerHTML = list.map((d, i) => `
    <button class="doc-link ${i === 0 ? 'active' : ''}" data-path="${d.path}" data-title="${d.title}">
      ${d.title}
      <small>${d.desc}</small>
    </button>
  `).join('');

  if (list.length) loadDoc(list[0].path, list[0].title);
  else {
    docTitle.textContent = 'No matching docs';
    docContent.innerHTML = '<p class="doc-placeholder">Try a broader search query.</p>';
    docOpenLink.removeAttribute('href');
  }
}

async function loadDoc(path, title) {
  docTitle.textContent = `${title} (loading...)`;
  docOpenLink.href = path;

  try {
    const res = await fetch(path, { cache: 'no-store' });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const text = await res.text();
    docTitle.textContent = title;
    docContent.innerHTML = markdownToHtml(text);
  } catch (err) {
    docTitle.textContent = `${title} (unavailable)`;
    docContent.innerHTML = `<p class="empty">Unable to load this document: ${err.message}</p>`;
  }
}

filters.addEventListener('click', (e) => {
  const btn = e.target.closest('.chip');
  if (!btn) return;
  [...filters.querySelectorAll('.chip')].forEach((el) => el.classList.remove('active'));
  btn.classList.add('active');
  renderFeatures(btn.dataset.filter);
});

apiSearch.addEventListener('input', renderEndpoints);
apiGroup.addEventListener('change', renderEndpoints);
docsSearch.addEventListener('input', (e) => renderDocs(e.target.value));

docsList.addEventListener('click', (e) => {
  const btn = e.target.closest('.doc-link');
  if (!btn) return;
  [...docsList.querySelectorAll('.doc-link')].forEach((el) => el.classList.remove('active'));
  btn.classList.add('active');
  loadDoc(btn.dataset.path, btn.dataset.title);
});

themeToggle.addEventListener('click', () => {
  document.documentElement.classList.toggle('light');
  themeToggle.textContent = document.documentElement.classList.contains('light') ? '☀️' : '🌙';
});

const reveals = document.querySelectorAll('.reveal');
const io = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting) entry.target.classList.add('show');
  });
}, { threshold: 0.1 });
reveals.forEach((el) => io.observe(el));

const counters = document.querySelectorAll('[data-counter]');
const animateCounter = (el) => {
  const target = Number(el.dataset.counter);
  let value = 0;
  const step = Math.max(1, Math.ceil(target / 40));
  const timer = setInterval(() => {
    value += step;
    if (value >= target) {
      value = target;
      clearInterval(timer);
    }
    el.textContent = `${value}+`;
  }, 28);
};

const counterObserver = new IntersectionObserver((entries) => {
  entries.forEach((entry) => {
    if (entry.isIntersecting && !entry.target.dataset.done) {
      animateCounter(entry.target);
      entry.target.dataset.done = '1';
    }
  });
}, { threshold: 0.5 });
counters.forEach((c) => counterObserver.observe(c));

renderFeatures();
renderEndpoints();
renderDocs();
