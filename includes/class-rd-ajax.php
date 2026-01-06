<?php
class RD_Algo_Ajax {
    private $db;
    private $opts;

    public function __construct() {
        $this->db = new RD_Algo_DB();
        $this->opts = get_option('rd_algo_settings');
        
        // Hooks
        add_action('wp_ajax_rd_fetch_counters', [$this, 'fetch_counters']);
        add_action('wp_ajax_rd_search_student', [$this, 'search_student']);
        add_action('wp_ajax_rd_get_single_student', [$this, 'get_single_student']);
        add_action('wp_ajax_rd_update_student_profile', [$this, 'update_student_profile']);
        add_action('wp_ajax_rd_perform_action', [$this, 'perform_action']);
        add_action('wp_ajax_rd_generate_bat', [$this, 'generate_bat']);
        add_action('wp_ajax_rd_get_datetypes', [$this, 'get_datetypes']);
        add_action('wp_ajax_rd_add_new_student', [$this, 'add_new_student']);
        add_action('wp_ajax_rd_get_form_options', [$this, 'get_form_options']);
        add_action('wp_ajax_rd_get_table_columns', [$this, 'get_table_columns']);
    }

    public function get_table_columns() {
        check_ajax_referer('rd_algo_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        global $wpdb;
        $table = sanitize_text_field($_POST['table']);
        if (empty($table)) wp_send_json_error('No table provided');
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if (!$exists) wp_send_json_error('Table not found');
        $columns = $wpdb->get_col("DESCRIBE $table");
        wp_send_json_success($columns);
    }

    public function fetch_counters() {
        check_ajax_referer('rd_algo_nonce', 'nonce');
        wp_send_json_success($this->db->get_counters());
    }

    private function has_role_permission($setting_key, $sub_key = null) {
        $user = wp_get_current_user();
        if (empty($user->roles)) return false;
        $config = $this->opts[$setting_key] ?? [];
        if ($sub_key) {
             if (!is_array($config) || !isset($config[$sub_key])) return false;
             $config = $config[$sub_key];
        }
        foreach ($user->roles as $role) if (!empty($config[$role])) return true;
        return false;
    }

    public function search_student() {
        check_ajax_referer('rd_algo_nonce', 'nonce');
        $results = $this->db->search_student(sanitize_text_field($_POST['term']));
        $final = []; foreach($results as $s) $final[] = $this->prepare_student($s)['stu'];
        $dummy = $this->prepare_student((object)['student_phone'=>'']);
        wp_send_json_success(['students' => $final, 'global_perms' => $dummy['perms']]);
    }

    public function get_single_student() {
        check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb; $id=intval($_POST['id']);
        $s=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->opts['tb_student']} WHERE id=%d",$id));
        if(!$s) wp_send_json_error('Not found');
        wp_send_json_success($this->prepare_student($s));
    }

    public function add_new_student() {
        check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb;
        if (!$this->has_role_permission('btn_perms', 'add_student')) wp_send_json_error('Permission Denied.');
        $phone = sanitize_text_field($_POST['student_phone']);
        if (!preg_match('/^\d{10}$/', $phone)) wp_send_json_error('Phone must be 10 digits.');
        $entry_date = sanitize_text_field($_POST['date_created']) ?: date('Y-m-d');
        $expiry_date = sanitize_text_field($_POST['student_expiry_date']);
        if (empty($expiry_date)) $expiry_date = date('Y-m-d', strtotime('+1 year', strtotime($entry_date)));
        $data = ['entry_date' => $entry_date, 'software_type' => sanitize_text_field($_POST['software_type']), 'student_name' => sanitize_text_field($_POST['student_name']), 'student_email' => sanitize_email($_POST['student_email']), 'student_phone' => $phone, 'state' => sanitize_text_field($_POST['state']), 'student_expiry_date' => $expiry_date, 'status' => 'active'];
        if ($wpdb->insert($this->opts['tb_student'], $data)) wp_send_json_success('Student Added'); else wp_send_json_error('DB Error');
    }

    public function get_form_options() {
        check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb; $table = $this->opts['tb_student'];
        $types = $wpdb->get_col("SELECT DISTINCT software_type FROM $table WHERE software_type != '' ORDER BY software_type ASC");
        $states = $wpdb->get_col("SELECT DISTINCT state FROM $table WHERE state != '' ORDER BY state ASC");
        wp_send_json_success(['types' => $types, 'states' => $states]);
    }

