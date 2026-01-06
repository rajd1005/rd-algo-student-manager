<?php
class RD_Algo_Admin {
    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function add_admin_menu() {
        add_menu_page( 'RD Algo Manager', 'RD Algo', 'manage_options', 'rd-algo-settings', [ $this, 'settings_page' ] );
    }

    public function register_settings() {
        register_setting( 'rd_algo_group', 'rd_algo_settings', ['sanitize_callback' => [$this, 'sanitize_settings']] );
    }

    public function sanitize_settings($input) {
        $new_input = [];
        if(is_array($input)) {
            foreach($input as $k => $v) {
                if($k === 'sop_content' || strpos($k, 'tpl_') === 0 || strpos($k, 'off_copy_btn_') !== false) {
                    $new_input[$k] = wp_kses_post($v);
                } elseif(is_array($v)) {
                    $new_input[$k] = $v;
                } else {
                    $new_input[$k] = sanitize_text_field($v);
                }
            }
        }
        return $new_input;
    }

    // --- HELPER: Get GF Forms ---
    private function get_gf_data() {
        if ( ! class_exists( 'GFAPI' ) ) return [];
        $forms = GFAPI::get_forms(true, false, 'title');
        $data = [];
        foreach ( $forms as $form ) {
            $fields = [];
            if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
                foreach ( $form['fields'] as $field ) {
                    if ( in_array( $field->type, ['html', 'section', 'page'] ) ) continue;
                    $label = $field->adminLabel ? $field->adminLabel : $field->label;
                    $label = wp_trim_words($label, 5, '...');
                    $fields[] = [ 'id' => (string)$field->id, 'label' => "$label (ID: {$field->id})" ];
                    if ( isset( $field->inputs ) && is_array( $field->inputs ) ) {
                        foreach ( $field->inputs as $input ) {
                            $inputLabel = $input['label'] ?? $input['id'];
                            $fields[] = [ 'id' => (string)$input['id'], 'label' => " - $inputLabel (ID: {$input['id']})" ];
                        }
                    }
                }
            }
            $data[ $form['id'] ] = [ 'title' => $form['title'], 'fields' => $fields ];
        }
        return $data;
    }

    // --- HELPER: Get All Tables ---
    private function get_all_tables() {
        global $wpdb;
        return $wpdb->get_col("SHOW TABLES");
    }

    // --- HELPER: Get MT4 DateTypes ---
    private function get_mt4_types() {
        global $wpdb;
        $opts = get_option('rd_algo_settings', []);
        $tb = $opts['tb_mt4'] ?? 'wp_mt4_user_records';
        if($wpdb->get_var("SHOW TABLES LIKE '$tb'") != $tb) return [];
        $types = $wpdb->get_col("SELECT DISTINCT datetype FROM $tb WHERE datetype != '' ORDER BY datetype ASC");
        if(!is_array($types)) return [];
        return array_values(array_unique(array_filter(array_map('trim', $types))));
    }

    // --- HELPER: Get Payment Purposes ---
    private function get_payment_purposes() {
        global $wpdb;
        $opts = get_option('rd_algo_settings', []);
        $tb = $opts['tb_payment'] ?? 'wp_customer_payment_history';
        $col = $opts['col_pay_purpose'] ?? 'purpose';
        
        if($wpdb->get_var("SHOW TABLES LIKE '$tb'") != $tb) return [];
        
        // Ensure column exists
        $col_exists = $wpdb->get_results("SHOW COLUMNS FROM $tb LIKE '$col'");
        if(empty($col_exists)) return [];

        $items = $wpdb->get_col("SELECT DISTINCT $col FROM $tb WHERE $col != '' ORDER BY $col ASC");
        if(!is_array($items)) return [];
        return array_values(array_unique(array_filter(array_map('trim', $items))));
    }

    private function render_form_select($name, $value, $group_id) {
        $forms = $this->get_gf_data();
        $html = '<select name="'.$name.'" class="rd-gf-form-selector widefat" data-group="'.$group_id.'">';
        $html .= '<option value="">-- Select Form --</option>';
        if(!empty($forms)) {
            foreach($forms as $id => $f) {
                $selected = ((string)$value === (string)$id) ? 'selected' : '';
                $html .= '<option value="'.$id.'" '.$selected.'>'.$f['title'].' (ID: '.$id.')</option>';
            }
        } else { $html .= '<option value="">Gravity Forms Not Found</option>'; }
        $html .= '</select>';
        if(strpos($value, ',') !== false) $html .= '<p class="description" style="color:red; font-size:10px;">Warning: Multiple IDs detected. Save to overwrite.</p>';
        return $html;
    }

    private function render_field_select($name, $value, $group_id) {
        return '<select name="'.$name.'" class="rd-gf-field-selector widefat" data-group="'.$group_id.'" data-value="'.esc_attr($value).'"><option value="'.esc_attr($value).'">Loading...</option></select>';
    }

    // --- HELPER: Render Table Selector ---
    private function render_table_select($name, $selected_val, $default_val, $group_id) {
        $tables = $this->get_all_tables();
        $val = !empty($selected_val) ? $selected_val : $default_val;
        
        $html = '<select name="'.$name.'" class="rd-db-table-selector widefat" data-group="'.$group_id.'">';
        $html .= '<option value="">-- Select Table --</option>';
        foreach($tables as $t) {
            $sel = ($t === $val) ? 'selected' : '';
            $html .= '<option value="'.$t.'" '.$sel.'>'.$t.'</option>';
        }
        $html .= '</select>';
        return $html;
    }

    // --- HELPER: Render Column Selector ---
    private function render_column_select($name, $selected_val, $default_val, $group_id) {
        $val = !empty($selected_val) ? $selected_val : $default_val;
        return '<select name="'.$name.'" class="rd-db-column-selector widefat" data-group="'.$group_id.'" data-value="'.esc_attr($val).'">
                    <option value="'.esc_attr($val).'">'.esc_html($val).' (Saved)</option>
                </select>';
    }

    public function settings_page() {
        $options = get_option( 'rd_algo_settings', [] );
        global $wp_roles;
        $gf_data = $this->get_gf_data();
        $mt4_types = $this->get_mt4_types();
        $pay_purposes = $this->get_payment_purposes();
        
        $defaults = [
            'tb_student' => 'wp_gf_student_registrations',
            'tb_mt4' => 'wp_mt4_user_records',
            'tb_vps' => 'wp_vps_records',
            'tb_payment' => 'wp_customer_payment_history',
            'tb_vps_staging' => 'wp_vps_records_verify',
            'tb_tg' => 'wp_gf_telegram_subs',
            
            // Student Cols
            'col_stu_name' => 'student_name',
            'col_stu_email' => 'student_email',
            'col_stu_phone' => 'student_phone',
            'col_stu_expiry' => 'student_expiry_date',
            'col_stu_soft' => 'software_type',
            'col_stu_mt4' => 'mt4_server_id',
            'col_stu_vps' => 'vps_host_name',

            // Student Level Cols (NEW)
            'col_l2_status' => 'level_2_status',
            'col_l2_date'   => 'level_2_join_date',
            'col_l3_status' => 'level_3_status',
            'col_l3_date'   => 'level_3_join_date',
            'col_l4_status' => 'level_4_status',
            'col_l4_date'   => 'level_4_join_date',

            // MT4 Cols
            'col_mt4_login' => 'mt4userid',
            'col_mt4_pass' => 'mt4password',
            'col_mt4_server' => 'mt4servername',
            'col_mt4_expiry' => 'mt4expirydate',
            'col_mt4_status' => 'status',

            // VPS Cols
            'col_vps_host' => 'host_name',
            'col_vps_ip' => 'vps_ip',
            'col_vps_user' => 'vps_user_id',
            'col_vps_pass' => 'vps_password',
            
            // Payment Cols
            'col_pay_id' => 'id',
            'col_pay_phone' => 'student_phone',
            'col_pay_purpose' => 'purpose',
            'col_pay_amount' => 'amount',
            'col_pay_date' => 'created_at',

            // TG Cols
            'col_tg_id' => 'id',
            'col_tg_links' => 'links',
            'col_tg_phone' => 'mobile', 

            // Staging Cols
            'col_stg_id' => 'id'
        ];

        $buttons = [
            'add_student' => 'Add New Student',
            'renewal'=>'Renewal', 'upgrade'=>'Upgrade', 'downgrade'=>'Downgrade',
            'assign_mt4'=>'Assign MT4', 'assign_vps'=>'Assign VPS',
            'renew_mt4'=>'Renew MT4', 'renew_vps'=>'Renew VPS',
            'remove_mt4'=>'Remove MT4', 'remove_vps'=>'Remove VPS',
            'copy_tg'=>'Copy TG Link', 'copy_course'=>'Copy Course Info', 'copy_vps_guide'=>'Copy VPS Guide',
            'auto_tg'=>'Email TG Access', 'auto_course'=>'Email Course Access',
            'level_2_access'=>'Level 2 Access', 
            'level_3_access'=>'Level 3 Access', 
            'level_4_access'=>'Level 4 Access', 
            'extend_exp'=>'Extend Expiry',
            'btn_offline_dl' => 'Offline Downloads (All)', 
            'btn_offline_cp' => 'Offline Copy Btns (All)',
            'btn_offline_lnk' => 'Offline Direct Links (All)'
        ];

        $accordions = [
            'acc_actions' => 'Actions', 'acc_mt4' => 'MT4 Details', 'acc_vps' => 'VPS Details',
            'acc_install' => 'Install Software', 'acc_copy' => 'Copy Details', 'acc_pay' => 'Payment History',
            'acc_access'  => 'Access Automation', 'acc_offline' => 'Offline Section', 'acc_sop' => 'SOP Details'
        ];
        
        $def_tg = "Rd Algo Premium Telegram Channel Access Link:\n\nRD ALGO Batch 01: https://t.me/+RcI99RuWpu8zMzg9\nRD Algo Daily Calls: https://t.me/+4n3vMeqvHpc5MmY1";
        $def_course = "Hi {{student_name}},\n\nFor Rd Algo Pro or Pro+ Premium Video Access Visit This Link:\n\n[ https://learn.rdalgo.in/ ]\n\nUse Below Credentials For Login:\nUsername/Email: {{student_email}}\nPassword: {{student_phone}}\n\nThanks,\nRd Algo Support";
        $def_vps = "Hi RDA {{student_name}},\n\nFor installation on Windows, Android, iPhone/iPad (iOS), or Mac, follow the guide below:\n\n Access Course Page:\nhttps://learn.rdalgo.in/\n\n Login Details:\nUsername / Email: {{student_email}}\nPassword: {{student_phone}}\n\n1. For Windows System\nComputer / IP: {{vps_ip}}\nUsername: {{vps_user}}\nPassword: {{vps_pass}}\n\nThanks,\nRD Algo Support";

        // Helper for Payment Select
        $render_pay_select = function($field_key, $default) use ($options, $pay_purposes) {
            $val = $options[$field_key] ?? $default;
            $h = '<select name="rd_algo_settings['.$field_key.']" class="widefat">';
            $h .= '<option value="">-- Select Purpose --</option>';
            if($val && !in_array($val, $pay_purposes)) {
                $h .= '<option value="'.esc_attr($val).'" selected>'.esc_html($val).' (Saved/Custom)</option>';
            }
            foreach($pay_purposes as $p) {
                $sel = ($p == $val) ? 'selected' : '';
                $h .= '<option value="'.esc_attr($p).'" '.$sel.'>'.esc_html($p).'</option>';
            }
            $h .= '</select>';
            return $h;
        };

        ?>
        <style>
            .rd-admin-wrap { max-width: 1200px; margin: 20px 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            .rd-tab-nav { border-bottom: 1px solid #ccc; margin-bottom: 20px; }
            .rd-tab-nav a { display: inline-block; padding: 10px 20px; text-decoration: none; color: #555; background: #e5e5e5; margin-right: 5px; border-radius: 5px 5px 0 0; border: 1px solid #ccc; border-bottom: none; font-weight: 600; }
            .rd-tab-nav a.active { background: #fff; color: #000; border-bottom: 1px solid #fff; margin-bottom: -1px; }
            .rd-tab-content { display: none; background: #fff; border: 1px solid #ccc; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .rd-tab-content.active { display: block; }
            .rd-card { background: #f9f9f9; border: 1px solid #e1e1e1; padding: 15px; margin-bottom: 15px; border-radius: 4px; }
            .rd-card h3 { margin-top: 0; padding-bottom: 10px; border-bottom: 1px dashed #ccc; font-size: 14px; text-transform: uppercase; color: #444; }
            .rd-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
            .rd-grid-4 { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; }
            .rd-field-group { margin-bottom: 10px; }
            .rd-field-group label { display: block; font-size: 11px; font-weight: 700; color: #666; margin-bottom: 3px; }
            .rd-desc { font-size: 11px; color: #888; display: block; margin-top: 2px; font-style: italic; }
            .rd-perm-box { background: #fff; border: 1px solid #ddd; padding: 10px; font-size: 12px; }
            .rd-perm-box strong { display: block; margin-bottom: 8px; font-size: 13px; color: #2271b1; }
            .rd-perm-row { margin-bottom: 4px; }
            .rd-map-area { background: #fff; border: 1px solid #ddd; padding: 10px; margin-top: 10px; border-radius: 4px; display: none; }
            .rd-map-area.active { display: block; animation: fadeIn 0.3s; }
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        </style>
        
        <div class="wrap rd-admin-wrap">
            <h1 class="wp-heading-inline">RD Algo Configuration</h1>
            <hr class="wp-header-end">

            <form method="post" action="options.php">
                <?php settings_fields('rd_algo_group'); ?>

                <div class="rd-tab-nav">
                    <a href="#tab-db" class="active">Database & Mapping</a>
                    <a href="#tab-general">General & Logic</a>
                    <a href="#tab-automation">Automation (GF)</a>
                    <a href="#tab-content">Content & Offline</a>
                    <a href="#tab-perms">Permissions</a>
                </div>

                <div id="tab-db" class="rd-tab-content active">
                     <div class="rd-grid-2">
                        <div class="rd-card">
                            <h3>1. Student Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_student]', $options['tb_student']??'', $defaults['tb_student'], 'stu'); ?>
                            
                            <div id="map-stu" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>Name Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_name]', $options['col_stu_name']??'', $defaults['col_stu_name'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Email Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_email]', $options['col_stu_email']??'', $defaults['col_stu_email'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Phone Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_phone]', $options['col_stu_phone']??'', $defaults['col_stu_phone'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Expiry Date Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_expiry]', $options['col_stu_expiry']??'', $defaults['col_stu_expiry'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Software Type Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_soft]', $options['col_stu_soft']??'', $defaults['col_stu_soft'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>MT4 Ref ID Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_mt4]', $options['col_stu_mt4']??'', $defaults['col_stu_mt4'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>VPS Ref Host Column</label><?php echo $this->render_column_select('rd_algo_settings[col_stu_vps]', $options['col_stu_vps']??'', $defaults['col_stu_vps'], 'stu'); ?></div>
                                </div>
                                <hr style="margin: 10px 0; border: 0; border-top: 1px dashed #ddd;">
                                <strong>Level Columns (2, 3, 4):</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>Level 2 Status</label><?php echo $this->render_column_select('rd_algo_settings[col_l2_status]', $options['col_l2_status']??'', $defaults['col_l2_status'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Level 2 Date</label><?php echo $this->render_column_select('rd_algo_settings[col_l2_date]', $options['col_l2_date']??'', $defaults['col_l2_date'], 'stu'); ?></div>
                                    
                                    <div class="rd-field-group"><label>Level 3 Status</label><?php echo $this->render_column_select('rd_algo_settings[col_l3_status]', $options['col_l3_status']??'', $defaults['col_l3_status'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Level 3 Date</label><?php echo $this->render_column_select('rd_algo_settings[col_l3_date]', $options['col_l3_date']??'', $defaults['col_l3_date'], 'stu'); ?></div>
                                    
                                    <div class="rd-field-group"><label>Level 4 Status</label><?php echo $this->render_column_select('rd_algo_settings[col_l4_status]', $options['col_l4_status']??'', $defaults['col_l4_status'], 'stu'); ?></div>
                                    <div class="rd-field-group"><label>Level 4 Date</label><?php echo $this->render_column_select('rd_algo_settings[col_l4_date]', $options['col_l4_date']??'', $defaults['col_l4_date'], 'stu'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="rd-card">
                            <h3>2. MT4 Inventory Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_mt4]', $options['tb_mt4']??'', $defaults['tb_mt4'], 'mt4'); ?>
                            
                            <div id="map-mt4" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>Login ID (PK)</label><?php echo $this->render_column_select('rd_algo_settings[col_mt4_login]', $options['col_mt4_login']??'', $defaults['col_mt4_login'], 'mt4'); ?></div>
                                    <div class="rd-field-group"><label>Password</label><?php echo $this->render_column_select('rd_algo_settings[col_mt4_pass]', $options['col_mt4_pass']??'', $defaults['col_mt4_pass'], 'mt4'); ?></div>
                                    <div class="rd-field-group"><label>Server</label><?php echo $this->render_column_select('rd_algo_settings[col_mt4_server]', $options['col_mt4_server']??'', $defaults['col_mt4_server'], 'mt4'); ?></div>
                                    <div class="rd-field-group"><label>Expiry Date</label><?php echo $this->render_column_select('rd_algo_settings[col_mt4_expiry]', $options['col_mt4_expiry']??'', $defaults['col_mt4_expiry'], 'mt4'); ?></div>
                                    <div class="rd-field-group"><label>Status</label><?php echo $this->render_column_select('rd_algo_settings[col_mt4_status]', $options['col_mt4_status']??'', $defaults['col_mt4_status'], 'mt4'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="rd-card">
                            <h3>3. VPS Inventory Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_vps]', $options['tb_vps']??'', $defaults['tb_vps'], 'vps'); ?>
                            
                            <div id="map-vps" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>Host Name (PK)</label><?php echo $this->render_column_select('rd_algo_settings[col_vps_host]', $options['col_vps_host']??'', $defaults['col_vps_host'], 'vps'); ?></div>
                                    <div class="rd-field-group"><label>IP Address</label><?php echo $this->render_column_select('rd_algo_settings[col_vps_ip]', $options['col_vps_ip']??'', $defaults['col_vps_ip'], 'vps'); ?></div>
                                    <div class="rd-field-group"><label>User ID</label><?php echo $this->render_column_select('rd_algo_settings[col_vps_user]', $options['col_vps_user']??'', $defaults['col_vps_user'], 'vps'); ?></div>
                                    <div class="rd-field-group"><label>Password</label><?php echo $this->render_column_select('rd_algo_settings[col_vps_pass]', $options['col_vps_pass']??'', $defaults['col_vps_pass'], 'vps'); ?></div>
                                </div>
                            </div>
                        </div>
                        
                         <div class="rd-card">
                            <h3>4. Payment Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_payment]', $options['tb_payment']??'', $defaults['tb_payment'], 'pay'); ?>
                            
                            <div id="map-pay" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>ID Column (PK)</label><?php echo $this->render_column_select('rd_algo_settings[col_pay_id]', $options['col_pay_id']??'', $defaults['col_pay_id'], 'pay'); ?></div>
                                    <div class="rd-field-group"><label>Phone Column</label><?php echo $this->render_column_select('rd_algo_settings[col_pay_phone]', $options['col_pay_phone']??'', $defaults['col_pay_phone'], 'pay'); ?></div>
                                    <div class="rd-field-group"><label>Purpose Column</label><?php echo $this->render_column_select('rd_algo_settings[col_pay_purpose]', $options['col_pay_purpose']??'', $defaults['col_pay_purpose'], 'pay'); ?></div>
                                    <div class="rd-field-group"><label>Amount Column</label><?php echo $this->render_column_select('rd_algo_settings[col_pay_amount]', $options['col_pay_amount']??'', $defaults['col_pay_amount'], 'pay'); ?></div>
                                    <div class="rd-field-group"><label>Date Column</label><?php echo $this->render_column_select('rd_algo_settings[col_pay_date]', $options['col_pay_date']??'', $defaults['col_pay_date'], 'pay'); ?></div>
                                </div>
                            </div>
                        </div>

                         <div class="rd-card">
                            <h3>5. TG Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_tg]', $options['tb_tg']??'', $defaults['tb_tg'], 'tg'); ?>
                            
                            <div id="map-tg" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-grid-2">
                                    <div class="rd-field-group"><label>ID Column (PK)</label><?php echo $this->render_column_select('rd_algo_settings[col_tg_id]', $options['col_tg_id']??'', $defaults['col_tg_id'], 'tg'); ?></div>
                                    <div class="rd-field-group"><label>Mobile Column</label><?php echo $this->render_column_select('rd_algo_settings[col_tg_phone]', $options['col_tg_phone']??'', $defaults['col_tg_phone'], 'tg'); ?></div>
                                    <div class="rd-field-group"><label>Links Column</label><?php echo $this->render_column_select('rd_algo_settings[col_tg_links]', $options['col_tg_links']??'', $defaults['col_tg_links'], 'tg'); ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="rd-card">
                            <h3>6. VPS Staging Table</h3>
                            <?php echo $this->render_table_select('rd_algo_settings[tb_vps_staging]', $options['tb_vps_staging']??'', $defaults['tb_vps_staging'], 'stg'); ?>
                            
                            <div id="map-stg" class="rd-map-area">
                                <strong>Map Columns:</strong>
                                <div class="rd-field-group"><label>Primary Key</label><?php echo $this->render_column_select('rd_algo_settings[col_stg_id]', $options['col_stg_id']??'', $defaults['col_stg_id'], 'stg'); ?></div>
                            </div>
                        </div>

                    </div>
                </div>

                <div id="tab-general" class="rd-tab-content">
                    <div class="rd-grid-2">
                        <div class="rd-card">
                            <h3>Business Logic & Limits</h3>
                            <p class="description" style="margin-bottom:10px;">Control validation rules for payments and expiry.</p>
                            <table class="form-table" style="margin-top:0;">
                                <tr><th style="padding:5px 0;">Payment Validity</th><td><input type="number" name="rd_algo_settings[pay_hours]" value="<?php echo esc_attr($options['pay_hours']??48); ?>" class="small-text"> Hours <span class="rd-desc">Check last X hours.</span></td></tr>
                                <tr><th style="padding:5px 0;">Renewal Keyword</th><td><?php echo $render_pay_select('pay_renewal', 'Renewal'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Upgrade Keyword</th><td><?php echo $render_pay_select('pay_upgrade', 'Upgrade'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Downgrade Keyword</th><td><?php echo $render_pay_select('pay_downgrade', 'Downgrade'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Level 2 Keyword</th><td><?php echo $render_pay_select('pay_level2', 'Calls'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Level 3 Keyword</th><td><?php echo $render_pay_select('pay_level3', 'Level 3'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Level 4 Keyword</th><td><?php echo $render_pay_select('pay_level4', 'Level 4'); ?></td></tr>
                                <tr><th style="padding:5px 0;">Block Threshold</th><td><input type="number" name="rd_algo_settings[block_threshold]" value="<?php echo esc_attr($options['block_threshold']??30); ?>" class="small-text"> Days</td></tr>
                                <tr><th style="padding:5px 0;">Extend Days</th><td><input type="number" name="rd_algo_settings[extend_days]" value="<?php echo esc_attr($options['extend_days']??9); ?>" class="small-text"> Days</td></tr>
                            </table>
                        </div>
                        <div class="rd-card">
                            <h3>Stock Rules</h3>
                            <p>
                                MT4 Limit: <input type="number" name="rd_algo_settings[mt4_limit]" value="<?php echo esc_attr($options['mt4_limit']??1); ?>" class="tiny-text"> User per ID<br>
                                MT4 Min Expiry: <input type="number" name="rd_algo_settings[mt4_expiry_days]" value="<?php echo esc_attr($options['mt4_expiry_days']??25); ?>" class="tiny-text"> Days<br>
                                <span style="display:block; margin-top:5px;">MT4 Default Type (Button):</span>
                                <select name="rd_algo_settings[mt4_default_type]" style="width:100%;">
                                    <option value="1 Month" <?php selected($options['mt4_default_type']??'1 Month', '1 Month'); ?>>1 Month (Fallback)</option>
                                    <?php 
                                    $saved_def = $options['mt4_default_type'] ?? '';
                                    foreach($mt4_types as $type): 
                                        $sel = ($type == $saved_def) ? 'selected' : '';
                                    ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php echo $sel; ?>><?php echo esc_html($type); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </p>
                            <hr>
                            <label><input type="checkbox" name="rd_algo_settings[mt4_only_renew]" <?php checked(1, $options['mt4_only_renew']??0); ?> value="1"> Force Renew (Hide Assign)</label><br>
                            <label><input type="checkbox" name="rd_algo_settings[mt4_only_assign]" <?php checked(1, $options['mt4_only_assign']??0); ?> value="1"> Force Assign (Hide Renew)</label>
                            <hr style="margin:10px 0;">
                            <p>
                                VPS Limit: <input type="number" name="rd_algo_settings[vps_limit]" value="<?php echo esc_attr($options['vps_limit']??1); ?>" class="tiny-text"> User per Host<br>
                                VPS Min Expiry: <input type="number" name="rd_algo_settings[vps_expiry_days]" value="<?php echo esc_attr($options['vps_expiry_days']??25); ?>" class="tiny-text"> Days
                            </p>
                            <hr>
                            <label><input type="checkbox" name="rd_algo_settings[vps_only_renew]" <?php checked(1, $options['vps_only_renew']??0); ?> value="1"> Force Renew (Hide Assign)</label><br>
                            <label><input type="checkbox" name="rd_algo_settings[vps_only_assign]" <?php checked(1, $options['vps_only_assign']??0); ?> value="1"> Force Assign (Hide Renew)</label>
                        </div>
                    </div>
                </div>

                <div id="tab-automation" class="rd-tab-content">
                    <div style="background:#eef; border:1px solid #ccd; padding:10px; margin-bottom:15px;">
                        <strong>Instructions:</strong> Select a Form to auto-populate fields.
                    </div>
                    <div class="rd-card" style="border-left: 4px solid #2271b1;">
                        <h3>Agent Action Forms (Remote Assign)</h3>
                        <div class="rd-grid-2">
                            <div>
                                <strong>Assign MT4 Form</strong><br>
                                <?php echo $this->render_form_select('rd_algo_settings[agent_mt4_form_id]', $options['agent_mt4_form_id']??'', 'grp_ag_mt4'); ?>
                                <div class="rd-field-group" style="margin-top:5px;"><label>Phone Field</label><?php echo $this->render_field_select('rd_algo_settings[agent_mt4_phone_id]', $options['agent_mt4_phone_id']??'', 'grp_ag_mt4'); ?></div>
                                <div class="rd-field-group" style="margin-top:5px;">
                                    <label>MT4 Default Type (Agent Only)</label>
                                    <select name="rd_algo_settings[agent_mt4_type]" class="widefat">
                                        <option value="">-- Use Global Default --</option>
                                        <?php 
                                        $saved_type = $options['agent_mt4_type'] ?? '';
                                        foreach($mt4_types as $type): 
                                            $sel = ($type == $saved_type) ? 'selected' : '';
                                        ?>
                                        <option value="<?php echo esc_attr($type); ?>" <?php echo $sel; ?>><?php echo esc_html($type); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div>
                                <strong>Assign VPS Form</strong><br>
                                <?php echo $this->render_form_select('rd_algo_settings[agent_vps_form_id]', $options['agent_vps_form_id']??'', 'grp_ag_vps'); ?>
                                <div class="rd-field-group" style="margin-top:5px;"><label>Phone Field</label><?php echo $this->render_field_select('rd_algo_settings[agent_vps_phone_id]', $options['agent_vps_phone_id']??'', 'grp_ag_vps'); ?></div>
                            </div>
                        </div>
                    </div>

                    <h3>Service Automation Maps (Data Push)</h3>
                    <div class="rd-grid-2">
                        <div class="rd-card">
                            <strong>MT4 Assign</strong><br>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_mt4_ids]', $options['gf_mt4_ids']??'', 'grp_mt4'); ?>
                            <div class="rd-grid-2" style="margin-top:10px;">
                                <div class="rd-field-group"><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_name]', $options['gf_mt4_map_name']??'', 'grp_mt4'); ?></div>
                                <div class="rd-field-group"><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_email]', $options['gf_mt4_map_email']??'', 'grp_mt4'); ?></div>
                                <div class="rd-field-group"><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_phone]', $options['gf_mt4_map_phone']??'', 'grp_mt4'); ?></div>
                                <div class="rd-field-group"><label>Login ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_login]', $options['gf_mt4_map_login']??'', 'grp_mt4'); ?></div>
                                <div class="rd-field-group"><label>Pass ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_pass]', $options['gf_mt4_map_pass']??'', 'grp_mt4'); ?></div>
                                <div class="rd-field-group"><label>Server ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_mt4_map_server]', $options['gf_mt4_map_server']??'', 'grp_mt4'); ?></div>
                            </div>
                        </div>
                        <div class="rd-card">
                            <strong>VPS Assign</strong><br>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_vps_ids]', $options['gf_vps_ids']??'', 'grp_vps'); ?>
                            <div class="rd-grid-2" style="margin-top:10px;">
                                <div class="rd-field-group"><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_name]', $options['gf_vps_map_name']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_email]', $options['gf_vps_map_email']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_phone]', $options['gf_vps_map_phone']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>Host ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_host]', $options['gf_vps_map_host']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>IP ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_ip]', $options['gf_vps_map_ip']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>User ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_user]', $options['gf_vps_map_user']??'', 'grp_vps'); ?></div>
                                <div class="rd-field-group"><label>Pass ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_vps_map_pass]', $options['gf_vps_map_pass']??'', 'grp_vps'); ?></div>
                            </div>
                        </div>
                        <div class="rd-card">
                            <strong>MT4 Renewal</strong><br>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_renew_mt4_ids]', $options['gf_renew_mt4_ids']??'', 'grp_rn_mt4'); ?>
                            <div class="rd-grid-2" style="margin-top:10px;">
                                <div class="rd-field-group"><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_name]', $options['gf_renew_mt4_map_name']??'', 'grp_rn_mt4'); ?></div>
                                <div class="rd-field-group"><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_email]', $options['gf_renew_mt4_map_email']??'', 'grp_rn_mt4'); ?></div>
                                <div class="rd-field-group"><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_phone]', $options['gf_renew_mt4_map_phone']??'', 'grp_rn_mt4'); ?></div>
                                <div class="rd-field-group"><label>Login ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_login]', $options['gf_renew_mt4_map_login']??'', 'grp_rn_mt4'); ?></div>
                                <div class="rd-field-group"><label>Pass ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_pass]', $options['gf_renew_mt4_map_pass']??'', 'grp_rn_mt4'); ?></div>
                                <div class="rd-field-group"><label>Server ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_mt4_map_server]', $options['gf_renew_mt4_map_server']??'', 'grp_rn_mt4'); ?></div>
                            </div>
                        </div>
                        <div class="rd-card">
                            <strong>VPS Renewal</strong><br>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_renew_vps_ids]', $options['gf_renew_vps_ids']??'', 'grp_rn_vps'); ?>
                            <div class="rd-grid-2" style="margin-top:10px;">
                                <div class="rd-field-group"><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_name]', $options['gf_renew_vps_map_name']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_email]', $options['gf_renew_vps_map_email']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_phone]', $options['gf_renew_vps_map_phone']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>Host ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_host]', $options['gf_renew_vps_map_host']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>IP ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_ip]', $options['gf_renew_vps_map_ip']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>User ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_user]', $options['gf_renew_vps_map_user']??'', 'grp_rn_vps'); ?></div>
                                <div class="rd-field-group"><label>Pass ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_vps_map_pass]', $options['gf_renew_vps_map_pass']??'', 'grp_rn_vps'); ?></div>
                            </div>
                        </div>
                    </div>

                    <h3>Account Actions & Access</h3>
                    <div class="rd-grid-4">
                        <div class="rd-card"><strong>Renewal</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_renew_ids]', $options['gf_renew_ids']??'', 'grp_act_rn'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_map_name]', $options['gf_renew_map_name']??'', 'grp_act_rn'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_map_email]', $options['gf_renew_map_email']??'', 'grp_act_rn'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_renew_map_phone]', $options['gf_renew_map_phone']??'', 'grp_act_rn'); ?></div>
                        <div class="rd-card"><strong>Upgrade</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_upg_ids]', $options['gf_upg_ids']??'', 'grp_act_up'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_upg_map_name]', $options['gf_upg_map_name']??'', 'grp_act_up'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_upg_map_email]', $options['gf_upg_map_email']??'', 'grp_act_up'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_upg_map_phone]', $options['gf_upg_map_phone']??'', 'grp_act_up'); ?></div>
                        <div class="rd-card"><strong>Downgrade</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_dwn_ids]', $options['gf_dwn_ids']??'', 'grp_act_dn'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_dwn_map_name]', $options['gf_dwn_map_name']??'', 'grp_act_dn'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_dwn_map_email]', $options['gf_dwn_map_email']??'', 'grp_act_dn'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_dwn_map_phone]', $options['gf_dwn_map_phone']??'', 'grp_act_dn'); ?></div>
                        
                        <div class="rd-card"><strong>Level 2</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_l2_ids]', $options['gf_l2_ids']??'', 'grp_act_l2'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l2_map_name]', $options['gf_l2_map_name']??'', 'grp_act_l2'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l2_map_email]', $options['gf_l2_map_email']??'', 'grp_act_l2'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l2_map_phone]', $options['gf_l2_map_phone']??'', 'grp_act_l2'); ?></div>
                        <div class="rd-card"><strong>Level 3</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_l3_ids]', $options['gf_l3_ids']??'', 'grp_act_l3'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l3_map_name]', $options['gf_l3_map_name']??'', 'grp_act_l3'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l3_map_email]', $options['gf_l3_map_email']??'', 'grp_act_l3'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l3_map_phone]', $options['gf_l3_map_phone']??'', 'grp_act_l3'); ?></div>
                        <div class="rd-card"><strong>Level 4</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_l4_ids]', $options['gf_l4_ids']??'', 'grp_act_l4'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l4_map_name]', $options['gf_l4_map_name']??'', 'grp_act_l4'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l4_map_email]', $options['gf_l4_map_email']??'', 'grp_act_l4'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_l4_map_phone]', $options['gf_l4_map_phone']??'', 'grp_act_l4'); ?></div>

                        <div class="rd-card"><strong>TG Auto</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_auto_tg_ids]', $options['gf_auto_tg_ids']??'', 'grp_acc_tg'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_tg_map_name]', $options['gf_auto_tg_map_name']??'', 'grp_acc_tg'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_tg_map_email]', $options['gf_auto_tg_map_email']??'', 'grp_acc_tg'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_tg_map_phone]', $options['gf_auto_tg_map_phone']??'', 'grp_acc_tg'); ?></div>
                        <div class="rd-card"><strong>Course Auto</strong><hr>Form: <?php echo $this->render_form_select('rd_algo_settings[gf_auto_course_ids]', $options['gf_auto_course_ids']??'', 'grp_acc_cr'); ?><br><label>Name ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_course_map_name]', $options['gf_auto_course_map_name']??'', 'grp_acc_cr'); ?><label>Email ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_course_map_email]', $options['gf_auto_course_map_email']??'', 'grp_acc_cr'); ?><label>Phone ID</label><?php echo $this->render_field_select('rd_algo_settings[gf_auto_course_map_phone]', $options['gf_auto_course_map_phone']??'', 'grp_acc_cr'); ?></div>
                    </div>
                </div>

                <div id="tab-content" class="rd-tab-content">
                    <div class="rd-card">
                        <h3>Configuration</h3>
                        <table class="form-table">
                            <tr><th>Download Slug</th><td><input type="text" name="rd_algo_settings[download_slug]" value="<?php echo esc_attr($options['download_slug']??'rd-install'); ?>" class="regular-text"></td></tr>
                            <tr><th>MT4 ZIP URL</th><td><input type="text" name="rd_algo_settings[mt4_zip_url]" value="<?php echo esc_attr($options['mt4_zip_url']??''); ?>" class="large-text"></td></tr>
                        </table>
                    </div>
                    <div class="rd-grid-2">
                        <div class="rd-card">
                            <h3>Email/Text Templates</h3>
                            <div class="rd-field-group"><label>Telegram Access Template</label><textarea name="rd_algo_settings[tpl_tg_access]" rows="3" class="large-text"><?php echo esc_textarea($options['tpl_tg_access']??$def_tg); ?></textarea></div>
                            <div class="rd-field-group"><label>Course Access Template</label><textarea name="rd_algo_settings[tpl_course_access]" rows="3" class="large-text"><?php echo esc_textarea($options['tpl_course_access']??$def_course); ?></textarea></div>
                            <div class="rd-field-group"><label>VPS Guide Template</label><textarea name="rd_algo_settings[tpl_vps_guide]" rows="3" class="large-text"><?php echo esc_textarea($options['tpl_vps_guide']??$def_vps); ?></textarea></div>
                        </div>
                        <div class="rd-card">
                            <h3>SOP Content</h3>
                            <?php wp_editor($options['sop_content'] ?? '', 'sop_editor', ['textarea_name'=>'rd_algo_settings[sop_content]', 'media_buttons'=>false, 'textarea_rows'=>15, 'teeny'=>true]); ?>
                        </div>
                    </div>
                    <h3>Offline Section Buttons</h3>
                    <div class="rd-card">
                        <h4 style="margin:0 0 10px;">1. Force Download Buttons</h4>
                        <div class="rd-grid-2">
                            <?php for($i=1;$i<=4;$i++): ?><div style="background:#fff; border:1px solid #eee; padding:5px;"><strong>Btn <?php echo $i; ?></strong><br>Name: <input type="text" name="rd_algo_settings[off_btn_<?php echo $i; ?>_name]" value="<?php echo esc_attr($options["off_btn_{$i}_name"]??''); ?>" style="width:100%"><br>URL: <input type="text" name="rd_algo_settings[off_btn_<?php echo $i; ?>_url]" value="<?php echo esc_attr($options["off_btn_{$i}_url"]??''); ?>" style="width:100%"></div><?php endfor; ?>
                        </div>
                        <h4 style="margin:15px 0 10px; border-top:1px dashed #ccc; padding-top:10px;">2. Direct Links</h4>
                        <div class="rd-grid-2">
                            <?php for($i=1;$i<=4;$i++): ?><div style="background:#fff; border:1px solid #eee; padding:5px;"><strong>Link <?php echo $i; ?></strong><br>Name: <input type="text" name="rd_algo_settings[off_link_btn_<?php echo $i; ?>_name]" value="<?php echo esc_attr($options["off_link_btn_{$i}_name"]??''); ?>" style="width:100%"><br>URL: <input type="text" name="rd_algo_settings[off_link_btn_<?php echo $i; ?>_url]" value="<?php echo esc_attr($options["off_link_btn_{$i}_url"]??''); ?>" style="width:100%"></div><?php endfor; ?>
                        </div>
                        <h4 style="margin:15px 0 10px; border-top:1px dashed #ccc; padding-top:10px;">3. Copy Buttons</h4>
                        <div class="rd-grid-2">
                            <?php for($i=1;$i<=4;$i++): ?><div style="background:#fff; border:1px solid #eee; padding:5px;"><strong>Copy Btn <?php echo $i; ?></strong><br>Name: <input type="text" name="rd_algo_settings[off_copy_btn_<?php echo $i; ?>_name]" value="<?php echo esc_attr($options["off_copy_btn_{$i}_name"]??''); ?>" style="width:100%"><br>Text:<br><textarea name="rd_algo_settings[off_copy_btn_<?php echo $i; ?>_text]" rows="2" style="width:100%"><?php echo esc_textarea($options["off_copy_btn_{$i}_text"]??''); ?></textarea></div><?php endfor; ?>
                        </div>
                    </div>
                </div>

                <div id="tab-perms" class="rd-tab-content">
                     <div class="rd-grid-2" style="margin-bottom:20px;">
                        <div class="rd-card">
                            <strong>Edit Primary Data</strong><br>
                            <div style="column-count:2;"><?php foreach($wp_roles->roles as $slug => $role): $chk = isset($options['roles_primary'][$slug]) ? 'checked' : ''; ?><label style="display:block;"><input type="checkbox" name="rd_algo_settings[roles_primary][<?php echo $slug; ?>]" <?php echo $chk; ?>> <?php echo $role['name']; ?></label><?php endforeach; ?></div>
                        </div>
                        <div class="rd-card">
                            <strong>Edit Secondary Data</strong><br>
                            <div style="column-count:2;"><?php foreach($wp_roles->roles as $slug => $role): $chk = isset($options['roles_secondary'][$slug]) ? 'checked' : ''; ?><label style="display:block;"><input type="checkbox" name="rd_algo_settings[roles_secondary][<?php echo $slug; ?>]" <?php echo $chk; ?>> <?php echo $role['name']; ?></label><?php endforeach; ?></div>
                        </div>
                    </div>
                    <h3>Action Permissions</h3>
                    <div class="rd-grid-4">
                        <?php foreach($buttons as $key => $label): ?>
                        <div class="rd-perm-box"><strong><?php echo $label; ?></strong>
                            <?php foreach($wp_roles->roles as $slug => $role): $chk = isset($options['btn_perms'][$key][$slug]) ? 'checked' : ''; ?><div class="rd-perm-row"><label><input type="checkbox" name="rd_algo_settings[btn_perms][<?php echo $key; ?>][<?php echo $slug; ?>]" <?php echo $chk; ?>> <?php echo $role['name']; ?></label></div><?php endforeach; ?>
                            <div style="border-top:1px solid #eee; margin-top:5px; padding-top:5px;"><label style="color:#d63638;"><input type="checkbox" name="rd_algo_settings[block_actions][<?php echo $key; ?>]" <?php echo isset($options['block_actions'][$key]) ? 'checked' : ''; ?>> Block Low Expiry</label></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <h3 style="margin-top:20px;">Accordion Visibility</h3>
                    <div class="rd-grid-4">
                        <?php foreach($accordions as $key => $label): ?>
                        <div class="rd-perm-box" style="background:#f0f6fc;"><strong><?php echo $label; ?></strong>
                            <?php foreach($wp_roles->roles as $slug => $role): $chk = isset($options['acc_perms'][$key][$slug]) ? 'checked' : ''; ?><div class="rd-perm-row"><label><input type="checkbox" name="rd_algo_settings[acc_perms][<?php echo $key; ?>][<?php echo $slug; ?>]" <?php echo $chk; ?>> <?php echo $role['name']; ?></label></div><?php endforeach; ?>
                            <div style="border-top:1px solid #eee; margin-top:5px; padding-top:5px;"><label style="color:#d63638;"><input type="checkbox" name="rd_algo_settings[block_accs][<?php echo $key; ?>]" <?php echo isset($options['block_accs'][$key]) ? 'checked' : ''; ?>> Block Low Expiry</label></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <?php submit_button('Save Settings', 'primary large', 'submit', true, ['style' => 'position:fixed; top:32px; right:20px; z-index:9999;']); ?>
            </form>
        </div>

        <script>
            var rd_gf_data = <?php echo json_encode($gf_data); ?>;
            jQuery(document).ready(function($) {
                // Tab Nav
                $('.rd-tab-nav a').click(function(e) {
                    e.preventDefault();
                    $('.rd-tab-nav a').removeClass('active'); $(this).addClass('active');
                    $('.rd-tab-content').removeClass('active'); $($(this).attr('href')).addClass('active');
                });

                // 1. GF Fields Population
                function populateGF(formSelect) {
                    var groupId = formSelect.data('group'); var formId = formSelect.val();
                    var fields = (rd_gf_data[formId] && rd_gf_data[formId].fields) ? rd_gf_data[formId].fields : [];
                    $('.rd-gf-field-selector[data-group="' + groupId + '"]').each(function() {
                        var fieldSelect = $(this); var savedVal = fieldSelect.data('value');
                        var html = '<option value="">-- Select Field --</option>';
                        fields.forEach(function(f) {
                            var selected = (String(f.id) === String(savedVal)) ? 'selected' : '';
                            html += '<option value="' + f.id + '" ' + selected + '>' + f.label + '</option>';
                        });
                        if(fields.length === 0 && formId) fieldSelect.html('<option value="">No fields found</option>');
                        else if (!formId) fieldSelect.html('<option value="">-- Select Form First --</option>');
                        else fieldSelect.html(html);
                    });
                }
                $('.rd-gf-form-selector').each(function() { populateGF($(this)); });
                $(document).on('change', '.rd-gf-form-selector', function() { populateGF($(this)); });

                // 2. DB Column Population
                function fetchColumns(tableSelect) {
                    var group = tableSelect.data('group'); var tableName = tableSelect.val(); var mapArea = $('#map-' + group);
                    if(!tableName) { mapArea.removeClass('active'); return; }
                    mapArea.addClass('active');
                    $('.rd-db-column-selector[data-group="'+group+'"]').each(function(){ $(this).prop('disabled', true).html('<option>Loading...</option>'); });

                    $.post(ajaxurl, {
                        action: 'rd_get_table_columns', table: tableName, nonce: '<?php echo wp_create_nonce('rd_algo_nonce'); ?>'
                    }, function(res) {
                        if(res.success) {
                            var cols = res.data;
                            $('.rd-db-column-selector[data-group="'+group+'"]').each(function(){
                                var selector = $(this); var savedVal = selector.data('value');
                                var html = '<option value="">-- Select Column --</option>';
                                cols.forEach(function(c) {
                                    var isSel = (c === savedVal) ? 'selected' : '';
                                    html += '<option value="'+c+'" '+isSel+'>'+c+'</option>';
                                });
                                selector.html(html).prop('disabled', false);
                            });
                        } else { alert('Error: ' + res.data); }
                    });
                }
                $('.rd-db-table-selector').each(function() { if($(this).val()) fetchColumns($(this)); });
                $(document).on('change', '.rd-db-table-selector', function() { fetchColumns($(this)); });
            });
        </script>
        <?php
    }
}
?>