/**
 * bs-init.js
 * Bootstrap initialization and global JS utilities.
 */

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function(el) {
        return new bootstrap.Tooltip(el);
    });

    // Initialize popovers
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function(el) {
        return new bootstrap.Popover(el);
    });
});

/**
 * POST to the API endpoint and handle JSON response.
 *
 * @param {string} action  The action name
 * @param {object} data    Key-value pairs to send
 * @param {function} callback  Called with (response) on success
 */
function apiPost(action, data, callback) {
    var formData = new FormData();
    formData.append('action', action);
    for (var key in data) {
        formData.append(key, data[key]);
    }

    fetch('../api/index.php', {
        method: 'POST',
        body: formData
    })
    .then(function(r) { return r.json(); })
    .then(function(json) {
        if (json.error) {
            showAlert('danger', json.error);
        } else if (json.success) {
            showAlert('success', json.success);
        }
        if (callback) callback(json);
    })
    .catch(function(err) {
        showAlert('danger', 'Request failed: ' + err.message);
    });
}

/**
 * Show a Bootstrap alert at the top of the content area.
 */
function showAlert(type, message) {
    var container = document.querySelector('.container-fluid');
    if (!container) return;

    var alert = document.createElement('div');
    alert.className = 'alert alert-' + type + ' alert-dismissible fade show';
    alert.setAttribute('role', 'alert');
    alert.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';

    var firstChild = container.querySelector('.alert') || container.firstChild;
    container.insertBefore(alert, firstChild);

    setTimeout(function() {
        alert.remove();
    }, 5000);
}