    private function prepare_student($student) {
        global $wpdb;
        // MT4 Setup
        $t_mt4 = $this->opts['tb_mt4'] ?? 'wp_mt4_user_records';
        $c_mt4_pk = $this->opts['col_mt4_login'] ?? 'mt4userid';
        $c_mt4_exp = $this->opts['col_mt4_expiry'] ?? 'mt4expirydate';
        
        // VPS Setup
        $t_vps = $this->opts['tb_vps'] ?? 'wp_vps_records';
        $c_vps_pk = $this->opts['col_vps_host'] ?? 'host_name';
        $c_vps_exp = $this->opts['col_vps_expiry'] ?? 'vps_expier';
        
        // Payment Setup
        $t_pay = $this->opts['tb_payment'] ?? 'wp_customer_payment_history';
        $c_pay_ph = $this->opts['col_pay_phone'] ?? 'student_phone';
        $c_pay_id = $this->opts['col_pay_id'] ?? 'id';

        // --- DYNAMIC LEVEL COLUMNS MAPPING ---
        // Map the custom DB columns (configured in settings) to the standard keys expected by the frontend (JS)
        $c_l2_s = $this->opts['col_l2_status'] ?? 'level_2_status';
        $c_l2_d = $this->opts['col_l2_date']   ?? 'level_2_join_date';
        $c_l3_s = $this->opts['col_l3_status'] ?? 'level_3_status';
        $c_l3_d = $this->opts['col_l3_date']   ?? 'level_3_join_date';
        $c_l4_s = $this->opts['col_l4_status'] ?? 'level_4_status';
        $c_l4_d = $this->opts['col_l4_date']   ?? 'level_4_join_date';

        // Assign to standard properties used by script.js
        $student->level_2_status    = $student->$c_l2_s ?? null;
        $student->level_2_join_date = $student->$c_l2_d ?? null;
        $student->level_3_status    = $student->$c_l3_s ?? null;
        $student->level_3_join_date = $student->$c_l3_d ?? null;
        $student->level_4_status    = $student->$c_l4_s ?? null;
        $student->level_4_join_date = $student->$c_l4_d ?? null;


        $student->mt4_data = null; $student->mt4_is_expired = false; 
        $mt4_status = '<span style="color:#999; font-size:12px; margin-left:8px;">(Not Assigned)</span>';
        
        if(!empty($student->mt4_server_id)) {
            $m = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_mt4 WHERE $c_mt4_pk = %s", $student->mt4_server_id));
            if($m) { 
                $student->mt4_data = $m; 
                if(!empty($m->$c_mt4_exp)) { 
                    $d=ceil((strtotime($m->$c_mt4_exp)-time())/86400); 
                    if($d>=0)$mt4_status='<span class="rd-success" style="font-size:12px; margin-left:8px;">(Expires in '.$d.' days)</span>'; 
                    else { $student->mt4_is_expired=true; $mt4_status='<span class="rd-err" style="font-size:12px; margin-left:8px;">(Expired '.abs($d).' days ago)</span>'; } 
                } 
            }
        } 
        $student->mt4_status_display = $mt4_status;

        $student->vps_data = null; $student->vps_is_expired = false; 
        $vps_status = '<span style="color:#999; font-size:12px; margin-left:8px;">(Not Assigned)</span>';
        
        if(!empty($student->vps_host_name)) {
            $v = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_vps WHERE $c_vps_pk = %s", $student->vps_host_name));
            if($v) { 
                $student->vps_data = $v; 
                if(!empty($v->$c_vps_exp)) { 
                    $d=ceil((strtotime($v->$c_vps_exp)-time())/86400); 
                    if($d>=0)$vps_status='<span class="rd-success" style="font-size:12px; margin-left:8px;">(Expires in '.$d.' days)</span>'; 
                    else { $student->vps_is_expired=true; $vps_status='<span class="rd-err" style="font-size:12px; margin-left:8px;">(Expired '.abs($d).' days ago)</span>'; } 
                } 
            }
        } 
        $student->vps_status_display = $vps_status;

        $cl = preg_replace('/[^0-9]/', '', $student->student_phone); 
        $ph = (strlen($cl) > 10) ? substr($cl, -10) : $cl;
        $student->payment_history = $wpdb->get_results($wpdb->prepare("SELECT * FROM $t_pay WHERE replace($c_pay_ph,' ','') LIKE %s ORDER BY $c_pay_id DESC", "%$ph%"));
        
        $perms = ['can_edit_primary'=>$this->has_role_permission('roles_primary'), 'can_edit_secondary'=>$this->has_role_permission('roles_secondary'), 'buttons'=>[], 'accordions'=>[], 'can_add_student'=>$this->has_role_permission('btn_perms', 'add_student')];
        $btns = [
            'renewal','upgrade','downgrade',
            'assign_mt4','assign_vps','renew_mt4','renew_vps','remove_mt4','remove_vps',
            'copy_tg','copy_course','copy_vps_guide',
            'auto_tg','auto_course',
            'level_2_access', 'level_3_access', 'level_4_access', // Added Levels
            'extend_exp','add_student',
            'btn_offline_dl',
            'btn_offline_cp',
            'btn_offline_lnk'
        ];
        foreach($btns as $b) { if($this->has_role_permission('btn_perms', $b)) $perms['buttons'][] = $b; }
        
        if( !empty($student->id) && empty($student->student_email) ) {
            $perms['buttons'] = []; 
            $perms['can_edit_primary'] = false; 
            $perms['can_edit_secondary'] = false; 
        }

        $is_expiry_low = false;
        $student->expiry_display = "No Date";
        if(!empty($student->student_expiry_date)) {
            $diff = ceil((strtotime($student->student_expiry_date) - time()) / 86400);
            $student->expiry_display = ($diff < 0) ? "Expired (In $diff days)" : "Expires in $diff days ($student->student_expiry_date)";
            if($diff < intval($this->opts['block_threshold']??30)) $is_expiry_low = true;
        }
        $accs = ['acc_actions', 'acc_mt4', 'acc_vps', 'acc_install', 'acc_copy', 'acc_pay', 'acc_access', 'acc_offline', 'acc_sop'];
        foreach($accs as $acc) {
            $allowed = $this->has_role_permission('acc_perms', $acc);
            $blocked = !empty($this->opts['block_accs'][$acc]) && $is_expiry_low;
            $perms['accordions'][$acc] = ($allowed && !$blocked);
        }

        // --- NEW: Dynamic Labels for L2, L3, L4 based on Payment Purposes ---
        $labels = [
            'l2' => !empty($this->opts['pay_level2']) ? $this->opts['pay_level2'] : 'Level 2',
            'l3' => !empty($this->opts['pay_level3']) ? $this->opts['pay_level3'] : 'Level 3',
            'l4' => !empty($this->opts['pay_level4']) ? $this->opts['pay_level4'] : 'Level 4',
            'renew' => 'Renewal',
            'upg' => 'Upgrade',
            'dwn' => 'Downgrade'
        ];

        return ['stu' => $student, 'perms' => $perms, 'labels' => $labels];
    }

