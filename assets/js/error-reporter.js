/**
 * error-reporter.js
 * Captures JavaScript errors and sends them to the error log via apiPost().
 * Catches: uncaught errors, unhandled promise rejections, console.error calls.
 * Client-side dedup: max 10 unique errors per page load.
 */
(function() {
    var reported = {};
    var reportCount = 0;
    var MAX_REPORTS = 10;

    function makeHash(msg, file, line) {
        return (file || '') + ':' + (line || '') + ':' + (msg || '');
    }

    function reportError(data) {
        if (reportCount >= MAX_REPORTS) return;

        var key = makeHash(data.message, data.file, data.line);
        if (reported[key]) return;
        reported[key] = true;
        reportCount++;

        // Detect source_app from current URL path
        var pathParts = window.location.pathname.replace(/^\//, '').split('/');
        var sourceApp = pathParts[0] || 'unknown';

        var payload = {
            message:    (data.message || '').substring(0, 65535),
            file:       (data.file || '').substring(0, 500),
            line:       data.line || 0,
            column:     data.column || 0,
            stack:      (data.stack || '').substring(0, 10000),
            error_type: data.error_type || 'uncaught',
            source_app: sourceApp,
            page_url:   window.location.href.substring(0, 500),
            referrer:   (document.referrer || '').substring(0, 500)
        };

        // Use apiPost if available (loaded after bs-init.js), otherwise raw fetch
        if (typeof apiPost === 'function') {
            apiPost('logJsError', payload, function() {});
        } else {
            var formData = new FormData();
            formData.append('action', 'logJsError');
            for (var k in payload) {
                formData.append(k, payload[k]);
            }
            try {
                fetch('../api/index.php', { method: 'POST', body: formData });
            } catch(e) {
                // Silent fail — can't report errors about error reporting
            }
        }
    }

    // 1. Uncaught runtime errors
    window.addEventListener('error', function(event) {
        reportError({
            message:    event.message,
            file:       event.filename,
            line:       event.lineno,
            column:     event.colno,
            stack:      event.error ? event.error.stack : '',
            error_type: 'uncaught'
        });
    });

    // 2. Unhandled promise rejections
    window.addEventListener('unhandledrejection', function(event) {
        var reason = event.reason;
        var message = '';
        var stack = '';

        if (reason instanceof Error) {
            message = reason.message;
            stack = reason.stack || '';
        } else if (typeof reason === 'string') {
            message = reason;
        } else {
            message = 'Unhandled promise rejection';
            try { stack = JSON.stringify(reason); } catch(e) {}
        }

        reportError({
            message:    message,
            file:       '',
            line:       0,
            column:     0,
            stack:      stack,
            error_type: 'unhandled_rejection'
        });
    });

    // 3. Console.error override
    var originalConsoleError = console.error;
    console.error = function() {
        // Always pass through to real console.error
        originalConsoleError.apply(console, arguments);

        var parts = [];
        var errorStack = '';
        for (var i = 0; i < arguments.length; i++) {
            var arg = arguments[i];
            if (arg instanceof Error) {
                parts.push(arg.message);
                errorStack = arg.stack || '';
            } else if (typeof arg === 'object') {
                try { parts.push(JSON.stringify(arg)); } catch(e) { parts.push(String(arg)); }
            } else {
                parts.push(String(arg));
            }
        }

        var message = parts.join(' ');
        // Capture a stack trace for location context
        var traceStack = errorStack;
        if (!traceStack) {
            try {
                throw new Error('__console_error_trace__');
            } catch(e) {
                traceStack = (e.stack || '').replace(/.*__console_error_trace__.*\n?/, '');
                // Remove the console.error override frame
                traceStack = traceStack.replace(/.*error-reporter\.js.*\n?/g, '');
            }
        }

        reportError({
            message:    message,
            file:       '',
            line:       0,
            column:     0,
            stack:      traceStack,
            error_type: 'console_error'
        });
    };
})();
