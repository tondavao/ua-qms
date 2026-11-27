/**
 * UA QMS Profile Settings -> Security & MFA Tab Controller
 * Enhances #profile-settings-modal cleanly without modifying existing page layouts.
 */
(function() {
    if (window.QmsMfaSettings) return;

    const QRCODE_CDN = 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js';
    let qrPromise = null;

    function ensureQrLib() {
        if (typeof window.QRCode !== 'undefined') return Promise.resolve();
        if (qrPromise) return qrPromise;
        qrPromise = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = QRCODE_CDN;
            s.onload = () => resolve();
            s.onerror = () => reject(new Error('Failed to load QR generator'));
            document.head.appendChild(s);
        });
        return qrPromise;
    }

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[ch]));
    }

    class MfaSettingsUI {
        constructor() {
            this.status = null;
            this.injected = false;
            this.initWhenReady();
        }

        initWhenReady() {
            const run = () => this.injectIntoProfileModal();
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }
        }

        injectIntoProfileModal() {
            const modal = document.getElementById('profile-settings-modal');
            const form = document.getElementById('profile-settings-form');
            if (!modal || !form || document.getElementById('profile-mfa-tab-bar')) return;

            // Allow tall content to scroll comfortably inside the modal card
            const card = form.parentElement;
            if (card) {
                card.classList.add('max-h-[90vh]');
                form.classList.add('overflow-y-auto');
            }

            // Insert compact tab switcher right above the profile form
            const tabBarHtml = `
                <div id="profile-mfa-tab-bar" class="flex border-b border-gray-200 bg-gray-50 px-6 flex-shrink-0">
                    <button type="button" id="prof-tab-profile" onclick="QmsMfaSettings.switchTab('profile')" class="py-2.5 px-3 text-xs font-bold border-b-2 border-[#800000] text-[#800000] transition">
                        <i class="fas fa-user mr-1"></i> Profile
                    </button>
                    <button type="button" id="prof-tab-mfa" onclick="QmsMfaSettings.switchTab('mfa')" class="py-2.5 px-3 text-xs font-bold border-b-2 border-transparent text-gray-500 hover:text-[#800000] transition">
                        <i class="fas fa-shield-alt mr-1"></i> Security &amp; MFA
                    </button>
                </div>
            `;
            form.insertAdjacentHTML('beforebegin', tabBarHtml);

            // Insert Security & MFA panel right after the profile form
            const panelHtml = `
                <div id="profile-mfa-panel" class="hidden p-6 space-y-4 overflow-y-auto text-sm">
                    <div id="mfa-panel-msg" class="hidden p-3 rounded-lg text-xs font-semibold"></div>
                    <div id="mfa-panel-body">
                        <div class="py-8 text-center text-gray-400 text-xs">
                            <i class="fas fa-spinner fa-spin text-lg text-[#800000] mb-2 block"></i>
                            Loading security settings...
                        </div>
                    </div>
                </div>
            `;
            form.insertAdjacentHTML('afterend', panelHtml);

            // Hook openProfileSettingsModal so it always opens on Profile tab first
            if (typeof window.openProfileSettingsModal === 'function' && !window._mfaHookedOpenProfile) {
                window._mfaHookedOpenProfile = true;
                const origOpen = window.openProfileSettingsModal;
                window.openProfileSettingsModal = function() {
                    origOpen.apply(this, arguments);
                    window.QmsMfaSettings.switchTab('profile');
                };
            }
            this.injected = true;
        }

        switchTab(tab) {
            const form = document.getElementById('profile-settings-form');
            const panel = document.getElementById('profile-mfa-panel');
            const btnProf = document.getElementById('prof-tab-profile');
            const btnMfa = document.getElementById('prof-tab-mfa');
            if (!form || !panel) return;

            if (tab === 'mfa') {
                form.classList.add('hidden');
                panel.classList.remove('hidden');
                btnProf.className = 'py-2.5 px-3 text-xs font-bold border-b-2 border-transparent text-gray-500 hover:text-[#800000] transition';
                btnMfa.className = 'py-2.5 px-3 text-xs font-bold border-b-2 border-[#800000] text-[#800000] transition';
                this.loadStatus();
            } else {
                panel.classList.add('hidden');
                form.classList.remove('hidden');
                btnProf.className = 'py-2.5 px-3 text-xs font-bold border-b-2 border-[#800000] text-[#800000] transition';
                btnMfa.className = 'py-2.5 px-3 text-xs font-bold border-b-2 border-transparent text-gray-500 hover:text-[#800000] transition';
            }
        }

        showMsg(text, isError = false) {
            const el = document.getElementById('mfa-panel-msg');
            if (!el) return;
            el.className = isError
                ? 'p-3 rounded-lg text-xs font-semibold bg-red-50 border border-red-200 text-red-800'
                : 'p-3 rounded-lg text-xs font-semibold bg-green-50 border border-green-200 text-green-800';
            el.textContent = text;
            el.classList.remove('hidden');
        }

        clearMsg() {
            const el = document.getElementById('mfa-panel-msg');
            if (el) el.classList.add('hidden');
        }

        async loadStatus() {
            this.clearMsg();
            const body = document.getElementById('mfa-panel-body');
            if (!body) return;
            try {
                const res = await fetch('../../api/mfa_api.php?action=get_status&_t=' + Date.now(), { cache: 'no-store' });
                const json = await res.json();
                if (!json.success) throw new Error(json.error || 'Failed to load MFA status');
                this.status = json.data;
                this.renderStatus();
            } catch (err) {
                body.innerHTML = `<p class="text-xs text-red-600 text-center py-6">${esc(err.message)}</p>`;
            }
        }

        renderStatus(freshBackupCodes = null) {
            const body = document.getElementById('mfa-panel-body');
            const s = this.status;
            if (!body || !s) return;

            const isActive = s.mfa_enabled || s.global_required;
            const statusBadge = isActive
                ? `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-100 text-emerald-800"><i class="fas fa-check-circle mr-1"></i>Protected</span>`
                : `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-gray-100 text-gray-600">Off</span>`;

            let backupHtml = '';
            if (Array.isArray(freshBackupCodes) && freshBackupCodes.length > 0) {
                backupHtml = `
                    <div class="p-3 bg-amber-50 border border-amber-300 rounded-lg space-y-2">
                        <div class="text-xs font-bold text-amber-900 flex items-center gap-1.5">
                            <i class="fas fa-key"></i> Save Your One-Time Backup Recovery Codes
                        </div>
                        <p class="text-[11px] text-amber-800">Store these safely. Each code can be used once if you lose access to your phone or email:</p>
                        <div class="grid grid-cols-2 gap-1.5 font-mono text-xs font-bold text-gray-900 bg-white p-2.5 rounded border border-amber-200">
                            ${freshBackupCodes.map(c => `<div class="text-center py-0.5">${esc(c)}</div>`).join('')}
                        </div>
                    </div>
                `;
            }

            const devicesHtml = (s.trusted_devices && s.trusted_devices.length > 0)
                ? s.trusted_devices.map(d => `
                    <div class="flex items-center justify-between gap-2 p-2.5 bg-gray-50 rounded-lg border border-gray-200">
                        <div class="min-w-0">
                            <div class="text-xs font-bold text-gray-800 truncate">
                                ${esc(d.device_name)}
                                ${d.is_current ? '<span class="ml-1 px-1.5 py-0.5 text-[9px] rounded bg-emerald-100 text-emerald-800">This Device</span>' : ''}
                            </div>
                            <div class="text-[10px] text-gray-500">IP: ${esc(d.ip_address || '-')} &middot; Last used: ${esc(d.last_used_at)}</div>
                        </div>
                        <button type="button" onclick="QmsMfaSettings.revokeDevice(${d.device_id})" class="px-2 py-1 text-[10px] font-bold text-red-700 bg-red-50 hover:bg-red-700 hover:text-white border border-red-200 rounded transition flex-shrink-0">
                            Revoke
                        </button>
                    </div>
                `).join('')
                : `<p class="text-[11px] text-gray-400 text-center py-2">No trusted devices saved yet.</p>`;

            body.innerHTML = `
                <div class="space-y-4">
                    <div class="p-3.5 bg-gray-50 border border-gray-200 rounded-xl flex items-start justify-between gap-3">
                        <div>
                            <div class="text-xs font-bold text-gray-900 flex items-center gap-2">
                                New-Device Multi-Factor Authentication ${statusBadge}
                            </div>
                            <p class="text-[11px] text-gray-500 mt-1 leading-relaxed">
                                When enabled, signing in from a <strong>different device or browser</strong> requires a 6-digit verification code from your Authenticator App or Email.
                            </p>
                            ${s.global_required ? '<p class="text-[11px] text-[#800000] font-semibold mt-1"><i class="fas fa-lock mr-1"></i>Required for all accounts by QMO Admin policy.</p>' : ''}
                        </div>
                    </div>

                    ${backupHtml}

                    <!-- Setup / Method Controls -->
                    <div id="mfa-setup-area" class="space-y-2.5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <button type="button" onclick="QmsMfaSettings.beginTotpSetup()" class="px-3 py-2.5 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5 shadow-sm">
                                <i class="fas fa-qrcode"></i> ${s.totp_verified ? 'Reconfigure Authenticator' : 'Set Up Authenticator App'}
                            </button>
                            <button type="button" onclick="QmsMfaSettings.enableEmailMfa()" class="px-3 py-2.5 bg-white hover:bg-red-50 text-[#800000] border border-red-200 rounded-lg text-xs font-bold transition flex items-center justify-center gap-1.5">
                                <i class="fas fa-envelope"></i> ${s.mfa_enabled && s.preferred_method === 'email' ? 'Email OTP Active' : 'Use Email OTP Fallback'}
                            </button>
                        </div>

                        ${isActive ? `
                            <div class="flex flex-wrap items-center justify-between gap-2 pt-1 text-[11px] text-gray-600">
                                <span>Backup codes left: <strong>${s.backup_codes_remaining}</strong></span>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="QmsMfaSettings.regenerateBackupCodes()" class="text-[#800000] hover:underline font-bold">Regenerate Backup Codes</button>
                                    ${!s.global_required && s.mfa_enabled ? `<button type="button" onclick="QmsMfaSettings.disableMfa()" class="text-red-600 hover:underline font-bold">Disable MFA</button>` : ''}
                                </div>
                            </div>
                        ` : ''}
                    </div>

                    <!-- Trusted Devices -->
                    <div class="border-t border-gray-200 pt-3 space-y-2">
                        <div class="flex items-center justify-between">
                            <h4 class="text-xs font-bold text-gray-700 uppercase tracking-wider">Trusted Devices (30 Days)</h4>
                            ${s.trusted_devices && s.trusted_devices.length > 0 ? `<button type="button" onclick="QmsMfaSettings.revokeAllDevices()" class="text-[11px] font-bold text-red-700 hover:underline">Revoke All</button>` : ''}
                        </div>
                        <div class="space-y-1.5 max-h-40 overflow-y-auto">
                            ${devicesHtml}
                        </div>
                    </div>
                </div>
            `;
        }

        async beginTotpSetup() {
            this.clearMsg();
            const area = document.getElementById('mfa-setup-area');
            if (!area) return;
            area.innerHTML = `<div class="p-4 text-center text-xs text-gray-500"><i class="fas fa-spinner fa-spin text-[#800000] mr-1"></i> Generating authenticator key...</div>`;
            try {
                const res = await fetch('../../api/mfa_api.php?action=begin_totp_setup', { method: 'POST' });
                const data = await res.json();
                if (!data.success) throw new Error(data.error || 'Setup failed');

                area.innerHTML = `
                    <div class="p-4 bg-white border border-red-200 rounded-xl space-y-3">
                        <div class="text-xs font-bold text-[#800000]"><i class="fas fa-mobile-alt mr-1"></i> Step 1: Scan QR Code in Google / Microsoft Authenticator</div>
                        <div id="mfa-qr-box" class="flex justify-center p-2 bg-white"></div>
                        <div class="text-[11px] text-gray-600 text-center">
                            Or enter this manual key:<br>
                            <code class="inline-block mt-1 px-2.5 py-1 bg-gray-100 rounded font-bold text-gray-900 select-all tracking-wider">${esc(data.secret)}</code>
                        </div>
                        <div class="pt-2 border-t border-gray-100 space-y-2">
                            <label class="block text-xs font-bold text-gray-700">Step 2: Enter 6-Digit Code from App</label>
                            <div class="flex gap-2">
                                <input type="text" id="mfa-totp-verify-input" maxlength="6" inputmode="numeric" placeholder="000000" class="flex-1 px-3 py-2 border border-gray-300 rounded-lg text-center font-bold tracking-widest text-sm focus:ring-2 focus:ring-[#800000] outline-none">
                                <button type="button" onclick="QmsMfaSettings.confirmTotpSetup()" class="px-4 py-2 bg-[#800000] hover:bg-red-900 text-white rounded-lg text-xs font-bold transition">Verify</button>
                                <button type="button" onclick="QmsMfaSettings.renderStatus()" class="px-3 py-2 border border-gray-300 text-gray-600 rounded-lg text-xs font-bold hover:bg-gray-50">Cancel</button>
                            </div>
                        </div>
                    </div>
                `;

                try {
                    await ensureQrLib();
                    const qrBox = document.getElementById('mfa-qr-box');
                    if (qrBox && typeof window.QRCode !== 'undefined') {
                        qrBox.innerHTML = '';
                        new window.QRCode(qrBox, {
                            text: data.otpauth_uri,
                            width: 168,
                            height: 168,
                            colorDark: '#111827',
                            colorLight: '#ffffff',
                        });
                    }
                } catch (qrErr) {
                    const qrBox = document.getElementById('mfa-qr-box');
                    if (qrBox) qrBox.innerHTML = '<p class="text-[11px] text-amber-700">QR library offline — enter the manual key below in your Authenticator app.</p>';
                }
            } catch (err) {
                this.showMsg(err.message, true);
                this.renderStatus();
            }
        }

        async confirmTotpSetup() {
            const code = (document.getElementById('mfa-totp-verify-input')?.value || '').trim();
            if (!/^\d{6}$/.test(code)) {
                this.showMsg('Please enter the 6-digit code from your authenticator app.', true);
                return;
            }
            const res = await fetch('../../api/mfa_api.php?action=confirm_totp_setup', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ code })
            });
            const data = await res.json();
            if (!data.success) {
                this.showMsg(data.error || 'Verification failed.', true);
                return;
            }
            this.showMsg(data.message, false);
            await this.refreshStatusWithBackupCodes(data.backup_codes || null);
        }

        async enableEmailMfa() {
            this.clearMsg();
            const res = await fetch('../../api/mfa_api.php?action=enable_email_mfa', { method: 'POST' });
            const data = await res.json();
            if (!data.success) {
                this.showMsg(data.error || 'Could not enable Email OTP.', true);
                return;
            }
            this.showMsg(data.message, false);
            await this.refreshStatusWithBackupCodes(data.backup_codes || null);
        }

        async refreshStatusWithBackupCodes(backupCodes) {
            const res = await fetch('../../api/mfa_api.php?action=get_status&_t=' + Date.now(), { cache: 'no-store' });
            const json = await res.json();
            if (json.success) {
                this.status = json.data;
                this.renderStatus(backupCodes);
            }
        }

        async regenerateBackupCodes() {
            const password = prompt('Enter your current password to regenerate backup recovery codes:');
            if (!password) return;
            const res = await fetch('../../api/mfa_api.php?action=regenerate_backup_codes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password })
            });
            const data = await res.json();
            if (!data.success) {
                this.showMsg(data.error || 'Failed to regenerate codes.', true);
                return;
            }
            this.showMsg(data.message, false);
            await this.refreshStatusWithBackupCodes(data.backup_codes || null);
        }

        async disableMfa() {
            const password = prompt('Enter your current password to disable Multi-Factor Authentication:');
            if (!password) return;
            const res = await fetch('../../api/mfa_api.php?action=disable_mfa', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ password })
            });
            const data = await res.json();
            if (!data.success) {
                this.showMsg(data.error || 'Failed to disable MFA.', true);
                return;
            }
            this.showMsg(data.message, false);
            await this.loadStatus();
        }

        async revokeDevice(deviceId) {
            const res = await fetch('../../api/mfa_api.php?action=revoke_device', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ device_id: deviceId })
            });
            const data = await res.json();
            if (data.success) {
                this.showMsg(data.message, false);
                await this.loadStatus();
            } else {
                this.showMsg(data.error || 'Failed to revoke device.', true);
            }
        }

        async revokeAllDevices() {
            if (!confirm('Remove all trusted devices? You will be prompted for MFA on your next login.')) return;
            const res = await fetch('../../api/mfa_api.php?action=revoke_all_devices', { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                this.showMsg(data.message, false);
                await this.loadStatus();
            }
        }
    }

    window.QmsMfaSettings = new MfaSettingsUI();
})();