    public function update_student_profile() { check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb; $id=intval($_POST['id']); $data=$_POST['data']; $updates=[]; if($this->has_role_permission('roles_primary')){ if(isset($data['student_name']))$updates['student_name']=sanitize_text_field($data['student_name']); if(isset($data['student_email']))$updates['student_email']=sanitize_email($data['student_email']); if(isset($data['student_phone']))$updates['student_phone']=sanitize_text_field($data['student_phone']); if(isset($data['student_expiry_date']))$updates['student_expiry_date']=sanitize_text_field($data['student_expiry_date']); } if($this->has_role_permission('roles_secondary')){ if(isset($data['student_phone_alt']))$updates['student_phone_alt']=sanitize_text_field($data['student_phone_alt']); if(isset($data['anydesk_id']))$updates['anydesk_id']=sanitize_text_field($data['anydesk_id']); } if(!empty($updates)){ $wpdb->update($this->opts['tb_student'], $updates, ['id'=>$id]); wp_send_json_success("Updated"); } else wp_send_json_error("No Changes"); }
    public function get_datetypes() { check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb; $types = $wpdb->get_col("SELECT DISTINCT datetype FROM {$this->opts['tb_mt4']} WHERE datetype != ''"); wp_send_json_success(['types' => array_values(array_unique(array_map('trim', $types))), 'default' => $this->opts['mt4_default_type'] ?? '1 Month']); }
    
