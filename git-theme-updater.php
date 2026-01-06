<?php
/**
 * Plugin Name: FloorSpace Theme Updaters
 * Description: Professional theme updater with automatic backup on every update and improved revert functionality
 * Version: 2.4
 * Author: FloorSpace Dev Team
 */

if (!defined('ABSPATH')) exit;

class FloorSpaceThemeUpdaterPro {
    
    private $github_repo = 'websitetown/floorspace-site';
    private $github_branch = 'dev_v1';
    private $github_token = '';
    private $theme_name = 'floorspace-v2';
    private $plugin_version = '2.5';
    private $cache_duration = 300;
    private $backup_dir = '';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('wp_ajax_update_theme', array($this, 'ajax_update_theme'));
        add_action('wp_ajax_check_version', array($this, 'ajax_check_version'));
        add_action('wp_ajax_get_changelog', array($this, 'ajax_get_changelog'));
        add_action('wp_ajax_refresh_changelog', array($this, 'ajax_refresh_changelog'));
        add_action('wp_ajax_revert_theme', array($this, 'ajax_revert_theme'));
        add_action('wp_ajax_download_backup', array($this, 'ajax_download_backup'));
        add_action('wp_ajax_delete_backup', array($this, 'ajax_delete_backup'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/floorspace-backups';
        
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            file_put_contents($this->backup_dir . '/.htaccess', 'deny from all');
        }
        
        ini_set('memory_limit', '512M');
    }
    
