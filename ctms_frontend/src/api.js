// ============================================================
// api.js — CTMS Central API Bridge
// ============================================================
import axios from 'axios';

const isViteDev = ['5173', '5174'].includes(window.location.port);
const projectPath = (import.meta.env.VITE_CTMS_PROJECT_PATH || '/ctms').replace(/\/+$/, '');
const BASE = isViteDev ? '/ctms_api' : `${projectPath}/ctms_server/api`;

/** Downloads follow the same route as API requests, including Vite's Apache proxy. */
export const API_DOWNLOAD_BASE = isViteDev
  ? `${window.location.origin}/ctms_api`
  : `${window.location.protocol}//${window.location.host}${projectPath}/ctms_server/api`;

export const saveToken  = (token) => localStorage.setItem('ctms_token', token);
export const getToken   = ()      => localStorage.getItem('ctms_token');
export const clearToken = ()      => localStorage.removeItem('ctms_token');

// A-2: one-time download tickets — POST to a generator, open the URL
// it returns (the URL carries a single-use ticket, never the bearer token).
export const getDownloadTicket = async (file, payload) => {
  const res = await api.post(`/${file}`, payload);
  const d = res.data || {};
  if (!d.ticket) throw new Error(d.error || 'Could not issue download ticket.');
  const url = `${API_DOWNLOAD_BASE}/${d.url || `${file}?ticket=${encodeURIComponent(d.ticket)}`}`;
  return isViteDev && file === 'report_generate.php' ? `${url}&asset_proxy=1` : url;
};


// A-4: self-service credential rotation
export const changeOwnPassword = (currentPassword, newPassword) =>
  api.post('/auth.php', { action: 'change_password', current_password: currentPassword, new_password: newPassword });

// End-to-end encrypted, append-only team chat shared across CTMS apps.
export const fetchTeamChat = (beforeId = 0, afterId = 0) => {
  const query = new URLSearchParams();
  if (beforeId) query.set('before_id', String(beforeId));
  if (afterId) query.set('after_id', String(afterId));
  const queryString = query.toString();
  return api.get(`/chat.php${queryString ? `?${queryString}` : ''}`);
};
export const registerTeamChatIdentity = (identity) => api.post('/chat.php', { action: 'register_identity', ...identity });
export const sendTeamChatMessage = (message) => api.post('/chat.php', { action: 'send', ...message });
export const hideTeamChatMessage = (messageId) => api.post('/chat.php', { action: 'hide_for_me', message_id: messageId });

// Audit fix (UTC date bug): toISOString() is UTC, which can differ from the
// browser's local calendar date. Use the configured display-time convention.
// it yields YESTERDAY's date between 00:00 and 04:59 local, silently
// mislabelling cheques and payments for the whole early shift.
export const localYmd = (d = new Date()) => {
  const p = n => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
};

const api = axios.create({ baseURL: BASE });
api.interceptors.request.use((config) => {
  const token = getToken();
  if (token) config.headers['Authorization'] = `Bearer ${token}`;
  return config;
});
// PHP/XAMPP sometimes emits warnings before JSON — extract the JSON object
api.interceptors.response.use((response) => {
  if (typeof response.data === 'string') {
    const s = response.data.trim();
    const start = s.indexOf('{');
    const startArr = s.indexOf('[');
    const idx = start >= 0 && (startArr < 0 || start < startArr) ? start : startArr;
    if (idx >= 0) {
      try { response.data = JSON.parse(s.slice(idx)); } catch { /* keep string */ }
    }
  }
  return response;
}, (error) => {
  // Session-expiry handling: a 401 from any endpoint (except the login
  // call itself) means the token died — 8-hour expiry or a credential
  // rotation. End the session cleanly and return to the login screen
  // instead of letting every tab show "backend offline".
  const status = error.response?.status;
  const url = String(error.config?.url || '');
  let requestAction = '';
  try { requestAction = JSON.parse(error.config?.data || '{}').action || ''; } catch {}
  const isLoginCall = url.includes('auth.php') || (url.includes('mfa.php') && ['setup_init', 'setup_confirm', 'login_verify'].includes(requestAction));
  if (status === 401 && !isLoginCall) {
    clearToken();
    localStorage.removeItem('ctms_session');
    localStorage.setItem('ctms_expired', '1');
    if (!window.location.hash.includes('expired')) {
      window.location.hash = 'expired';
      window.location.reload();
    }
    return Promise.reject(new Error('Your session has ended. Please log in again.'));
  }
  return Promise.reject(error);
});