    public function trigger_gf_automation($type, $student, $extra = []) {
        $this->process_gf_automation($type, $student, $extra);
    }

    private function process_gf_automation($type, $student, $extra = []) {
        if(!class_exists('GFAPI')) return; $config = [];
        
        // --- SEPARATED CONFIGURATIONS ---
        switch($type) {
            case 'auto_tg': $config=['ids'=>'gf_auto_tg_ids','n'=>'gf_auto_tg_map_name','e'=>'gf_auto_tg_map_email','p'=>'gf_auto_tg_map_phone']; break;
            case 'auto_course': $config=['ids'=>'gf_auto_course_ids','n'=>'gf_auto_course_map_name','e'=>'gf_auto_course_map_email','p'=>'gf_auto_course_map_phone']; break;
            case 'mt4': $config=['ids'=>'gf_mt4_ids','n'=>'gf_mt4_map_name','e'=>'gf_mt4_map_email','p'=>'gf_mt4_map_phone','login'=>'gf_mt4_map_login','pass'=>'gf_mt4_map_pass','server'=>'gf_mt4_map_server']; break;
            case 'vps': $config=['ids'=>'gf_vps_ids','n'=>'gf_vps_map_name','e'=>'gf_vps_map_email','p'=>'gf_vps_map_phone','host'=>'gf_vps_map_host','ip'=>'gf_vps_map_ip','u'=>'gf_vps_map_user','pass'=>'gf_vps_map_pass']; break;
            case 'renew_mt4': $config=['ids'=>'gf_renew_mt4_ids','n'=>'gf_renew_mt4_map_name','e'=>'gf_renew_mt4_map_email','p'=>'gf_renew_mt4_map_phone','login'=>'gf_renew_mt4_map_login','pass'=>'gf_renew_mt4_map_pass','server'=>'gf_renew_mt4_map_server']; break;
            case 'renew_vps': $config=['ids'=>'gf_renew_vps_ids','n'=>'gf_renew_vps_map_name','e'=>'gf_renew_vps_map_email','p'=>'gf_renew_vps_map_phone','host'=>'gf_renew_vps_map_host','ip'=>'gf_renew_vps_map_ip','u'=>'gf_renew_vps_map_user','pass'=>'gf_renew_vps_map_pass']; break;
            case 'renew': $config=['ids'=>'gf_renew_ids','n'=>'gf_renew_map_name','e'=>'gf_renew_map_email','p'=>'gf_renew_map_phone']; break;
            case 'upg': $config=['ids'=>'gf_upg_ids','n'=>'gf_upg_map_name','e'=>'gf_upg_map_email','p'=>'gf_upg_map_phone']; break;
            case 'dwn': $config=['ids'=>'gf_dwn_ids','n'=>'gf_dwn_map_name','e'=>'gf_dwn_map_email','p'=>'gf_dwn_map_phone']; break;
            case 'l2': $config=['ids'=>'gf_l2_ids','n'=>'gf_l2_map_name','e'=>'gf_l2_map_email','p'=>'gf_l2_map_phone']; break;
            case 'l3': $config=['ids'=>'gf_l3_ids','n'=>'gf_l3_map_name','e'=>'gf_l3_map_email','p'=>'gf_l3_map_phone']; break;
            case 'l4': $config=['ids'=>'gf_l4_ids','n'=>'gf_l4_map_name','e'=>'gf_l4_map_email','p'=>'gf_l4_map_phone']; break;
        }
        
        if(empty($config) || empty($this->opts[$config['ids']])) return;
        
        $form_ids = explode(',', $this->opts[$config['ids']]);
        
        foreach($form_ids as $fid) {
            $fid = trim($fid); 
            if(!is_numeric($fid)) continue; 
            
            $entry = ['form_id' => $fid];
            $set_field = function($opt_key, $val, $is_name = false) use (&$entry) {
                if(!empty($this->opts[$opt_key])) {
                    $field_id = trim($this->opts[$opt_key]);
                    if ($is_name && strpos($field_id, '.') === false) {
                        $parts = explode(' ', trim($val), 2);
                        $entry[$field_id . '.3'] = $parts[0] ?? ''; 
                        $entry[$field_id . '.6'] = $parts[1] ?? ''; 
                    } else {
                        $entry[$field_id] = $val;
                    }
                }
            };
            $set_field($config['n'], $student->student_name, true);
            $set_field($config['e'], $student->student_email);
            $set_field($config['p'], $student->student_phone);

            if(isset($extra['mt4userid'])) {
                if(isset($config['login'])) $set_field($config['login'], $extra['mt4userid']);
                if(isset($config['pass'])) $set_field($config['pass'], $extra['mt4password']);
                if(isset($config['server'])) $set_field($config['server'], $extra['mt4servername']);
            }
            if(isset($extra['host_name'])) {
                 if(isset($config['host'])) $set_field($config['host'], $extra['host_name']);
                 if(isset($config['ip'])) $set_field($config['ip'], $extra['vps_ip']);
                 if(isset($config['u'])) $set_field($config['u'], $extra['vps_user_id']);
                 if(isset($config['pass'])) $set_field($config['pass'], $extra['vps_password']);
            }
            $eid = GFAPI::add_entry($entry);
            if(!is_wp_error($eid)) { 
                GFAPI::add_note($eid, 0, 'RD Algo', 'Entry created via RD Algo Automation');
                $form = GFAPI::get_form($fid);
                $entry_obj = GFAPI::get_entry($eid); 
                if($form && !is_wp_error($entry_obj)) GFAPI::send_notifications($form, $entry_obj, 'form_submission'); 
            }
        }
    }

