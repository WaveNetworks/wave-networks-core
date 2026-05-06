<?php
/**
 * snippets/feedback_tab.php
 * Floating feedback tab — available on every page for logged-in users.
 * Include in admin/views/template.php and child-app/views/template.php.
 *
 * Child-app usage:
 *   <?php include(__DIR__ . '/../../admin/snippets/feedback_tab.php'); ?>
 */
if (empty($_SESSION['user_id'])) return;
?>

<!-- Floating feedback trigger tab -->
<!-- onclick captures BEFORE Bootstrap shows the offcanvas; data-bs-toggle is removed
     so we can sequence: capture → then open. submitFeedbackTrigger() opens it. -->
<button type="button" id="feedbackTabTrigger" class="btn btn-primary shadow"
        onclick="openFeedbackTabWithCapture()"
        style="position:fixed; right:0; top:50%; transform:translateX(calc(50% - 0.75em)) translateY(-50%) rotate(-90deg);
               z-index:1040; border-radius:0.375rem 0.375rem 0 0; padding:0.2rem 1rem 1rem 1rem; font-size:0.85rem; letter-spacing:0.03em;">
    <i class="bi bi-chat-left-text me-1"></i>Feedback
</button>

<!-- Feedback offcanvas panel -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="feedbackOffcanvas" style="width:360px;">
    <div class="offcanvas-header border-bottom">
        <h6 class="offcanvas-title"><i class="bi bi-chat-left-text me-2"></i>Send Feedback</h6>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body">
        <div id="feedbackFormContainer">
            <div class="mb-3">
                <label class="form-label fw-semibold">Type</label>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="fb_type" id="fbTypeBug" value="bug">
                    <label class="btn btn-outline-danger btn-sm" for="fbTypeBug"><i class="bi bi-bug me-1"></i>Bug</label>

                    <input type="radio" class="btn-check" name="fb_type" id="fbTypeSuggestion" value="suggestion">
                    <label class="btn btn-outline-primary btn-sm" for="fbTypeSuggestion"><i class="bi bi-lightbulb me-1"></i>Suggestion</label>

                    <input type="radio" class="btn-check" name="fb_type" id="fbTypeGeneral" value="general" checked>
                    <label class="btn btn-outline-secondary btn-sm" for="fbTypeGeneral"><i class="bi bi-chat me-1"></i>General</label>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Your Feedback</label>
                <textarea class="form-control" id="fbMessage" rows="5" placeholder="Tell us what you think..."></textarea>
            </div>
            <!-- Screenshot preview (hidden until capture completes) -->
            <div class="mb-3" id="fbScreenshotPanel" style="display:none;">
                <label class="form-label fw-semibold d-flex align-items-center justify-content-between">
                    <span>Screenshot</span>
                    <span class="text-muted" style="font-size:0.7rem;" id="fbScreenshotStatus"></span>
                </label>
                <div class="position-relative d-inline-block" id="fbScreenshotWrap"
                     title="Click to remove if it contains sensitive info"
                     style="cursor:pointer;">
                    <img id="fbScreenshotThumb" alt="page capture" style="max-width:120px; max-height:120px; border:1px solid #ddd; border-radius:4px;">
                    <button type="button" id="fbScreenshotRemove" onclick="removeFeedbackScreenshot()"
                            class="btn btn-sm btn-danger position-absolute"
                            style="top:-8px; right:-8px; padding:0.05rem 0.35rem; line-height:1; border-radius:50%;"
                            title="Remove screenshot before submitting">×</button>
                </div>
                <div class="form-check form-check-sm mt-2">
                    <input class="form-check-input" type="checkbox" id="fbIncludeScreenshotPref" onchange="setFeedbackScreenshotPref(this.checked)">
                    <label class="form-check-label small text-muted" for="fbIncludeScreenshotPref">
                        Include screenshots with my feedback
                    </label>
                </div>
            </div>
            <div class="mb-3" id="fbCapturingPanel" style="display:none;">
                <div class="d-flex align-items-center text-muted" style="font-size:0.8rem;">
                    <span class="spinner-border spinner-border-sm me-2"></span>
                    Capturing screen…
                </div>
            </div>

            <div class="mb-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>Page and user info captured automatically.
                </small>
                <div class="mt-1 px-2 py-1 bg-light rounded" style="font-size:0.75rem;">
                    <span class="text-muted d-block text-truncate" id="fbCapturedUrl" title=""></span>
                    <?php if (!empty($_SESSION['user_id'])) { ?>
                    <span class="text-muted d-block"><?= h(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')) . ' <' . ($_SESSION['email'] ?? '') . '>') ?></span>
                    <?php } ?>
                </div>
            </div>
            <button class="btn btn-primary w-100" id="fbSubmitBtn" onclick="submitFeedbackTab()">
                <i class="bi bi-send me-1"></i>Submit Feedback
            </button>
        </div>
        <div id="feedbackSuccessContainer" style="display:none;" class="text-center py-5">
            <i class="bi bi-check-circle text-success" style="font-size:3rem;"></i>
            <h5 class="mt-3">Thank you!</h5>
            <p class="text-muted">Your feedback has been received.</p>
            <button class="btn btn-outline-primary btn-sm" onclick="resetFeedbackForm()">Send More</button>
        </div>
    </div>
</div>

<script>
(function() {
    var urlEl = document.getElementById('fbCapturedUrl');
    if (urlEl) {
        urlEl.textContent = window.location.href;
        urlEl.title = window.location.href;
    }
    // Default to opt-in for admin users; respect prior localStorage choice if set.
    var pref = localStorage.getItem('fb_include_screenshot');
    var prefBox = document.getElementById('fbIncludeScreenshotPref');
    if (prefBox) prefBox.checked = (pref === null) ? true : (pref === '1');
})();

// In-memory capture state. Cleared when the offcanvas hides or after submit.
window._fbCapture = { dataUrl: null, context: null };

function setFeedbackScreenshotPref(on) {
    localStorage.setItem('fb_include_screenshot', on ? '1' : '0');
    if (!on) removeFeedbackScreenshot();
}

function removeFeedbackScreenshot() {
    window._fbCapture.dataUrl = null;
    var panel = document.getElementById('fbScreenshotPanel');
    if (panel) panel.style.display = 'none';
}

// Lazy-load html2canvas the first time we need it.
var _fbHtml2CanvasPromise = null;
function loadHtml2Canvas() {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    if (_fbHtml2CanvasPromise) return _fbHtml2CanvasPromise;
    _fbHtml2CanvasPromise = new Promise(function (resolve, reject) {
        var s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js';
        s.async = true;
        s.onload = function () { resolve(window.html2canvas); };
        s.onerror = function () { reject(new Error('html2canvas load failed')); };
        document.head.appendChild(s);
    });
    return _fbHtml2CanvasPromise;
}

// Walk all data-feedback-context elements and merge their JSON hints.
function collectFeedbackContext() {
    var custom = {};
    var els = document.querySelectorAll('[data-feedback-context]');
    for (var i = 0; i < els.length; i++) {
        try {
            var hint = JSON.parse(els[i].getAttribute('data-feedback-context'));
            if (hint && typeof hint === 'object') {
                for (var k in hint) if (Object.prototype.hasOwnProperty.call(hint, k)) custom[k] = hint[k];
            }
        } catch (e) { /* malformed hint — skip */ }
    }
    return {
        url: window.location.href,
        title: document.title,
        viewport_w: window.innerWidth,
        viewport_h: window.innerHeight,
        scroll_y: window.scrollY,
        user_agent: navigator.userAgent,
        custom: custom
    };
}

// Trigger flow: hide modal element → capture → restore → open offcanvas.
// We can't open the offcanvas first because html2canvas would render it
// over the page (and capture itself). visibility:hidden preserves layout
// to avoid jank.
function openFeedbackTabWithCapture() {
    var offEl = document.getElementById('feedbackOffcanvas');
    var capturingPanel = document.getElementById('fbCapturingPanel');
    var screenshotPanel = document.getElementById('fbScreenshotPanel');
    var prefBox = document.getElementById('fbIncludeScreenshotPref');

    // Always update the captured-URL display + context bundle
    window._fbCapture.context = collectFeedbackContext();
    var urlEl = document.getElementById('fbCapturedUrl');
    if (urlEl) {
        urlEl.textContent = window._fbCapture.context.url;
        urlEl.title = window._fbCapture.context.url;
    }

    function show() {
        bootstrap.Offcanvas.getOrCreateInstance(offEl).show();
    }

    var wantCapture = prefBox ? prefBox.checked : true;
    if (!wantCapture) {
        if (screenshotPanel) screenshotPanel.style.display = 'none';
        show();
        return;
    }

    // Hide via visibility (not display:none) so layout doesn't shift mid-capture.
    var prevVis = offEl.style.visibility;
    offEl.style.visibility = 'hidden';

    // Show the offcanvas so it's already painted under the capture
    show();
    if (capturingPanel) capturingPanel.style.display = '';
    if (screenshotPanel) screenshotPanel.style.display = 'none';

    loadHtml2Canvas()
        .then(function (h2c) {
            var scale = Math.min(window.devicePixelRatio || 1, 2);
            return h2c(document.body, {
                useCORS: true,
                scale: scale,
                backgroundColor: null,
                logging: false,
                ignoreElements: function (el) {
                    return el.id === 'feedbackOffcanvas' ||
                           el.id === 'feedbackTabTrigger' ||
                           (el.classList && el.classList.contains('offcanvas-backdrop'));
                }
            });
        })
        .then(function (canvas) {
            var dataUrl = canvas.toDataURL('image/jpeg', 0.85);
            window._fbCapture.dataUrl = dataUrl;
            var thumb = document.getElementById('fbScreenshotThumb');
            if (thumb) thumb.src = dataUrl;
            var status = document.getElementById('fbScreenshotStatus');
            if (status) status.textContent = canvas.width + '×' + canvas.height;
            if (screenshotPanel) screenshotPanel.style.display = '';
        })
        .catch(function () {
            // Capture failure is non-fatal — let the user submit without an image.
            if (screenshotPanel) screenshotPanel.style.display = 'none';
        })
        .then(function () {
            if (capturingPanel) capturingPanel.style.display = 'none';
            offEl.style.visibility = prevVis || '';
        });
}

function submitFeedbackTab() {
    var message = document.getElementById('fbMessage').value.trim();
    if (!message) {
        document.getElementById('fbMessage').classList.add('is-invalid');
        return;
    }
    document.getElementById('fbMessage').classList.remove('is-invalid');

    var typeEl = document.querySelector('input[name="fb_type"]:checked');
    var type = typeEl ? typeEl.value : 'general';

    var btn = document.getElementById('fbSubmitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending...';

    var fd = new FormData();
    fd.append('action', 'submitFeedback');
    fd.append('feedback_type', type);
    fd.append('message', message);
    fd.append('page_url', window.location.href);
    fd.append('source_app', detectSourceApp());

    if (window._fbCapture && window._fbCapture.context) {
        fd.append('context_json', JSON.stringify(window._fbCapture.context));
    }
    if (window._fbCapture && window._fbCapture.dataUrl) {
        // Strip the data: prefix server-side; the column is JPEG bytes only.
        fd.append('screenshot', window._fbCapture.dataUrl);
    }

    fetch(getApiUrl(), { method: 'POST', body: fd })
        .then(function (r) { return r.json(); })
        .then(function (j) {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit Feedback';
            if (j.error) {
                alert(j.error);
                return;
            }
            document.getElementById('feedbackFormContainer').style.display = 'none';
            document.getElementById('feedbackSuccessContainer').style.display = '';
        })
        .catch(function () {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send me-1"></i>Submit Feedback';
            alert('Failed to submit feedback. Please try again.');
        });
}

function resetFeedbackForm() {
    document.getElementById('fbMessage').value = '';
    document.getElementById('fbTypeGeneral').checked = true;
    document.getElementById('feedbackFormContainer').style.display = '';
    document.getElementById('feedbackSuccessContainer').style.display = 'none';
    removeFeedbackScreenshot();
}

function detectSourceApp() {
    var path = window.location.pathname;
    var match = path.match(/\/([^\/]+)\/(app|api|auth)\//);
    return match ? match[1] : 'admin';
}

function getApiUrl() {
    // Resolve admin API path relative to current page
    var path = window.location.pathname;
    if (path.indexOf('/admin/') !== -1) {
        return '../api/index.php';
    }
    // Child app — go up to admin
    return '../../admin/api/index.php';
}
</script>