// AUTH
export const getLoginCaptcha = () => api.get('/captcha.php');
export const loginUser = (employee_id, password, captcha_id, captcha_answer) =>
  api.post('/auth.php', { employee_id, password, captcha_id, captcha_answer });
export const beginMfaSetup = (ticket) => api.post('/mfa.php', { action: 'setup_init', ticket });
export const confirmMfaSetup = (ticket, code) => api.post('/mfa.php', { action: 'setup_confirm', ticket, code });
export const verifyMfaLogin = (ticket, code) => api.post('/mfa.php', { action: 'login_verify', ticket, code });
export const fetchAuthSecurityAlerts = () => api.post('/mfa.php', { action: 'security_alerts' });
export const resetUserMfa = (user_id) => api.post('/mfa.php', { action: 'reset_user', user_id });

// Forgot password — no token needed (called from login page)
export const sendForgotPassword = (email) =>
  axios.post(`${BASE}/cheques.php?action=notification`, { email });

// LOTS
export const fetchAllLots = ()     => api.get('/lots.php');
export const createLot    = (data) => api.post('/lots.php', data);
export const deleteLot    = (id)   => api.delete(`/lots.php?id=${id}`);

// SAMPLES
export const fetchSamplesForLot = (lot_id) => api.get(`/samples.php?lot_id=${lot_id}`);
export const createSamples      = (data)   => api.post('/samples.php', data);
export const updateSampleStatus = (data)   => api.put('/samples.php', data);
export const fetchCustodySamples = (lotId) => api.get(`/sample_custody.php?lot_id=${lotId}`);
export const fetchCustodyTimeline = (sampleId) => api.get(`/sample_custody.php?sample_id=${sampleId}`);
export const recordCustodyEvent = (data) => api.post('/sample_custody.php', data);
export const fetchLotAccessConfig = (userId) => api.get(`/lot_access.php?user_id=${userId}`);
export const saveLotAccessConfig = (data) => api.post('/lot_access.php', data);

// TEST RESULTS
export const submitTestResults = (data) => api.post('/results.php', data);
export const fetchResultFields = (lab_type) => api.get(`/result_fields.php?lab_type=${encodeURIComponent(lab_type)}`);
export const saveResultField = (lab_type, data) => api.post(`/result_fields.php?lab_type=${encodeURIComponent(lab_type)}`, data);

// USERS
export const fetchUsers       = ()     => api.get('/users.php');
export const createUser       = (data) => api.post('/users.php', data);
export const updateUser       = (data) => api.put('/users.php', data);
export const deleteUser       = (id)   => api.delete(`/users.php?id=${id}`);

// ANALYTICS
export const fetchAnalytics = () => api.get('/analytics.php');

// CHEQUES
export const fetchCheques   = (type, date) => api.get(`/cheques.php?type=${type}${date ? '&date='+date : ''}`);
export const saveCheque     = (data)        => api.post('/cheques.php', data);
export const updateCheque   = (data)        => api.put('/cheques.php', data);
export const deleteCheque   = (id)          => api.delete(`/cheques.php?id=${id}`);

// Notifications — mark a single notification as read so it disappears
// from the badge count without requiring a logout/login cycle
export const markNotificationRead = (id) =>
  api.post('/cheques.php', { mark_read: id });