    public function perform_action() {
        check_ajax_referer('rd_algo_nonce', 'nonce'); global $wpdb; $act=$_POST['sub_action']; $id=intval($_POST['student_id']);
        $stu=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->opts['tb_student']} WHERE id=%d",$id));
        if(!$stu) wp_send_json_error("Student Not Found");
        if(!$this->has_role_permission('btn_perms',$act)) wp_send_json_error("Permission Denied");
        if(isset($this->opts['block_actions'][$act]) && $stu->student_expiry_date) {
            if((strtotime($stu->student_expiry_date)-time())/86400 < intval($this->opts['block_threshold']??30)) wp_send_json_error("Blocked: Low Expiry");
        }
        
        // Define Column Mappings for Payment Validation
        $validate_pay = function($kw, $action_key) use ($wpdb, $stu, $id) {
            $key = $this->opts[$kw] ?? ''; if(!$key) return false;
            $limit_hours = intval($this->opts['pay_hours'] ?? 48);
            $time_limit = date('Y-m-d H:i:s', strtotime("-$limit_hours hours"));
            
            $t = $this->opts['tb_payment']; 
            $c_ph = $this->opts['col_pay_phone'] ?? 'student_phone';
            $c_pur = $this->opts['col_pay_purpose'] ?? 'purpose';
            $c_date = $this->opts['col_pay_date'] ?? 'created_at';
            $c_id = $this->opts['col_pay_id'] ?? 'id';

            $p = preg_replace('/[^0-9]/', '', $stu->student_phone);
            
            // Dynamic Query
            $pay = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE replace($c_ph,' ','') LIKE %s AND $c_pur LIKE %s AND $c_date >= %s ORDER BY $c_date DESC LIMIT 1", "%$p%", "%".$wpdb->esc_like($key)."%", $time_limit));
            
            if(!$pay) return "Payment Not Found (> $limit_hours hrs)";
            $option_key = 'rd_pay_used_' . $id . '_' . $action_key;
            
            // Check against dynamic ID
            $pid = $pay->$c_id;
            if(get_option($option_key) == $pid) return "Payment ID {$pid} already used.";
            return ['valid' => true, 'data' => $pay, 'opt_key' => $option_key];
        };
        
