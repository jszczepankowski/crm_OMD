<?php
/**
 * Plugin Name: CRM OMD Time Manager
 * Description: Rejestracja czasu pracy pracowników dla klientów i projektów, akceptacja wpisów, raporty miesięczne i eksport CSV.
 * Version: 0.3.0
 * Author: OMD
 * Text Domain: crm-omd-time-manager
 */

if (!defined('ABSPATH')) {
    exit;
}

class CRM_OMD_Time_Manager
{
    private string $tbl_clients;
    private string $tbl_projects;
    private string $tbl_services;
    private string $tbl_entries;

    public function __construct()
    {
        global $wpdb;
        $this->tbl_clients = $wpdb->prefix . 'crm_omd_clients';
        $this->tbl_projects = $wpdb->prefix . 'crm_omd_projects';
        $this->tbl_services = $wpdb->prefix . 'crm_omd_services';
        $this->tbl_entries = $wpdb->prefix . 'crm_omd_entries';

        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);

        add_action('admin_menu', [$this, 'register_admin_menu']);

        add_action('admin_post_crm_omd_save_client', [$this, 'handle_save_client']);
        add_action('admin_post_crm_omd_delete_client', [$this, 'handle_delete_client']);
        add_action('admin_post_crm_omd_save_project', [$this, 'handle_save_project']);
        add_action('admin_post_crm_omd_delete_project', [$this, 'handle_delete_project']);
        add_action('admin_post_crm_omd_save_service', [$this, 'handle_save_service']);
        add_action('admin_post_crm_omd_delete_service', [$this, 'handle_delete_service']);

        add_action('admin_post_crm_omd_review_entry', [$this, 'handle_review_entry']);
        add_action('admin_post_crm_omd_save_entry_admin', [$this, 'handle_save_entry_admin']);
        add_action('admin_post_crm_omd_delete_entry', [$this, 'handle_delete_entry']);
        add_action('admin_post_crm_omd_duplicate_fixed_entry', [$this, 'handle_duplicate_fixed_entry']);

        add_action('admin_post_crm_omd_export_report', [$this, 'handle_export_report']);
        add_action('admin_post_crm_omd_save_worker_settings', [$this, 'handle_save_worker_settings']);
        add_action('admin_post_crm_omd_save_reminder_settings', [$this, 'handle_save_reminder_settings']);
        add_action('admin_post_crm_omd_update_worker', [$this, 'handle_update_worker']);
        add_action('admin_post_crm_omd_delete_worker', [$this, 'handle_delete_worker']);

        add_shortcode('crm_omd_time_tracker', [$this, 'render_tracker_shortcode']);
        add_shortcode('crm_omd_employee_login', [$this, 'render_employee_login_shortcode']);
        add_shortcode('crm_omd_employee_monthly_view', [$this, 'render_employee_monthly_view_shortcode']);
        add_shortcode('crm_omd_employee_portal', [$this, 'render_employee_portal_shortcode']);
        add_action('admin_post_crm_omd_submit_entry', [$this, 'handle_submit_entry']);