// Durable staff broadcasts, automatic lot notices, and per-user read receipts.
export const fetchStaffNotifications = () => api.get('/notifications_center.php');
export const markStaffNotificationRead = (id) => api.post('/notifications_center.php', { action: 'mark_read', id });
export const fetchAnnouncementHistory = () => api.get('/notifications_center.php?admin=1');
export const publishAnnouncement = (data) => api.post('/notifications_center.php', { ...data, action: 'create_broadcast' });
export const fetchAnnouncementReceipts = (id) => api.post('/notifications_center.php', { action: 'receipts', id });

export default api;

// AUDIT TRAIL + ARCHIVE
export const searchAudit   = (query)  => api.get(`/audit.php?search=${encodeURIComponent(query)}`);
export const getLotAudit   = (lot_id) => api.get(`/audit.php?lot_id=${lot_id}`);
export const archiveLot    = (data)   => api.post('/audit.php', { ...data, action: 'archive' });
export const unarchiveLot  = (data)   => api.post('/audit.php', { ...data, action: 'unarchive' });
export const flagCorrection = (data)  => api.post('/audit.php', { ...data, action: 'flag_correction' });
export const revertCorrection = (data)=> api.post('/audit.php', { ...data, action: 'revert_correction' });
export const fetchLotsArchived = ()   => api.get('/lots.php?include_archived=1');

// INVOICE / QUOTATION / CLIENT
export const fetchInvoices      = (params='') => api.get(`/invoice.php?resource=invoices${params}`);
export const fetchQuotations    = (params='') => api.get(`/invoice.php?resource=quotations${params}`);
export const fetchClients       = (search='') => api.get(`/invoice.php?resource=clients${search ? '&search='+encodeURIComponent(search) : ''}`);
export const fetchClientHistory = (company)   => api.get(`/invoice.php?resource=client_history&company=${encodeURIComponent(company)}`);
export const fetchPriceMaster   = ()          => api.get('/invoice.php?resource=price_master');
export const fetchSettings      = ()          => api.get('/invoice.php?resource=settings');
export const fetchClientAnalytics = ()        => api.get('/invoice.php?resource=analytics');