        switch($act) {
            case 'auto_tg': $this->process_gf_automation('auto_tg', $stu); wp_send_json_success("TG Email Sent"); break;
            case 'auto_course': $this->process_gf_automation('auto_course', $stu); wp_send_json_success("Course Email Sent"); break;
            
            case 'copy_tg': 
                $t_tg = $this->opts['tb_tg'] ?? 'wp_gf_telegram_subs';
                $c_tg_link = $this->opts['col_tg_links'] ?? 'links';
                $c_tg_id = $this->opts['col_tg_id'] ?? 'id';
                
                // --- UPDATE: CHECK PHONE NUMBER IN TG TABLE ---
                $clean_phone = preg_replace('/[^0-9]/', '', $stu->student_phone);
                // Ensure we search for the last 10 digits to match DB format shown in screenshot (9431706753)
                if(strlen($clean_phone) > 10) $clean_phone = substr($clean_phone, -10);
                
                // Using 'mobile' column as seen in the DB screenshot provided
                $l = $wpdb->get_var($wpdb->prepare("SELECT $c_tg_link FROM $t_tg WHERE mobile LIKE %s ORDER BY $c_tg_id DESC LIMIT 1", "%$clean_phone%"));
                
                if(!$l) wp_send_json_error("TG Link Not Found for {$stu->student_phone}");
                
                wp_send_json_success(['copy_text'=>str_replace('{{links}}', $l, $this->opts['tpl_tg_access']??'')]); 
                break;
                
            case 'copy_course': wp_send_json_success(['copy_text'=>str_replace(['{{student_name}}','{{student_email}}','{{student_phone}}'], [$stu->student_name, $stu->student_email, $stu->student_phone], $this->opts['tpl_course_access']??'')]); break;
            
            case 'copy_vps_guide': 
                $c_vps_pk = $this->opts['col_vps_host'] ?? 'host_name';
                $v = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->opts['tb_vps']} WHERE $c_vps_pk=%s", $stu->vps_host_name)); 
                if(!$v) wp_send_json_error("VPS Not Found"); 
                wp_send_json_success(['copy_text'=>str_replace(['{{student_name}}','{{student_email}}','{{student_phone}}','{{vps_ip}}','{{vps_user}}','{{vps_pass}}'], [$stu->student_name, $stu->student_email, $stu->student_phone, $v->vps_ip, $v->vps_user_id, $v->vps_password], $this->opts['tpl_vps_guide']??'')]); 
                break;
            
            case 'assign_mt4':
                require_once RD_ALGO_PATH . 'includes/class-rd-logic.php';
                $logic = new RD_Algo_Logic();
                $res = $logic->assign_mt4($id);
                if($res['success']) wp_send_json_success($res['message']); else wp_send_json_error($res['message']);
                break;
            case 'assign_vps':
                require_once RD_ALGO_PATH . 'includes/class-rd-logic.php';
                $logic = new RD_Algo_Logic();
                $res = $logic->assign_vps($id);
                if($res['success']) wp_send_json_success($res['message']); else wp_send_json_error($res['message']);
                break;
            
