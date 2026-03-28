/**
 * Reviews & Reputation — manage business reviews and AI-generated responses.
 */

import { api } from '../core/api.js';
import { $, escapeHtml, formatDateTime, onSubmit, formData, onClick, emptyState, confirm } from '../core/utils.js';
import { success, error } from '../core/toast.js';

async function loadReviewStats() {
  const el = $('reviewStats');
  if (!el) return;
  try {
    const { item } = await api('/api/reviews/stats');
    const stats = item || {};
    const cards = [
      ['Total Reviews', stats.total || 0, '&#11088;'],
      ['Avg Rating', stats.avg_rating ? Number(stats.avg_rating).toFixed(1) : '-', '&#9733;'],
      ['Pending Response', stats.pending || 0, '&#128172;'],
      ['Responded', stats.responded || 0, '&#10003;'],
    ];
    el.innerHTML = cards.map(([label, value, icon]) =>
      `<div class="metric-card"><div class="metric-icon">${icon}</div><div class="metric-value">${escapeHtml(String(value))}</div><div class="metric-label">${label}</div></div>`
    ).join('');
  } catch (_) {
    el.innerHTML = '';
  }
}

async function loadReviews() {
  try {
    const { items } = await api('/api/reviews');
    const list = $('reviewList');
    if (!list) return;

    if (!items || items.length === 0) {
      list.innerHTML = emptyState('&#11088;', 'No reviews yet', 'Add your first customer review to start managing your reputation.', '<button class="btn btn-ai" onclick="document.getElementById(\'addReviewBtn\').click()"><span class="btn-ai-icon">+</span> Add Review</button>');
      return;
    }

    list.innerHTML = items.map(r => {
      const stars = '&#9733;'.repeat(r.rating || 0) + '&#9734;'.repeat(5 - (r.rating || 0));
      const platformBadge = { google: 'badge-info', yelp: 'badge-danger', facebook: 'badge-primary', manual: '' };
      return `<div class="card mb-1">
        <div class="flex-between">
          <div>
            <span class="badge ${platformBadge[r.platform] || ''}">${escapeHtml(r.platform || 'manual')}</span>
            <strong class="ml-1">${escapeHtml(r.reviewer_name || 'Anonymous')}</strong>
          </div>
          <div class="flex gap-1">
            <span style="color:#f59e0b">${stars}</span>
            <span class="text-small text-muted">${formatDateTime(r.created_at)}</span>
          </div>
        </div>
        <p class="mt-1">${escapeHtml(r.review_text || '')}</p>
        ${r.response_text ? `
          <div class="mt-1" style="border-left:3px solid var(--accent);padding-left:12px">
            <p class="text-small"><strong>Your Response:</strong></p>
            <p class="text-small">${escapeHtml(r.response_text)}</p>
          </div>
        ` : `
          <div class="flex gap-1 mt-1">
            <button class="btn btn-sm btn-ai" data-ai-respond="${r.id}"><span class="btn-ai-icon">&#9733;</span> AI Response</button>
            <button class="btn btn-sm btn-outline" data-manual-respond="${r.id}">Write Response</button>
            <button class="btn btn-sm btn-ghost text-danger" data-delete-review="${r.id}">Delete</button>
          </div>
        `}
      </div>`;
    }).join('');

    // Wire action buttons using event delegation
    list.onclick = async (e) => {
      const aiBtn = e.target.closest('[data-ai-respond]');
      if (aiBtn) {
        const id = aiBtn.dataset.aiRespond;
        aiBtn.classList.add('loading'); aiBtn.disabled = true;
        try {
          const review = items.find(r => r.id == id);
          const { item } = await api('/api/ai/review-response', {
            method: 'POST',
            body: JSON.stringify({ review_text: review?.review_text || '', rating: review?.rating || 3, reviewer_name: review?.reviewer_name || '' }),
          });
          const response = item?.response || item?.raw || '';
          if (response) {
            await api(`/api/reviews/${id}`, {
              method: 'PUT',
              body: JSON.stringify({ response_text: response, response_status: 'responded' }),
            });
            success('AI response generated and saved');
            loadReviews();
          }
        } catch (err) { error(err.message); }
        finally { aiBtn.classList.remove('loading'); aiBtn.disabled = false; }
        return;
      }

      const manualBtn = e.target.closest('[data-manual-respond]');
      if (manualBtn) {
        const id = manualBtn.dataset.manualRespond;
        const card = manualBtn.closest('.card');
        const existing = card.querySelector('.response-form');
        if (existing) { existing.remove(); return; }

        const form = document.createElement('div');
        form.className = 'response-form mt-1';
        form.innerHTML = `
          <textarea class="input w-full" rows="3" placeholder="Write your response..."></textarea>
          <div class="flex gap-1 mt-1">
            <button class="btn btn-sm btn-ai save-response-btn">Save Response</button>
            <button class="btn btn-sm btn-ghost cancel-response-btn">Cancel</button>
          </div>
        `;
        card.appendChild(form);

        form.querySelector('.save-response-btn').addEventListener('click', async () => {
          const text = form.querySelector('textarea').value.trim();
          if (!text) { error('Write a response first'); return; }
          try {
            await api(`/api/reviews/${id}`, {
              method: 'PUT',
              body: JSON.stringify({ response_text: text, response_status: 'responded' }),
            });
            success('Response saved');
            loadReviews();
          } catch (err) { error(err.message); }
        });
        form.querySelector('.cancel-response-btn').addEventListener('click', () => form.remove());
        return;
      }

      const delBtn = e.target.closest('[data-delete-review]');
      if (delBtn) {
        if (!await confirm('Delete Review', 'Are you sure you want to delete this review?')) return;
        try {
          await api(`/api/reviews/${delBtn.dataset.deleteReview}`, { method: 'DELETE' });
          success('Review deleted');
          loadReviews();
          loadReviewStats();
        } catch (err) { error(err.message); }
      }
    };
  } catch (err) {
    error('Failed to load reviews: ' + err.message);
  }
}

export async function refresh() {
  await Promise.all([loadReviewStats(), loadReviews()]);
}

export function init() {
  // Toggle add review form
  onClick('addReviewBtn', () => {
    $('addReviewForm')?.classList.toggle('hidden');
  });
  onClick('cancelAddReview', () => {
    $('addReviewForm')?.classList.add('hidden');
  });

  // Submit review form
  onSubmit('reviewForm', async (e) => {
    const data = formData(e);
    try {
      await api('/api/reviews', { method: 'POST', body: JSON.stringify(data) });
      e.target.reset();
      $('addReviewForm')?.classList.add('hidden');
      success('Review added');
      refresh();
    } catch (err) { error(err.message); }
  });
}
