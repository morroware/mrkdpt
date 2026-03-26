/**
 * Onboarding wizard — collects business profile data, then launches AI Autopilot.
 */

import { api } from '../core/api.js';
import { $, escapeHtml } from '../core/utils.js';
import { success, error } from '../core/toast.js';
import { navigate } from '../core/router.js';

let currentStep = 1;
const totalSteps = 5;

export async function refresh() {
  currentStep = 1;
  showStep(1);
}

export function init() {
  // Step navigation buttons
  document.querySelectorAll('.onboard-next').forEach(btn => {
    btn.addEventListener('click', () => {
      if (validateStep(currentStep)) {
        currentStep++;
        showStep(currentStep);
      }
    });
  });

  document.querySelectorAll('.onboard-prev').forEach(btn => {
    btn.addEventListener('click', () => {
      currentStep--;
      showStep(currentStep);
    });
  });

  // Add competitor fields dynamically
  const addCompBtn = $('addCompetitorField');
  if (addCompBtn) {
    addCompBtn.addEventListener('click', () => {
      const container = $('competitorFields');
      if (!container) return;
      const count = container.querySelectorAll('input').length;
      if (count >= 5) { error('Maximum 5 competitors'); return; }
      const div = document.createElement('div');
      div.className = 'flex gap-1 mb-1';
      div.innerHTML = `<input type="text" class="input competitor-input" placeholder="Competitor name or URL">
        <button type="button" class="btn btn-sm btn-ghost remove-comp">&times;</button>`;
      div.querySelector('.remove-comp').addEventListener('click', () => div.remove());
      container.appendChild(div);
    });
  }

  // Remove competitor buttons (for initial ones)
  document.querySelectorAll('.remove-comp').forEach(btn => {
    btn.addEventListener('click', () => btn.closest('.flex')?.remove());
  });

  // Launch autopilot
  const launchBtn = $('launchAutopilot');
  if (launchBtn) {
    launchBtn.addEventListener('click', launchAutopilot);
  }

  // Skip onboarding
  const skipBtn = $('skipOnboarding');
  if (skipBtn) {
    skipBtn.addEventListener('click', async () => {
      try {
        await api('/api/onboarding/profile', {
          method: 'POST',
          body: JSON.stringify({ onboarding_completed: true }),
        });
        navigate('dashboard');
      } catch (err) {
        error(err.message);
      }
    });
  }
}

function showStep(step) {
  // Hide all steps
  document.querySelectorAll('.onboard-step').forEach(el => {
    el.style.display = 'none';
  });
  // Show current step
  const stepEl = $('onboardStep' + step);
  if (stepEl) stepEl.style.display = '';

  // Update progress bar
  const progressFill = $('onboardProgressFill');
  if (progressFill) {
    progressFill.style.width = ((step / totalSteps) * 100) + '%';
  }

  // Update step indicators
  document.querySelectorAll('.onboard-step-indicator').forEach(el => {
    const s = parseInt(el.dataset.step);
    el.classList.toggle('active', s === step);
    el.classList.toggle('completed', s < step);
  });
}

function validateStep(step) {
  if (step === 1) {
    const desc = $('obBusinessDesc');
    if (desc && desc.value.trim().length < 10) {
      error('Please describe your business (at least 10 characters)');
      desc.focus();
      return false;
    }
  }
  return true;
}

function collectFormData() {
  const competitors = [];
  document.querySelectorAll('.competitor-input').forEach(input => {
    if (input.value.trim()) competitors.push(input.value.trim());
  });

  const goals = [];
  document.querySelectorAll('.goal-checkbox:checked').forEach(cb => {
    goals.push(cb.value);
  });

  const platforms = [];
  document.querySelectorAll('.platform-checkbox:checked').forEach(cb => {
    platforms.push(cb.value);
  });

  return {
    business_description: $('obBusinessDesc')?.value?.trim() || '',
    products_services: $('obProducts')?.value?.trim() || '',
    website_url: $('obWebsite')?.value?.trim() || '',
    unique_selling_points: $('obUSPs')?.value?.trim() || '',
    target_audience: $('obAudience')?.value?.trim() || '',
    competitors: competitors.join(', '),
    marketing_goals: goals.join(', '),
    active_platforms: platforms.join(', '),
    content_examples: $('obExamples')?.value?.trim() || '',
    budget_range: $('obBudget')?.value || '',
    onboarding_completed: true,
  };
}

async function launchAutopilot() {
  const btn = $('launchAutopilot');
  if (!btn) return;

  const data = collectFormData();

  btn.classList.add('loading');
  btn.disabled = true;
  btn.textContent = 'Saving profile...';

  try {
    // Save profile first
    await api('/api/onboarding/profile', {
      method: 'POST',
      body: JSON.stringify(data),
    });

    btn.textContent = 'Launching AI Autopilot...';

    // Launch autopilot pipeline
    const result = await api('/api/autopilot/launch', { method: 'POST', body: '{}' });

    success('AI Autopilot complete! Your marketing foundation is ready.');
    navigate('dashboard');
  } catch (err) {
    error('Autopilot error: ' + err.message);
    btn.textContent = 'Launch AI Autopilot';
  } finally {
    btn.classList.remove('loading');
    btn.disabled = false;
  }
}
