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
<button type="button" id="feedbackTabTrigger" class="btn btn-primary shadow"
        data-bs-toggle="offcanvas" data-bs-target="#feedbackOffcanvas"
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
            <div class="mb-3">
                <small class="text-muted">
                    <i class="bi bi-info-circle me-1"></i>Page and user info captured automatically.
                </small>
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
