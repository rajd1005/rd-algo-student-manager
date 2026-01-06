<?php
class RD_Algo_Logic {
    private $opts;
    private $db;

    public function __construct() {
        $this->opts = get_option('rd_algo_settings', []);
        // We need the DB class helper for GF automation triggering
        require_once RD_ALGO_PATH . 'includes/class-rd-ajax.php'; 
    }

    /**
     * Logic to Assign MT4
     * Returns ['success' => bool, 'message' => string]
     * @param int $student_id
     * @param string|null $override_type Optional forced type (e.g. from Agent Form)
     */
    public function assign_mt4($student_id, $override_type = null) {
        global $wpdb;
        $t_student = $this->opts['tb_student'] ?? 'wp_gf_student_registrations';
        $t_mt4 = $this->opts['tb_mt4'] ?? 'wp_mt4_user_records';

        $stu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_student WHERE id=%d", $student_id));
        if (!$stu) return ['success' => false, 'message' => 'Student Not Found'];

        // 1. Check if already assigned (Block only if ACTIVE)
        if (!empty($stu->mt4_server_id)) {
            $existing = $wpdb->get_row($wpdb->prepare("SELECT mt4expirydate FROM $t_mt4 WHERE mt4userid=%s", $stu->mt4_server_id));
            
            $is_active = false;
            if ($existing && !empty($existing->mt4expirydate)) {
                // Check if expiry is in the future
                if (strtotime($existing->mt4expirydate) > time()) {
                    $is_active = true;
                }
            } else {
                // If ID exists on student but not in DB (or no date), assume it's an old record or error, 
                // but usually we treat unknown as "expired/invalid" allowing overwrite.
                // However, to be safe, if we can't verify it's expired, we might block. 
                // Here we assume if it exists in student col, we treat it as active unless proven expired.
                // actually, for "Assign New", we usually want to allow overwrite if the previous is dead.
                // Let's stick to: If we find a valid future date, it's active.
            }

            if ($is_active) {
                return ['success' => false, 'message' => 'Student already has an ACTIVE MT4 ID: ' . $stu->mt4_server_id . '. Please Remove or Renewal it.'];
            }
            // If expired, we proceed (Overwrite)
        }

        // 2. Settings & Limits
        $limit = intval($this->opts['mt4_limit'] ?? 1);
        $min_days = intval($this->opts['mt4_expiry_days'] ?? 25);
        
        // Use override if provided, else fallback to global default
        $type = !empty($override_type) ? $override_type : ($this->opts['mt4_default_type'] ?? '1 Month');

        // 3. Find Candidates
        // ORDER BY mt4expirydate ASC puts the closest dates first
        $candidates = $wpdb->get_results("SELECT * FROM $t_mt4 WHERE status='Active' ORDER BY mt4expirydate ASC");
        $ids = array_column($candidates, 'mt4userid');
        
        $usage = [];
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '%s'));
            $usage = $wpdb->get_results($wpdb->prepare("SELECT mt4_server_id, COUNT(*) as c FROM $t_student WHERE mt4_server_id IN ($placeholders) GROUP BY mt4_server_id", $ids), OBJECT_K);
        }

        $selected = null;
        $now = time();

        foreach ($candidates as $c) {
            // Match Type
            if (strcasecmp(trim($c->datetype), $type) !== 0) continue;
            
            // Check Expiry
            $exp = strtotime($c->mt4expirydate);
            if (!$exp || ceil(($exp - $now) / 86400) <= $min_days) continue;

            // Check Usage Limit
            $used_count = isset($usage[$c->mt4userid]) ? intval($usage[$c->mt4userid]->c) : 0;
            if ($used_count < $limit) {
                $selected = $c;
                break;
            }
        }

        if (!$selected) {
            // Trigger Admin Notification here if needed (e.g. wp_mail)
            return ['success' => false, 'message' => 'Stock Unavailable (No Active MT4 matching criteria: ' . $type . ').'];
        }

        // 4. Assign
        $wpdb->update($t_student, ['mt4_server_id' => $selected->mt4userid], ['id' => $student_id]);

        // 5. Trigger Automation (Reuse existing Ajax helper logic if possible, or replicate)
        // For simplicity/robustness, we instantiate the Ajax class just to use its helper
        $ajax_handler = new RD_Algo_Ajax();
        $ajax_handler->trigger_gf_automation('mt4', $stu, (array)$selected);

        return ['success' => true, 'message' => 'Assigned MT4: ' . $selected->mt4userid];
    }

    /**
     * Logic to Assign VPS
     */
    public function assign_vps($student_id) {
        global $wpdb;
        $t_student = $this->opts['tb_student'] ?? 'wp_gf_student_registrations';
        $t_vps = $this->opts['tb_vps'] ?? 'wp_vps_records';

        $stu = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t_student WHERE id=%d", $student_id));
        if (!$stu) return ['success' => false, 'message' => 'Student Not Found'];

        // 1. Check Software Type
        if (stripos($stu->software_type, 'Mobile') === false || stripos($stu->software_type, 'Laptop') === false) {
             return ['success' => false, 'message' => 'Skipped: Software Type must be "Mobile and Laptop".'];
        }

        // 2. Check if already assigned (Block only if ACTIVE)
        if (!empty($stu->vps_host_name)) {
            // Check actual status of this VPS in the inventory
            $existing = $wpdb->get_row($wpdb->prepare("SELECT vps_expier FROM $t_vps WHERE host_name=%s", $stu->vps_host_name));
            
            $is_active = false;
            if ($existing && !empty($existing->vps_expier)) {
                // Check if expiry is in the future
                if (strtotime($existing->vps_expier) > time()) {
                    $is_active = true;
                }
            }

            if ($is_active) {
                return ['success' => false, 'message' => 'Student already has an ACTIVE VPS: ' . $stu->vps_host_name . '. Renew it instead.'];
            }
            // If expired, we proceed (Overwrite)
        }

        // 3. Settings
        $limit = intval($this->opts['vps_limit'] ?? 1);
        $min_days = intval($this->opts['vps_expiry_days'] ?? 25);

        // 4. Find Candidates
        // ORDER BY vps_expier ASC puts the closest dates first
        $candidates = $wpdb->get_results("SELECT * FROM $t_vps WHERE status='Active' ORDER BY vps_expier ASC");
        $hosts = array_column($candidates, 'host_name');

        $usage = [];
        if ($hosts) {
            $placeholders = implode(',', array_fill(0, count($hosts), '%s'));
            $usage = $wpdb->get_results($wpdb->prepare("SELECT vps_host_name, COUNT(*) as c FROM $t_student WHERE vps_host_name IN ($placeholders) GROUP BY vps_host_name", $hosts), OBJECT_K);
        }

        $selected = null;
        $now = time();

        foreach ($candidates as $c) {
            // Check Expiry
            $exp = strtotime($c->vps_expier);
            if (!$exp || ceil(($exp - $now) / 86400) <= $min_days) continue;

            // Check Usage
            $used_count = isset($usage[$c->host_name]) ? intval($usage[$c->host_name]->c) : 0;
            if ($used_count < $limit) {
                $selected = $c;
                break;
            }
        }

        if (!$selected) {
            return ['success' => false, 'message' => 'Stock Unavailable (No Active VPS matching criteria).'];
        }

        // 5. Assign
        $wpdb->update($t_student, ['vps_host_name' => $selected->host_name], ['id' => $student_id]);

        // 6. Automation
        $ajax_handler = new RD_Algo_Ajax();
        $ajax_handler->trigger_gf_automation('vps', $stu, (array)$selected);

        return ['success' => true, 'message' => 'Assigned VPS: ' . $selected->host_name];
    }
}
?>