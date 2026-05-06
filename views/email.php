<?php
/**
 * views/email.php
 * Admin email settings — SMTP, allowed senders, throttle, DNS info, queue monitor.
 */
$page_title = 'Email Settings';

if (!has_role('admin')) {
    $_SESSION['error'] = 'Admin access required.';
    header('Location: index.php?page=dashboard');
    exit;
}

$emailSettings = get_email_settings();
$senders       = get_allowed_senders();
$stats         = get_email_queue_stats();

// DNS info from default sender domain
$dns_info = null;
$sender_domain = '';
$default_from = $emailSettings['default_from_email'] ?? '';
if (!empty($default_from) && strpos($default_from, '@') !== false) {
    $sender_domain = substr($default_from, strpos($default_from, '@') + 1);
    $dns_info = get_email_dns_info($sender_domain);
}

$active_email_tab = $_GET['tab'] ?? 'settings';
$email_templates  = function_exists('list_email_templates')        ? list_email_templates()        : [];
$drip_campaigns   = function_exists('list_email_drip_campaigns')   ? list_email_drip_campaigns()   : [];
$trigger_events   = function_exists('list_email_trigger_events')   ? list_email_trigger_events()   : [];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h3><i class="bi bi-envelope me-2"></i>Email</h3>
</div>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $active_email_tab === 'settings' ? 'active' : '' ?>" href="?page=email&tab=settings">
            <i class="bi bi-gear me-1"></i> Settings &amp; Queue
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_email_tab === 'templates' ? 'active' : '' ?>" href="?page=email&tab=templates">
            <i class="bi bi-file-earmark-text me-1"></i> Templates
            <span class="badge bg-secondary"><?= count($email_templates) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $active_email_tab === 'campaigns' ? 'active' : '' ?>" href="?page=email&tab=campaigns">
            <i class="bi bi-broadcast me-1"></i> Campaigns
            <span class="badge bg-secondary"><?= count($drip_campaigns) ?></span>
        </a>
    </li>
</ul>