export const createClient       = (data) => api.post('/invoice.php', { ...data, resource:'client' });
export const createQuotation    = (data) => api.post('/invoice.php', { ...data, resource:'quotation' });
const invoiceRequestKey = (data) => {
  const session = (() => { try { return JSON.parse(localStorage.getItem('ctms_session') || '{}'); } catch { return {}; } })();
  const canonical = JSON.stringify(data);
  let hash = 2166136261;
  let hash2 = 5381;
  for (let i = 0; i < canonical.length; i++) {
    const code = canonical.charCodeAt(i);
    hash ^= code; hash = Math.imul(hash, 16777619);
    hash2 = Math.imul(hash2, 33) ^ code;
  }
  const fingerprint = `${(hash >>> 0).toString(16)}${(hash2 >>> 0).toString(16)}`;
  const storageKey = `ctms:invoice-submit:v1:${session.userId || 'user'}:${fingerprint}:${canonical.length}`;
  let key = '';
  try { key = localStorage.getItem(storageKey) || ''; } catch { /* storage may be disabled */ }
  if (!key) {
    key = globalThis.crypto?.randomUUID?.() || `inv-${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
    try { localStorage.setItem(storageKey, key); } catch { /* request still proceeds; server handles submitted key */ }
  }
  return { key, storageKey };
};
export const createInvoice = (data) => {
  const { key, storageKey } = invoiceRequestKey(data);
  return api.post('/invoice.php', { ...data, resource: 'invoice' }, { headers: { 'Idempotency-Key': key } })
    .then((response) => { try { localStorage.removeItem(storageKey); } catch { /* storage may be disabled */ } return response; });
};
export const updatePriceItem    = (data) => api.post('/invoice.php', { ...data, resource:'price_master_update' });
export const updateSetting      = (data) => api.post('/invoice.php', { ...data, resource:'setting' });

export const markInvoicePaid    = (data) => api.put('/invoice.php',  { ...data, resource:'invoice_pay' });
export const updateInvoiceStatus  = (data) => api.put('/invoice.php', { ...data, resource:'invoice_status' });
export const updateQuotationStatus= (data) => api.put('/invoice.php', { ...data, resource:'quotation_status' });

export const deleteInvoice      = (id) => api.delete(`/invoice.php?resource=invoice&id=${id}`);
export const deleteQuotation    = (id) => api.delete(`/invoice.php?resource=quotation&id=${id}`);

// Recent activity browser — for bulk review (e.g. "show all cheques created today")
export const fetchRecentAudit = (params='') => api.get(`/audit.php?recent=1${params}`);

// REPORT ARCHIVE SEARCH — registry of every generated report
// (steel + electronics). q matches report no, lot no, sample type,
// SD/ref no, client; year='' = all years; dispatch filter for the
// dispatch register (all|open|done).

// ── Finance / TAT / templates / equipment / dispatch ──────────
export const fetchReceipts     = ()            => api.get('/finance.php?resource=receipts');
export const fetchAgeing       = ()            => api.get('/finance.php?resource=ageing');
export const fetchTatBoard     = (overdue=0)   => api.get(`/finance.php?resource=tat&overdue=${overdue}`);
export const fetchTemplates    = ()            => api.get('/finance.php?resource=templates');
export const fetchEquipment    = ()            => api.get('/finance.php?resource=equipment');
export const addReceipt        = (data)        => api.post('/finance.php', { ...data, resource: 'receipt' });
export const allocateReceipt   = (data)        => api.post('/finance.php', { ...data, resource: 'allocate' });
export const saveTemplate      = (data)        => api.post('/finance.php', { ...data, resource: 'template' });
export const saveEquipment     = (data)        => api.post('/finance.php', { ...data, resource: 'equipment' });
export const deleteEquipment   = (id)          => api.post('/finance.php', { id, resource: 'equipment_delete' });
export const dispatchReport    = (data)        => api.post('/report_archive.php', { ...data, do: 'dispatch' });
export const undispatchReport  = (id)          => api.post('/report_archive.php', { id, do: 'undispatch' });
export const fetchReportArchive = (q, year, limit = 200, dispatch = 'all') =>
  api.get(`/report_archive.php?q=${encodeURIComponent(q)}&year=${year || ''}&limit=${limit}&dispatch=${dispatch}`);
export const fetchReportWorkflow = () => api.get('/report_workflow.php');
export const configureReportWorkflow = (data) => api.post('/report_workflow.php', { ...data, action: 'configure' });
export const updateReportWorkflow = (data) => api.post('/report_workflow.php', data);

// ── Lot 200+ Archiving & Email Dispatch ──────────
export const fetchLotArchivePreview = (lotId) =>
  api.get(`/lot_archive_zip.php?lot_id=${lotId}&action=preview`);

export const buildLotArchiveZip = (lotId, format = 'pdf') =>
  api.get(`/lot_archive_zip.php?lot_id=${lotId}&action=build&format=${encodeURIComponent(format)}`);

/** Resolve backend-provided archive URLs through the same Vite/Apache route as API calls. */
export const resolveDownloadUrl = (url) => {
  const value = String(url || '');
  const archivePath = value.match(/(?:^|\/)lot_archive_zip\.php(?:\?.*)?$/i)?.[0]?.replace(/^\//, '');
  if (archivePath) return `${API_DOWNLOAD_BASE}/${archivePath}`;
  return new URL(value, `${API_DOWNLOAD_BASE}/`).href;
};

export const downloadLotArchiveZip = (url) =>
  api.get(resolveDownloadUrl(url), { responseType: 'blob' });

export const previewEmailDispatch = (lotId, recipientEmail = '', customSubject = '') =>
  api.post('/email_dispatch.php', { lot_id: lotId, action: 'preview', recipient_email: recipientEmail, custom_subject: customSubject });

export const sendEmailDispatch = (lotId, recipientEmail, confirmed = true, customSubject = '') =>
  api.post('/email_dispatch.php', { lot_id: lotId, action: 'send', recipient_email: recipientEmail, confirmed, custom_subject: customSubject });
