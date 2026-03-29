const features = [
  { group: 'ai', name: 'AI Studio', desc: '25+ tools for writing, analysis, strategy, and creative pipelines.' },
  { group: 'ai', name: 'AI Brain', desc: 'Self-learning context engine with memory, insights, and feedback loops.' },
  { group: 'ai', name: 'AI Agents', desc: 'Goal-driven multi-agent planning and execution with approval checkpoints.' },
  { group: 'growth', name: 'Social Publishing', desc: '15-platform publishing, queues, and optimal timing insights.' },
  { group: 'growth', name: 'Email Marketing', desc: 'Campaign composer, templates, and open/click performance tracking.' },
  { group: 'growth', name: 'CRM + Segments', desc: 'Pipeline stages, scoring, activity timelines, and dynamic audience rules.' },
  { group: 'ops', name: 'Automations', desc: 'Event triggers and action workflows for repeatable marketing execution.' },
  { group: 'ops', name: 'Funnels + A/B Tests', desc: 'Build conversion funnels and test variants with impression/conversion stats.' },
  { group: 'ops', name: 'Forms + Landing', desc: 'Create forms, hosted landing pages, embeds, short links, and UTM tracking.' },
  { group: 'ops', name: 'WordPress Connector', desc: 'Sync content and use AI tooling directly from WordPress.' }
];

const endpoints = [
  'GET /api/posts', 'POST /api/posts', 'GET /api/posts/calendar',
  'GET /api/campaigns', 'POST /api/campaigns/compare', 'GET /api/campaigns/{id}/summary',
  'GET /api/contacts', 'POST /api/contacts/import', 'POST /api/contacts/bulk',
  'GET /api/social-queue', 'GET /api/social-queue/best-times',
  'GET /api/email-campaigns', 'POST /api/email-campaigns/{id}/send',
  'GET /api/forms', 'POST /api/forms/{slug}/submit',
  'GET /api/landing-pages', 'POST /api/landing-pages',
  'GET /api/automations', 'POST /api/automations/{id}/run',
  'GET /api/funnels', 'GET /api/ab-tests',
  'GET /api/ai/providers', 'POST /api/ai/chat', 'POST /api/autopilot/launch'
];

const featureGrid = document.getElementById('featureGrid');
const filters = document.getElementById('featureFilters');
const apiSearch = document.getElementById('apiSearch');
const apiResults = document.getElementById('apiResults');
const themeToggle = document.getElementById('themeToggle');

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

function renderEndpoints(query = '') {
  const q = query.trim().toLowerCase();
  const filtered = q ? endpoints.filter((e) => e.toLowerCase().includes(q)) : endpoints;
  apiResults.innerHTML = filtered.slice(0, 16).map((e) => `<article class="endpoint"><code>${e}</code></article>`).join('');
}

filters.addEventListener('click', (e) => {
  const btn = e.target.closest('.chip');
  if (!btn) return;
  [...filters.querySelectorAll('.chip')].forEach((el) => el.classList.remove('active'));
  btn.classList.add('active');
  renderFeatures(btn.dataset.filter);
});

apiSearch.addEventListener('input', (e) => renderEndpoints(e.target.value));

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
