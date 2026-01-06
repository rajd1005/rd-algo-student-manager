<?php
/**
 * Plugin Name: RD Algo Student Manager
 * Description: Complete CRM for Student, VPS, and MT4 management.
 * Version: 75.0 (Restored GF + Direct Links + Agent MT4 Type)
 * Author: RD Algo
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'RD_ALGO_PATH', plugin_dir_path( __FILE__ ) );
define( 'RD_ALGO_URL', plugin_dir_url( __FILE__ ) );

require_once RD_ALGO_PATH . 'includes/class-rd-admin.php';
require_once RD_ALGO_PATH . 'includes/class-rd-db.php';
require_once RD_ALGO_PATH . 'includes/class-rd-bat-gen.php';
require_once RD_ALGO_PATH . 'includes/class-rd-ajax.php';
require_once RD_ALGO_PATH . 'includes/class-rd-logic.php';

function rd_algo_init() {
    new RD_Algo_Admin();
    new RD_Algo_Ajax();
    $bat_gen = new RD_Algo_Bat_Gen();
    $bat_gen->init();
}
add_action( 'plugins_loaded', 'rd_algo_init' );

add_action( 'gform_after_submission', 'rd_algo_agent_assignment', 10, 2 );
add_action( 'gform_post_add_entry', 'rd_algo_agent_assignment', 10, 2 );

function rd_algo_agent_assignment( $entry, $form ) {
    static $processed_entries = [];
    if ( isset($processed_entries[$entry['id']]) ) return;
    $processed_entries[$entry['id']] = true;
    GFAPI::add_note( $entry['id'], 0, 'RD Algo Debug', "Processing Entry (Source: " . (did_action('gform_after_submission') ? 'Manual' : 'API') . ")" );

    $opts = get_option('rd_algo_settings', []);
    $mt4_setting_id = trim($opts['agent_mt4_form_id'] ?? '');
    $vps_setting_id = trim($opts['agent_vps_form_id'] ?? '');
    $action_type = ''; $phone_field_id = '';

    if ( $mt4_setting_id && $form['id'] == $mt4_setting_id ) { $action_type = 'mt4'; $phone_field_id = trim($opts['agent_mt4_phone_id']); } 
    elseif ( $vps_setting_id && $form['id'] == $vps_setting_id ) { $action_type = 'vps'; $phone_field_id = trim($opts['agent_vps_phone_id']); } 
    else { return; }

    $raw_phone = rgar( $entry, $phone_field_id );
    if ( empty($raw_phone) ) { GFAPI::add_note( $entry['id'], 0, 'RD Algo Bot', 'Error: Phone field is empty.' ); return; }
    $clean_phone = preg_replace('/[^0-9]/', '', $raw_phone);
    if(strlen($clean_phone) > 10) $clean_phone = substr($clean_phone, -10);
    
    global $wpdb;
    $t_student = $opts['tb_student'] ?? 'wp_gf_student_registrations';
    $student = $wpdb->get_row( $wpdb->prepare( "SELECT id, student_name FROM $t_student WHERE REPLACE(REPLACE(REPLACE(student_phone, ' ', ''), '-', ''), '+', '') LIKE %s LIMIT 1", '%' . $clean_phone ));

    if ( !$student ) { GFAPI::add_note( $entry['id'], 0, 'RD Algo Bot', "Error: Student Not Found ($clean_phone)" ); return; }

    $logic = new RD_Algo_Logic();
    $result = ['success' => false, 'message' => 'Unknown'];
    try {
        if ( $action_type === 'mt4' ) {
            // Check for Agent specific type override
            $agent_mt4_type = $opts['agent_mt4_type'] ?? null;
            $result = $logic->assign_mt4( $student->id, $agent_mt4_type );
        }
        elseif ( $action_type === 'vps' ) {
            $result = $logic->assign_vps( $student->id );
        }
    } catch (Exception $e) { GFAPI::add_note( $entry['id'], 0, 'RD Algo Bot', 'Logic Error: ' . $e->getMessage() ); return; }
    GFAPI::add_note( $entry['id'], 0, 'RD Algo Bot', $result['success'] ? 'SUCCESS: '.$result['message'] : 'FAILED: '.$result['message'] );
}

add_shortcode( 'rd_student_manager', function() {
    ob_start();
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.tailwindcss.com?plugins=typography"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        .custom-scrollbar::-webkit-scrollbar { height: 8px; width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 4px; }
        .custom-scrollbar::-webkit-scrollbar-track { background: #f1f5f9; }
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        details[open] summary ~ * { animation: sweep .2s ease-in-out; }
        @keyframes sweep { 0% {opacity: 0; transform: translateY(-10px)} 100% {opacity: 1; transform: translateY(0)} }
    </style>

    <div id="rd-algo-manager-app" class="max-w-7xl mx-auto p-4 font-sans text-gray-700">
        
        <div id="rd-counters-section" class="flex flex-wrap gap-4 mb-6 text-sm font-medium text-gray-600"></div>
        
        <div class="bg-white p-4 rounded-lg shadow-sm border border-gray-200 mb-6 flex flex-col md:flex-row gap-4 items-center">
            <div class="relative w-full">
                <input type="text" id="rd-student-search" class="w-full px-4 py-3 border border-gray-300 rounded-full focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent shadow-sm transition" placeholder="Search by Phone, Email, or Name..." autocomplete="off">
                <div id="rd-search-results" class="absolute w-full bg-white border border-gray-200 mt-1 rounded-lg shadow-xl z-50 hidden max-h-60 overflow-y-auto custom-scrollbar"></div>
            </div>
            <div id="rd-add-btn-container"></div>
        </div>

        <div id="rd-student-card-container"></div>
        
        <div id="rd-edit-modal" class="rd-modal fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50 backdrop-blur-sm">
            <div class="bg-white rounded-lg shadow-2xl w-[95%] max-w-lg overflow-hidden transform transition-all scale-100">
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-bold text-lg text-gray-800">Edit Student Profile</h3>
                    <button type="button" class="rd-close-modal text-gray-400 hover:text-gray-600"><i class="fa-solid fa-times text-xl"></i></button>
                </div>
                <form id="rd-edit-form" class="p-6 space-y-4">
                    <input type="hidden" id="edit_student_id">
                    <div data-group="primary" class="space-y-3">
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Name</label><input type="text" id="edit_name" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label><input type="email" id="edit_email" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Phone</label><input type="text" id="edit_phone" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Expiry</label><input type="date" id="edit_expiry" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                    </div>
                    <div data-group="secondary" class="space-y-3 border-t pt-3 mt-3">
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Alt Phone</label><input type="text" id="edit_phone_alt" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">AnyDesk ID</label><input type="text" id="edit_anydesk" class="w-full border border-gray-300 p-2 rounded focus:ring-blue-500 focus:border-blue-500"></div>
                    </div>
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                        <button type="button" id="rd-edit-cancel" class="rd-close-modal px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition">Cancel</button>
                        <button type="button" id="rd-edit-save" class="px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 shadow transition">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="rd-add-student-modal" class="rd-modal fixed inset-0 bg-black bg-opacity-50 hidden items-center justify-center p-4 z-50 backdrop-blur-sm">
            <div class="bg-white rounded-lg shadow-2xl w-[95%] max-w-lg overflow-hidden transform transition-all scale-100">
                <div class="bg-gray-50 px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                    <h3 class="font-bold text-lg text-gray-800">Add New Student</h3>
                    <button type="button" class="rd-close-modal text-gray-400 hover:text-gray-600"><i class="fa-solid fa-times text-xl"></i></button>
                </div>
                <form id="rd-add-student-form" class="p-6 space-y-3">
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Entry Date</label><input type="date" id="new_entry_date" class="w-full border p-2 rounded" value="<?php echo date('Y-m-d'); ?>"></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Software Type</label><select id="new_software_type" class="w-full border p-2 rounded bg-white"><option>Loading...</option></select></div>
                    </div>
                    <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Name</label><input type="text" id="new_student_name" class="w-full border p-2 rounded" required></div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Email</label><input type="email" id="new_student_email" class="w-full border p-2 rounded" required></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Phone (10 Digits)</label><input type="text" id="new_student_phone" class="w-full border p-2 rounded" pattern="\d{10}" placeholder="9876543210" required></div>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">State</label><select id="new_state" class="w-full border p-2 rounded bg-white"><option>Loading...</option></select></div>
                        <div><label class="block text-xs font-bold text-gray-500 uppercase mb-1">Expiry (Default: +1 Year)</label><input type="date" id="new_expiry_date" class="w-full border p-2 rounded"></div>
                    </div>
                    <div class="flex justify-end gap-3 pt-4 border-t border-gray-100">
                        <button type="button" id="rd-add-student-cancel" class="rd-close-modal px-4 py-2 bg-gray-100 text-gray-700 rounded hover:bg-gray-200 transition">Cancel</button>
                        <button type="button" id="rd-add-student-save" class="px-4 py-2 bg-green-600 text-white rounded hover:bg-green-700 shadow transition">Add Student</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="rd-alert-popup" class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-[99999] backdrop-blur-sm transition-opacity opacity-0">
            <div class="bg-white rounded-xl shadow-2xl p-6 max-w-sm w-full transform scale-95 transition-transform duration-200 text-center relative overflow-hidden">
                <div id="rd-popup-icon-container" class="mb-4 text-5xl"></div>
                <h3 id="rd-popup-title" class="text-xl font-bold mb-2 text-gray-800">Notification</h3>
                <p id="rd-popup-msg" class="text-gray-600 mb-6 text-sm"></p>
                <button id="rd-popup-close" class="bg-gray-800 text-white px-6 py-2 rounded-lg font-medium hover:bg-black transition w-full">OK</button>
            </div>
        </div>

    </div>
    <?php
    return ob_get_clean();
});

add_action( 'wp_enqueue_scripts', function() {
    wp_enqueue_script( 'rd-algo-js', RD_ALGO_URL . 'assets/js/script.js', ['jquery'], '75.0', true );
    $opts = get_option('rd_algo_settings', []);
    
    // 1. Files
    $offline_btns = [];
    for($i=1; $i<=4; $i++) {
        $offline_btns[] = ['name' => $opts["off_btn_{$i}_name"] ?? '', 'url' => $opts["off_btn_{$i}_url"] ?? ''];
    }

    // 2. Direct Links (NEW)
    $offline_links = [];
    for($i=1; $i<=4; $i++) {
        $offline_links[] = ['name' => $opts["off_link_btn_{$i}_name"] ?? '', 'url' => $opts["off_link_btn_{$i}_url"] ?? ''];
    }

    // 3. Copy Text
    $offline_copy = [];
    for($i=1; $i<=4; $i++) {
        $offline_copy[] = ['name' => $opts["off_copy_btn_{$i}_name"] ?? '', 'text' => $opts["off_copy_btn_{$i}_text"] ?? ''];
    }
    
    wp_localize_script( 'rd-algo-js', 'rd_ajax', [
        'ajax_url' => admin_url( 'admin-ajax.php' ),
        'nonce'    => wp_create_nonce( 'rd_algo_nonce' )
    ]);
    
    // --- UPDATED: ADDED wpautop() TO PRESERVE FORMATTING ---
    wp_localize_script( 'rd-algo-js', 'rd_vars', [
        'sop_content' => wpautop(wp_kses_post($opts['sop_content'] ?? '')),
        'offline_btns' => $offline_btns,
        'offline_links' => $offline_links, // New
        'offline_copy' => $offline_copy,
        'mt4_only_renew' => !empty($opts['mt4_only_renew']),
        'mt4_only_assign' => !empty($opts['mt4_only_assign']),
        'vps_only_renew' => !empty($opts['vps_only_renew']),
        'vps_only_assign' => !empty($opts['vps_only_assign']),
    ]);
});
?>