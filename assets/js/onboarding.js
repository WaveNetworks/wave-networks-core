/**
 * onboarding.js
 * Reads window.WN_ONBOARDING (injected by template) and runs either:
 *   - the welcome modal (status=not_started) — title/body/two CTAs
 *   - the guided tour (status=in_progress) — popover-style highlight per step
 *
 * Self-contained: depends only on Bootstrap 5 (already loaded for modal/popover)
 * + the apiPost() helper from bs-init.js. driver.js can replace the player
 * later without changing the data contract.
 */
(function () {
  var data = window.WN_ONBOARDING;
  if (!data || !data.tour) return;

  var tour = data.tour;
  var steps = data.steps || [];
  var status = data.status || 'not_started';
  var slug = tour.slug;

  var post = (window.apiPost) ? window.apiPost : function (action, params) {
    var fd = new FormData();
    fd.append('action', action);
    Object.keys(params || {}).forEach(function (k) { fd.append(k, params[k]); });
    return fetch('../api/index.php', { method: 'POST', body: fd, credentials: 'same-origin' });
  };

  function escapeHtml(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  // Minimal markdown: paragraphs + **bold** + *italic* + line breaks. Anything
  // richer can swap in a real markdown lib later.
  function md(s) {
    if (!s) return '';
    var out = escapeHtml(s);
    out = out.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    out = out.replace(/\*([^*]+)\*/g, '<em>$1</em>');
    out = out.replace(/\n\n/g, '</p><p>');
    out = out.replace(/\n/g, '<br>');
    return '<p>' + out + '</p>';
  }

  function showWelcome() {
    var modalHtml = ''
      + '<div class="modal fade" id="wnOnboardingWelcome" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">'
      + '  <div class="modal-dialog modal-dialog-centered modal-lg">'
      + '    <div class="modal-content">'
      + '      <div class="modal-header">'
      + '        <h5 class="modal-title">' + escapeHtml(tour.welcome_title || tour.name) + '</h5>'
      + '      </div>'
      + '      <div class="modal-body">' + md(tour.welcome_body_md || '') + '</div>'
      + '      <div class="modal-footer">'
      + '        <button type="button" class="btn btn-outline-secondary" id="wnOnbSkip">' + escapeHtml(tour.welcome_cta_secondary || 'Explore on my own') + '</button>'
      + '        <button type="button" class="btn btn-primary" id="wnOnbStart">' + escapeHtml(tour.welcome_cta_primary || 'Take the tour') + '</button>'
      + '      </div>'
      + '    </div>'
      + '  </div>'
      + '</div>';

    var holder = document.createElement('div');
    holder.innerHTML = modalHtml;
    document.body.appendChild(holder.firstChild);

    var el = document.getElementById('wnOnboardingWelcome');
    var modal = new bootstrap.Modal(el);
    modal.show();

    document.getElementById('wnOnbSkip').addEventListener('click', function () {
      post('tourSkip', { tour_slug: slug });
      modal.hide();
    });
    document.getElementById('wnOnbStart').addEventListener('click', function () {
      post('tourStart', { tour_slug: slug }).then(function () {
        modal.hide();
        runTour(0);
      });
    });
  }

  /* ---------- guided tour player ---------- */

  function clearPopover() {
    var existing = document.getElementById('wnOnboardingPopover');
    if (existing) existing.remove();
    var overlay = document.getElementById('wnOnboardingOverlay');
    if (overlay) overlay.remove();
    document.querySelectorAll('.wn-onb-highlight').forEach(function (n) {
      n.classList.remove('wn-onb-highlight');
    });
  }

  function runTour(idx) {
    clearPopover();
    if (idx >= steps.length) {
      post('tourComplete', { tour_slug: slug });
      return;
    }

    var step = steps[idx];
    var target = step.selector ? document.querySelector(step.selector) : null;
    var rect = target ? target.getBoundingClientRect() : null;

    if (target) target.classList.add('wn-onb-highlight');

    var overlay = document.createElement('div');
    overlay.id = 'wnOnboardingOverlay';
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1080;pointer-events:auto;';
    document.body.appendChild(overlay);

    var pop = document.createElement('div');
    pop.id = 'wnOnboardingPopover';
    pop.className = 'card shadow';
    pop.style.cssText = 'position:fixed;z-index:1090;max-width:360px;min-width:280px;';
    pop.innerHTML = ''
      + '<div class="card-body">'
      + '  <h6 class="card-title mb-2">' + escapeHtml(step.title || '') + '</h6>'
      + '  <div class="card-text small">' + md(step.body_md || '') + '</div>'
      + '  <div class="d-flex justify-content-between align-items-center mt-3">'
      + '    <small class="text-muted">' + (idx + 1) + ' / ' + steps.length + '</small>'
      + '    <div>'
      + (idx > 0 ? '<button class="btn btn-sm btn-outline-secondary me-1" id="wnOnbPrev">Back</button>' : '')
      + '      <button class="btn btn-sm btn-link" id="wnOnbStepSkip">Skip</button>'
      + '      <button class="btn btn-sm btn-primary" id="wnOnbNext">' + (idx + 1 === steps.length ? 'Finish' : 'Next') + '</button>'
      + '    </div>'
      + '  </div>'
      + '</div>';
    document.body.appendChild(pop);

    var pos = step.position || 'bottom';
    var pw = pop.offsetWidth, ph = pop.offsetHeight;
    var top, left;
    if (!rect || pos === 'center') {
      top = (window.innerHeight - ph) / 2;
      left = (window.innerWidth - pw) / 2;
    } else if (pos === 'top') {
      top = rect.top - ph - 8;
      left = rect.left + (rect.width - pw) / 2;
    } else if (pos === 'left') {
      top = rect.top + (rect.height - ph) / 2;
      left = rect.left - pw - 8;
    } else if (pos === 'right') {
      top = rect.top + (rect.height - ph) / 2;
      left = rect.right + 8;
    } else { /* bottom */
      top = rect.bottom + 8;
      left = rect.left + (rect.width - pw) / 2;
    }
    top = Math.max(8, Math.min(top, window.innerHeight - ph - 8));
    left = Math.max(8, Math.min(left, window.innerWidth - pw - 8));
    pop.style.top = top + 'px';
    pop.style.left = left + 'px';

    if (target && target.scrollIntoView) {
      try { target.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
    }

    document.getElementById('wnOnbNext').addEventListener('click', function () {
      post('tourAdvance', { tour_slug: slug, step: idx + 1 });
      runTour(idx + 1);
    });
    document.getElementById('wnOnbStepSkip').addEventListener('click', function () {
      post('tourSkip', { tour_slug: slug });
      clearPopover();
    });
    var prev = document.getElementById('wnOnbPrev');
    if (prev) {
      prev.addEventListener('click', function () { runTour(idx - 1); });
    }
  }

  // Inject highlight style once
  if (!document.getElementById('wn-onb-style')) {
    var s = document.createElement('style');
    s.id = 'wn-onb-style';
    s.textContent = '.wn-onb-highlight{position:relative;z-index:1085;box-shadow:0 0 0 4px rgba(13,110,253,.85),0 0 0 9999px rgba(0,0,0,0);border-radius:4px;}';
    document.head.appendChild(s);
  }

  // Boot
  if (data.preview) {
    runTour(0);
  } else if (status === 'not_started') {
    showWelcome();
  } else if (status === 'in_progress') {
    runTour(parseInt(data.current_step || 0, 10) || 0);
  }
})();
