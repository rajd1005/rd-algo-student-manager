<?php
class RD_Algo_DB {
    private $wpdb;
    private $opts;

    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->opts = get_option('rd_algo_settings');
    }

    public function get_counters() {
        $t_mt4 = $this->opts['tb_mt4'] ?? 'wp_mt4_user_records';
        $t_vps = $this->opts['tb_vps'] ?? 'wp_vps_records';
        $t_stg = $this->opts['tb_vps_staging'] ?? 'wp_vps_records_verify';
        $t_stu = $this->opts['tb_student'] ?? 'wp_gf_student_registrations';

        $mt4_d = intval($this->opts['mt4_expiry_days'] ?? 25);
        $vps_d = intval($this->opts['vps_expiry_days'] ?? 25);
        $mt4_l = intval($this->opts['mt4_limit'] ?? 1);
        $vps_l = intval($this->opts['vps_limit'] ?? 1);
        $today = time();

        // 1. MT4 Logic
        $mt4_all = $this->wpdb->get_results("SELECT mt4userid, status, mt4expirydate FROM $t_mt4");
        $mt4_usage = $this->wpdb->get_results("SELECT mt4_server_id, COUNT(*) as c FROM $t_stu WHERE mt4_server_id != '' GROUP BY mt4_server_id", OBJECT_K);
        $mt4_assigned_total = $this->wpdb->get_var("SELECT COUNT(*) FROM $t_stu WHERE mt4_server_id != ''");

        $mt4_valid = 0;
        $mt4_free_slots = 0;

        foreach($mt4_all as $m) {
            if(strcasecmp(trim($m->status), 'Active') !== 0) continue;
            $exp = strtotime($m->mt4expirydate);
            if(!$exp || ceil(($exp - $today)/86400) <= $mt4_d) continue;
            $mt4_valid++;
            $used = isset($mt4_usage[$m->mt4userid]) ? intval($mt4_usage[$m->mt4userid]->c) : 0;
            if($used < $mt4_l) $mt4_free_slots += ($mt4_l - $used);
        }

        // 2. VPS Logic
        $vps_all = $this->wpdb->get_results("SELECT host_name, status, vps_expier FROM $t_vps");
        $vps_usage = $this->wpdb->get_results("SELECT vps_host_name, COUNT(*) as c FROM $t_stu WHERE vps_host_name != '' GROUP BY vps_host_name", OBJECT_K);
        $vps_assigned_total = $this->wpdb->get_var("SELECT COUNT(*) FROM $t_stu WHERE vps_host_name != ''");

        $vps_valid = 0;
        $vps_free_slots = 0;

        foreach($vps_all as $v) {
            if(strcasecmp(trim($v->status), 'Active') !== 0) continue;
            $exp = strtotime($v->vps_expier);
            if(!$exp || ceil(($exp - $today)/86400) <= $vps_d) continue;
            $vps_valid++;
            $used = isset($vps_usage[$v->host_name]) ? intval($vps_usage[$v->host_name]->c) : 0;
            if($used < $vps_l) $vps_free_slots += ($vps_l - $used);
        }

        $stg = ($this->wpdb->get_var("SHOW TABLES LIKE '$t_stg'")==$t_stg) ? $this->wpdb->get_var("SELECT COUNT(*) FROM $t_stg") : 0;

        return [
            'mt4_total' => $mt4_valid, 'mt4_used' => $mt4_assigned_total ?: 0, 'mt4_free' => $mt4_free_slots,
            'vps_total' => $vps_valid, 'vps_used' => $vps_assigned_total ?: 0, 'vps_free' => $vps_free_slots,
            'staging' => $stg ?: 0
        ];
    }

    public function search_student($term) {
        $t_stu = $this->opts['tb_student'] ?? 'wp_gf_student_registrations';
        $clean = preg_replace('/[^0-9]/', '', $term);
        $sql = "SELECT * FROM $t_stu WHERE student_name LIKE %s OR student_email LIKE %s OR mt4_server_id LIKE %s OR vps_host_name LIKE %s OR anydesk_id LIKE %s";
        $args = array_fill(0, 5, '%'.$this->wpdb->esc_like($term).'%');
        if(strlen($clean)>2) {
            $sql .= " OR REPLACE(REPLACE(REPLACE(student_phone,' ',''),'-',''),'+','') LIKE %s";
            $sql .= " OR REPLACE(REPLACE(REPLACE(student_phone_alt,' ',''),'-',''),'+','') LIKE %s";
            $args[] = '%'.$clean.'%'; $args[] = '%'.$clean.'%';
        }
        return $this->wpdb->get_results($this->wpdb->prepare($sql, $args));
    }
}