<?php if ($active_email_tab === 'settings') { ?>

<!-- SMTP Configuration + Default Sender -->
<div class="row">
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-hdd-network me-1"></i> SMTP Configuration</h6></div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="saveEmailSettings">

                    <div class="row mb-3">
                        <div class="col-sm-8 mb-2 mb-sm-0">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" class="form-control form-control-sm" name="smtp_host" value="<?= h($emailSettings['smtp_host']) ?>" placeholder="smtp.yourdomain.com">
                        </div>
                        <div class="col-sm-4">
                            <label class="form-label">Port</label>
                            <input type="number" class="form-control form-control-sm" name="smtp_port" value="<?= (int)$emailSettings['smtp_port'] ?>" placeholder="587">
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-sm-6 mb-2 mb-sm-0">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control form-control-sm" name="smtp_user" value="<?= h($emailSettings['smtp_user']) ?>" autocomplete="off">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control form-control-sm" name="smtp_pass" value="" placeholder="<?= !empty($emailSettings['smtp_pass']) ? '••••••••' : '' ?>" autocomplete="new-password">
                            <div class="form-text">Leave blank to keep current.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Encryption</label>
                        <select class="form-select form-select-sm" name="smtp_encryption">
                            <option value="tls" <?= ($emailSettings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>>STARTTLS (Port 587)</option>
                            <option value="ssl" <?= ($emailSettings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL/TLS (Port 465)</option>
                            <option value="none" <?= ($emailSettings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                        </select>
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Default Sender &amp; Reply-To</h6>

                    <div class="row mb-3">
                        <div class="col-sm-6 mb-2 mb-sm-0">
                            <label class="form-label">From Email</label>
                            <input type="email" class="form-control form-control-sm" name="default_from_email" value="<?= h($emailSettings['default_from_email']) ?>" placeholder="noreply@yourdomain.com">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label">From Name</label>
                            <input type="text" class="form-control form-control-sm" name="default_from_name" value="<?= h($emailSettings['default_from_name']) ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reply-To Email <span class="text-muted small">(optional)</span></label>
                        <input type="email" class="form-control form-control-sm" name="default_reply_to" value="<?= h($emailSettings['default_reply_to']) ?>" placeholder="support@yourdomain.com">
                    </div>

                    <hr>
                    <h6 class="text-muted mb-3">Throttle Settings</h6>

                    <div class="row mb-3">
                        <div class="col-4">
                            <label class="form-label">Per Min</label>
                            <input type="number" class="form-control form-control-sm" name="throttle_per_minute" value="<?= (int)$emailSettings['throttle_per_minute'] ?>" min="1">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Per Hour</label>
                            <input type="number" class="form-control form-control-sm" name="throttle_per_hour" value="<?= (int)$emailSettings['throttle_per_hour'] ?>" min="1">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Retries</label>
                            <input type="number" class="form-control form-control-sm" name="max_attempts" value="<?= (int)$emailSettings['max_attempts'] ?>" min="1" max="10">
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-check-lg"></i> Save
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="testEmail()">
                            <i class="bi bi-send"></i> Test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Allowed Senders + DNS Info -->
    <div class="col-lg-6 mb-4">
        <!-- Allowed Senders -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-person-check me-1"></i> Allowed Senders</h6>
                <button class="btn btn-sm btn-primary" onclick="showAddSender()">
                    <i class="bi bi-plus"></i> Add
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Email</th>
                            <th>Name</th>
                            <th>Default</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="sendersTable">
                        <?php if (empty($senders)) { ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No senders configured — all addresses allowed.</td></tr>
                        <?php } ?>
                        <?php foreach ($senders as $s) { ?>
                        <tr>
                            <td><?= h($s['email_address']) ?></td>
                            <td><?= h($s['display_name']) ?></td>
                            <td>
                                <?php if ($s['is_default']) { ?>
                                <span class="badge bg-success">Default</span>
                                <?php } else { ?>
                                <button class="btn btn-sm btn-outline-secondary" onclick="setDefault(<?= (int)$s['sender_id'] ?>)">Set</button>
                                <?php } ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteSender(<?= (int)$s['sender_id'] ?>, '<?= h($s['email_address']) ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>

            <!-- Inline add form (hidden by default) -->
            <div class="card-footer d-none" id="addSenderForm">
                <form onsubmit="addSender(event)">
                    <div class="row g-2 align-items-end">
                        <div class="col-sm-5 col-12">
                            <label class="form-label small">Email <span class="text-danger">*</span></label>
                            <input type="email" class="form-control form-control-sm" name="email_address" id="newSenderEmail" required>
                        </div>
                        <div class="col-sm-3 col-12">
                            <label class="form-label small">Name</label>
                            <input type="text" class="form-control form-control-sm" name="display_name" id="newSenderName">
                        </div>
                        <div class="col-sm-2 col-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_default" id="newSenderDefault">
                                <label class="form-check-label small" for="newSenderDefault">Default</label>
                            </div>
                        </div>
                        <div class="col-sm-2 col-6">
                            <button type="submit" class="btn btn-sm btn-primary w-100">Add</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- DNS / Deliverability Info -->
        <div class="card">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-shield-check me-1"></i> DNS / Deliverability</h6></div>
            <div class="card-body">
                <?php if (empty($sender_domain)) { ?>
                <p class="text-muted mb-0">Configure a default from email above to check DNS records.</p>
                <?php } else { ?>
                <p class="small text-muted mb-3">Checking records for <strong><?= h($sender_domain) ?></strong></p>

                <!-- SPF -->
                <div class="mb-3">
                    <h6>
                        <?php if (!empty($dns_info['spf'])) { ?>
                        <i class="bi bi-check-circle-fill text-success me-1"></i> SPF Record Found
                        <?php } else { ?>
                        <i class="bi bi-x-circle-fill text-danger me-1"></i> SPF Record Missing
                        <?php } ?>
                    </h6>
                    <?php if (!empty($dns_info['spf'])) { ?>
                    <code class="d-block small p-2 bg-light rounded" style="word-break: break-all;"><?= h($dns_info['spf']) ?></code>
                    <?php } else { ?>
                    <p class="small text-muted mb-0">Add a TXT record starting with <code>v=spf1</code> to your domain's DNS.</p>
                    <?php } ?>
                </div>

                <!-- DKIM -->
                <div>
                    <h6>
                        <?php if (!empty($dns_info['dkim'])) { ?>
                        <i class="bi bi-check-circle-fill text-success me-1"></i> DKIM Record(s) Found
                        <?php } else { ?>
                        <i class="bi bi-exclamation-triangle-fill text-warning me-1"></i> No DKIM Records Found
                        <?php } ?>
                    </h6>
                    <?php if (!empty($dns_info['dkim'])) { ?>
                        <?php foreach ($dns_info['dkim'] as $dkim) { ?>
                        <div class="mb-2">
                            <span class="badge bg-secondary"><?= h($dkim['selector']) ?></span>
                            <code class="d-block small mt-1 p-2 bg-light rounded" style="word-break: break-all;"><?= h($dkim['record']) ?></code>
                        </div>
                        <?php } ?>
                    <?php } else { ?>
                    <p class="small text-muted mb-0">Common selectors checked: default, google, selector1, selector2, mail, dkim, k1. Your DKIM selector may differ.</p>
                    <?php } ?>
                </div>
                <?php } ?>
            </div>
        </div>
    </div>
</div>

<!-- Queue Monitor -->
<div class="card mb-4">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h6 class="mb-0"><i class="bi bi-inbox me-1"></i> Email Queue</h6>
            <div class="d-flex flex-wrap gap-1 align-items-center">
                <span class="badge bg-primary"><?= $stats['queued'] ?> queued</span>
                <span class="badge bg-warning text-dark"><?= $stats['sending'] ?> sending</span>
                <span class="badge bg-success"><?= $stats['sent_today'] ?> sent today</span>
                <span class="badge bg-danger"><?= $stats['failed'] ?> failed</span>
                <span class="badge bg-info"><?= $stats['sent_hour'] ?>/hr</span>
                <select class="form-select form-select-sm" id="queueFilter" onchange="loadQueue()" style="width: auto;">
                    <option value="">All</option>
                    <option value="queued">Queued</option>
                    <option value="sent">Sent</option>
                    <option value="failed">Failed</option>
                </select>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm mb-0">
            <thead>
                <tr>
                    <th>To</th>
                    <th>Subject</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Created</th>
                    <th>Sent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="queueTable">
                <tr><td colspan="8" class="text-center text-muted py-3">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    <div class="card-footer d-flex justify-content-between align-items-center" id="queuePagination">
        <span id="queueInfo" class="text-muted small"></span>
        <div>
            <button class="btn btn-sm btn-outline-secondary" id="prevPage" onclick="changePage(-1)" disabled>&laquo; Prev</button>
            <button class="btn btn-sm btn-outline-secondary" id="nextPage" onclick="changePage(1)">Next &raquo;</button>
        </div>
    </div>
</div>

<?php } /* end settings tab */ ?>

<?php if ($active_email_tab === 'templates') { ?>
<!-- ═══ TEMPLATES TAB ═══ -->
<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-file-earmark-text me-1"></i> Templates</h6>
                <button class="btn btn-sm btn-primary" onclick="newTemplate()"><i class="bi bi-plus"></i> New</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Slug</th><th>Format</th><th>Updated</th></tr></thead>
                    <tbody>
                        <?php if (empty($email_templates)) { ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No templates yet — create one to start sending drip emails.</td></tr>
                        <?php } ?>
                        <?php foreach ($email_templates as $t) { ?>
                        <tr style="cursor:pointer" onclick="loadTemplate(<?= (int)$t['template_id'] ?>)">
                            <td><?= h($t['name']) ?></td>
                            <td><code class="small"><?= h($t['slug']) ?></code></td>
                            <td><span class="badge bg-light text-dark"><?= h($t['body_format']) ?></span></td>
                            <td class="small text-muted"><?= h($t['updated']) ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-4">
        <div class="card">
            <div class="card-header"><h6 class="mb-0" id="templateEditorTitle">Template editor</h6></div>
            <div class="card-body">
                <form id="templateForm" onsubmit="saveTemplate(event)">
                    <input type="hidden" name="template_id" id="tpl_id" value="0">
                    <div class="row mb-3">
                        <div class="col-md-7">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control form-control-sm" name="name" id="tpl_name" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control form-control-sm" name="slug" id="tpl_slug" placeholder="welcome_email" pattern="[a-z0-9_\-]+" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Subject <small class="text-muted">(supports <code>{{vars}}</code>)</small></label>
                        <input type="text" class="form-control form-control-sm" name="subject_tpl" id="tpl_subject" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Body</label>
                        <textarea class="form-control form-control-sm" name="body_tpl" id="tpl_body" rows="10" style="font-family: monospace;"></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Format</label>
                            <select class="form-select form-select-sm" name="body_format" id="tpl_format">
                                <option value="html">HTML</option>
                                <option value="markdown">Markdown</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Available variables</label>
                            <div id="tpl_vars" class="small">
                                <code class="me-1" onclick="insertVar('{{first_name}}')" style="cursor:pointer">{{first_name}}</code>
                                <code class="me-1" onclick="insertVar('{{last_name}}')" style="cursor:pointer">{{last_name}}</code>
                                <code class="me-1" onclick="insertVar('{{email}}')" style="cursor:pointer">{{email}}</code>
                                <code class="me-1" onclick="insertVar('{{site_name}}')" style="cursor:pointer">{{site_name}}</code>
                                <code class="me-1" onclick="insertVar('{{user_id}}')" style="cursor:pointer">{{user_id}}</code>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save</button>
                        <button type="button" class="btn btn-sm btn-outline-info" onclick="sendTestForCurrent()"><i class="bi bi-send"></i> Send test to me</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="tpl_delete_btn" onclick="deleteCurrentTemplate()"><i class="bi bi-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php } /* end templates tab */ ?>

<?php if ($active_email_tab === 'campaigns') { ?>
<!-- ═══ CAMPAIGNS TAB ═══ -->
<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-broadcast me-1"></i> Campaigns</h6>
                <button class="btn btn-sm btn-primary" onclick="newCampaign()"><i class="bi bi-plus"></i> New</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Name</th><th>Trigger</th><th>Steps</th><th>Active</th></tr></thead>
                    <tbody>
                        <?php if (empty($drip_campaigns)) { ?>
                        <tr><td colspan="4" class="text-muted text-center py-3">No campaigns yet.</td></tr>
                        <?php } ?>
                        <?php foreach ($drip_campaigns as $c) { ?>
                        <tr style="cursor:pointer" onclick='loadCampaign(<?= (int)$c['campaign_id'] ?>, <?= json_encode($c) ?>)'>
                            <td>
                                <?= h($c['name']) ?>
                                <div class="small text-muted"><?= (int)$c['active_enrollments'] ?> active</div>
                            </td>
                            <td><code class="small"><?= h($c['trigger_event'] ?: '—') ?></code></td>
                            <td><span class="badge bg-secondary"><?= (int)$c['step_count'] ?></span></td>
                            <td>
                                <?php if ($c['is_active']) { ?>
                                <span class="badge bg-success">Yes</span>
                                <?php } else { ?>
                                <span class="badge bg-secondary">No</span>
                                <?php } ?>
                            </td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-4">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0" id="campaignEditorTitle">Campaign editor</h6></div>
            <div class="card-body">
                <form id="campaignForm" onsubmit="saveCampaign(event)">
                    <input type="hidden" name="campaign_id" id="cmp_id" value="0">
                    <div class="row mb-3">
                        <div class="col-md-7">
                            <label class="form-label">Name</label>
                            <input type="text" class="form-control form-control-sm" name="name" id="cmp_name" required>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Slug</label>
                            <input type="text" class="form-control form-control-sm" name="slug" id="cmp_slug" placeholder="onboarding" pattern="[a-z0-9_\-]+" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control form-control-sm" name="description" id="cmp_desc" rows="2"></textarea>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-7">
                            <label class="form-label">Trigger event <small class="text-muted">(optional — informational)</small></label>
                            <select class="form-select form-select-sm" name="trigger_event" id="cmp_trigger">
                                <option value="">—</option>
                                <?php foreach ($trigger_events as $ev) { ?>
                                <option value="<?= h($ev['slug']) ?>"><?= h($ev['label']) ?> (<?= h($ev['slug']) ?>)</option>
                                <?php } ?>
                            </select>
                        </div>
                        <div class="col-md-5 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="cmp_active" value="1" checked>
                                <label class="form-check-label" for="cmp_active">Active</label>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save</button>
                        <button type="button" class="btn btn-sm btn-outline-danger ms-auto d-none" id="cmp_delete_btn" onclick="deleteCurrentCampaign()"><i class="bi bi-trash"></i> Delete</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card" id="cmp_steps_card" style="display:none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-list-ol me-1"></i> Steps</h6>
                <button class="btn btn-sm btn-primary" onclick="addStep()"><i class="bi bi-plus"></i> Add step</button>
            </div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>#</th><th>Delay</th><th>Template</th><th>Skip if</th><th></th></tr></thead>
                    <tbody id="stepsTable"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Step editor modal-ish row template -->
<div id="stepEditor" class="card mt-3" style="display:none;">
    <div class="card-body">
        <form onsubmit="saveStep(event)">
            <input type="hidden" name="step_id" id="step_id" value="0">
            <input type="hidden" name="campaign_id" id="step_campaign_id" value="0">
            <div class="row g-2">
                <div class="col-md-2">
                    <label class="form-label small">Order</label>
                    <input type="number" class="form-control form-control-sm" name="step_order" id="step_order" min="1" value="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Delay (min)</label>
                    <input type="number" class="form-control form-control-sm" name="delay_minutes" id="step_delay" min="0" value="0">
                </div>
                <div class="col-md-4">
                    <label class="form-label small">Template</label>
                    <select class="form-select form-select-sm" name="template_slug" id="step_template" required>
                        <option value="">— Pick template —</option>
                        <?php foreach ($email_templates as $t) { ?>
                        <option value="<?= h($t['slug']) ?>"><?= h($t['name']) ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small">Skip if event</label>
                    <select class="form-select form-select-sm" name="send_condition_event" id="step_skip">
                        <option value="">—</option>
                        <?php foreach ($trigger_events as $ev) { ?>
                        <option value="<?= h($ev['slug']) ?>"><?= h($ev['slug']) ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check-lg"></i> Save step</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="hideStepEditor()">Cancel</button>
            </div>
        </form>
    </div>
</div>
<?php } /* end campaigns tab */ ?>

<script>
var currentPage = 1;
var totalItems = 0;
var perPage = 50;

// ── Templates UI ──
var templateRows = <?= json_encode(array_column($email_templates, null, 'template_id')) ?>;

function newTemplate() {
    document.getElementById('tpl_id').value = '0';
    document.getElementById('tpl_name').value = '';
    document.getElementById('tpl_slug').value = '';
    document.getElementById('tpl_subject').value = '';
    document.getElementById('tpl_body').value = '';
    document.getElementById('tpl_format').value = 'html';
    document.getElementById('templateEditorTitle').textContent = 'New template';
    document.getElementById('tpl_delete_btn').classList.add('d-none');
}

function loadTemplate(id) {
    apiPost('getEmailTemplate', { template_id: id }, function(json) {
        if (json.error) return;
        var t = json.results.template;
        document.getElementById('tpl_id').value = t.template_id;
        document.getElementById('tpl_name').value = t.name;
        document.getElementById('tpl_slug').value = t.slug;
        document.getElementById('tpl_subject').value = t.subject_tpl;
        document.getElementById('tpl_body').value = t.body_tpl || '';
        document.getElementById('tpl_format').value = t.body_format;
        document.getElementById('templateEditorTitle').textContent = 'Editing: ' + t.name;
        document.getElementById('tpl_delete_btn').classList.remove('d-none');
    });
}

function saveTemplate(e) {
    e.preventDefault();
    var f = document.getElementById('templateForm');
    var data = {
        template_id: f.template_id.value,
        slug: f.slug.value,
        name: f.name.value,
        subject_tpl: f.subject_tpl.value,
        body_tpl: f.body_tpl.value,
        body_format: f.body_format.value
    };
    apiPost('saveEmailTemplate', data, function(json) {
        if (!json.error) location.reload();
    });
}

function deleteCurrentTemplate() {
    var id = document.getElementById('tpl_id').value;
    if (!id || id === '0') return;
    if (!confirm('Delete this template? This cannot be undone.')) return;
    apiPost('deleteEmailTemplate', { template_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function sendTestForCurrent() {
    var slug = document.getElementById('tpl_slug').value;
    if (!slug) { alert('Save the template first.'); return; }
    apiPost('sendTestEmail', { slug: slug }, function(json) {});
}

function insertVar(token) {
    var ta = document.getElementById('tpl_body');
    var start = ta.selectionStart, end = ta.selectionEnd;
    ta.value = ta.value.substring(0, start) + token + ta.value.substring(end);
    ta.focus();
    ta.selectionStart = ta.selectionEnd = start + token.length;
}

// ── Campaigns UI ──
var currentCampaign = null;

function newCampaign() {
    currentCampaign = null;
    document.getElementById('cmp_id').value = '0';
    document.getElementById('cmp_name').value = '';
    document.getElementById('cmp_slug').value = '';
    document.getElementById('cmp_desc').value = '';
    document.getElementById('cmp_trigger').value = '';
    document.getElementById('cmp_active').checked = true;
    document.getElementById('campaignEditorTitle').textContent = 'New campaign';
    document.getElementById('cmp_delete_btn').classList.add('d-none');
    document.getElementById('cmp_steps_card').style.display = 'none';
}

function loadCampaign(id, c) {
    currentCampaign = c;
    document.getElementById('cmp_id').value = c.campaign_id;
    document.getElementById('cmp_name').value = c.name;
    document.getElementById('cmp_slug').value = c.slug;
    document.getElementById('cmp_desc').value = c.description || '';
    document.getElementById('cmp_trigger').value = c.trigger_event || '';
    document.getElementById('cmp_active').checked = !!parseInt(c.is_active, 10);
    document.getElementById('campaignEditorTitle').textContent = 'Editing: ' + c.name;
    document.getElementById('cmp_delete_btn').classList.remove('d-none');
    document.getElementById('cmp_steps_card').style.display = '';
    loadSteps(c.campaign_id);
}

function saveCampaign(e) {
    e.preventDefault();
    var f = document.getElementById('campaignForm');
    var data = {
        campaign_id: f.campaign_id.value,
        slug: f.slug.value,
        name: f.name.value,
        description: f.description.value,
        trigger_event: f.trigger_event.value,
        is_active: f.is_active.checked ? 1 : 0
    };
    apiPost('saveCampaign', data, function(json) {
        if (!json.error) location.reload();
    });
}

function deleteCurrentCampaign() {
    var id = document.getElementById('cmp_id').value;
    if (!id || id === '0') return;
    if (!confirm('Delete this campaign? Pending sends will be cancelled and active enrollments unenrolled.')) return;
    apiPost('deleteCampaign', { campaign_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function loadSteps(cid) {
    apiPost('getCampaignSteps', { campaign_id: cid }, function(json) {
        if (json.error) return;
        var rows = json.results.steps || [];
        var tbody = document.getElementById('stepsTable');
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="text-muted text-center py-3">No steps yet — add one to start the drip.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function(s) {
            html += '<tr>';
            html += '<td>' + s.step_order + '</td>';
            html += '<td>' + s.delay_minutes + ' min</td>';
            html += '<td><code class="small">' + escHtml(s.template_slug) + '</code></td>';
            html += '<td>' + (s.send_condition_event ? '<code class="small">' + escHtml(s.send_condition_event) + '</code>' : '<span class="text-muted">—</span>') + '</td>';
            html += '<td class="text-end">';
            html += '<button class="btn btn-sm btn-outline-secondary me-1" onclick=\'editStep(' + JSON.stringify(s) + ')\'><i class="bi bi-pencil"></i></button>';
            html += '<button class="btn btn-sm btn-outline-danger" onclick="deleteStep(' + s.step_id + ')"><i class="bi bi-trash"></i></button>';
            html += '</td></tr>';
        });
        tbody.innerHTML = html;
    });
}

function addStep() {
    if (!currentCampaign) return;
    document.getElementById('step_id').value = '0';
    document.getElementById('step_campaign_id').value = currentCampaign.campaign_id;
    document.getElementById('step_order').value = (parseInt(currentCampaign.step_count, 10) || 0) + 1;
    document.getElementById('step_delay').value = '0';
    document.getElementById('step_template').value = '';
    document.getElementById('step_skip').value = '';
    document.getElementById('stepEditor').style.display = '';
}

function editStep(s) {
    document.getElementById('step_id').value = s.step_id;
    document.getElementById('step_campaign_id').value = s.campaign_id;
    document.getElementById('step_order').value = s.step_order;
    document.getElementById('step_delay').value = s.delay_minutes;
    document.getElementById('step_template').value = s.template_slug;
    document.getElementById('step_skip').value = s.send_condition_event || '';
    document.getElementById('stepEditor').style.display = '';
}

function saveStep(e) {
    e.preventDefault();
    var data = {
        step_id: document.getElementById('step_id').value,
        campaign_id: document.getElementById('step_campaign_id').value,
        step_order: document.getElementById('step_order').value,
        delay_minutes: document.getElementById('step_delay').value,
        template_slug: document.getElementById('step_template').value,
        send_condition_event: document.getElementById('step_skip').value
    };
    apiPost('saveStep', data, function(json) {
        if (!json.error) {
            hideStepEditor();
            loadSteps(data.campaign_id);
        }
    });
}

function deleteStep(id) {
    if (!confirm('Delete this step?')) return;
    apiPost('deleteStep', { step_id: id }, function(json) {
        if (!json.error && currentCampaign) loadSteps(currentCampaign.campaign_id);
    });
}

function hideStepEditor() {
    document.getElementById('stepEditor').style.display = 'none';
}

function testEmail() {
    apiPost('testEmailSettings', {}, function(json) {});
}

function showAddSender() {
    document.getElementById('addSenderForm').classList.toggle('d-none');
    document.getElementById('newSenderEmail').focus();
}

function addSender(e) {
    e.preventDefault();
    var data = {
        email_address: document.getElementById('newSenderEmail').value,
        display_name: document.getElementById('newSenderName').value,
        is_default: document.getElementById('newSenderDefault').checked ? 1 : 0
    };
    apiPost('addAllowedSender', data, function(json) {
        if (!json.error) location.reload();
    });
}

function deleteSender(id, email) {
    if (!confirm('Remove "' + email + '" from allowed senders?')) return;
    apiPost('deleteAllowedSender', { sender_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function setDefault(id) {
    apiPost('setDefaultSender', { sender_id: id }, function(json) {
        if (!json.error) location.reload();
    });
}

function loadQueue() {
    var filter = document.getElementById('queueFilter').value;
    apiPost('getQueueItems', { status: filter, page: currentPage, per_page: perPage }, function(json) {
        if (json.error) return;
        var items = json.results.items || [];
        totalItems = json.results.total || 0;
        var tbody = document.getElementById('queueTable');

        if (items.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-3">No emails found.</td></tr>';
        } else {
            var html = '';
            items.forEach(function(item) {
                var badge = {queued:'bg-primary',sending:'bg-warning text-dark',sent:'bg-success',failed:'bg-danger'}[item.status] || 'bg-secondary';
                html += '<tr>';
                html += '<td class="small">' + escHtml(item.to_email) + '</td>';
                html += '<td class="small">' + escHtml(item.subject || '').substring(0, 60) + '</td>';
                html += '<td><span class="badge bg-light text-dark">' + escHtml(item.source_app) + '</span></td>';
                html += '<td><span class="badge ' + badge + '">' + escHtml(item.status) + '</span></td>';
                html += '<td class="small">' + item.attempts + '/' + item.max_attempts + '</td>';
                html += '<td class="small">' + escHtml(item.created || '') + '</td>';
                html += '<td class="small">' + escHtml(item.sent_at || '—') + '</td>';
                html += '<td class="text-end">';
                if (item.status === 'failed') {
                    html += '<button class="btn btn-sm btn-outline-warning me-1" onclick="retryEmail(' + item.queue_id + ')"><i class="bi bi-arrow-clockwise"></i></button>';
                }
                if (item.status === 'queued' || item.status === 'failed') {
                    html += '<button class="btn btn-sm btn-outline-danger" onclick="deleteEmail(' + item.queue_id + ')"><i class="bi bi-trash"></i></button>';
                }
                html += '</td>';
                html += '</tr>';
                if (item.status === 'failed' && item.error_message) {
                    html += '<tr><td colspan="8" class="small text-danger py-1 ps-4"><i class="bi bi-exclamation-triangle me-1"></i>' + escHtml(item.error_message) + '</td></tr>';
                }
            });
            tbody.innerHTML = html;
        }

        // Pagination info
        var start = ((currentPage - 1) * perPage) + 1;
        var end = Math.min(currentPage * perPage, totalItems);
        document.getElementById('queueInfo').textContent = totalItems > 0 ? start + '-' + end + ' of ' + totalItems : '';
        document.getElementById('prevPage').disabled = currentPage <= 1;
        document.getElementById('nextPage').disabled = end >= totalItems;
    });
}

function changePage(dir) {
    currentPage += dir;
    if (currentPage < 1) currentPage = 1;
    loadQueue();
}

function retryEmail(id) {
    apiPost('retryFailedEmail', { queue_id: id }, function(json) {
        if (!json.error) loadQueue();
    });
}

function deleteEmail(id) {
    if (!confirm('Delete this queue item?')) return;
    apiPost('deleteQueuedEmail', { queue_id: id }, function(json) {
        if (!json.error) loadQueue();
    });
}

function escHtml(str) {
    var div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// Load queue on page load (only on the settings tab — table only exists there)
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('queueTable')) loadQueue();
});
</script>
