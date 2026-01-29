jQuery(document).ready(function($) {

    // --- POPUP NOTIFICATION ---
    window.showToast = function(msg, isError = false) { 
        const popup = $('#rd-alert-popup');
        const content = popup.find('> div');
        const iconContainer = $('#rd-popup-icon-container');
        const title = $('#rd-popup-title');
        
        $('#rd-popup-msg').text(msg);
        
        if (isError) {
            iconContainer.html('<i class="fa-solid fa-circle-xmark text-red-500"></i>');
            title.text('Error');
            $('#rd-popup-close').removeClass('bg-green-600 hover:bg-green-700').addClass('bg-red-600 hover:bg-red-700');
        } else {
            iconContainer.html('<i class="fa-solid fa-circle-check text-green-500"></i>');
            title.text('Success');
            $('#rd-popup-close').removeClass('bg-red-600 hover:bg-red-700').addClass('bg-green-600 hover:bg-green-700');
        }
        
        popup.removeClass('hidden').addClass('flex');
        setTimeout(() => {
            popup.removeClass('opacity-0');
            content.removeClass('scale-95').addClass('scale-100');
        }, 10);
    };

    $('#rd-popup-close, #rd-alert-popup').on('click', function(e) {
        if (e.target === this || e.target.id === 'rd-popup-close') {
            const popup = $('#rd-alert-popup');
            const content = popup.find('> div');
            popup.addClass('opacity-0');
            content.removeClass('scale-100').addClass('scale-95');
            setTimeout(() => {
                popup.addClass('hidden').removeClass('flex');
            }, 200);
        }
    });

    // --- HELPER FUNCTIONS ---
    function buildAccordion(t,c){
        return `<details class="border-t border-gray-200 group"><summary class="cursor-pointer p-4 bg-white hover:bg-gray-50 font-bold text-gray-700 flex justify-between items-center transition select-none"><span>${t}</span><i class="fa-solid fa-chevron-down text-gray-300 transition-transform group-open:rotate-180"></i></summary><div class="p-5 bg-white border-t border-gray-100 text-sm text-gray-600">${c}</div></details>`;
    }
    
    function getDurationSelect(id) { 
        return `<select id="${id}" class="p-2 border rounded text-sm bg-white"><option value="1">1 Month</option><option value="3">3 Months</option><option value="6">6 Months</option><option value="9">9 Months</option><option value="12">12 Months</option></select>`; 
    }
    
    // NEW Helper: Calculate Days Ago
    function getDaysAgo(dateStr) {
        if(!dateStr) return '';
        const date = new Date(dateStr);
        const today = new Date();
        const diffTime = today - date;
        const diffDays = Math.floor(diffTime / (1000 * 60 * 60 * 24)); 
        return `(${diffDays} Days Ago)`;
    }
    
    window.rd_copyText = function(text) { 
        if(!text) return;
        navigator.clipboard.writeText(text).then(() => showToast('Copied to clipboard!')).catch(err => console.error(err)); 
    };
    
    function createRow(label, val, canCopy=true) { 
        if(!val) return ''; 
        let content = `<span class="text-sm font-medium text-gray-800">${val}</span>`; 
        if(canCopy) content = `<span class="text-sm font-medium text-gray-800 cursor-pointer hover:text-blue-600 transition group" onclick="rd_copyText(this.innerText)" title="Click to Copy">${val} <i class="fa-regular fa-copy text-xs text-gray-300 group-hover:opacity-100 opacity-0 ml-1"></i></span>`; 
        return `<div class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0"><span class="text-xs font-bold text-gray-400 uppercase w-24">${label}</span> ${content}</div>`; 
    }

    window.doAction = function(a, d={}) { 
        if((a.includes('remove')||a.includes('assign')||a.includes('renewal')) && !confirm('Are you sure?')) return; 
        $.post(rd_ajax.ajax_url, {action:'rd_perform_action', sub_action:a, student_id:window.currentStuId, ...d, nonce:rd_ajax.nonce}, function(r){ showToast(r.data, !r.success); if(r.success) reloadCard(); }); 
    };

    window.doAssignMT4 = function() { doAction('assign_mt4', {mt4_datetype:$('#rd-mt4-type').val()}); };
    window.doRenewMT4 = function() { doAction('renew_mt4', {duration:$('#rd-renew-mt4-dur').val()}); };
    window.doRenewVPS = function() { doAction('renew_vps', {duration:$('#rd-renew-vps-dur').val()}); };
    window.doExtend = function() { doAction('extend_exp', {days:$('#rd-extend-days').val()}); };
    window.doCopy = function(a) { $.post(rd_ajax.ajax_url, {action:'rd_perform_action', sub_action:a, student_id:window.currentStuId, nonce:rd_ajax.nonce}, function(r){ if(r.success){rd_copyText(r.data.copy_text);} else {showToast(r.data, true);} }); };
    
    window.getBat = function(type, id, method) { 
        $.post(rd_ajax.ajax_url, {
            action: 'rd_generate_bat', 
            bat_type: type, 
            record_id: id, 
            method: method, 
            nonce: rd_ajax.nonce
        }, function(r) { 
            if(r.success) {
                if(method === 'link') {
                    rd_copyText(r.data.url);
                } else {
                    window.location.href = r.data.url;
                }
            } else {
                showToast("Error generating link", true);
            }
        }); 
    };
    
    function fetchDatetypes() { $.post(rd_ajax.ajax_url, {action:'rd_get_datetypes', nonce:rd_ajax.nonce}, function(r){ if(r.success){let o='';r.data.types.forEach(t=>o+=`<option value="${t}" ${t===r.data.default?'selected':''}>${t}</option>`); $('#rd-mt4-type').html(o);} }); }
    
    function reloadCard() { 
        if(!window.currentStuId) return;
        $.post(rd_ajax.ajax_url, {action:'rd_get_single_student', id:window.currentStuId, nonce:rd_ajax.nonce}, function(r){ 
            if(r.success) renderStudentCard(r.data.stu, r.data.perms, r.data.labels, r.data.meta); 
        }); 
    }

    // --- RENDER CARD ---
    function renderStudentCard(stu, perms, labels, meta) {
        if(!stu) return;
        window.currentStu = stu; window.currentPerms = perms; window.currentStuId = stu.id;
        window.currentMeta = meta || {}; // Store it for use in logic

        // Define settings variables here to prevent Scope Errors
        let rdVars = (typeof rd_vars !== 'undefined') ? rd_vars : {};

        let pButtons = (perms && perms.buttons) ? perms.buttons : [];
        let pAccs = (perms && perms.accordions) ? perms.accordions : {};
        let editBtn = (perms && (perms.can_edit_primary || perms.can_edit_secondary)) ? `<button class="rd-edit-profile-btn text-gray-500 hover:text-blue-600 transition p-2 rounded-full hover:bg-blue-50"><i class="fa-solid fa-pen-to-square text-lg"></i></button>` : '';

        // Default labels fallback
        labels = labels || {
            l2:'Level 2', l3:'Level 3', l4:'Level 4', 
            renew:'Renewal', upg:'Upgrade', dwn:'Downgrade'
        };

        let details = '<div class="p-6 grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">';
        details += createRow('Name', stu.student_name); details += createRow('Email', stu.student_email); details += createRow('Phone', stu.student_phone);
        if(stu.student_phone_alt) details += createRow('Alt Phone', stu.student_phone_alt); if(stu.anydesk_id) details += createRow('AnyDesk', stu.anydesk_id);
        details += `<div class="flex justify-between items-center py-2 border-b border-gray-100"><span class="text-xs font-bold text-gray-400 uppercase">Software</span> <span class="text-sm font-medium text-gray-800">${stu.software_type||'N/A'}</span></div>`;
        let expClass = (stu.expiry_display && stu.expiry_display.includes('Expired')) ? 'text-red-600 font-bold' : 'text-green-600 font-bold';
        details += `<div class="flex justify-between items-center py-2 border-b border-gray-100"><span class="text-xs font-bold text-gray-400 uppercase">Expiry</span> <span class="text-sm ${expClass}">${stu.expiry_display||'N/A'}</span></div>`;
        
        // --- DISPLAY STATUS FOR LEVEL 2, 3, 4 (UPDATED WITH LABEL & DAYS AGO) ---
        if(stu.level_2_status === 'Yes') details += `<div class="flex justify-between items-center py-2 border-b border-gray-100"><span class="text-xs font-bold text-gray-400 uppercase">${labels.l2}</span> <span class="text-sm text-purple-600 font-bold">Joined: ${stu.level_2_join_date} ${getDaysAgo(stu.level_2_join_date)}</span></div>`;
        if(stu.level_3_status === 'Yes') details += `<div class="flex justify-between items-center py-2 border-b border-gray-100"><span class="text-xs font-bold text-gray-400 uppercase">${labels.l3}</span> <span class="text-sm text-purple-600 font-bold">Joined: ${stu.level_3_join_date} ${getDaysAgo(stu.level_3_join_date)}</span></div>`;
        if(stu.level_4_status === 'Yes') details += `<div class="flex justify-between items-center py-2 border-b border-gray-100"><span class="text-xs font-bold text-gray-400 uppercase">${labels.l4}</span> <span class="text-sm text-purple-600 font-bold">Joined: ${stu.level_4_join_date} ${getDaysAgo(stu.level_4_join_date)}</span></div>`;

        details += '</div>';
        
        let header = `<div class="bg-gray-50 px-6 py-4 border-b border-gray-200 flex justify-between items-center"><h2 class="text-xl font-bold text-gray-800 m-0">${stu.student_name}</h2>${editBtn}</div>`;
        let accHtml = '';

        if(pAccs.acc_actions) {
            let acts = '';
            // --- ACTION BUTTONS (UPDATED NAMES) ---
            if(pButtons.includes('renewal')) acts += `<button class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('renewal')">${labels.renew}</button>`;
            if(pButtons.includes('upgrade')) acts += `<button class="bg-purple-500 hover:bg-purple-600 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('upgrade')">${labels.upg}</button>`;
            if(pButtons.includes('downgrade')) acts += `<button class="bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('downgrade')">${labels.dwn}</button>`;
            
            // Level 2, 3, 4 Buttons
            if(pButtons.includes('level_2_access')) acts += `<button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('level_2_access')">${labels.l2}</button>`;
            if(pButtons.includes('level_3_access')) acts += `<button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('level_3_access')">${labels.l3}</button>`;
            if(pButtons.includes('level_4_access')) acts += `<button class="bg-gray-800 hover:bg-gray-900 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('level_4_access')">${labels.l4}</button>`;

            if(pButtons.includes('extend_exp')) acts += `<div class="flex items-center gap-2 bg-gray-100 p-1 rounded"><input type="number" id="rd-extend-days" class="w-16 p-1 text-center border rounded text-sm" value="9"><button class="bg-teal-500 hover:bg-teal-600 text-white px-3 py-1 rounded shadow text-xs font-medium transition" onclick="doExtend()">Extend</button></div>`;
            
            if(acts) accHtml += buildAccordion('Actions', `<div class="flex flex-wrap gap-3">${acts}</div>`);
        }

        if(pAccs.acc_mt4) {
            let c = '';
            if(stu.mt4_data) c = createRow('Login', stu.mt4_server_id) + createRow('Pass', stu.mt4_data.mt4password) + createRow('Server', stu.mt4_data.mt4servername) + createRow('Expiry', stu.mt4_data.mt4expirydate);
            else if(stu.mt4_server_id) c = `<p class="text-red-500 text-sm">ID: ${stu.mt4_server_id} (Data Missing)</p>`;
            if(pButtons.includes('remove_mt4') && stu.mt4_server_id) c += `<div class="mt-4"><button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs" onclick="doAction('remove_mt4')">Remove MT4</button></div>`;
            let b = '';
            
            // --- NEW: MT4 SMART LOGIC (Conflict + Stock Override) ---
            let mt4Stock = (window.currentMeta && window.currentMeta.mt4_stock) ? parseInt(window.currentMeta.mt4_stock) : 0;
            let mt4Conflict = (window.currentMeta && window.currentMeta.mt4_conflict) || false;

            // Default Admin Settings
            let showAssign = !rdVars.mt4_only_renew;
            let showRenew = !rdVars.mt4_only_assign;
            let assignMsg = "";

            // 1. Conflict Check (Highest Priority)
            if (stu.mt4_server_id && mt4Conflict) {
                showAssign = true;
                showRenew = false; // Block renewal to prevent reviving shared expired user
                assignMsg = `<div class="w-full text-xs text-red-600 font-bold mb-2"><i class="fa-solid fa-triangle-exclamation"></i> Shared User Expired: Must Assign New.</div>`;
            } 
            // 2. Stock Availability Check (Overrides Admin Settings)
            else if (stu.mt4_server_id && stu.mt4_is_expired) {
                if (mt4Stock >= 4) {
                    showAssign = true;
                    showRenew = false; // Force Assign (Stock is healthy)
                } else {
                    showAssign = false;
                    showRenew = true; // Force Renew (Stock is low)
                }
            }

            // Render Assign Button
            if ((!stu.mt4_server_id || (stu.mt4_data && stu.mt4_is_expired)) && pButtons.includes('assign_mt4') && showAssign) {
                b += `<div class="flex flex-wrap items-center gap-2 mt-4 bg-blue-50 p-3 rounded border border-blue-100">${assignMsg}<select id="rd-mt4-type" class="p-2 border rounded text-sm bg-white"></select><button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAssignMT4()">Assign New</button></div>`; fetchDatetypes();
            }
            
            // Render Renew Button
            if (stu.mt4_server_id && stu.mt4_is_expired && pButtons.includes('renew_mt4') && showRenew) {
                b += `<div class="flex items-center gap-2 mt-4 bg-green-50 p-3 rounded border border-green-100">${getDurationSelect('rd-renew-mt4-dur')}<button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doRenewMT4()">Renew</button></div>`;
            }
            
            if(b) c += b;
            accHtml += buildAccordion(`MT4 Details ${stu.mt4_status_display||''}`, c);
        }

        if(pAccs.acc_vps) {
            let c = '';
            if(stu.vps_data) c = createRow('Host', stu.vps_data.host_name) + createRow('IP', stu.vps_data.vps_ip) + createRow('User', stu.vps_data.vps_user_id) + createRow('Pass', stu.vps_data.vps_password) + createRow('Expiry', stu.vps_data.vps_expier);
            else if(stu.vps_host_name) c = `<p class="text-gray-500 text-sm">Host: ${stu.vps_host_name}</p>`;
            if(pButtons.includes('remove_vps') && stu.vps_host_name) c += `<div class="mt-4"><button class="bg-red-500 hover:bg-red-600 text-white px-3 py-1 rounded text-xs" onclick="doAction('remove_vps')">Remove VPS</button></div>`;
            let b = '';
            
            // --- NEW: VPS SMART LOGIC (Conflict Only) ---
            // Note: Stock logic does NOT apply here per requirements.
            let vpsConflict = (window.currentMeta && window.currentMeta.vps_conflict) || false;

            // Default Admin Settings
            let showVpsAssign = !rdVars.vps_only_renew;
            let showVpsRenew = !rdVars.vps_only_assign;
            let vpsMsg = "";

            // 1. Conflict Check Only
            if (stu.vps_host_name && vpsConflict) {
                showVpsAssign = true;
                showVpsRenew = false;
                vpsMsg = `<div class="w-full text-xs text-red-600 font-bold mb-2"><i class="fa-solid fa-triangle-exclamation"></i> Shared User Expired: Must Assign New.</div>`;
            }
            
            // Render VPS Assign
            if ((!stu.vps_host_name || (stu.vps_data && stu.vps_is_expired)) && pButtons.includes('assign_vps') && showVpsAssign) {
                b += `<div class="mt-4 bg-blue-50 p-3 rounded border border-blue-100">${vpsMsg}<button class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('assign_vps')">Assign New VPS</button></div>`;
            }
            
            // Render VPS Renew
            if (stu.vps_host_name && stu.vps_is_expired && pButtons.includes('renew_vps') && showVpsRenew) {
                b += `<div class="flex items-center gap-2 mt-4 bg-green-50 p-3 rounded border border-green-100">${getDurationSelect('rd-renew-vps-dur')}<button class="bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doRenewVPS()">Renew</button></div>`;
            }
            
            if(b) c += b;
            accHtml += buildAccordion(`VPS Details ${stu.vps_status_display||''}`, c);
        }

        if(pAccs.acc_install) {
            let dl = '';
            if(stu.mt4_server_id) dl += `<div class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0"><span class="text-sm font-bold text-gray-700">MT4 Installer</span> <div class="flex gap-2"><button class="bg-gray-800 text-white px-3 py-1 rounded text-xs hover:bg-black transition" onclick="window.getBat('mt4_install','${stu.mt4_server_id}','dl')"><i class="fa-brands fa-windows"></i> Download</button> <button class="bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs hover:bg-gray-300 transition" onclick="window.getBat('mt4_install','${stu.mt4_server_id}','link')"><i class="fa-solid fa-link"></i> Link</button></div></div>`;
            if(stu.vps_host_name) dl += `<div class="flex justify-between items-center py-2 border-b border-gray-100 last:border-0"><span class="text-sm font-bold text-gray-700">VPS Connector</span> <div class="flex gap-2"><button class="bg-gray-800 text-white px-3 py-1 rounded text-xs hover:bg-black transition" onclick="window.getBat('vps_connect','${stu.vps_host_name}','dl')"><i class="fa-brands fa-windows"></i> Download</button> <button class="bg-gray-200 text-gray-700 px-3 py-1 rounded text-xs hover:bg-gray-300 transition" onclick="window.getBat('vps_connect','${stu.vps_host_name}','link')"><i class="fa-solid fa-link"></i> Link</button></div></div>`;
            if(dl) accHtml += buildAccordion('Install Software', dl);
        }

        if(pAccs.acc_copy) {
            let cp = '<div class="flex flex-wrap gap-2">';
            if(pButtons.includes('copy_tg')) cp += `<button class="bg-cyan-500 hover:bg-cyan-600 text-white px-3 py-2 rounded text-sm transition" onclick="doCopy('copy_tg')">Copy TG Link</button>`;
            if(pButtons.includes('copy_course')) cp += `<button class="bg-cyan-500 hover:bg-cyan-600 text-white px-3 py-2 rounded text-sm transition" onclick="doCopy('copy_course')">Copy Course Info</button>`;
            if(stu.vps_data && pButtons.includes('copy_vps_guide')) cp += `<button class="bg-cyan-500 hover:bg-cyan-600 text-white px-3 py-2 rounded text-sm transition" onclick="doCopy('copy_vps_guide')">Copy VPS Guide</button>`;
            cp += '</div>';
            if(cp.includes('button')) accHtml += buildAccordion('Copy Details', cp);
        }

        if(pAccs.acc_pay) {
            let pC = '<p class="text-gray-400 text-sm italic">No history available.</p>';
            if(stu.payment_history && stu.payment_history.length > 0) {
                pC = '<table class="w-full text-sm text-left"><thead><tr class="bg-gray-50 text-gray-500"><th class="p-2">Date</th><th class="p-2">Purpose</th><th class="p-2">Amount</th></tr></thead><tbody class="divide-y divide-gray-100">';
                stu.payment_history.forEach(p => pC += `<tr><td class="p-2">${p.created_at||'-'}</td><td class="p-2">${p.purpose||'-'}</td><td class="p-2 font-mono">${p.amount||'-'}</td></tr>`);
                pC += '</tbody></table>';
            }
            accHtml += buildAccordion('Payment History', pC);
        }

        if(pAccs.acc_access) {
            let ac = '<div class="flex flex-wrap gap-3">';
            if(pButtons.includes('auto_tg')) ac += `<button class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('auto_tg')">Email TG Access</button>`;
            if(pButtons.includes('auto_course')) ac += `<button class="bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition" onclick="doAction('auto_course')">Email Course Access</button>`;
            ac += '</div>';
            if(ac.includes('button')) accHtml += buildAccordion('Access Automation', ac);
        }

        if(pAccs.acc_offline) {
             try {
                let offC = '';
                let safeVars = (typeof rd_vars !== 'undefined') ? rd_vars : { offline_btns: [], offline_links: [], offline_copy: [] };
                
                // 1. Files (Download)
                let offBtns = safeVars.offline_btns || [];
                let hasFiles = false;
                let filesHtml = '<div class="mb-4"><h4 class="text-xs font-bold text-gray-400 uppercase mb-2">Files</h4><div class="flex flex-wrap gap-2">';
                offBtns.forEach((b, i) => {
                    let hasPerm = pButtons.includes('btn_offline_dl');
                    if(b.name && b.url && hasPerm) {
                        hasFiles = true;
                        filesHtml += `<a href="${b.url}" target="_blank" download class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition inline-flex items-center gap-2"><i class="fa-solid fa-download"></i> ${b.name}</a>`;
                    }
                });
                filesHtml += '</div></div>';
                if(hasFiles) offC += filesHtml;

                // 2. Direct Links (New Tab)
                let offLinks = safeVars.offline_links || [];
                let hasLinks = false;
                let linksHtml = '<div class="mb-4"><h4 class="text-xs font-bold text-gray-400 uppercase mb-2">Links</h4><div class="flex flex-wrap gap-2">';
                offLinks.forEach((b) => {
                    let hasPerm = pButtons.includes('btn_offline_lnk') || pButtons.includes('btn_offline_dl'); // Share/Fallback perm
                    if(b.name && b.url && hasPerm) {
                        hasLinks = true;
                        linksHtml += `<a href="${b.url}" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded shadow text-sm font-medium transition inline-flex items-center gap-2"><i class="fa-solid fa-arrow-up-right-from-square"></i> ${b.name}</a>`;
                    }
                });
                linksHtml += '</div></div>';
                if(hasLinks) offC += linksHtml;

                // 3. Copy Buttons (Text)
                let offCopy = safeVars.offline_copy || [];
                let hasText = false;
                let textHtml = '<div><h4 class="text-xs font-bold text-gray-400 uppercase mb-2">Copy Text</h4><div class="flex flex-wrap gap-2">';
                offCopy.forEach((b) => {
                    let hasPerm = pButtons.includes('btn_offline_cp') || pButtons.includes('btn_offline_dl');
                    if(b.name && b.text && hasPerm) {
                        hasText = true;
// ESCAPE ORDER: Backslashes first -> Then Quotes -> Then All Newline Types
let safeText = b.text
    .replace(/\\/g, '\\\\')       // 1. Escape backslashes
    .replace(/'/g, "\\'")         // 2. Escape single quotes
    .replace(/"/g, '&quot;')      // 3. Escape double quotes
    .replace(/(\r\n|\n|\r)/g, '\\n'); // 4. Escape ALL line breaks (\r, \n, or \r\n)
                        textHtml += `<button onclick="rd_copyText('${safeText}')" class="bg-gray-700 hover:bg-gray-800 text-white px-4 py-2 rounded shadow text-sm font-medium transition inline-flex items-center gap-2"><i class="fa-regular fa-copy"></i> ${b.name}</button>`;
                    }
                });
                textHtml += '</div></div>';
                if(hasText) offC += textHtml;

             if(offC) accHtml += buildAccordion('Offline Section', offC);
            } catch(e) { console.error(e); }
        }

        if(pAccs.acc_sop) {
            try {
                let safeVars = (typeof rd_vars !== 'undefined') ? rd_vars : { sop_content: '' };
                let sop = safeVars.sop_content || '<p class="text-gray-400 italic">No SOP content.</p>';
                // REMOVED 'text-gray-600' to allow inline styles/colors from Admin to show.
                // KEPT 'prose' to ensure lists and headings have proper spacing/defaults.
                accHtml += buildAccordion('SOP Details', '<div class="prose prose-sm max-w-none">'+sop+'</div>');
            } catch(e) {}
        }

        $('#rd-student-card-container').html(`<div class="bg-white shadow-lg rounded-lg border border-gray-200 overflow-hidden mb-6 animate-fade-in">${header}${details}${accHtml}</div>`);
    }

    // --- EXECUTION (Same as before) ---
    function loadCounters() {
        $.post(rd_ajax.ajax_url, { action: 'rd_fetch_counters', nonce: rd_ajax.nonce }, function(res) {
            if(res.success) {
                let d = res.data;
                $('#rd-counters-section').html(`
                <span class="bg-gray-100 px-3 py-1 rounded border border-gray-200 text-xs font-semibold text-gray-700">[MT4 Valid: ${d.mt4_total} | Used: ${d.mt4_used} | <span class="text-green-600">Free: ${d.mt4_free}</span>]</span>
                <span class="bg-gray-100 px-3 py-1 rounded border border-gray-200 text-xs font-semibold text-gray-700">[VPS Valid: ${d.vps_total} | Used: ${d.vps_used} | <span class="text-green-600">Free: ${d.vps_free}</span>]</span>
                <span class="bg-gray-100 px-3 py-1 rounded border border-gray-200 text-xs font-semibold text-gray-700">[VPS Staging: ${d.staging}]</span>`);
            }
        });
    }
    loadCounters();
    
    let searchCache=[], globalPerms={};
    $('#rd-student-search').on('input', function() {
        let val = $(this).val(); if(val.length < 3) return;
        $.post(rd_ajax.ajax_url, { action: 'rd_search_student', term: val, nonce: rd_ajax.nonce }, function(res) {
            if(res.success) {
                globalPerms = res.data.global_perms || {}; updateAddStudentBtn();
                searchCache = res.data.students || [];
                if(searchCache.length) {
                    let list = '';
                    searchCache.forEach((item, index) => {
                        list += `<li data-index="${index}" class="px-4 py-3 hover:bg-blue-50 cursor-pointer border-b border-gray-100 last:border-0 text-sm text-gray-700 flex justify-between items-center group"><span><span class="font-bold text-gray-900">${item.student_name}</span> <span class="text-gray-500 ml-1">(${item.student_phone})</span></span></li>`;
                    });
                    $('#rd-search-results').html(list).removeClass('hidden');
                } else { $('#rd-search-results').addClass('hidden'); }
            }
        });
    });

    $(document).on('click', '#rd-search-results li', function() {
        let idx = $(this).data('index');
        let stu = searchCache[idx];
        if(stu) {
            $('#rd-search-results').addClass('hidden');
            $('#rd-student-search').val('');
            $.post(rd_ajax.ajax_url, {action:'rd_get_single_student', id:stu.id, nonce:rd_ajax.nonce}, function(r){
                if(r.success) renderStudentCard(r.data.stu, r.data.perms, r.data.labels, r.data.meta);
            });
        }
    });

    $('.rd-search-wrapper').append('<div id="rd-add-btn-container"></div>');
    function updateAddStudentBtn() {
        if(globalPerms.can_add_student) $('#rd-add-btn-container').html('<button id="rd-open-add-student" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-3 rounded-full shadow transition flex items-center gap-2 whitespace-nowrap text-sm font-medium ml-4"><i class="fa-solid fa-user-plus"></i> Add Student</button>');
        else $('#rd-add-btn-container').empty();
    }

    $(document).on('click', '#rd-open-add-student', function(e) { e.preventDefault(); $('#rd-add-student-form')[0].reset(); $.post(rd_ajax.ajax_url, {action: 'rd_get_form_options', nonce: rd_ajax.nonce}, function(r) { if(r.success) { let types = ''; r.data.types.forEach(t => types += `<option value="${t}">${t}</option>`); $('#new_software_type').html(types); let states = '<option value="">Select State</option>'; r.data.states.forEach(s => states += `<option value="${s}">${s}</option>`); $('#new_state').html(states); } }); $('#rd-add-student-modal').removeClass('hidden').addClass('flex'); });

    $('#rd-add-student-save').click(function() { let phone = $('#new_student_phone').val(); if(!/^\d{10}$/.test(phone)) { showToast('Phone must be 10 digits', true); return; } let data = { date_created: $('#new_entry_date').val(), software_type: $('#new_software_type').val(), student_name: $('#new_student_name').val(), student_email: $('#new_student_email').val(), student_phone: phone, state: $('#new_state').val(), student_expiry_date: $('#new_expiry_date').val() }; $.post(rd_ajax.ajax_url, {action: 'rd_add_new_student', ...data, nonce: rd_ajax.nonce}, function(r) { if(r.success) { showToast(r.data); $('#rd-add-student-modal').addClass('hidden').removeClass('flex'); } else showToast(r.data, true); }); });

    $(document).on('click', '.rd-close-modal, #rd-edit-cancel, #rd-add-student-cancel', function(e) { e.preventDefault(); $('#rd-edit-modal').addClass('hidden').removeClass('flex'); $('#rd-add-student-modal').addClass('hidden').removeClass('flex'); });
    $(document).on('click', '#rd-edit-modal, #rd-add-student-modal', function(e) { if(e.target === this) { $(this).addClass('hidden').removeClass('flex'); } });

    $(document).on('click', '#rd-student-card-container details summary', function() {
        let details = $(this).parent();
        if(!details.prop('open')) $('#rd-student-card-container details').not(details).removeAttr('open');
    });

    $(document).on('click', '.rd-edit-profile-btn', function(e) { e.preventDefault(); let s = window.currentStu, p = window.currentPerms; if(!s) return; $('#edit_student_id').val(s.id); $('[data-group="primary"]').toggle(p.can_edit_primary); if(p.can_edit_primary) { $('#edit_name').val(s.student_name); $('#edit_email').val(s.student_email); $('#edit_phone').val(s.student_phone); $('#edit_expiry').val(s.student_expiry_date); } $('[data-group="secondary"]').toggle(p.can_edit_secondary); if(p.can_edit_secondary) { $('#edit_phone_alt').val(s.student_phone_alt); $('#edit_anydesk').val(s.anydesk_id); } $('#rd-edit-modal').removeClass('hidden').addClass('flex'); });
    $('#rd-edit-save').click(function() { let d = { student_name: $('#edit_name').val(), student_email: $('#edit_email').val(), student_phone: $('#edit_phone').val(), student_expiry_date: $('#edit_expiry').val(), student_phone_alt: $('#edit_phone_alt').val(), anydesk_id: $('#edit_anydesk').val() }; $.post(rd_ajax.ajax_url, {action: 'rd_update_student_profile', id: $('#edit_student_id').val(), data: d, nonce: rd_ajax.nonce}, function(r) { showToast(r.success?'Saved!':r.data, !r.success); if(r.success) { $('#rd-edit-modal').addClass('hidden').removeClass('flex'); reloadCard(); } }); });

});