        add_action('crm_omd_daily_reminder', [$this, 'send_daily_reminders']);
        add_action('wp_login', [$this, 'track_user_login'], 10, 2);
        add_filter('login_redirect', [$this, 'filter_login_redirect'], 10, 3);
    }

    public function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->tbl_clients} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            nip VARCHAR(20) NULL,
            contact_name VARCHAR(191) NULL,
            contact_email VARCHAR(191) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tbl_projects} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            description TEXT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY client_id (client_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tbl_services} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            client_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(191) NOT NULL,
            billing_type VARCHAR(20) NOT NULL DEFAULT 'hourly',
            hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            fixed_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY client_id (client_id)
        ) $charset;");

        dbDelta("CREATE TABLE {$this->tbl_entries} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            client_id BIGINT UNSIGNED NOT NULL,
            project_id BIGINT UNSIGNED NOT NULL,
            service_id BIGINT UNSIGNED NOT NULL,
            work_date DATE NOT NULL,
            hours DECIMAL(7,2) NOT NULL DEFAULT 0,
            description TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            calculated_value DECIMAL(10,2) NOT NULL DEFAULT 0,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY user_id (user_id),
            KEY client_id (client_id),
            KEY project_id (project_id),
            KEY service_id (service_id),
            KEY status (status),
            KEY work_date (work_date)
        ) $charset;");

        if (!wp_next_scheduled('crm_omd_daily_reminder')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'crm_omd_daily_reminder');
        }

        add_option('crm_omd_reminder_mode', 'interval');
        add_option('crm_omd_reminder_interval_days', 5);
        add_option('crm_omd_reminder_day_of_month', 5);
    }

    public function deactivate(): void
    {
        $timestamp = wp_next_scheduled('crm_omd_daily_reminder');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'crm_omd_daily_reminder');
        }
    }

    private function require_admin_access(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Brak uprawnień.', 'crm-omd-time-manager'));
        }
    }

    private function recalculate_entry_value(int $client_id, int $service_id, float $hours): ?float
    {
        global $wpdb;
        $service = $wpdb->get_row($wpdb->prepare("SELECT billing_type, hourly_rate, fixed_value FROM {$this->tbl_services} WHERE id = %d AND client_id = %d", $service_id, $client_id));
        if (!$service) {
            return null;
        }

        return $service->billing_type === 'fixed' ? (float) $service->fixed_value : $hours * (float) $service->hourly_rate;
    }

    public function track_user_login(string $user_login, WP_User $user): void
    {
        update_user_meta($user->ID, 'crm_omd_last_login', current_time('mysql'));
    }

    public function filter_login_redirect(string $redirect_to, string $requested_redirect_to, $user): string
    {
        if (!($user instanceof WP_User)) {
            return $redirect_to;
        }

        if (user_can($user, 'manage_options')) {
            return $redirect_to;
        }

        if (!empty($requested_redirect_to)) {
            return $requested_redirect_to;
        }

        return home_url('/panel-pracownika/');
    }

    public function render_employee_login_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (is_user_logged_in()) {
            return '<p>Jesteś już zalogowany.</p>';
        }

        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'logo_url' => '',
            'title' => 'Panel logowania pracownika',
            'redirect_to' => home_url('/panel-pracownika/'),
        ], $atts, 'crm_omd_employee_login');

        $redirect_to = !empty($atts['redirect_to']) ? esc_url_raw((string) $atts['redirect_to']) : home_url('/panel-pracownika/');
        $args = [
            'echo' => false,
            'redirect' => $redirect_to,
            'remember' => true,
            'label_username' => 'Login lub e-mail',
            'label_password' => 'Hasło',
            'label_log_in' => 'Zaloguj',
            'form_id' => 'crm-omd-employee-login-form',
        ];

        ob_start();
        echo '<div class="crm-omd-login-panel" style="max-width:460px;margin:24px auto;padding:24px;border:1px solid #ddd;border-radius:8px;background:#fff;">';
        if (!empty($atts['logo_url'])) {
            echo '<div style="text-align:center;margin-bottom:16px;"><img src="' . esc_url($atts['logo_url']) . '" alt="Logo" style="max-height:80px;width:auto;"></div>';
        } else {
            echo '<div style="text-align:center;margin-bottom:16px;padding:16px;border:1px dashed #ccc;color:#666;">Miejsce na branding / logo</div>';
        }
        echo '<h3 style="margin-top:0;text-align:center;">' . esc_html($atts['title']) . '</h3>';
        echo wp_login_form($args);
        echo '</div>';
        return (string) ob_get_clean();
    }

    public function register_admin_menu(): void
    {
        add_menu_page('CRM OMD Time', 'CRM OMD Time', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page'], 'dashicons-clock', 28);
        add_submenu_page('crm-omd-time', 'Wpisy godzinowe', 'Wpisy godzinowe', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page']);
        add_submenu_page('crm-omd-time', 'Klienci', 'Klienci', 'manage_options', 'crm-omd-clients', [$this, 'render_clients_page']);
        add_submenu_page('crm-omd-time', 'Projekty', 'Projekty', 'manage_options', 'crm-omd-projects', [$this, 'render_projects_page']);
        add_submenu_page('crm-omd-time', 'Usługi', 'Usługi', 'manage_options', 'crm-omd-services', [$this, 'render_services_page']);
        add_submenu_page('crm-omd-time', 'Pracownicy', 'Pracownicy', 'manage_options', 'crm-omd-workers', [$this, 'render_workers_page']);
        add_submenu_page('crm-omd-time', 'Raporty', 'Raporty', 'manage_options', 'crm-omd-reports', [$this, 'render_reports_page']);
    }

    private function get_working_days_in_month(int $year, int $month): int
    {
        $days_in_month = (int) cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $working_days = 0;

        for ($day = 1; $day <= $days_in_month; $day++) {
            $timestamp = strtotime(sprintf('%04d-%02d-%02d', $year, $month, $day));
            $weekday = (int) date('N', $timestamp);
            if ($weekday <= 5) {
                $working_days++;
            }
        }

        return $working_days;
    }

    private function get_month_boundaries(string $month): array
    {
        $month = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : date('Y-m');
        $date_from = $month . '-01';
        $date_to = date('Y-m-t', strtotime($date_from));

        return [$date_from, $date_to];
    }

    private function get_user_reported_hours_for_range(int $user_id, string $date_from, string $date_to): float
    {
        global $wpdb;
        $sum = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(hours), 0) FROM {$this->tbl_entries} WHERE user_id = %d AND work_date BETWEEN %s AND %s",
                $user_id,
                $date_from,
                $date_to
            )
        );

        return (float) $sum;
    }

    public function render_employee_monthly_view_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p>Musisz być zalogowany.</p>';
        }

        $allow = get_user_meta(get_current_user_id(), 'crm_omd_worker_enabled', true);
        if ($allow === '0') {
            return '<p>Twoje konto jest wyłączone z raportowania czasu pracy.</p>';
        }

        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'month' => date('Y-m'),
        ], $atts, 'crm_omd_employee_monthly_view');

        $selected_month = isset($_GET['crm_omd_month']) ? sanitize_text_field(wp_unslash($_GET['crm_omd_month'])) : (string) $atts['month'];
        $month = preg_match('/^\d{4}-\d{2}$/', $selected_month) ? $selected_month : date('Y-m');
        $year = (int) substr($month, 0, 4);
        $month_num = (int) substr($month, 5, 2);
        [$date_from, $date_to] = $this->get_month_boundaries($month);

        global $wpdb;
        $user_id = get_current_user_id();
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, e.hours, e.status, e.description
                FROM {$this->tbl_entries} e
                INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                WHERE e.user_id = %d AND e.work_date BETWEEN %s AND %s
                ORDER BY e.work_date DESC, e.id DESC",
                $user_id,
                $date_from,
                $date_to
            )
        );

        $reported_hours = $this->get_user_reported_hours_for_range($user_id, $date_from, $date_to);
        $working_days = $this->get_working_days_in_month($year, $month_num);
        $expected_hours = $working_days * 8;

        ob_start();
        echo '<div class="crm-omd-employee-monthly-view">';
        echo '<h3>Moje godziny - ' . esc_html($month) . '</h3>';

        echo '<form method="get" style="margin:10px 0 15px;">';
        foreach ($_GET as $key => $value) {
            if ($key === 'crm_omd_month') {
                continue;
            }
            if (is_scalar($value)) {
                echo '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">';
            }
        }
        echo '<label>Miesiąc: <input type="month" name="crm_omd_month" value="' . esc_attr($month) . '"></label> ';
        echo '<button type="submit">Pokaż</button>';
        echo '</form>';

        echo '<table class="widefat striped" style="margin-bottom:12px;max-width:680px;">';
        echo '<thead><tr><th>Metryka</th><th>Wartość</th></tr></thead><tbody>';
        echo '<tr><td>Zaraportowane godziny</td><td>' . esc_html(number_format($reported_hours, 2, ',', ' ')) . '</td></tr>';
        echo '<tr><td>Godziny do przepracowania (8h x dni robocze)</td><td>' . esc_html((string) $expected_hours) . '</td></tr>';
        echo '<tr><td>Różnica</td><td>' . esc_html(number_format($reported_hours - (float) $expected_hours, 2, ',', ' ')) . '</td></tr>';
        echo '</tbody></table>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>Data</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Godziny</th><th>Status</th><th>Opis</th></tr></thead><tbody>';
        if (empty($rows)) {
            echo '<tr><td colspan="7">Brak wpisów dla tego miesiąca.</td></tr>';
        } else {
            foreach ($rows as $row) {
                echo '<tr>';
                echo '<td>' . esc_html($row->work_date) . '</td>';
                echo '<td>' . esc_html($row->client_name) . '</td>';
                echo '<td>' . esc_html($row->project_name) . '</td>';
                echo '<td>' . esc_html($row->service_name) . '</td>';
                echo '<td>' . esc_html(number_format((float) $row->hours, 2, ',', ' ')) . '</td>';
                echo '<td>' . esc_html($row->status) . '</td>';
                echo '<td>' . esc_html($row->description) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div>';

        return (string) ob_get_clean();
    }

    public function render_employee_portal_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p>Musisz być zalogowany.</p>';
        }

        $allow = get_user_meta(get_current_user_id(), 'crm_omd_worker_enabled', true);
        if ($allow === '0') {
            return '<p>Twoje konto jest wyłączone z raportowania czasu pracy.</p>';
        }

        if (!is_array($atts)) {
            $atts = [];
        }

        $atts = shortcode_atts([
            'month' => date('Y-m'),
            'show_tracker' => '1',
        ], $atts, 'crm_omd_employee_portal');

        $month = preg_match('/^\d{4}-\d{2}$/', (string) $atts['month']) ? (string) $atts['month'] : date('Y-m');

        ob_start();
        echo '<div class="crm-omd-employee-portal">';
        echo '<h2>Panel pracownika</h2>';
        echo '<p>Twoje statystyki i raportowanie godzin w jednym miejscu.</p>';

        $monthly_view_html = $this->render_employee_monthly_view_shortcode(['month' => $month]);
        echo $monthly_view_html;

        if ((string) $atts['show_tracker'] === '1') {
            echo '<hr style="margin:20px 0;">';
            echo '<h3>Raportowanie godzin</h3>';
            $tracker_html = $this->render_tracker_shortcode();
            echo $tracker_html;
        }

        if (trim((string) $monthly_view_html) === '' && (!isset($tracker_html) || trim((string) $tracker_html) === '')) {
            echo '<p>Brak danych do wyświetlenia w panelu pracownika.</p>';
        }

        echo '</div>';
        return (string) ob_get_clean();
    }

    public function render_tracker_shortcode($atts = [], $content = null, string $shortcode_tag = ''): string
    {
        if (!is_user_logged_in()) {
            return '<p>Musisz być zalogowany.</p>';
        }

        $allow = get_user_meta(get_current_user_id(), 'crm_omd_worker_enabled', true);
        if ($allow === '0') {
            return '<p>Twoje konto jest wyłączone z raportowania czasu pracy.</p>';
        }

        global $wpdb;
        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects = $wpdb->get_results("SELECT id, client_id, name FROM {$this->tbl_projects} WHERE is_active = 1 ORDER BY name ASC");
        $services = $wpdb->get_results("SELECT id, client_id, name, billing_type FROM {$this->tbl_services} WHERE is_active = 1 ORDER BY name ASC");

        ob_start();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('crm_omd_submit_entry'); ?>
            <input type="hidden" name="action" value="crm_omd_submit_entry">
            <p><label>Data pracy<br><input type="date" name="work_date" value="<?php echo esc_attr(date('Y-m-d')); ?>" required></label></p>
            <p><label>Klient<br>
                    <select name="client_id" required>
                        <option value="">Wybierz klienta</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo (int) $client->id; ?>"><?php echo esc_html($client->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
            <p><label>Projekt<br>
                    <select name="project_id">
                        <option value="">Wybierz projekt</option>
                        <?php foreach ($projects as $project): ?>
                            <option value="<?php echo (int) $project->id; ?>" data-client="<?php echo (int) $project->client_id; ?>"><?php echo esc_html($project->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
            <p><label>Lub nowy projekt<br><input type="text" name="new_project" maxlength="191"></label></p>
            <p><label>Usługa<br>
                    <select name="service_id" required>
                        <option value="">Wybierz usługę</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?php echo (int) $service->id; ?>" data-client="<?php echo (int) $service->client_id; ?>">
                                <?php echo esc_html($service->name . ' (' . $service->billing_type . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label></p>
            <p><label>Liczba godzin<br><input type="number" name="hours" min="0" step="0.25" value="1" required></label></p>
            <p><label>Opis prac<br><textarea name="description" rows="4" required></textarea></label></p>
            <p><button type="submit">Zapisz wpis</button></p>
        </form>
        <script>
            (function() {
                const client = document.querySelector('select[name="client_id"]');
                const project = document.querySelector('select[name="project_id"]');
                const service = document.querySelector('select[name="service_id"]');
                if (!client || !project || !service) return;
                const filterByClient = function(selectEl) {
                    const selectedClient = client.value;
                    Array.from(selectEl.options).forEach((opt, index) => {
                        if (index === 0 || !opt.dataset.client) return;
                        opt.hidden = selectedClient && opt.dataset.client !== selectedClient;
                    });
                    selectEl.value = '';
                };
                client.addEventListener('change', function() {
                    filterByClient(project);
                    filterByClient(service);
                });
            })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    public function handle_submit_entry(): void
    {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('Musisz być zalogowany.', 'crm-omd-time-manager'));
        }
        check_admin_referer('crm_omd_submit_entry');

        global $wpdb;
        $user_id = get_current_user_id();
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $new_project = isset($_POST['new_project']) ? sanitize_text_field(wp_unslash($_POST['new_project'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';

        if (!$client_id || !$service_id || !$work_date || !$description || $hours < 0) {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        if (!$project_id && $new_project !== '') {
            $wpdb->insert(
                $this->tbl_projects,
                ['client_id' => $client_id, 'name' => $new_project, 'description' => '', 'is_active' => 1, 'created_at' => current_time('mysql')],
                ['%d', '%s', '%s', '%d', '%s']
            );
            $project_id = (int) $wpdb->insert_id;
        }

        if (!$project_id) {
            wp_die(esc_html__('Wybierz lub dodaj projekt.', 'crm-omd-time-manager'));
        }

        $value = $this->recalculate_entry_value($client_id, $service_id, $hours);
        if ($value === null) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        $wpdb->insert(
            $this->tbl_entries,
            [
                'user_id' => $user_id,
                'client_id' => $client_id,
                'project_id' => $project_id,
                'service_id' => $service_id,
                'work_date' => $work_date,
                'hours' => $hours,
                'description' => $description,
                'status' => 'pending',
                'calculated_value' => $value,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%s']
        );

        wp_safe_redirect(wp_get_referer() ?: home_url('/'));
        exit;
    }

    public function render_entries_page(): void
    {
        $this->require_admin_access();
        global $wpdb;

        $order_by = isset($_GET['order_by']) ? sanitize_text_field(wp_unslash($_GET['order_by'])) : 'work_date';
        $order_dir = isset($_GET['order_dir']) ? strtoupper(sanitize_text_field(wp_unslash($_GET['order_dir']))) : 'DESC';
        $status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;

        $allowed_order_by = [
            'work_date' => 'e.work_date',
            'status' => 'e.status',
            'worker' => 'u.display_name',
            'client' => 'c.name',
            'created_at' => 'e.created_at',
        ];
        $sql_order_by = $allowed_order_by[$order_by] ?? 'e.work_date';
        $sql_order_dir = $order_dir === 'ASC' ? 'ASC' : 'DESC';

        $where = 'WHERE 1=1';
        $params = [];
        if ($status !== '') {
            $where .= ' AND e.status = %s';
            $params[] = $status;
        }
        if ($user_id > 0) {
            $where .= ' AND e.user_id = %d';
            $params[] = $user_id;
        }
        if ($client_id > 0) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }

        $sql = "SELECT e.id, e.user_id, e.client_id, e.project_id, e.service_id, e.work_date, e.hours, e.description, e.status, e.calculated_value, c.name AS client_name, p.name AS project_name, s.name AS service_name, s.billing_type, u.display_name
            FROM {$this->tbl_entries} e
            INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
            INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
            INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
            INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
            {$where}
            ORDER BY {$sql_order_by} {$sql_order_dir}, e.id DESC
            LIMIT 300";
        $rows = empty($params) ? $wpdb->get_results($sql) : $wpdb->get_results($wpdb->prepare($sql, ...$params));

        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);
        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects = $wpdb->get_results("SELECT id, name FROM {$this->tbl_projects} WHERE is_active = 1 ORDER BY name ASC");
        $services = $wpdb->get_results("SELECT id, name FROM {$this->tbl_services} WHERE is_active = 1 ORDER BY name ASC");

        $edit_entry_id = isset($_GET['edit_entry']) ? (int) $_GET['edit_entry'] : 0;
        $edit_entry = null;
        if ($edit_entry_id > 0) {
            $edit_entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_entries} WHERE id = %d", $edit_entry_id));
        }

        echo '<div class="wrap"><h1>Wpisy godzinowe</h1>';

        $create_defaults = (object) [
            'id' => 0,
            'user_id' => get_current_user_id(),
            'client_id' => 0,
            'project_id' => 0,
            'service_id' => 0,
            'work_date' => date('Y-m-d'),
            'hours' => 1,
            'status' => 'approved',
            'description' => '',
        ];

        if (!$edit_entry) {
            echo '<h2>Dodaj wpis jako administrator</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:15px;">';
            wp_nonce_field('crm_omd_save_entry_admin_0');
            echo '<input type="hidden" name="action" value="crm_omd_save_entry_admin">';
            echo '<input type="hidden" name="id" value="0">';

            echo '<p><label>Pracownik<br><select name="user_id" required>';
            foreach ($users as $user) {
                echo '<option value="' . (int) $user->ID . '"' . selected((int) $create_defaults->user_id, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Klient<br><select name="client_id" required>';
            echo '<option value="">Wybierz klienta</option>';
            foreach ($clients as $client) {
                echo '<option value="' . (int) $client->id . '">' . esc_html($client->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Projekt<br><select name="project_id" required>';
            echo '<option value="">Wybierz projekt</option>';
            foreach ($projects as $project) {
                echo '<option value="' . (int) $project->id . '">' . esc_html($project->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Usługa<br><select name="service_id" required>';
            echo '<option value="">Wybierz usługę</option>';
            foreach ($services as $service) {
                echo '<option value="' . (int) $service->id . '">' . esc_html($service->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Data pracy<br><input type="date" name="work_date" value="' . esc_attr($create_defaults->work_date) . '" required></label></p>';
            echo '<p><label>Godziny<br><input type="number" name="hours" min="0" step="0.25" value="' . esc_attr((string) $create_defaults->hours) . '" required></label></p>';
            echo '<p><label>Status<br><select name="status"><option value="pending">pending</option><option value="approved" selected>approved</option><option value="rejected">rejected</option></select></label></p>';
            echo '<p><label>Opis<br><textarea name="description" rows="4" required></textarea></label></p>';
            echo '<p><button class="button button-primary" type="submit">Dodaj wpis</button></p>';
            echo '</form>';
        }

        if ($edit_entry) {
            echo '<h2>Edycja wpisu #' . (int) $edit_entry->id . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;padding:12px;border:1px solid #ddd;margin-bottom:15px;">';
            wp_nonce_field('crm_omd_save_entry_admin_' . (int) $edit_entry->id);
            echo '<input type="hidden" name="action" value="crm_omd_save_entry_admin">';
            echo '<input type="hidden" name="id" value="' . (int) $edit_entry->id . '">';

            echo '<p><label>Pracownik<br><select name="user_id" required>';
            foreach ($users as $user) {
                echo '<option value="' . (int) $user->ID . '"' . selected((int) $edit_entry->user_id, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Klient<br><select name="client_id" required>';
            foreach ($clients as $client) {
                echo '<option value="' . (int) $client->id . '"' . selected((int) $edit_entry->client_id, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Projekt<br><select name="project_id" required>';
            foreach ($projects as $project) {
                echo '<option value="' . (int) $project->id . '"' . selected((int) $edit_entry->project_id, (int) $project->id, false) . '>' . esc_html($project->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Usługa<br><select name="service_id" required>';
            foreach ($services as $service) {
                echo '<option value="' . (int) $service->id . '"' . selected((int) $edit_entry->service_id, (int) $service->id, false) . '>' . esc_html($service->name) . '</option>';
            }
            echo '</select></label></p>';

            echo '<p><label>Data pracy<br><input type="date" name="work_date" value="' . esc_attr($edit_entry->work_date) . '" required></label></p>';
            echo '<p><label>Godziny<br><input type="number" name="hours" min="0" step="0.25" value="' . esc_attr((string) $edit_entry->hours) . '" required></label></p>';
            echo '<p><label>Status<br><select name="status"><option value="pending"' . selected($edit_entry->status, 'pending', false) . '>pending</option><option value="approved"' . selected($edit_entry->status, 'approved', false) . '>approved</option><option value="rejected"' . selected($edit_entry->status, 'rejected', false) . '>rejected</option></select></label></p>';
            echo '<p><label>Opis<br><textarea name="description" rows="4" required>' . esc_textarea($edit_entry->description) . '</textarea></label></p>';
            echo '<p><button class="button button-primary" type="submit">Zapisz zmiany</button> <a class="button" href="' . esc_url(admin_url('admin.php?page=crm-omd-time')) . '">Anuluj</a></p>';
            echo '</form>';
        }

        echo '<form method="get" style="margin-bottom:12px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="crm-omd-time">';
        echo '<label>Status <select name="status"><option value="">Wszystkie</option><option value="pending"' . selected($status, 'pending', false) . '>pending</option><option value="approved"' . selected($status, 'approved', false) . '>approved</option><option value="rejected"' . selected($status, 'rejected', false) . '>rejected</option></select></label>';

        echo '<label>Pracownik <select name="user_id"><option value="0">Wszyscy</option>';
        foreach ($users as $user) {
            echo '<option value="' . (int) $user->ID . '"' . selected($user_id, (int) $user->ID, false) . '>' . esc_html($user->display_name) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Klient <select name="client_id"><option value="0">Wszyscy</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($client_id, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select></label>';

        echo '<label>Sortuj wg <select name="order_by">';
        echo '<option value="work_date"' . selected($order_by, 'work_date', false) . '>Data pracy</option>';
        echo '<option value="status"' . selected($order_by, 'status', false) . '>Status</option>';
        echo '<option value="worker"' . selected($order_by, 'worker', false) . '>Pracownik</option>';
        echo '<option value="client"' . selected($order_by, 'client', false) . '>Klient</option>';
        echo '<option value="created_at"' . selected($order_by, 'created_at', false) . '>Data dodania</option>';
        echo '</select></label>';

        echo '<label>Kierunek <select name="order_dir"><option value="DESC"' . selected($order_dir, 'DESC', false) . '>Malejąco</option><option value="ASC"' . selected($order_dir, 'ASC', false) . '>Rosnąco</option></select></label>';
        echo '<button class="button button-primary" type="submit">Filtruj</button>';
        echo '</form>';

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Data</th><th>Pracownik</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Godziny</th><th>Wartość</th><th>Status</th><th>Opis</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . (int) $row->id . '</td>';
            echo '<td>' . esc_html($row->work_date) . '</td>';
            echo '<td>' . esc_html($row->display_name) . '</td>';
            echo '<td>' . esc_html($row->client_name) . '</td>';
            echo '<td>' . esc_html($row->project_name) . '</td>';
            echo '<td>' . esc_html($row->service_name) . '</td>';
            echo '<td>' . esc_html((string) $row->hours) . '</td>';
            echo '<td>' . esc_html(number_format((float) $row->calculated_value, 2, ',', ' ')) . ' PLN</td>';
            echo '<td>' . esc_html($row->status) . '</td>';
            echo '<td>' . esc_html($row->description) . '</td>';
            echo '<td>';
            if ($row->status === 'pending') {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_review_entry&id=' . (int) $row->id . '&decision=approved'), 'crm_omd_review_entry_' . (int) $row->id)) . '">Akceptuj</a> ';
                echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_review_entry&id=' . (int) $row->id . '&decision=rejected'), 'crm_omd_review_entry_' . (int) $row->id)) . '">Odrzuć</a> ';
            }
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-time', 'edit_entry' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            if ($row->billing_type === 'fixed') {
                echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_duplicate_fixed_entry&id=' . (int) $row->id), 'crm_omd_duplicate_fixed_entry_' . (int) $row->id)) . '">Duplikuj ryczałt</a> ';
            }
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_entry&id=' . (int) $row->id), 'crm_omd_delete_entry_' . (int) $row->id)) . '" onclick="return confirm(\'Na pewno usunąć wpis?\');">Usuń</a>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_review_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        $decision = isset($_GET['decision']) ? sanitize_text_field(wp_unslash($_GET['decision'])) : '';
        check_admin_referer('crm_omd_review_entry_' . $id);

        if (!$id || !in_array($decision, ['approved', 'rejected'], true)) {
            wp_die(esc_html__('Niepoprawne dane.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $wpdb->update(
            $this->tbl_entries,
            ['status' => $decision, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time('mysql')],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_save_entry_admin(): void
    {
        $this->require_admin_access();
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        check_admin_referer('crm_omd_save_entry_admin_' . $id);

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        $work_date = isset($_POST['work_date']) ? sanitize_text_field(wp_unslash($_POST['work_date'])) : '';
        $hours = isset($_POST['hours']) ? (float) $_POST['hours'] : 0;
        $status = isset($_POST['status']) ? sanitize_text_field(wp_unslash($_POST['status'])) : 'pending';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

        if (!$user_id || !$client_id || !$project_id || !$service_id || !$work_date || !in_array($status, ['pending', 'approved', 'rejected'], true)) {
            wp_die(esc_html__('Niepoprawne dane formularza.', 'crm-omd-time-manager'));
        }

        $value = $this->recalculate_entry_value($client_id, $service_id, $hours);
        if ($value === null) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $data = [
            'user_id' => $user_id,
            'client_id' => $client_id,
            'project_id' => $project_id,
            'service_id' => $service_id,
            'work_date' => $work_date,
            'hours' => $hours,
            'description' => $description,
            'status' => $status,
            'calculated_value' => $value,
            'reviewed_by' => $status === 'pending' ? null : get_current_user_id(),
            'reviewed_at' => $status === 'pending' ? null : current_time('mysql'),
        ];

        if ($id > 0) {
            $wpdb->update(
                $this->tbl_entries,
                $data,
                ['id' => $id],
                ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%d', '%s'],
                ['%d']
            );
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert(
                $this->tbl_entries,
                $data,
                ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%d', '%s', '%s']
            );
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_duplicate_fixed_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_duplicate_fixed_entry_' . $id);

        if ($id <= 0) {
            wp_die(esc_html__('Niepoprawny wpis.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $entry = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_entries} WHERE id = %d", $id));
        if (!$entry) {
            wp_die(esc_html__('Nie znaleziono wpisu.', 'crm-omd-time-manager'));
        }

        $service = $wpdb->get_row($wpdb->prepare("SELECT billing_type FROM {$this->tbl_services} WHERE id = %d", (int) $entry->service_id));
        if (!$service || $service->billing_type !== 'fixed') {
            wp_die(esc_html__('Duplikowanie dostępne tylko dla wpisów ryczałtowych.', 'crm-omd-time-manager'));
        }

        $wpdb->insert(
            $this->tbl_entries,
            [
                'user_id' => (int) $entry->user_id,
                'client_id' => (int) $entry->client_id,
                'project_id' => (int) $entry->project_id,
                'service_id' => (int) $entry->service_id,
                'work_date' => current_time('Y-m-d'),
                'hours' => (float) $entry->hours,
                'description' => (string) $entry->description,
                'status' => 'pending',
                'calculated_value' => (float) $entry->calculated_value,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%d', '%d', '%d', '%s', '%f', '%s', '%s', '%f', '%s']
        );

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function handle_delete_entry(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_entry_' . $id);

        global $wpdb;
        $wpdb->delete($this->tbl_entries, ['id' => $id], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function render_clients_page(): void
    {
        $this->require_admin_access();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, name, nip, contact_name, contact_email, is_active FROM {$this->tbl_clients} ORDER BY name ASC");
        $edit_id = isset($_GET['edit_client']) ? (int) $_GET['edit_client'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_clients} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap"><h1>Klienci</h1>';
        echo '<h2>' . ($edit ? 'Edytuj klienta' : 'Dodaj klienta') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_client');
        echo '<input type="hidden" name="action" value="crm_omd_save_client">';
        echo '<input type="hidden" name="id" value="' . ($edit ? (int) $edit->id : 0) . '">';
        echo '<input type="text" name="name" placeholder="Nazwa klienta" value="' . ($edit ? esc_attr($edit->name) : '') . '" required> ';
        echo '<input type="text" name="nip" placeholder="NIP" value="' . ($edit ? esc_attr((string) $edit->nip) : '') . '"> ';
        echo '<input type="text" name="contact_name" placeholder="Osoba kontaktowa" value="' . ($edit ? esc_attr((string) $edit->contact_name) : '') . '"> ';
        echo '<input type="email" name="contact_email" placeholder="Email kontaktowy" value="' . ($edit ? esc_attr((string) $edit->contact_email) : '') . '"> ';
        echo '<label><input type="checkbox" name="is_active" value="1" ' . checked($edit ? (int) $edit->is_active : 1, 1, false) . '> Aktywny</label> ';
        echo '<button class="button button-primary" type="submit">' . ($edit ? 'Zapisz zmiany' : 'Zapisz') . '</button> ';
        if ($edit) {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=crm-omd-clients')) . '">Anuluj</a>';
        }
        echo '</form>';

        echo '<h2>Lista klientów</h2><table class="widefat striped"><thead><tr><th>Nazwa</th><th>NIP</th><th>Osoba kontaktowa</th><th>Email</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->name) . '</td><td>' . esc_html((string) $row->nip) . '</td><td>' . esc_html((string) $row->contact_name) . '</td><td>' . esc_html((string) $row->contact_email) . '</td><td>' . ((int) $row->is_active ? 'Aktywny' : 'Nieaktywny') . '</td><td>';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-clients', 'edit_client' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_client&id=' . (int) $row->id), 'crm_omd_delete_client_' . (int) $row->id)) . '">Dezaktywuj</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_client(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_client');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $nip = isset($_POST['nip']) ? sanitize_text_field(wp_unslash($_POST['nip'])) : '';
        $contact_name = isset($_POST['contact_name']) ? sanitize_text_field(wp_unslash($_POST['contact_name'])) : '';
        $contact_email = isset($_POST['contact_email']) ? sanitize_email(wp_unslash($_POST['contact_email'])) : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') {
            wp_die(esc_html__('Nazwa jest wymagana.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        if ($id > 0) {
            $wpdb->update($this->tbl_clients, ['name' => $name, 'nip' => $nip, 'contact_name' => $contact_name, 'contact_email' => $contact_email, 'is_active' => $is_active], ['id' => $id], ['%s', '%s', '%s', '%s', '%d'], ['%d']);
        } else {
            $wpdb->insert($this->tbl_clients, ['name' => $name, 'nip' => $nip, 'contact_name' => $contact_name, 'contact_email' => $contact_email, 'is_active' => 1, 'created_at' => current_time('mysql')], ['%s', '%s', '%s', '%s', '%d', '%s']);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-clients'));
        exit;
    }

    public function handle_delete_client(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_client_' . $id);

        global $wpdb;
        $wpdb->update($this->tbl_clients, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-clients'));
        exit;
    }

    public function render_projects_page(): void
    {
        $this->require_admin_access();
        global $wpdb;
        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $rows = $wpdb->get_results("SELECT p.id, p.client_id, p.name, p.is_active, c.name AS client_name FROM {$this->tbl_projects} p INNER JOIN {$this->tbl_clients} c ON c.id = p.client_id ORDER BY p.id DESC");
        $edit_id = isset($_GET['edit_project']) ? (int) $_GET['edit_project'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_projects} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap"><h1>Projekty</h1>';
        echo '<h2>' . ($edit ? 'Edytuj projekt' : 'Dodaj projekt') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_project');
        echo '<input type="hidden" name="action" value="crm_omd_save_project">';
        echo '<input type="hidden" name="id" value="' . ($edit ? (int) $edit->id : 0) . '">';
        echo '<select name="client_id" required><option value="">Wybierz klienta</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($edit ? (int) $edit->client_id : 0, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select> <input type="text" name="name" placeholder="Nazwa projektu" value="' . ($edit ? esc_attr($edit->name) : '') . '" required> ';
        echo '<label><input type="checkbox" name="is_active" value="1" ' . checked($edit ? (int) $edit->is_active : 1, 1, false) . '> Aktywny</label> ';
        echo '<button class="button button-primary" type="submit">' . ($edit ? 'Zapisz zmiany' : 'Zapisz') . '</button> ';
        if ($edit) {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=crm-omd-projects')) . '">Anuluj</a>';
        }
        echo '</form>';

        echo '<h2>Lista projektów</h2><table class="widefat striped"><thead><tr><th>Klient</th><th>Projekt</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->client_name) . '</td><td>' . esc_html($row->name) . '</td><td>' . ((int) $row->is_active ? 'Aktywny' : 'Nieaktywny') . '</td><td>';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-projects', 'edit_project' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_project&id=' . (int) $row->id), 'crm_omd_delete_project_' . (int) $row->id)) . '">Dezaktywuj</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_project(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_project');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$client_id || $name === '') {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        if ($id > 0) {
            $wpdb->update($this->tbl_projects, ['client_id' => $client_id, 'name' => $name, 'is_active' => $is_active], ['id' => $id], ['%d', '%s', '%d'], ['%d']);
        } else {
            $wpdb->insert($this->tbl_projects, ['client_id' => $client_id, 'name' => $name, 'description' => '', 'is_active' => 1, 'created_at' => current_time('mysql')], ['%d', '%s', '%s', '%d', '%s']);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-projects'));
        exit;
    }

    public function handle_delete_project(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_project_' . $id);
        global $wpdb;
        $wpdb->update($this->tbl_projects, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);
        wp_safe_redirect(admin_url('admin.php?page=crm-omd-projects'));
        exit;
    }

    public function render_services_page(): void
    {
        $this->require_admin_access();
        global $wpdb;
        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $rows = $wpdb->get_results("SELECT s.id, s.client_id, s.name, s.billing_type, s.hourly_rate, s.fixed_value, s.is_active, c.name AS client_name
            FROM {$this->tbl_services} s
            INNER JOIN {$this->tbl_clients} c ON c.id = s.client_id
            ORDER BY s.id DESC");
        $edit_id = isset($_GET['edit_service']) ? (int) $_GET['edit_service'] : 0;
        $edit = $edit_id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->tbl_services} WHERE id = %d", $edit_id)) : null;

        echo '<div class="wrap"><h1>Usługi i stawki</h1>';
        echo '<h2>' . ($edit ? 'Edytuj usługę' : 'Dodaj usługę') . '</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_service');
        echo '<input type="hidden" name="action" value="crm_omd_save_service">';
        echo '<input type="hidden" name="id" value="' . ($edit ? (int) $edit->id : 0) . '">';
        echo '<select name="client_id" required><option value="">Wybierz klienta</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($edit ? (int) $edit->client_id : 0, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select> <input type="text" name="name" placeholder="Nazwa usługi" value="' . ($edit ? esc_attr($edit->name) : '') . '" required> ';
        echo '<select name="billing_type"><option value="hourly"' . selected($edit ? $edit->billing_type : 'hourly', 'hourly', false) . '>Godzinowa</option><option value="fixed"' . selected($edit ? $edit->billing_type : 'hourly', 'fixed', false) . '>Ryczałt</option></select> ';
        echo '<input type="number" name="hourly_rate" step="0.01" min="0" placeholder="Stawka godzinowa" value="' . ($edit ? esc_attr((string) $edit->hourly_rate) : '') . '"> ';
        echo '<input type="number" name="fixed_value" step="0.01" min="0" placeholder="Wartość ryczałtu" value="' . ($edit ? esc_attr((string) $edit->fixed_value) : '') . '"> ';
        echo '<label><input type="checkbox" name="is_active" value="1" ' . checked($edit ? (int) $edit->is_active : 1, 1, false) . '> Aktywna</label> ';
        echo '<button class="button button-primary" type="submit">' . ($edit ? 'Zapisz zmiany' : 'Zapisz') . '</button> ';
        if ($edit) {
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=crm-omd-services')) . '">Anuluj</a>';
        }
        echo '</form>';

        echo '<h2>Lista usług</h2><table class="widefat striped"><thead><tr><th>Klient</th><th>Usługa</th><th>Typ</th><th>Stawka h</th><th>Ryczałt</th><th>Status</th><th>Akcje</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->client_name) . '</td><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->billing_type) . '</td><td>' . esc_html(number_format((float) $row->hourly_rate, 2, ',', ' ')) . '</td><td>' . esc_html(number_format((float) $row->fixed_value, 2, ',', ' ')) . '</td><td>' . ((int) $row->is_active ? 'Aktywna' : 'Nieaktywna') . '</td><td>';
            echo '<a class="button" href="' . esc_url(add_query_arg(['page' => 'crm-omd-services', 'edit_service' => (int) $row->id], admin_url('admin.php'))) . '">Edytuj</a> ';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_service&id=' . (int) $row->id), 'crm_omd_delete_service_' . (int) $row->id)) . '">Dezaktywuj</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_service(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_service');

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $billing_type = isset($_POST['billing_type']) ? sanitize_text_field(wp_unslash($_POST['billing_type'])) : 'hourly';
        $hourly_rate = isset($_POST['hourly_rate']) ? (float) $_POST['hourly_rate'] : 0;
        $fixed_value = isset($_POST['fixed_value']) ? (float) $_POST['fixed_value'] : 0;
        $is_active = isset($_POST['is_active']) ? 1 : 0;

        if (!$client_id || $name === '' || !in_array($billing_type, ['hourly', 'fixed'], true)) {
            wp_die(esc_html__('Wypełnij poprawnie formularz.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $data = [
            'client_id' => $client_id,
            'name' => $name,
            'billing_type' => $billing_type,
            'hourly_rate' => $hourly_rate,
            'fixed_value' => $fixed_value,
            'is_active' => $is_active,
        ];
        if ($id > 0) {
            $wpdb->update($this->tbl_services, $data, ['id' => $id], ['%d', '%s', '%s', '%f', '%f', '%d'], ['%d']);
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($this->tbl_services, $data, ['%d', '%s', '%s', '%f', '%f', '%d', '%s']);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-services'));
        exit;
    }

    public function handle_delete_service(): void
    {
        $this->require_admin_access();
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        check_admin_referer('crm_omd_delete_service_' . $id);
        global $wpdb;
        $wpdb->update($this->tbl_services, ['is_active' => 0], ['id' => $id], ['%d'], ['%d']);
        wp_safe_redirect(admin_url('admin.php?page=crm-omd-services'));
        exit;
    }

    public function render_workers_page(): void
    {
        $this->require_admin_access();
        $users = get_users(['orderby' => 'display_name', 'order' => 'ASC']);

        $edit_worker_id = isset($_GET['edit_worker']) ? (int) $_GET['edit_worker'] : 0;
        $edit_worker = $edit_worker_id > 0 ? get_user_by('id', $edit_worker_id) : false;

        echo '<div class="wrap"><h1>Pracownicy</h1>';
        echo '<p>Zarządzanie dostępem użytkowników do rejestracji godzin i przypomnień mailowych.</p>';

        $reminder_mode = (string) get_option('crm_omd_reminder_mode', 'interval');
        $reminder_interval_days = max(1, (int) get_option('crm_omd_reminder_interval_days', 5));
        $reminder_day_of_month = min(28, max(1, (int) get_option('crm_omd_reminder_day_of_month', 5)));

        echo '<h2>Ustawienia przypomnień</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #ddd;padding:12px;margin-bottom:14px;">';
        wp_nonce_field('crm_omd_save_reminder_settings');
        echo '<input type="hidden" name="action" value="crm_omd_save_reminder_settings">';
        echo '<p><label>Tryb przypomnienia<br><select name="reminder_mode">';
        echo '<option value="interval"' . selected($reminder_mode, 'interval', false) . '>Co X dni</option>';
        echo '<option value="monthly"' . selected($reminder_mode, 'monthly', false) . '>Konkretny dzień miesiąca</option>';
        echo '</select></label></p>';
        echo '<p><label>Interwał dni (dla trybu "Co X dni")<br><input type="number" name="reminder_interval_days" min="1" max="60" value="' . esc_attr((string) $reminder_interval_days) . '"></label></p>';
        echo '<p><label>Dzień miesiąca (dla trybu miesięcznego, 1-28)<br><input type="number" name="reminder_day_of_month" min="1" max="28" value="' . esc_attr((string) $reminder_day_of_month) . '"></label></p>';
        echo '<p><button class="button button-primary" type="submit">Zapisz ustawienia przypomnień</button></p>';
        echo '</form>';

        if ($edit_worker instanceof WP_User) {
            echo '<h2>Edycja konta: ' . esc_html($edit_worker->display_name) . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="background:#fff;border:1px solid #ddd;padding:12px;margin-bottom:14px;">';
            wp_nonce_field('crm_omd_update_worker_' . (int) $edit_worker->ID);
            echo '<input type="hidden" name="action" value="crm_omd_update_worker">';
            echo '<input type="hidden" name="user_id" value="' . (int) $edit_worker->ID . '">';
            echo '<p><label>Nowe hasło (opcjonalnie)<br><input type="password" name="new_password" autocomplete="new-password"></label></p>';
            echo '<p><label>Rola<br><select name="role">';
            foreach (array_keys(get_editable_roles()) as $role_key) {
                echo '<option value="' . esc_attr($role_key) . '"' . selected(in_array($role_key, $edit_worker->roles, true), true, false) . '>' . esc_html($role_key) . '</option>';
            }
            echo '</select></label></p>';
            echo '<p><button class="button button-primary" type="submit">Zapisz konto</button> ';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_worker&user_id=' . (int) $edit_worker->ID), 'crm_omd_delete_worker_' . (int) $edit_worker->ID)) . '" onclick="return confirm(\'Usunąć konto pracownika?\');">Usuń konto</a> ';
            echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=crm-omd-workers')) . '">Anuluj</a></p>';
            echo '</form>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_worker_settings');
        echo '<input type="hidden" name="action" value="crm_omd_save_worker_settings">';
        echo '<table class="widefat striped"><thead><tr><th>Użytkownik</th><th>Email</th><th>Rola</th><th>Ostatnie logowanie</th><th>Aktywny w time tracking</th><th>Przypomnienia mailowe</th><th>Pensja miesięczna (PLN)</th><th>Akcje</th></tr></thead><tbody>';

        foreach ($users as $user) {
            $enabled = get_user_meta($user->ID, 'crm_omd_worker_enabled', true);
            $reminder = get_user_meta($user->ID, 'crm_omd_worker_reminder', true);
            $last_login = get_user_meta($user->ID, 'crm_omd_last_login', true);
            $monthly_salary = (float) get_user_meta($user->ID, 'crm_omd_worker_monthly_salary', true);
            if ($enabled === '') {
                $enabled = '1';
            }
            if ($reminder === '') {
                $reminder = '1';
            }

            echo '<tr>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html($user->user_email) . '</td>';
            echo '<td>' . esc_html(implode(', ', $user->roles)) . '</td>';
            echo '<td>' . esc_html($last_login ? $last_login : 'brak') . '</td>';
            echo '<td><label><input type="checkbox" name="worker_enabled[' . (int) $user->ID . ']" value="1" ' . checked($enabled, '1', false) . '> Tak</label></td>';
            echo '<td><label><input type="checkbox" name="worker_reminder[' . (int) $user->ID . ']" value="1" ' . checked($reminder, '1', false) . '> Tak</label></td>';
            echo '<td><input type="number" name="worker_monthly_salary[' . (int) $user->ID . ']" min="0" step="0.01" value="' . esc_attr(number_format($monthly_salary, 2, '.', '')) . '" style="width:140px;"></td>';
            echo '<td><a class="button button-small" href="' . esc_url(add_query_arg(['page' => 'crm-omd-workers', 'edit_worker' => (int) $user->ID], admin_url('admin.php'))) . '">Edytuj konto</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '<p><button class="button button-primary" type="submit">Zapisz ustawienia pracowników</button></p>';
        echo '</form>';

        $admin_month = isset($_GET['worker_month']) ? sanitize_text_field(wp_unslash($_GET['worker_month'])) : date('Y-m');
        $admin_month = preg_match('/^\d{4}-\d{2}$/', $admin_month) ? $admin_month : date('Y-m');
        [$admin_date_from, $admin_date_to] = $this->get_month_boundaries($admin_month);
        $admin_year = (int) substr($admin_month, 0, 4);
        $admin_month_num = (int) substr($admin_month, 5, 2);
        $admin_expected_hours = $this->get_working_days_in_month($admin_year, $admin_month_num) * 8;

        echo '<h2>Podsumowanie pracowników</h2>';
        echo '<form method="get" style="margin:10px 0 8px;">';
        echo '<input type="hidden" name="page" value="crm-omd-workers">';
        echo '<label>Miesiąc: <input type="month" name="worker_month" value="' . esc_attr($admin_month) . '"></label> ';
        echo '<button class="button" type="submit">Pokaż</button>';
        echo '</form>';

        echo '<table class="widefat striped" style="margin-top:8px;">';
        echo '<thead><tr><th>Pracownik</th><th>Zaraportowane godziny</th><th>Godziny do przepracowania</th><th>Różnica godzin</th><th>Wypracowany zysk (PLN)</th><th>Koszt etatu / pensja (PLN)</th><th>Stopa zwrotu (zysk - pensja)</th></tr></thead><tbody>';
        foreach ($users as $user) {
            $reported = $this->get_user_reported_hours_for_range((int) $user->ID, $admin_date_from, $admin_date_to);
            $revenue = $this->get_user_revenue_for_range((int) $user->ID, $admin_date_from, $admin_date_to);
            $salary = (float) get_user_meta($user->ID, 'crm_omd_worker_monthly_salary', true);
            $return = $revenue - $salary;
            echo '<tr>';
            echo '<td>' . esc_html($user->display_name) . '</td>';
            echo '<td>' . esc_html(number_format($reported, 2, ',', ' ')) . '</td>';
            echo '<td>' . esc_html((string) $admin_expected_hours) . '</td>';
            echo '<td>' . esc_html(number_format($reported - (float) $admin_expected_hours, 2, ',', ' ')) . '</td>';
            echo '<td>' . esc_html(number_format($revenue, 2, ',', ' ')) . '</td>';
            echo '<td>' . esc_html(number_format($salary, 2, ',', ' ')) . '</td>';
            echo '<td>' . esc_html(number_format($return, 2, ',', ' ')) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_save_worker_settings(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_worker_settings');

        $users = get_users(['fields' => 'ID']);
        $enabled = isset($_POST['worker_enabled']) && is_array($_POST['worker_enabled']) ? $_POST['worker_enabled'] : [];
        $reminder = isset($_POST['worker_reminder']) && is_array($_POST['worker_reminder']) ? $_POST['worker_reminder'] : [];
        $monthly_salary = isset($_POST['worker_monthly_salary']) && is_array($_POST['worker_monthly_salary']) ? $_POST['worker_monthly_salary'] : [];

        foreach ($users as $id) {
            $is_enabled = isset($enabled[$id]) ? '1' : '0';
            $is_reminder = isset($reminder[$id]) ? '1' : '0';
            $salary_raw = isset($monthly_salary[$id]) ? (string) wp_unslash($monthly_salary[$id]) : '0';
            $salary = max(0, (float) str_replace(',', '.', $salary_raw));
            update_user_meta($id, 'crm_omd_worker_enabled', $is_enabled);
            update_user_meta($id, 'crm_omd_worker_reminder', $is_reminder);
            update_user_meta($id, 'crm_omd_worker_monthly_salary', $salary);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_save_reminder_settings(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_reminder_settings');

        $mode = isset($_POST['reminder_mode']) ? sanitize_text_field(wp_unslash($_POST['reminder_mode'])) : 'interval';
        if (!in_array($mode, ['interval', 'monthly'], true)) {
            $mode = 'interval';
        }

        $interval = isset($_POST['reminder_interval_days']) ? (int) $_POST['reminder_interval_days'] : 5;
        $interval = max(1, min(60, $interval));

        $day_of_month = isset($_POST['reminder_day_of_month']) ? (int) $_POST['reminder_day_of_month'] : 5;
        $day_of_month = min(28, max(1, $day_of_month));

        update_option('crm_omd_reminder_mode', $mode);
        update_option('crm_omd_reminder_interval_days', $interval);
        update_option('crm_omd_reminder_day_of_month', $day_of_month);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_update_worker(): void
    {
        $this->require_admin_access();
        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        check_admin_referer('crm_omd_update_worker_' . $user_id);

        if ($user_id <= 0) {
            wp_die(esc_html__('Niepoprawny użytkownik.', 'crm-omd-time-manager'));
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            wp_die(esc_html__('Użytkownik nie istnieje.', 'crm-omd-time-manager'));
        }

        $role = isset($_POST['role']) ? sanitize_text_field(wp_unslash($_POST['role'])) : '';
        $new_password = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';

        if ($role !== '' && array_key_exists($role, get_editable_roles())) {
            $user->set_role($role);
        }

        if ($new_password !== '') {
            wp_set_password($new_password, $user_id);
        }

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function handle_delete_worker(): void
    {
        $this->require_admin_access();
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;
        check_admin_referer('crm_omd_delete_worker_' . $user_id);

        if ($user_id <= 0 || $user_id === get_current_user_id()) {
            wp_die(esc_html__('Nie można usunąć tego konta.', 'crm-omd-time-manager'));
        }

        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($user_id);

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-workers'));
        exit;
    }

    public function render_reports_page(): void
    {
        $this->require_admin_access();
        global $wpdb;

        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : date('Y-m');
        $date_from = isset($_GET['date_from']) ? sanitize_text_field(wp_unslash($_GET['date_from'])) : ($month . '-01');
        $date_to = isset($_GET['date_to']) ? sanitize_text_field(wp_unslash($_GET['date_to'])) : date('Y-m-t', strtotime($month . '-01'));
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        $detail = isset($_GET['detail']) ? 1 : 0;

        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects = $wpdb->get_results("SELECT id, name FROM {$this->tbl_projects} WHERE is_active = 1 ORDER BY name ASC");

        $where = "WHERE e.status = 'approved' AND e.work_date BETWEEN %s AND %s";
        $params = [$date_from, $date_to];
        if ($client_id) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }
        if ($project_id) {
            $where .= ' AND e.project_id = %d';
            $params[] = $project_id;
        }

        if ($detail) {
            $sql = "SELECT e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, u.display_name, e.hours, e.calculated_value, e.description
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
                    {$where}
                    ORDER BY e.work_date ASC, e.id ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        } else {
            $sql = "SELECT s.name AS service_name, SUM(e.hours) AS hours_sum, SUM(e.calculated_value) AS value_sum
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    {$where}
                    GROUP BY e.service_id
                    ORDER BY s.name ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        }

        echo '<div class="wrap"><h1>Raporty</h1>';
        echo '<form method="get"><input type="hidden" name="page" value="crm-omd-reports">';
        echo '<input type="month" name="month" value="' . esc_attr($month) . '"> ';
        echo '<input type="date" name="date_from" value="' . esc_attr($date_from) . '"> ';
        echo '<input type="date" name="date_to" value="' . esc_attr($date_to) . '"> ';
        echo '<select name="client_id"><option value="0">Wszyscy klienci</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '"' . selected($client_id, (int) $client->id, false) . '>' . esc_html($client->name) . '</option>';
        }
        echo '</select> ';
        echo '<select name="project_id"><option value="0">Wszystkie projekty</option>';
        foreach ($projects as $project) {
            echo '<option value="' . (int) $project->id . '"' . selected($project_id, (int) $project->id, false) . '>' . esc_html($project->name) . '</option>';
        }
        echo '</select> ';
        echo '<label><input type="checkbox" name="detail" value="1"' . checked($detail, 1, false) . '> Szczegółowy</label> ';
        echo '<button class="button button-primary" type="submit">Generuj</button>';
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        wp_nonce_field('crm_omd_export_report');
        echo '<input type="hidden" name="action" value="crm_omd_export_report">';
        echo '<input type="hidden" name="month" value="' . esc_attr($month) . '">';
        echo '<input type="hidden" name="date_from" value="' . esc_attr($date_from) . '">';
        echo '<input type="hidden" name="date_to" value="' . esc_attr($date_to) . '">';
        echo '<input type="hidden" name="client_id" value="' . (int) $client_id . '">';
        echo '<input type="hidden" name="project_id" value="' . (int) $project_id . '">';
        echo '<input type="hidden" name="detail" value="' . (int) $detail . '">';
        echo '<button class="button" type="submit">Eksport do Excel (CSV)</button></form>';

        echo '<table class="widefat striped" style="margin-top:12px"><thead><tr>';
        if ($detail) {
            echo '<th>Data</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Pracownik</th><th>Godziny</th><th>Kwota</th><th>Opis</th>';
        } else {
            echo '<th>Usługa</th><th>Ilość godzin (miesiąc)</th><th>Łączna kwota</th>';
        }
        echo '</tr></thead><tbody>';

        $total_hours = 0.0;
        $total_value = 0.0;
        foreach ($rows as $row) {
            echo '<tr>';
            if ($detail) {
                echo '<td>' . esc_html($row->work_date) . '</td><td>' . esc_html($row->client_name) . '</td><td>' . esc_html($row->project_name) . '</td><td>' . esc_html($row->service_name) . '</td><td>' . esc_html($row->display_name) . '</td><td>' . esc_html((string) $row->hours) . '</td><td>' . esc_html(number_format((float) $row->calculated_value, 2, ',', ' ')) . '</td><td>' . esc_html($row->description) . '</td>';
                $total_hours += (float) $row->hours;
                $total_value += (float) $row->calculated_value;
            } else {
                echo '<td>' . esc_html($row->service_name) . '</td><td>' . esc_html((string) $row->hours_sum) . '</td><td>' . esc_html(number_format((float) $row->value_sum, 2, ',', ' ')) . '</td>';
                $total_hours += (float) $row->hours_sum;
                $total_value += (float) $row->value_sum;
            }
            echo '</tr>';
        }

        echo '</tbody><tfoot><tr>';
        if ($detail) {
            echo '<th colspan="5">SUMA</th><th>' . esc_html(number_format($total_hours, 2, ',', ' ')) . '</th><th>' . esc_html(number_format($total_value, 2, ',', ' ')) . '</th><th></th>';
        } else {
            echo '<th>SUMA</th><th>' . esc_html(number_format($total_hours, 2, ',', ' ')) . '</th><th>' . esc_html(number_format($total_value, 2, ',', ' ')) . '</th>';
        }
        echo '</tr></tfoot></table></div>';
    }

    public function handle_export_report(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_export_report');

        global $wpdb;
        $month = isset($_POST['month']) ? sanitize_text_field(wp_unslash($_POST['month'])) : date('Y-m');
        $date_from = isset($_POST['date_from']) ? sanitize_text_field(wp_unslash($_POST['date_from'])) : ($month . '-01');
        $date_to = isset($_POST['date_to']) ? sanitize_text_field(wp_unslash($_POST['date_to'])) : date('Y-m-t', strtotime($month . '-01'));
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $detail = isset($_POST['detail']) ? (int) $_POST['detail'] : 0;

        $where = "WHERE e.status = 'approved' AND e.work_date BETWEEN %s AND %s";
        $params = [$date_from, $date_to];
        if ($client_id) {
            $where .= ' AND e.client_id = %d';
            $params[] = $client_id;
        }
        if ($project_id) {
            $where .= ' AND e.project_id = %d';
            $params[] = $project_id;
        }

        if ($detail) {
            $sql = "SELECT e.work_date, c.name AS client_name, p.name AS project_name, s.name AS service_name, u.display_name, e.hours, e.calculated_value, e.description
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
                    INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
                    {$where}
                    ORDER BY e.work_date ASC, e.id ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        } else {
            $sql = "SELECT s.name AS usluga, SUM(e.hours) AS godziny, SUM(e.calculated_value) AS kwota
                    FROM {$this->tbl_entries} e
                    INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
                    {$where}
                    GROUP BY e.service_id
                    ORDER BY s.name ASC";
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=crm-omd-raport-' . $month . '.csv');

        $output = fopen('php://output', 'w');
        if (!$output) {
            exit;
        }
        if (!empty($rows)) {
            fputcsv($output, array_keys($rows[0]), ';');
            foreach ($rows as $row) {
                fputcsv($output, $row, ';');
            }
        }
        fclose($output);
        exit;
    }

    public function send_daily_reminders(): void
    {
        $today = current_time('Y-m-d');
        $mode = (string) get_option('crm_omd_reminder_mode', 'interval');
        $interval_days = max(1, (int) get_option('crm_omd_reminder_interval_days', 5));
        $day_of_month = min(28, max(1, (int) get_option('crm_omd_reminder_day_of_month', 5)));

        if ($mode === 'monthly') {
            if ((int) current_time('j') !== $day_of_month) {
                return;
            }
        } else {
            $last_sent = (string) get_option('crm_omd_last_global_reminder_sent', '');
            if ($last_sent !== '') {
                $last_ts = strtotime($last_sent);
                $today_ts = strtotime($today);
                if ($last_ts && $today_ts && ($today_ts - $last_ts) < ($interval_days * DAY_IN_SECONDS)) {
                    return;
                }
            }
        }

        global $wpdb;
        $users = get_users(['role__in' => ['subscriber', 'author', 'editor', 'administrator']]);

        foreach ($users as $user) {
            $enabled = get_user_meta($user->ID, 'crm_omd_worker_enabled', true);
            $reminder = get_user_meta($user->ID, 'crm_omd_worker_reminder', true);
            if ($enabled === '0' || $reminder === '0') {
                continue;
            }

            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tbl_entries} WHERE user_id = %d AND work_date = %s", $user->ID, $today));
            if ($count === 0) {
                wp_mail(
                    $user->user_email,
                    'Przypomnienie o uzupełnieniu godzin',
                    'Cześć, przypominamy o uzupełnieniu czasu pracy za dzisiejszy dzień.'
                );
            }
        }

        update_option('crm_omd_last_global_reminder_sent', $today);
    }
}

new CRM_OMD_Time_Manager();
