<?php
/**
 * Plugin Name: CRM OMD Time Manager
 * Description: Rejestracja czasu pracy pracowników dla klientów i projektów, akceptacja wpisów, raporty miesięczne i eksport CSV.
 * Version: 0.1.0
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
        add_action('admin_post_crm_omd_export_report', [$this, 'handle_export_report']);

        add_shortcode('crm_omd_time_tracker', [$this, 'render_tracker_shortcode']);
        add_action('admin_post_crm_omd_submit_entry', [$this, 'handle_submit_entry']);

        add_action('crm_omd_daily_reminder', [$this, 'send_daily_reminders']);
    }

    public function activate(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->tbl_clients} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
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

    public function register_admin_menu(): void
    {
        add_menu_page('CRM OMD Time', 'CRM OMD Time', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page'], 'dashicons-clock', 28);
        add_submenu_page('crm-omd-time', 'Akceptacja wpisów', 'Akceptacja wpisów', 'manage_options', 'crm-omd-time', [$this, 'render_entries_page']);
        add_submenu_page('crm-omd-time', 'Klienci', 'Klienci', 'manage_options', 'crm-omd-clients', [$this, 'render_clients_page']);
        add_submenu_page('crm-omd-time', 'Projekty', 'Projekty', 'manage_options', 'crm-omd-projects', [$this, 'render_projects_page']);
        add_submenu_page('crm-omd-time', 'Usługi', 'Usługi', 'manage_options', 'crm-omd-services', [$this, 'render_services_page']);
        add_submenu_page('crm-omd-time', 'Raporty', 'Raporty', 'manage_options', 'crm-omd-reports', [$this, 'render_reports_page']);
    }

    public function render_tracker_shortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p>Musisz być zalogowany.</p>';
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

        if (!$client_id || !$service_id || !$work_date || !$description) {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        if (!$project_id && $new_project !== '') {
            $wpdb->insert(
                $this->tbl_projects,
                [
                    'client_id' => $client_id,
                    'name' => $new_project,
                    'description' => '',
                    'is_active' => 1,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%s', '%d', '%s']
            );
            $project_id = (int) $wpdb->insert_id;
        }

        if (!$project_id) {
            wp_die(esc_html__('Wybierz lub dodaj projekt.', 'crm-omd-time-manager'));
        }

        $service = $wpdb->get_row($wpdb->prepare("SELECT billing_type, hourly_rate, fixed_value FROM {$this->tbl_services} WHERE id = %d AND client_id = %d", $service_id, $client_id));
        if (!$service) {
            wp_die(esc_html__('Usługa nie należy do wskazanego klienta.', 'crm-omd-time-manager'));
        }

        $value = $service->billing_type === 'fixed' ? (float) $service->fixed_value : $hours * (float) $service->hourly_rate;

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

        $rows = $wpdb->get_results("SELECT e.id, e.work_date, e.hours, e.description, e.status, e.calculated_value, c.name AS client_name, p.name AS project_name, s.name AS service_name, u.display_name
            FROM {$this->tbl_entries} e
            INNER JOIN {$this->tbl_clients} c ON c.id = e.client_id
            INNER JOIN {$this->tbl_projects} p ON p.id = e.project_id
            INNER JOIN {$this->tbl_services} s ON s.id = e.service_id
            INNER JOIN {$wpdb->users} u ON u.ID = e.user_id
            ORDER BY e.work_date DESC, e.id DESC
            LIMIT 200");

        echo '<div class="wrap"><h1>Akceptacja wpisów</h1>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Data</th><th>Pracownik</th><th>Klient</th><th>Projekt</th><th>Usługa</th><th>Godziny</th><th>Wartość</th><th>Status</th><th>Opis</th><th>Akcja</th></tr></thead><tbody>';
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
                echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_review_entry&id=' . (int) $row->id . '&decision=rejected'), 'crm_omd_review_entry_' . (int) $row->id)) . '">Odrzuć</a>';
            }
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
            [
                'status' => $decision,
                'reviewed_by' => get_current_user_id(),
                'reviewed_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%s', '%d', '%s'],
            ['%d']
        );

        wp_safe_redirect(admin_url('admin.php?page=crm-omd-time'));
        exit;
    }

    public function render_clients_page(): void
    {
        $this->require_admin_access();
        global $wpdb;
        $rows = $wpdb->get_results("SELECT id, name, is_active FROM {$this->tbl_clients} ORDER BY name ASC");

        echo '<div class="wrap"><h1>Klienci</h1>';
        echo '<h2>Dodaj klienta</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_client');
        echo '<input type="hidden" name="action" value="crm_omd_save_client">';
        echo '<input type="text" name="name" placeholder="Nazwa klienta" required> ';
        echo '<button class="button button-primary" type="submit">Zapisz</button></form>';

        echo '<h2>Lista klientów</h2><table class="widefat striped"><thead><tr><th>Nazwa</th><th>Status</th><th>Akcja</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->name) . '</td><td>' . ((int) $row->is_active ? 'Aktywny' : 'Nieaktywny') . '</td><td>';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_client&id=' . (int) $row->id), 'crm_omd_delete_client_' . (int) $row->id)) . '">Usuń</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_client(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_client');
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if ($name === '') {
            wp_die(esc_html__('Nazwa jest wymagana.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $wpdb->insert(
            $this->tbl_clients,
            ['name' => $name, 'is_active' => 1, 'created_at' => current_time('mysql')],
            ['%s', '%d', '%s']
        );

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
        $rows = $wpdb->get_results("SELECT p.id, p.name, p.is_active, c.name AS client_name FROM {$this->tbl_projects} p INNER JOIN {$this->tbl_clients} c ON c.id = p.client_id ORDER BY p.id DESC");

        echo '<div class="wrap"><h1>Projekty</h1>';
        echo '<h2>Dodaj projekt</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_project');
        echo '<input type="hidden" name="action" value="crm_omd_save_project">';
        echo '<select name="client_id" required><option value="">Wybierz klienta</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '">' . esc_html($client->name) . '</option>';
        }
        echo '</select> <input type="text" name="name" placeholder="Nazwa projektu" required> ';
        echo '<button class="button button-primary" type="submit">Zapisz</button></form>';

        echo '<h2>Lista projektów</h2><table class="widefat striped"><thead><tr><th>Klient</th><th>Projekt</th><th>Status</th><th>Akcja</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->client_name) . '</td><td>' . esc_html($row->name) . '</td><td>' . ((int) $row->is_active ? 'Aktywny' : 'Nieaktywny') . '</td><td>';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_project&id=' . (int) $row->id), 'crm_omd_delete_project_' . (int) $row->id)) . '">Usuń</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_project(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_project');

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (!$client_id || $name === '') {
            wp_die(esc_html__('Wypełnij wymagane pola.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $wpdb->insert(
            $this->tbl_projects,
            ['client_id' => $client_id, 'name' => $name, 'description' => '', 'is_active' => 1, 'created_at' => current_time('mysql')],
            ['%d', '%s', '%s', '%d', '%s']
        );

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
        $rows = $wpdb->get_results("SELECT s.id, s.name, s.billing_type, s.hourly_rate, s.fixed_value, s.is_active, c.name AS client_name
            FROM {$this->tbl_services} s
            INNER JOIN {$this->tbl_clients} c ON c.id = s.client_id
            ORDER BY s.id DESC");

        echo '<div class="wrap"><h1>Usługi i stawki</h1>';
        echo '<h2>Dodaj usługę</h2><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('crm_omd_save_service');
        echo '<input type="hidden" name="action" value="crm_omd_save_service">';
        echo '<select name="client_id" required><option value="">Wybierz klienta</option>';
        foreach ($clients as $client) {
            echo '<option value="' . (int) $client->id . '">' . esc_html($client->name) . '</option>';
        }
        echo '</select> <input type="text" name="name" placeholder="Nazwa usługi" required> ';
        echo '<select name="billing_type"><option value="hourly">Godzinowa</option><option value="fixed">Ryczałt</option></select> ';
        echo '<input type="number" name="hourly_rate" step="0.01" min="0" placeholder="Stawka godzinowa"> ';
        echo '<input type="number" name="fixed_value" step="0.01" min="0" placeholder="Wartość ryczałtu"> ';
        echo '<button class="button button-primary" type="submit">Zapisz</button></form>';

        echo '<h2>Lista usług</h2><table class="widefat striped"><thead><tr><th>Klient</th><th>Usługa</th><th>Typ</th><th>Stawka h</th><th>Ryczałt</th><th>Status</th><th>Akcja</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row->client_name) . '</td><td>' . esc_html($row->name) . '</td><td>' . esc_html($row->billing_type) . '</td><td>' . esc_html(number_format((float) $row->hourly_rate, 2, ',', ' ')) . '</td><td>' . esc_html(number_format((float) $row->fixed_value, 2, ',', ' ')) . '</td><td>' . ((int) $row->is_active ? 'Aktywna' : 'Nieaktywna') . '</td><td>';
            echo '<a class="button button-secondary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=crm_omd_delete_service&id=' . (int) $row->id), 'crm_omd_delete_service_' . (int) $row->id)) . '">Usuń</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    public function handle_save_service(): void
    {
        $this->require_admin_access();
        check_admin_referer('crm_omd_save_service');

        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $billing_type = isset($_POST['billing_type']) ? sanitize_text_field(wp_unslash($_POST['billing_type'])) : 'hourly';
        $hourly_rate = isset($_POST['hourly_rate']) ? (float) $_POST['hourly_rate'] : 0;
        $fixed_value = isset($_POST['fixed_value']) ? (float) $_POST['fixed_value'] : 0;

        if (!$client_id || $name === '' || !in_array($billing_type, ['hourly', 'fixed'], true)) {
            wp_die(esc_html__('Wypełnij poprawnie formularz.', 'crm-omd-time-manager'));
        }

        global $wpdb;
        $wpdb->insert(
            $this->tbl_services,
            [
                'client_id' => $client_id,
                'name' => $name,
                'billing_type' => $billing_type,
                'hourly_rate' => $hourly_rate,
                'fixed_value' => $fixed_value,
                'is_active' => 1,
                'created_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%f', '%f', '%d', '%s']
        );

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

    public function render_reports_page(): void
    {
        $this->require_admin_access();
        global $wpdb;

        $month = isset($_GET['month']) ? sanitize_text_field(wp_unslash($_GET['month'])) : date('Y-m');
        $client_id = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
        $project_id = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 0;
        $detail = isset($_GET['detail']) ? 1 : 0;

        $clients = $wpdb->get_results("SELECT id, name FROM {$this->tbl_clients} WHERE is_active = 1 ORDER BY name ASC");
        $projects = $wpdb->get_results("SELECT id, name FROM {$this->tbl_projects} WHERE is_active = 1 ORDER BY name ASC");

        $where = "WHERE e.status = 'approved' AND DATE_FORMAT(e.work_date, '%Y-%m') = %s";
        $params = [$month];
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
        echo '<button class="button button-primary" type="submit">Generuj</button> ';

        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:10px">';
        wp_nonce_field('crm_omd_export_report');
        echo '<input type="hidden" name="action" value="crm_omd_export_report">';
        echo '<input type="hidden" name="month" value="' . esc_attr($month) . '">';
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
        $client_id = isset($_POST['client_id']) ? (int) $_POST['client_id'] : 0;
        $project_id = isset($_POST['project_id']) ? (int) $_POST['project_id'] : 0;
        $detail = isset($_POST['detail']) ? (int) $_POST['detail'] : 0;

        $where = "WHERE e.status = 'approved' AND DATE_FORMAT(e.work_date, '%Y-%m') = %s";
        $params = [$month];
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
        $enabled = get_option('crm_omd_reminders_enabled', '1');
        if ($enabled !== '1') {
            return;
        }

        global $wpdb;
        $users = get_users(['role__in' => ['subscriber', 'author', 'editor', 'administrator']]);
        $today = current_time('Y-m-d');

        foreach ($users as $user) {
            $count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->tbl_entries} WHERE user_id = %d AND work_date = %s", $user->ID, $today));
            if ($count === 0) {
                wp_mail(
                    $user->user_email,
                    'Przypomnienie o uzupełnieniu godzin',
                    'Cześć, przypominamy o uzupełnieniu czasu pracy za dzisiejszy dzień.'
                );
            }
        }
    }
}

new CRM_OMD_Time_Manager();