    public function add_admin_menu() {
        add_options_page(
            'FloorSpace Updater',
            'Theme Manager',
            'manage_options',
            'floorspace-updater-pro',
            array($this, 'admin_page')
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if (strpos($hook, 'floorspace') !== false) {
            wp_enqueue_style('floorspace-updater-admin', plugin_dir_url(__FILE__) . 'admin-style.css', array(), $this->plugin_version);
        }
    }
    
    public function admin_page() {
        $current_theme_version = $this->get_current_theme_version();
        $latest_version = $this->get_latest_github_version();
        $component_versions = $this->get_component_versions_from_github();
        $changelog_data = $this->get_changelog_from_github();
        $needs_update = version_compare($current_theme_version, $latest_version, '<');
        $backup_info = $this->get_latest_backup_info();
        ?>
        <div class="wrap floorspace-updater-wrap">
            <h1 class="floorspace-title">
                <span class="dashicons dashicons-update"></span>
                FloorSpace Theme Updater Pro
            </h1>
            
            <div class="floorspace-dashboard">
                <div id="update-status" class="update-status"></div>
                
                <div class="two-column-layout">
                    <div class="left-column">
                        <div class="version-info-box" style="<?php echo $needs_update ? '' : 'display: none;'; ?>">
                            <h3>
                                <span class="dashicons dashicons-info"></span>
                                Latest Version Available
                            </h3>
                            <div class="version-details">
                                <div class="version-row">
                                    <strong>Theme Version:</strong>
                                    <span class="version-number"><?php echo esc_html($latest_version); ?></span>
                                </div>
                                <?php 
                                if (is_array($component_versions) && !empty($component_versions)) {
                                    foreach ($component_versions as $name => $version) {
                                        if (in_array(strtolower($name), array('status', 'note'))) continue;
                                        ?>
                                        <div class="version-row">
                                            <strong><?php echo esc_html($name); ?>:</strong>
                                            <span class="version-number"><?php echo esc_html($version); ?></span>
                                        </div>
                                    <?php }
                                }
                                ?>
                            </div>
                        </div>
                        
                        <div class="update-panel">
                            <div class="panel-header">
                                <h2>
                                    <span class="dashicons dashicons-download"></span>
                                    Theme Update Center
                                </h2>
                                <p>Update theme with automatic backup protection</p>
                            </div>
                            
                            <div class="panel-body">
                                <div class="update-info">
                                    <div class="info-grid">
                                        <div class="info-item">
                                            <strong>Theme:</strong> <?php echo $this->theme_name; ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Current:</strong> <?php echo $current_theme_version; ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Latest:</strong> <?php echo $latest_version; ?>
                                        </div>
                                        <div class="info-item">
                                            <strong>Auto-Backup:</strong> <span style="color: #46b450;">✓ Enabled</span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="action-buttons">
                                    <button id="check-version-btn" class="button button-secondary">
                                        <span class="dashicons dashicons-search"></span>
                                        Check Updates
                                    </button>
                                    
                                    <button id="update-theme-btn" class="button button-primary button-large" style="<?php echo $needs_update ? '' : 'display: none;'; ?>">
                                        <span class="dashicons dashicons-update"></span>
                                        Update Theme Now
                                    </button>
                                    
                                    <div id="up-to-date-message" class="notice notice-success inline" style="<?php echo $needs_update ? 'display: none;' : ''; ?>">
                                        <p>
                                            <span class="dashicons dashicons-yes-alt"></span>
                                            <strong>Theme is up to date!</strong>
                                        </p>
                                    </div>
                                </div>
                                
                                <div id="update-progress" class="update-progress" style="display:none;">
                                    <div class="progress-header">
                                        <h3 id="progress-title">Processing...</h3>
                                        <p id="progress-subtitle">Please wait...</p>
                                    </div>
                                    <div class="progress-container">
                                        <div class="progress-bar">
                                            <div id="progress-fill" class="progress-fill"></div>
                                        </div>
                                        <div id="progress-text" class="progress-text">Preparing...</div>
                                    </div>
                                    
                                    <div class="update-live-box">
                                        <div class="live-box-header">
                                            <span class="dashicons dashicons-info"></span>
                                            <strong>Live Process Status</strong>
                                        </div>
                                        <div class="live-box-content">
                                            <div id="backup-status" class="status-item">
                                                <span class="status-icon dashicons dashicons-backup"></span>
                                                <span class="status-text">Backup: <span id="backup-info">Waiting...</span></span>
                                            </div>
                                            <div id="download-status" class="status-item">
                                                <span class="status-icon dashicons dashicons-download"></span>
                                                <span class="status-text">Download: <span id="download-info">Waiting...</span></span>
                                            </div>
                                            <div id="install-status" class="status-item">
                                                <span class="status-icon dashicons dashicons-update"></span>
                                                <span class="status-text">Install: <span id="install-info">Pending</span></span>
                                            </div>
                                            <div id="verify-status" class="status-item">
                                                <span class="status-icon dashicons dashicons-yes"></span>
                                                <span class="status-text">Verify: <span id="verify-info">Pending</span></span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($backup_info): ?>
                        <div class="backup-info-box">
                            <h3>
                                <span class="dashicons dashicons-backup"></span>
                                Available Backup
                            </h3>
                            <div class="backup-details">
                                <div class="backup-row">
                                    <strong>Backup Version:</strong>
                                    <span class="version-number"><?php echo esc_html($backup_info['version']); ?></span>
                                </div>
                                <div class="backup-row">
                                    <strong>Created:</strong>
                                    <span><?php echo esc_html($backup_info['date']); ?></span>
                                </div>
                                <div class="backup-row">
                                    <strong>Size:</strong>
                                    <span><?php echo esc_html($backup_info['size']); ?></span>
                                </div>
                                <div class="backup-row">
                                    <strong>Current Version:</strong>
                                    <span class="version-number"><?php echo esc_html($current_theme_version); ?></span>
                                </div>
                            </div>
                            <div class="backup-actions">
                                <button id="revert-theme-btn" class="button button-warning">
                                    <span class="dashicons dashicons-undo"></span>
                                    Revert to Backup
                                </button>
                                <button id="download-backup-btn" class="button button-secondary">
                                    <span class="dashicons dashicons-download"></span>
                                    Download Backup
                                </button>
                                <button id="delete-backup-btn" class="button button-danger">
                                    <span class="dashicons dashicons-trash"></span>
                                    Delete Backup
                                </button>
                            </div>
                            <p class="backup-warning">
                                <span class="dashicons dashicons-warning"></span>
                                <em>Reverting will replace current version (<?php echo esc_html($current_theme_version); ?>) with backup (<?php echo esc_html($backup_info['version']); ?>). A safety backup will be created first.</em>
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="backup-info-box">
                            <h3>
                                <span class="dashicons dashicons-backup"></span>
                                Backup Status
                            </h3>
                            <div class="no-backup-message">
                                <p><span class="dashicons dashicons-info"></span> No backup available yet.</p>
                                <p class="description">A backup will be automatically created on first update.</p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="right-column">
                        <div class="changelog-panel">
                            <div class="changelog-header">
                                <h2>
                                    <span class="dashicons dashicons-list-view"></span>
                                    Changelog
                                </h2>
                                <p>Version history from GitHub</p>
                            </div>
                            
                            <div class="changelog-content">
                                <div class="changelog-table">
                                    <div class="changelog-table-header">
                                        <div class="col-version">Version</div>
                                        <div class="col-date">Date</div>
                                    </div>
                                    <div class="changelog-table-body">
                                        <?php echo $this->render_changelog_list($changelog_data); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="changelog-modal" class="changelog-modal" style="display:none;">
            <div class="changelog-modal-content">
                <div class="changelog-modal-header">
                    <h3 id="modal-version-title">Version Details</h3>
                    <button class="changelog-modal-close">&times;</button>
                </div>
                <div class="changelog-modal-body" id="modal-changelog-details">
                    Loading...
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            function resetStatus() {
                $('#backup-info, #download-info, #install-info, #verify-info')
                    .html('Waiting...').removeClass('status-success status-error status-progress');
                $('.status-icon').removeClass('dashicons-yes-alt dashicons-warning spin-icon');
            }
            
            function setProgress(id, msg) {
                $('#' + id).html(msg).addClass('status-progress');
                $('#' + id.replace('-info', '-status') + ' .status-icon').addClass('spin-icon');
            }
            
            function setSuccess(id, msg) {
                $('#' + id).html(msg + ' ✓').removeClass('status-progress').addClass('status-success');
                $('#' + id.replace('-info', '-status') + ' .status-icon').removeClass('spin-icon').addClass('dashicons-yes-alt');
            }
            
            function setError(id, msg) {
                $('#' + id).html(msg + ' ✗').removeClass('status-progress').addClass('status-error');
                $('#' + id.replace('-info', '-status') + ' .status-icon').removeClass('spin-icon').addClass('dashicons-warning');
            }
            
            $('#view-changes-btn').click(function() {
                var firstVersion = $('#changelog-list .changelog-row:first').data('version');
                if (firstVersion && firstVersion !== 'Not Available') {
                    showChangelogModal(firstVersion);
                } else {
                    showChangelogModal('<?php echo $latest_version; ?>');
                }
            });
            
            $(document).on('click', '.changelog-row', function() {
                if ($(this).hasClass('no-data')) {
                    return;
                }
                showChangelogModal($(this).data('version'));
            });
            
            $('.changelog-modal-close, .changelog-modal').click(function(e) {
                if (e.target === this) $('#changelog-modal').fadeOut();
            });
            
            function showChangelogModal(version) {
                $('#modal-version-title').text('Version ' + version + ' Details');
                $('#modal-changelog-details').html('<p>Loading changelog from GitHub...</p>');
                $('#changelog-modal').fadeIn();
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_changelog',
                        version: version,
                        nonce: '<?php echo wp_create_nonce("get_changelog_nonce"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#modal-changelog-details').html(response.data.changelog);
                        } else {
                            $('#modal-changelog-details').html('<p>No changelog available.</p>');
                        }
                    }
                });
            }
            
            // Download Backup
            $('#download-backup-btn').click(function() {
                window.location.href = '<?php echo admin_url('admin-ajax.php'); ?>?action=download_backup&nonce=<?php echo wp_create_nonce("download_backup_nonce"); ?>';
            });
            
            // Delete Backup
            $('#delete-backup-btn').click(function() {
                if (!confirm('Are you sure you want to delete the backup?\n\nThis action cannot be undone.')) return;
                
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin-icon"></span> Deleting...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_backup',
                        nonce: '<?php echo wp_create_nonce("delete_backup_nonce"); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            $('#update-status').html(
                                '<div class="notice notice-success" style="padding:20px;margin:20px 0;border-left:4px solid #46b450;">' +
                                '<h2 style="color:#46b450;margin-top:0;"><span class="dashicons dashicons-yes-alt"></span> Backup Deleted!</h2>' +
                                '<p style="font-size:16px;">Backup file has been successfully deleted.</p>' +
                                '<p style="color:#666;">Refreshing in 3 seconds...</p></div>'
                            );
                            setTimeout(function() { location.reload(); }, 3000);
                        } else {
                            $('#update-status').html('<div class="notice notice-error"><p>' + res.data.message + '</p></div>');
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete Backup');
                        }
                    },
                    error: function() {
                        $('#update-status').html('<div class="notice notice-error"><p>Delete failed. Please try again.</p></div>');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Delete Backup');
                    }
                });
            });
            
            // Revert Theme
            $('#revert-theme-btn').click(function() {
                if (!confirm('Revert to backup version?\n\nA safety backup of current theme will be created first.')) return;
                
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin-icon"></span> Reverting...');
                $('#update-progress').show();
                $('#progress-title').text('Reverting Theme...');
                $('#progress-subtitle').text('Creating safety backup and restoring...');
                $('#update-status').html('');
                resetStatus();
                
                var progress = 0;
                var interval = setInterval(function() {
                    progress += 5;
                    $('#progress-fill').css('width', progress + '%');
                    
                    if (progress === 15) {
                        $('#progress-text').text('Creating safety backup...');
                        setProgress('backup-info', 'Backing up');
                    } else if (progress === 40) {
                        setSuccess('backup-info', 'Backup created');
                        setProgress('download-info', 'Loading backup');
                    } else if (progress === 60) {
                        setSuccess('download-info', 'Loaded');
                        setProgress('install-info', 'Extracting');
                    } else if (progress === 80) {
                        setSuccess('install-info', 'Extracted');
                        setProgress('verify-info', 'Verifying');
                    }
                }, 300);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'revert_theme',
                        nonce: '<?php echo wp_create_nonce("revert_theme_nonce"); ?>'
                    },
                    timeout: 300000,
                    success: function(res) {
                        clearInterval(interval);
                        $('#progress-fill').css('width', '100%');
                        
                        if (res.success) {
                            setSuccess('verify-info', 'Complete');
                            $('#update-status').html(
                                '<div class="notice notice-success" style="padding:20px;margin:20px 0;border-left:4px solid #46b450;">' +
                                '<h2 style="color:#46b450;margin-top:0;"><span class="dashicons dashicons-yes-alt"></span> Revert Successful!</h2>' +
                                '<p style="font-size:16px;">Restored to version ' + res.data.reverted_version + '</p>' +
                                '<p style="color:#666;">Refreshing in 3 seconds...</p></div>'
                            );
                            setTimeout(function() { location.reload(); }, 3000);
                        } else {
                            setError('backup-info', 'Failed');
                            setError('download-info', 'Failed');
                            setError('install-info', 'Failed');
                            setError('verify-info', 'Failed');
                            $('#update-status').html('<div class="notice notice-error"><p>' + res.data.message + '</p></div>');
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> Revert to Backup');
                        }
                    },
                    error: function() {
                        clearInterval(interval);
                        setError('backup-info', 'Failed');
                        setError('download-info', 'Failed');
                        setError('install-info', 'Failed');
                        setError('verify-info', 'Failed');
                        $('#update-status').html('<div class="notice notice-error"><p>Revert failed. Please try again.</p></div>');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-undo"></span> Revert to Backup');
                    }
                });
            });
            
            // Update Theme
            $('#update-theme-btn').click(function() {
                if (!confirm('Update theme to latest version?\n\nAutomatic backup will be created first.')) return;
                
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin-icon"></span> Updating...');
                $('#update-progress').show();
                $('#progress-title').text('Updating Theme...');
                $('#progress-subtitle').text('Creating backup and downloading...');
                $('#update-status').html('');
                resetStatus();
                
                var progress = 0;
                var interval = setInterval(function() {
                    progress += 5;
                    $('#progress-fill').css('width', progress + '%');
                    
                    if (progress === 10) {
                        $('#progress-text').text('Creating backup...');
                        setProgress('backup-info', 'Backing up');
                    } else if (progress === 35) {
                        setSuccess('backup-info', 'Backup created');
                        setProgress('download-info', 'Downloading');
                    } else if (progress === 65) {
                        setSuccess('download-info', 'Downloaded');
                        setProgress('install-info', 'Installing');
                    } else if (progress === 85) {
                        setSuccess('install-info', 'Installed');
                        setProgress('verify-info', 'Verifying');
                    }
                }, 300);
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'update_theme',
                        nonce: '<?php echo wp_create_nonce("update_theme_nonce"); ?>'
                    },
                    timeout: 300000,
                    success: function(res) {
                        clearInterval(interval);
                        $('#progress-fill').css('width', '100%');
                        
                        if (res.success) {
                            setSuccess('verify-info', 'Complete');
                            $('#update-status').html(
                                '<div class="notice notice-success" style="padding:20px;margin:20px 0;border-left:4px solid #46b450;">' +
                                '<h2 style="color:#46b450;margin-top:0;"><span class="dashicons dashicons-yes-alt"></span> Update Successful!</h2>' +
                                '<p style="font-size:16px;">Updated to version ' + res.data.new_version + '</p>' +
                                '<p>✓ Backup created automatically</p>' +
                                '<p style="color:#666;">Refreshing in 3 seconds...</p></div>'
                            );
                            setTimeout(function() { location.reload(); }, 3000);
                        } else {
                            setError('backup-info', 'Failed');
                            setError('download-info', 'Failed');
                            setError('install-info', 'Failed');
                            setError('verify-info', 'Failed');
                            $('#update-status').html('<div class="notice notice-error"><p>' + res.data.message + '</p></div>');
                            btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Update Theme Now');
                        }
                    },
                    error: function() {
                        clearInterval(interval);
                        setError('backup-info', 'Failed');
                        setError('download-info', 'Failed');
                        setError('install-info', 'Failed');
                        setError('verify-info', 'Failed');
                        $('#update-status').html('<div class="notice notice-error"><p>Update failed. Please try again.</p></div>');
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> Update Theme Now');
                    }
                });
            });
            
            $('#check-version-btn').click(function() {
                var btn = $(this);
                btn.prop('disabled', true).html('<span class="dashicons dashicons-update spin-icon"></span> Checking...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'check_version',
                        nonce: '<?php echo wp_create_nonce("check_version_nonce"); ?>'
                    },
                    success: function(res) {
                        if (res.success) {
                            var type = res.data.needs_update ? 'notice-warning' : 'notice-success';
                            $('#update-status').html('<div class="notice ' + type + '"><p>' + res.data.message + '</p></div>');
                        }
                        btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Check Updates');
                    }
                });
            });
        });
        </script>
        
        <style>
        .refresh-btn { 
            float: right; 
            padding: 0 !important; 
            color: #00b894; 
            border: none; 
            background: none; 
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .refresh-btn:hover { 
            color: #00a383; 
            transform: scale(1.1);
        }
        .refresh-btn .dashicons { font-size: 16px; width: 16px; height: 16px; }
        .refresh-btn.spinning .dashicons { animation: spin 1s linear infinite; }
        .spin-icon { animation: spin 1s linear infinite; }
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        #up-to-date-message .dashicons { vertical-align: middle; margin-right: 5px; }
        .update-status { margin-bottom: 20px; }
        .update-status .notice { margin: 0; }
        
        /* Backup Info Box Styles */
        .backup-info-box {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }
        .backup-info-box h3 {
            margin-top: 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f0;
            color: #333;
        }
        .backup-info-box h3 .dashicons {
            color: #0073aa;
            vertical-align: middle;
        }
        .backup-details {
            margin: 15px 0;
        }
        .backup-row {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
        }
        .backup-row:last-child {
            border-bottom: none;
        }
        .backup-actions {
            margin: 15px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .backup-actions .button {
            flex: 1;
            min-width: 120px;
            text-align: center;
        }
        .button-danger {
            background: #dc3232 !important;
            border-color: #a00 !important;
            color: white !important;
            text-shadow: none !important;
            box-shadow: 0 1px 0 #a00 !important;
        }
        .button-danger:hover {
            background: #a00 !important;
            border-color: #800 !important;
        }
        .button-danger:active {
            background: #800 !important;
        }
        .backup-warning {
            margin: 15px 0 10px 0;
            padding: 10px;
            background: #fff3cd;
            border-left: 3px solid #ffb900;
            border-radius: 3px;
            font-size: 12px;
        }
        .backup-warning .dashicons {
            vertical-align: middle;
            margin-right: 5px;
        }
        .button-warning {
            background: #ff9800 !important;
            border-color: #e68900 !important;
            color: white !important;
            text-shadow: none !important;
            box-shadow: 0 1px 0 #e68900 !important;
        }
        .button-warning:hover {
            background: #e68900 !important;
            border-color: #cc7a00 !important;
        }
        .button-warning:active {
            background: #cc7a00 !important;
        }
        .button-warning .dashicons {
            vertical-align: middle;
        }
        
        /* Inline Notice Improvements */
        .inline-notice {
            padding: 12px 15px !important;
            margin: 15px 0 !important;
            border-left: 4px solid #ddd;
            background: #fff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .inline-notice p {
            margin: 0 !important;
            display: flex;
            align-items: center;
            font-size: 14px;
        }
        .inline-notice .dashicons {
            margin-right: 8px;
            font-size: 18px;
            width: 18px;
            height: 18px;
        }
        .notice-info.inline-notice {
            border-left-color: #0073aa;
        }
        .notice-info.inline-notice .dashicons {
            color: #0073aa;
        }
        .notice-success.inline-notice {
            border-left-color: #46b450;
        }
        .notice-success.inline-notice .dashicons {
            color: #46b450;
        }
        .notice-warning.inline-notice {
            border-left-color: #ffb900;
        }
        .notice-warning.inline-notice .dashicons {
            color: #ffb900;
        }
        .notice-error.inline-notice {
            border-left-color: #dc3232;
        }
        .notice-error.inline-notice .dashicons {
            color: #dc3232;
        }
        
        /* Changelog Styles */
        .changelog-row.no-data {
            cursor: default !important;
            background: #f9f9f9;
        }
        .changelog-row.no-data:hover {
            background: #f9f9f9 !important;
        }
        .changelog-row.highlight-row {
            background: #fff3cd;
            border-left: 3px solid #ffb900;
        }
        .security-badge {
            display: inline-block;
            padding: 2px 8px;
            background: #dc3232;
            color: white;
            font-size: 11px;
            border-radius: 3px;
            margin-left: 8px;
            font-weight: 600;
        }
        
        /* Component Versions Container */
        #component-versions-container .version-row {
            padding: 8px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        #component-versions-container .version-row:last-child {
            border-bottom: none;
        }
        
        /* Live Update Status Styles */
        .update-live-box { 
            margin-top: 20px; 
            background: #f8f9fa; 
            border: 1px solid #ddd; 
            border-radius: 5px; 
            padding: 15px; 
        }
        .live-box-header { 
            margin-bottom: 15px; 
            font-size: 14px; 
            color: #333; 
        }
        .live-box-header .dashicons { 
            color: #0073aa; 
            vertical-align: middle; 
        }
        .status-item { 
            padding: 10px; 
            margin-bottom: 8px; 
            background: white; 
            border-left: 3px solid #ddd; 
            border-radius: 3px; 
            transition: all 0.3s ease;
        }
        .status-item .status-icon { 
            margin-right: 8px; 
            color: #666; 
            vertical-align: middle;
        }
        .status-text { 
            font-size: 13px; 
        }
        .status-progress { 
            color: #0073aa; 
            font-weight: 600; 
        }
        .status-progress ~ .status-item { 
            border-left-color: #0073aa; 
        }
        #backup-status:has(.status-progress) { 
            border-left-color: #0073aa; 
        }
        #download-status:has(.status-progress) { 
            border-left-color: #0073aa; 
        }
        #install-status:has(.status-progress) { 
            border-left-color: #0073aa; 
        }
        #version-status:has(.status-progress) { 
            border-left-color: #0073aa; 
        }
        .status-success { 
            color: #46b450; 
            font-weight: 600; 
        }
        #backup-status:has(.status-success) { 
            border-left-color: #46b450; 
        }
        #download-status:has(.status-success) { 
            border-left-color: #46b450; 
        }
        #install-status:has(.status-success) { 
            border-left-color: #46b450; 
        }
        #version-status:has(.status-success) { 
            border-left-color: #46b450; 
        }
        .status-error { 
            color: #dc3232; 
            font-weight: 600; 
        }
        #backup-status:has(.status-error) { 
            border-left-color: #dc3232; 
        }
        #download-status:has(.status-error) { 
            border-left-color: #dc3232; 
        }
        #install-status:has(.status-error) { 
            border-left-color: #dc3232; 
        }
        #version-status:has(.status-error) { 
            border-left-color: #dc3232; 
        }
        .status-item:has(.status-progress) .status-icon { 
            color: #0073aa; 
        }
        .status-item:has(.status-success) .status-icon { 
            color: #46b450; 
        }
        .status-item:has(.status-error) .status-icon { 
            color: #dc3232; 
        }
        </style>
        <?php
    }
    
    public function ajax_check_version() {
        if (!wp_verify_nonce($_POST['nonce'], 'check_version_nonce')) wp_die('Security check');
        
        $current = $this->get_current_theme_version();
        $latest = $this->get_latest_github_version();
        
        wp_send_json_success(array(
            'message' => ($current === $latest) ? 'Up to date: ' . $current : 'Update available: ' . $current . ' → ' . $latest,
            'current_version' => $current,
            'latest_version' => $latest,
            'needs_update' => version_compare($current, $latest, '<')
        ));
    }
    
    public function ajax_refresh_changelog() {
        if (!wp_verify_nonce($_POST['nonce'], 'refresh_changelog_nonce')) wp_die('Security check failed');
        delete_transient('floorspace_changelog_data');
        $changelog_data = $this->get_changelog_from_github();
        wp_send_json_success(array('html' => $this->render_changelog_list($changelog_data)));
    }
    
    public function ajax_get_changelog() {
        if (!wp_verify_nonce($_POST['nonce'], 'get_changelog_nonce')) wp_die('Security check');
        $version = sanitize_text_field($_POST['version']);
        $changelog = $this->get_version_changelog_from_github($version);
        wp_send_json_success(array('changelog' => $changelog ?: '<p>No changelog available</p>'));
    }
    
    public function ajax_revert_theme() {
        if (!wp_verify_nonce($_POST['nonce'], 'revert_theme_nonce')) wp_die('Security check');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Insufficient permissions'));
        
        set_time_limit(300);
        
        $result = $this->revert_to_backup();
        
        if ($result['success']) {
            delete_transient('floorspace_github_version');
            wp_send_json_success(array(
                'message' => 'Theme reverted successfully',
                'reverted_version' => $this->get_current_theme_version()
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    public function ajax_download_backup() {
        if (!wp_verify_nonce($_GET['nonce'], 'download_backup_nonce')) wp_die('Security check');
        if (!current_user_can('manage_options')) wp_die('Insufficient permissions');
        
        $backup_file = $this->backup_dir . '/theme-backup.zip';
        
        if (!file_exists($backup_file)) {
            wp_die('Backup file not found');
        }
        
        $backup_info = $this->get_latest_backup_info();
        $filename = 'floorspace-theme-backup-' . $backup_info['version'] . '.zip';
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($backup_file));
        header('Pragma: no-cache');
        header('Expires: 0');
        
        readfile($backup_file);
        exit;
    }
    
    public function ajax_delete_backup() {
        if (!wp_verify_nonce($_POST['nonce'], 'delete_backup_nonce')) wp_die('Security check');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Insufficient permissions'));
        
        $backup_file = $this->backup_dir . '/theme-backup.zip';
        $backup_meta = $this->backup_dir . '/backup-meta.json';
        
        if (file_exists($backup_file)) {
            if (@unlink($backup_file)) {
                if (file_exists($backup_meta)) {
                    @unlink($backup_meta);
                }
                wp_send_json_success(array('message' => 'Backup deleted successfully'));
            } else {
                wp_send_json_error(array('message' => 'Failed to delete backup file'));
            }
        } else {
            wp_send_json_error(array('message' => 'Backup file not found'));
        }
    }
    
    public function ajax_update_theme() {
        if (!wp_verify_nonce($_POST['nonce'], 'update_theme_nonce')) wp_die('Security check');
        if (!current_user_can('manage_options')) wp_send_json_error(array('message' => 'Insufficient permissions'));
        
        set_time_limit(300);
        
        $result = $this->download_and_update_theme();
        
        if ($result['success']) {
            delete_transient('floorspace_github_version');
            wp_send_json_success(array(
                'message' => 'Theme updated successfully',
                'new_version' => $this->get_current_theme_version()
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    private function get_latest_github_version() {
        $cached = get_transient('floorspace_github_version');
        if ($cached) return $cached;
        
        $url = "https://raw.githubusercontent.com/{$this->github_repo}/{$this->github_branch}/wp-content/themes/{$this->theme_name}/style.css";
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'token ' . $this->github_token)
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            if (preg_match('/Version:\s*([^\r\n]+)/i', $body, $matches)) {
                $version = trim($matches[1]);
                set_transient('floorspace_github_version', $version, $this->cache_duration);
                return $version;
            }
        }
        return '1.0.0';
    }
    
    private function get_changelog_from_github() {
        $cache_key = 'floorspace_changelog_data';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;
        
        $changelog_data = $this->parse_changelog_md();
        set_transient($cache_key, $changelog_data, $this->cache_duration);
        return $changelog_data;
    }
    
    private function parse_changelog_md() {
        $url = "https://raw.githubusercontent.com/{$this->github_repo}/{$this->github_branch}/CHANGELOG.md";
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'sslverify' => false,
            'headers' => array(
                'Authorization' => 'token ' . $this->github_token,
                'User-Agent' => 'FloorSpace-Updater/2.3',
                'Cache-Control' => 'no-cache'
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('FloorSpace Updater: Failed to fetch CHANGELOG.md - ' . $response->get_error_message());
            return $this->get_fallback_changelog();
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('FloorSpace Updater: HTTP ' . $code . ' - CHANGELOG.md not found in repository root');
            return $this->get_fallback_changelog();
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            error_log('FloorSpace Updater: Empty CHANGELOG.md file');
            return $this->get_fallback_changelog();
        }
        
        $changelog_data = array();
        
        preg_match_all('/##\s*\[?(\d+\.\d+(?:\.\d+)?)\]?\s*[-–]\s*(\d{4}[-\/]\d{2}[-\/]\d{2})/i', $body, $matches, PREG_SET_ORDER);
        
        if (empty($matches)) {
            error_log('FloorSpace Updater: No version entries found in CHANGELOG.md. Expected format: ## [1.0.0] - 2025-01-01');
            return $this->get_fallback_changelog();
        }
        
        foreach ($matches as $match) {
            $version = $match[1];
            $date_string = str_replace('/', '-', $match[2]);
            $date = date('F j, Y', strtotime($date_string));
            $is_security = false;
            $badge = '';
            
            $pattern = '/##\s*\[?' . preg_quote($version, '/') . '\]?\s*[-–]\s*\d{4}[-\/]\d{2}[-\/]\d{2}(.*?)(?=##\s*\[?\d+\.\d+|\z)/is';
            if (preg_match($pattern, $body, $section_match)) {
                $section = $section_match[1];
                if (stripos($section, 'security') !== false || stripos($section, 'critical') !== false) {
                    $is_security = true;
                    $badge = 'Security';
                }
            }
            
            $changelog_data[] = array(
                'version' => $version,
                'date' => $date,
                'highlight' => $is_security,
                'badge' => $badge
            );
        }
        
        error_log('FloorSpace Updater: Successfully parsed ' . count($changelog_data) . ' versions from CHANGELOG.md');
        return $changelog_data;
    }

    private function get_component_versions_from_github() {
        $url = "https://raw.githubusercontent.com/{$this->github_repo}/{$this->github_branch}/wp-content/themes/{$this->theme_name}/versions.json";
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array('Authorization' => 'token ' . $this->github_token)
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data)) {
                return array_filter($data, function($k) {
                    return !in_array(strtolower($k), array('status', 'note'));
                }, ARRAY_FILTER_USE_KEY);
            }
        }
        return array();
    }
    
    private function get_version_changelog_from_github($version) {
        $url = "https://raw.githubusercontent.com/{$this->github_repo}/{$this->github_branch}/CHANGELOG.md";
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'token ' . $this->github_token,
                'User-Agent' => 'FloorSpace-Updater/2.3'
            )
        ));
        
        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $body = wp_remote_retrieve_body($response);
            if (!empty($body)) {
                $pattern = '/##\s*\[?' . preg_quote($version, '/') . '\]?\s*-\s*\d{4}-\d{2}-\d{2}(.*?)(?=##\s*\[?\d+\.\d+|\z)/is';
                if (preg_match($pattern, $body, $matches)) {
                    return $this->format_changelog_html(trim($matches[1]));
                }
            }
        }
        
        $versions_data = $this->get_component_versions_from_github();
        if (!empty($versions_data)) {
            $html = '<h4>Component Versions</h4><ul>';
            foreach ($versions_data as $component => $comp_version) {
                $html .= '<li><strong>' . esc_html($component) . ':</strong> ' . esc_html($comp_version) . '</li>';
            }
            $html .= '</ul>';
            $html .= '<p><em>This is the latest version information from the repository. Detailed changelog will be available after the next update.</em></p>';
            return $html;
        }
        
        return '<p>No detailed changelog available for this version.</p>';
    }
    
    private function format_changelog_html($markdown) {
        $html = $markdown;
        $html = preg_replace('/^###\s+(.+)$/m', '<h4>$1</h4>', $html);
        $lines = explode("\n", $html);
        $in_list = false;
        $result = array();
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^[-*]\s+(.+)$/', $line, $match)) {
                if (!$in_list) {
                    $result[] = '<ul>';
                    $in_list = true;
                }
                $result[] = '<li>' . $match[1] . '</li>';
            } else {
                if ($in_list && !empty($line)) {
                    $result[] = '</ul>';
                    $in_list = false;
                }
                if (!empty($line)) $result[] = $line;
            }
        }
        if ($in_list) $result[] = '</ul>';
        
        $html = implode("\n", $result);
        $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
        return nl2br($html);
    }
    
    private function get_fallback_changelog() {
        return array(
            array(
                'version' => 'Not Available',
                'date' => 'Add CHANGELOG.md to repository root',
                'highlight' => false,
                'badge' => ''
            )
        );
    }
    
    private function get_current_theme_version() {
        $path = get_theme_root() . '/' . $this->theme_name . '/style.css';
        if (file_exists($path)) {
            $data = get_file_data($path, array('Version' => 'Version'));
            return $data['Version'] ?: '1.0.0';
        }
        return 'Not installed';
    }
    
    private function render_changelog_list($changelog_data) {
        if (!is_array($changelog_data) || empty($changelog_data)) {
            return '<div class="changelog-row no-data">
                        <div class="col-version" style="color: #999; font-style: italic;">
                            No changelog data available
                        </div>
                        <div class="col-date" style="color: #999;">-</div>
                    </div>';
        }
        
        if (count($changelog_data) === 1 && $changelog_data[0]['version'] === 'Not Available') {
            return '<div class="changelog-row no-data">
                        <div class="col-version" style="color: #dc3232;">
                            <span class="dashicons dashicons-warning" style="font-size: 16px; vertical-align: middle;"></span>
                            CHANGELOG.md not found
                        </div>
                        <div class="col-date" style="color: #999; font-size: 12px;">
                            Add to repository root
                        </div>
                    </div>';
        }
        
        $html = '';
        foreach ($changelog_data as $item) {
            $badge = !empty($item['badge']) ? '<span class="security-badge">' . esc_html($item['badge']) . '</span>' : '';
            $highlight_class = $item['highlight'] ? ' highlight-row' : '';
            
            $html .= '<div class="changelog-row' . $highlight_class . '" data-version="' . esc_attr($item['version']) . '">';
            $html .= '<div class="col-version">' . esc_html($item['version']) . ' ' . $badge . '</div>';
            $html .= '<div class="col-date">' . esc_html($item['date']) . '</div>';
            $html .= '</div>';
        }
        return $html;
    }
    
    private function get_latest_backup_info() {
        $backup_file = $this->backup_dir . '/theme-backup.zip';
        $backup_meta = $this->backup_dir . '/backup-meta.json';
        
        if (!file_exists($backup_file) || !file_exists($backup_meta)) {
            return false;
        }
        
        $meta = json_decode(file_get_contents($backup_meta), true);
        if (!$meta) return false;
        
        return array(
            'version' => $meta['version'],
            'date' => date('F j, Y \a\t g:i A', $meta['timestamp']),
            'size' => $this->format_file_size(filesize($backup_file)),
            'timestamp' => $meta['timestamp']
        );
    }
    
    private function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' bytes';
    }
    
    private function create_backup() {
        try {
            $theme_path = get_theme_root() . '/' . $this->theme_name;
            
            if (!file_exists($theme_path)) {
                return array('success' => false, 'message' => 'Theme directory not found');
            }
            
            $current_version = $this->get_current_theme_version();
            $backup_file = $this->backup_dir . '/theme-backup.zip';
            
            if (file_exists($backup_file)) {
                @unlink($backup_file);
            }
            
            if (!class_exists('ZipArchive')) {
                return array('success' => false, 'message' => 'ZipArchive not available');
            }
            
            $zip = new ZipArchive;
            if ($zip->open($backup_file, ZipArchive::CREATE) !== TRUE) {
                return array('success' => false, 'message' => 'Cannot create backup ZIP');
            }
            
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($theme_path),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $file_path = $file->getRealPath();
                    $relative_path = substr($file_path, strlen($theme_path) + 1);
                    $zip->addFile($file_path, $relative_path);
                }
            }
            
            $zip->close();
            
            $meta = array(
                'version' => $current_version,
                'timestamp' => time(),
                'date' => date('Y-m-d H:i:s')
            );
            file_put_contents($this->backup_dir . '/backup-meta.json', json_encode($meta));
            
            return array('success' => true, 'message' => 'Backup created');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Backup failed: ' . $e->getMessage());
        }
    }
    
    private function revert_to_backup() {
        try {
            $backup_file = $this->backup_dir . '/theme-backup.zip';
            
            if (!file_exists($backup_file)) {
                return array('success' => false, 'message' => 'No backup file found');
            }
            
            $safety_backup = $this->create_safety_backup();
            if (!$safety_backup['success']) {
                error_log('FloorSpace: Warning - Safety backup failed before revert');
            }
            
            $theme_path = get_theme_root() . '/' . $this->theme_name;
            
            if (!class_exists('ZipArchive')) {
                return array('success' => false, 'message' => 'ZipArchive not available');
            }
            
            $zip = new ZipArchive;
            if ($zip->open($backup_file) !== TRUE) {
                return array('success' => false, 'message' => 'Cannot open backup ZIP');
            }
            
            if (file_exists($theme_path)) {
                $this->recursive_remove_directory($theme_path);
            }
            
            wp_mkdir_p($theme_path);
            
            $zip->extractTo($theme_path);
            $zip->close();
            
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            
            return array('success' => true, 'message' => 'Theme reverted successfully');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Revert failed: ' . $e->getMessage());
        }
    }
    
    private function create_safety_backup() {
        try {
            $theme_path = get_theme_root() . '/' . $this->theme_name;
            $current_version = $this->get_current_theme_version();
            $safety_file = $this->backup_dir . '/safety-backup-' . $current_version . '-' . time() . '.zip';
            
            if (!class_exists('ZipArchive')) {
                return array('success' => false, 'message' => 'ZipArchive not available');
            }
            
            $zip = new ZipArchive;
            if ($zip->open($safety_file, ZipArchive::CREATE) !== TRUE) {
                return array('success' => false, 'message' => 'Cannot create safety backup');
            }
            
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($theme_path),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $file_path = $file->getRealPath();
                    $relative_path = substr($file_path, strlen($theme_path) + 1);
                    $zip->addFile($file_path, $relative_path);
                }
            }
            
            $zip->close();
            
            return array('success' => true, 'message' => 'Safety backup created');
            
        } catch (Exception $e) {
            return array('success' => false, 'message' => 'Safety backup failed: ' . $e->getMessage());
        }
    }
    
    private function recursive_remove_directory($directory) {
        if (!file_exists($directory)) return true;
        if (!is_dir($directory)) return unlink($directory);
        
        foreach (scandir($directory) as $item) {
            if ($item == '.' || $item == '..') continue;
            
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            
            if (is_dir($path)) {
                $this->recursive_remove_directory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($directory);
    }
    
    private function download_and_update_theme() {
        set_time_limit(300);
        @ini_set('memory_limit', '512M');
        
        $backup_result = $this->create_backup();
        if (!$backup_result['success']) {
            error_log('FloorSpace: Warning - Backup failed: ' . $backup_result['message']);
        }
        
        try {
            $zip_url = "https://api.github.com/repos/{$this->github_repo}/zipball/{$this->github_branch}";
            $temp_file = tempnam(sys_get_temp_dir(), 'fs_theme_');
            
            if (!$temp_file) {
                return array('success' => false, 'message' => 'Cannot create temporary file');
            }
            
            $response = wp_remote_get($zip_url, array(
                'timeout' => 300,
                'stream' => true,
                'filename' => $temp_file,
                'headers' => array(
                    'Authorization' => 'token ' . $this->github_token,
                    'User-Agent' => 'FloorSpace-Updater/2.4'
                )
            ));
            
            if (is_wp_error($response)) {
                unlink($temp_file);
                return array('success' => false, 'message' => 'Download failed: ' . $response->get_error_message());
            }
            
            if (wp_remote_retrieve_response_code($response) !== 200) {
                unlink($temp_file);
                return array('success' => false, 'message' => 'Download failed with code: ' . wp_remote_retrieve_response_code($response));
            }
            
            if (!file_exists($temp_file) || filesize($temp_file) === 0) {
                return array('success' => false, 'message' => 'Downloaded file is empty');
            }
            
            if (!class_exists('ZipArchive')) {
                unlink($temp_file);
                return array('success' => false, 'message' => 'ZipArchive not available');
            }
            
            $zip = new ZipArchive;
            if ($zip->open($temp_file) !== TRUE) {
                unlink($temp_file);
                return array('success' => false, 'message' => 'Cannot open ZIP file');
            }
            
            $theme_path_in_zip = '';
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, '/wp-content/themes/' . $this->theme_name . '/') !== false) {
                    $parts = explode('/wp-content/themes/' . $this->theme_name . '/', $filename);
                    $theme_path_in_zip = $parts[0] . '/wp-content/themes/' . $this->theme_name . '/';
                    break;
                }
            }
            
            if (empty($theme_path_in_zip)) {
                $zip->close();
                unlink($temp_file);
                return array('success' => false, 'message' => 'Theme folder not found in ZIP');
            }
            
            $theme_dest = get_theme_root() . '/' . $this->theme_name;
            if (!file_exists($theme_dest)) {
                wp_mkdir_p($theme_dest);
            }
            
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                if (strpos($filename, $theme_path_in_zip) === 0) {
                    $relative_path = substr($filename, strlen($theme_path_in_zip));
                    if (empty($relative_path)) continue;
                    
                    $dest_path = $theme_dest . '/' . $relative_path;
                    
                    if (substr($filename, -1) === '/') {
                        if (!file_exists($dest_path)) {
                            wp_mkdir_p($dest_path);
                        }
                    } else {
                        $dest_dir = dirname($dest_path);
                        if (!file_exists($dest_dir)) {
                            wp_mkdir_p($dest_dir);
                        }
                        
                        $file_stream = $zip->getStream($filename);
                        if ($file_stream) {
                            $dest_stream = fopen($dest_path, 'w');
                            if ($dest_stream) {
                                stream_copy_to_stream($file_stream, $dest_stream);
                                fclose($dest_stream);
                            }
                            fclose($file_stream);
                        }
                    }
                }
                
                if ($i % 50 === 0 && function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
            }
            
            $zip->close();
            unlink($temp_file);
            
            if (function_exists('opcache_reset')) {
                @opcache_reset();
            }
            
            return array('success' => true, 'message' => 'Theme updated successfully');
            
        } catch (Exception $e) {
            if (isset($temp_file) && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            return array('success' => false, 'message' => 'Update failed: ' . $e->getMessage());
        }
    }
}

new FloorSpaceThemeUpdaterPro();

add_filter('plugin_action_links_' . plugin_basename(__FILE__), function($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=floorspace-updater-pro') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});

add_action('admin_notices', function() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->id, 'floorspace') === false) {
        $last_check = get_option('floorspace_last_update_check', 0);
        if (time() - $last_check > 86400) {
            echo '<div class="notice notice-info is-dismissible">
                    <p><strong>floorspace Theme Updater:</strong> 
                    <a href="' . admin_url('admin.php?page=floorspace-updater-pro') . '">Check for updates</a></p>
                  </div>';
        }
    }
});
?>