            case 'renew_mt4':
                if(empty($stu->mt4_server_id)) wp_send_json_error("No MT4"); $m=intval($_POST['duration']); if(!$m) wp_send_json_error("Invalid Duration");
                $c_pk = $this->opts['col_mt4_login'] ?? 'mt4userid'; $c_exp = $this->opts['col_mt4_expiry'] ?? 'mt4expirydate';
                $asset=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->opts['tb_mt4']} WHERE $c_pk=%s",$stu->mt4_server_id)); if(!$asset) wp_send_json_error("Asset Not Found");
                $new=date('Y-m-d', strtotime("+$m months")); $wpdb->update($this->opts['tb_mt4'], [$c_exp=>$new], [$c_pk=>$stu->mt4_server_id]);
                $this->process_gf_automation('renew_mt4', $stu, (array)$asset); wp_send_json_success("Renewed"); break;
            case 'renew_vps':
                if(empty($stu->vps_host_name)) wp_send_json_error("No VPS"); $m=intval($_POST['duration']); if(!$m) wp_send_json_error("Invalid Duration");
                $c_pk = $this->opts['col_vps_host'] ?? 'host_name'; $c_exp = $this->opts['col_vps_expiry'] ?? 'vps_expier';
                $asset=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->opts['tb_vps']} WHERE $c_pk=%s",$stu->vps_host_name)); if(!$asset) wp_send_json_error("Asset Not Found");
                $new=date('Y-m-d', strtotime("+$m months")); $wpdb->update($this->opts['tb_vps'], [$c_exp=>$new], [$c_pk=>$stu->vps_host_name]);
                $this->process_gf_automation('renew_vps', $stu, (array)$asset); wp_send_json_success("Renewed"); break;
            case 'renewal': $r=$validate_pay('pay_renewal','renew'); if(!is_array($r)) wp_send_json_error($r); $wpdb->update($this->opts['tb_student'], ['student_expiry_date'=>date('Y-m-d', strtotime('+1 year'))], ['id'=>$id]); update_option($r['opt_key'], $r['data']->id); $this->process_gf_automation('renew', $stu); wp_send_json_success("Renewed"); break;
            case 'upgrade': $r=$validate_pay('pay_upgrade','upg'); if(!is_array($r)) wp_send_json_error($r); $wpdb->update($this->opts['tb_student'], ['software_type'=>'Mobile and Laptop'], ['id'=>$id]); update_option($r['opt_key'], $r['data']->id); $this->process_gf_automation('upg', $stu); wp_send_json_success("Upgraded"); break;
            case 'downgrade': $r=$validate_pay('pay_downgrade','dwn'); if(!is_array($r)) wp_send_json_error($r); $wpdb->update($this->opts['tb_student'], ['software_type'=>'Laptop'], ['id'=>$id]); update_option($r['opt_key'], $r['data']->id); $this->process_gf_automation('dwn', $stu); wp_send_json_success("Downgraded"); break;
            
            // --- UPDATED LEVEL 2 ACTION (Use Dynamic Cols) ---
            case 'level_2_access': 
                $r=$validate_pay('pay_level2','l2'); if(!is_array($r)) wp_send_json_error($r); 
                $c_s = $this->opts['col_l2_status'] ?? 'level_2_status'; $c_d = $this->opts['col_l2_date'] ?? 'level_2_join_date';
                $wpdb->update($this->opts['tb_student'], [$c_s=>'Yes',$c_d=>date('Y-m-d')], ['id'=>$id]); 
                update_option($r['opt_key'], $r['data']->id); 
                $this->process_gf_automation('l2', $stu); wp_send_json_success("Activated"); break;
            
            // --- UPDATED LEVEL 3 ACTION (Use Dynamic Cols) ---
            case 'level_3_access': 
                $r=$validate_pay('pay_level3','l3'); if(!is_array($r)) wp_send_json_error($r); 
                $c_s = $this->opts['col_l3_status'] ?? 'level_3_status'; $c_d = $this->opts['col_l3_date'] ?? 'level_3_join_date';
                $wpdb->update($this->opts['tb_student'], [$c_s=>'Yes',$c_d=>date('Y-m-d')], ['id'=>$id]); 
                update_option($r['opt_key'], $r['data']->id); 
                $this->process_gf_automation('l3', $stu); wp_send_json_success("Activated L3"); break;
            
            // --- UPDATED LEVEL 4 ACTION (Use Dynamic Cols) ---
            case 'level_4_access': 
                $r=$validate_pay('pay_level4','l4'); if(!is_array($r)) wp_send_json_error($r); 
                $c_s = $this->opts['col_l4_status'] ?? 'level_4_status'; $c_d = $this->opts['col_l4_date'] ?? 'level_4_join_date';
                $wpdb->update($this->opts['tb_student'], [$c_s=>'Yes',$c_d=>date('Y-m-d')], ['id'=>$id]); 
                update_option($r['opt_key'], $r['data']->id); 
                $this->process_gf_automation('l4', $stu); wp_send_json_success("Activated L4"); break;

            case 'extend_exp': $d=intval($_POST['days']); $new=date('Y-m-d', strtotime(($stu->student_expiry_date?:date('Y-m-d')) . " + $d days")); $wpdb->update($this->opts['tb_student'], ['student_expiry_date'=>$new], ['id'=>$id]); wp_send_json_success("Extended"); break;
            case 'remove_mt4': $wpdb->update($this->opts['tb_student'], ['mt4_server_id'=>null], ['id'=>$id]); wp_send_json_success("Removed"); break;
            case 'remove_vps': $wpdb->update($this->opts['tb_student'], ['vps_host_name'=>null], ['id'=>$id]); wp_send_json_success("Removed"); break;
        }
    }
    public function generate_bat() { check_ajax_referer('rd_algo_nonce', 'nonce'); $gen = new RD_Algo_Bat_Gen(); $url = $gen->create_one_time_link($_POST['bat_type'], $_POST['record_id']); wp_send_json_success(['url' => $url]); }
}
?>