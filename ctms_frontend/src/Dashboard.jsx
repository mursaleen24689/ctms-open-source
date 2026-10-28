import { useState, useEffect, useCallback } from 'react';
import InstitutionFooter from './InstitutionFooter';
import {
  fetchAllLots, createLot, createSamples,
  updateSampleStatus, submitTestResults, fetchResultFields, saveResultField,
  fetchUsers, createUser, updateUser, deleteUser,
  fetchAnalytics,
  fetchCheques, saveCheque, updateCheque, deleteCheque,
  markNotificationRead,
  searchAudit, archiveLot, unarchiveLot, fetchRecentAudit,
  flagCorrection, fetchLotsArchived,
  fetchInvoices, fetchQuotations, fetchClients, fetchClientHistory,
  fetchPriceMaster, fetchSettings, fetchClientAnalytics,
  createClient, createQuotation, createInvoice,
  updatePriceItem, updateSetting,
  markInvoicePaid, updateInvoiceStatus, updateQuotationStatus,
  deleteInvoice, deleteQuotation,
  fetchReportArchive, dispatchReport, undispatchReport, fetchReportWorkflow, configureReportWorkflow, updateReportWorkflow,
  fetchCustodySamples, fetchCustodyTimeline, recordCustodyEvent,
  fetchLotAccessConfig, saveLotAccessConfig,
  getDownloadTicket, changeOwnPassword, localYmd,
  fetchReceipts, fetchAgeing, fetchTatBoard, fetchTemplates, fetchEquipment,
  addReceipt, allocateReceipt, saveTemplate, saveEquipment, deleteEquipment,
  fetchLotArchivePreview, buildLotArchiveZip, downloadLotArchiveZip, previewEmailDispatch, sendEmailDispatch,
  fetchAuthSecurityAlerts, resetUserMfa,
  fetchStaffNotifications, markStaffNotificationRead,
  fetchAnnouncementHistory, publishAnnouncement, fetchAnnouncementReceipts,
} from './api';
import { getToken } from './api';
import useDraftRecovery from './useDraftRecovery';
import { parseInstrumentCsv, findInstrumentHeader, normalizeImportHeader } from './instrumentImport';
import SecureChat from './SecureChat';

// ── Number to Words (for cheque totals) ───────────────────────
function numberToWords(n) {
  const ones = ['','ONE','TWO','THREE','FOUR','FIVE','SIX','SEVEN','EIGHT','NINE',
                 'TEN','ELEVEN','TWELVE','THIRTEEN','FOURTEEN','FIFTEEN','SIXTEEN',
                 'SEVENTEEN','EIGHTEEN','NINETEEN'];
  const tens = ['','','TWENTY','THIRTY','FORTY','FIFTY','SIXTY','SEVENTY','EIGHTY','NINETY'];
  if (n === 0) return 'ZERO';
  const toWords = (num) => {
    if (num < 20) return ones[num];
    if (num < 100) return tens[Math.floor(num/10)] + (num%10 ? ' '+ones[num%10] : '');
    if (num < 1000) return ones[Math.floor(num/100)]+' HUNDRED'+(num%100?' '+toWords(num%100):'');
    if (num < 100000) return toWords(Math.floor(num/1000))+' THOUSAND'+(num%1000?' '+toWords(num%1000):'');
    if (num < 10000000) return toWords(Math.floor(num/100000))+' LAC'+(num%100000?' '+toWords(num%100000):'');
    return toWords(Math.floor(num/10000000))+' CRORE'+(num%10000000?' '+toWords(num%10000000):'');
  };
  return toWords(Math.floor(n)) + ' ONLY';
}

// ── Column Schema per Lot Type ─────────────────────────────────
const defaultHeaders = {
  CHEQUE:      ['Reference Number', 'Request No', 'Code', 'Material Description', 'Mode'],
  ONLINE:      ['SD No', 'Request No', 'Material Description', 'Mode'],
  ELECTRONICS: ['SD No', 'Request No', 'Code', 'Material Description', 'Mode', 'Remarks', 'Received by'],
  // MIXED lot: all columns visible — Mode column classifies each row
  // as CHEQUE (no SD No) or ONLINE (has SD No). Used when a single
  // physical submission batch contains both cheque and online samples.
  MIXED:       ['SD No', 'Reference Number', 'Request No', 'Code', 'Material Description', 'Mode', 'Remarks'],
};
// Sr. No is no longer a typed grid column — it's system-generated
// (see srNumbers state) and auto-numbers 1, 2, 3... by default.
// Using "Duplicate row" on a row produces suffixed values like
// 1212A, 1212B for the "one SD No, multiple Request Nos" pattern.
const numericColumns = ['SD No', 'Reference Number', 'Request No', 'Code'];

// ── Smart date parser — type it ANY way you like ──────────────
// Accepts 22-4-26, 22/04/2026, 22.4.26, 220426, 2026-04-22,
// 22 Apr 26, 22apr2026 … and normalizes to YYYY-MM-DD. Day-first
// (22-4 = 22 April) — the lab's writing habit. 2-digit years pivot
// at 50 (00–49 → 2000s, 50–99 → 1900s) so 26 = 2026 and 27 = 2027.
// Invalid dates (31-2-26) return '' — the caller keeps the sheet
// date rather than storing a nonexistent day.
function smartDate(raw) {
  const s = String(raw ?? '').trim();
  if (!s) return '';
  const months = { jan:1,feb:2,mar:3,apr:4,may:5,jun:6,jul:7,aug:8,sep:9,sept:9,oct:10,nov:11,dec:12 };
  // ISO first
  let m = s.match(/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/);
  if (m) return mk(m[1], m[2], m[3]);
  // ddmmyy / ddmmyyyy (220426, 22042026)
  m = s.match(/^(\d{2})(\d{2})(\d{2}|\d{4})$/);
  if (m) return mk(yc(m[3]), m[2], m[1]);
  // day-monthname-year
  m = s.match(/^(\d{1,2})[ -\/.]?([a-z]{3,9})[ -\/.]?(\d{2}|\d{4})$/i);
  if (m && months[m[2].slice(0,3).toLowerCase()]) return mk(yc(m[3]), months[m[2].slice(0,3).toLowerCase()], m[1]);
  // numeric d-m-y (any separator: 22 4 26, 22-4-26, 22/4/26)
  m = s.match(/^(\d{1,2})[ -\/.](\d{1,2})[ -\/.](\d{2}|\d{4})$/);
  if (m) return mk(yc(m[3]), m[2], m[1]);
  return ''; // unparseable — caller keeps raw text so the user can fix it

  function yc(y2) {
    const y = parseInt(y2, 10);
    if (y > 99) return y;
    return y < 50 ? 2000 + y : 1900 + y;
  }
  function mk(y, mo, d) {
    const yy = parseInt(y, 10), mm = parseInt(mo, 10), dd = parseInt(d, 10);
    if (mm < 1 || mm > 12 || dd < 1 || dd > 31) return '';
    const dt = new Date(yy, mm - 1, dd);
    if (dt.getFullYear() !== yy || dt.getMonth() !== mm - 1 || dt.getDate() !== dd) return '';
    return `${yy}-${String(mm).padStart(2, '0')}-${String(dd).padStart(2, '0')}`;
  }
}

// ── Electronics multi-parameter presets (Example Client-style checklists).
// Ranges left blank — fill from your official Example Client worksheet; upload
// sample reports later and we can lock exact Specified Range values.
const emptyElecRow = () => ({ parameter: '', range: '', observed: '', remarks: '' });
const ELEC_PRESETS = {
  'HAND FREE': [
    'Rated Voltage', 'Rated Frequency', 'Rated Power / Current',
    'Insulation Resistance', 'High Voltage / Dielectric', 'Earthing Continuity',
    'Leakage Current', 'Temperature Rise', 'Cord / Connector Check', 'Marking & Labeling',
  ],
  'HEADPHONE': [
    'Rated Voltage', 'Rated Frequency', 'Insulation Resistance',
    'High Voltage / Dielectric', 'Frequency Response', 'Impedance',
    'Cord Continuity', 'Marking & Labeling',
  ],
  'GAS STOVE': [
    'Gas Leakage Test', 'Burner Ignition', 'Flame Stability',
    'Thermal Efficiency', 'Surface Temperature Rise', 'Valve Operation',
    'Hose / Connection Check', 'Marking & Labeling',
  ],
  'ELECTRIC IRON': [
    'Rated Voltage', 'Rated Power', 'Insulation Resistance',
    'High Voltage / Dielectric', 'Earthing Continuity', 'Temperature Control',
    'Leakage Current', 'Cord Flexing', 'Marking & Labeling',
  ],
  'FAN': [
    'Rated Voltage', 'Rated Frequency', 'Rated Power / Current',
    'Insulation Resistance', 'High Voltage / Dielectric', 'Earthing Continuity',
    'Speed / Air Delivery', 'Temperature Rise', 'Marking & Labeling',
  ],
  'TOASTER': [
    'Rated Voltage', 'Rated Power', 'Insulation Resistance',
    'High Voltage / Dielectric', 'Earthing Continuity', 'Temperature Rise',
    'Timer / Control Function', 'Marking & Labeling',
  ],
  'UPS': [
    'Rated Input Voltage', 'Rated Output Voltage', 'Rated Frequency',
    'Backup Time', 'Transfer Time', 'Insulation Resistance',
    'High Voltage / Dielectric', 'Protection Features', 'Marking & Labeling',
  ],
};
const elecPresetRows = (material) => {
  // Space-insensitive matching: Example Client operators type the same
  // appliance name many ways — HANDFREE / HAND FREE / HANDS-FREE.
  const norm = (s) => (s || '').toUpperCase().replace(/[^A-Z]/g, '');
  const mat = norm(material);
  const key = Object.keys(ELEC_PRESETS).find(k => {
    const nk = norm(k);
    return mat.includes(nk) || nk.includes(mat);
  });
  const names = key ? ELEC_PRESETS[key] : ['', '', ''];
  return names.map(parameter => ({ ...emptyElecRow(), parameter }));
};

// ── Lab stream → PHP status string mapping ─────────────────────
const STREAM_TO_STATUS = {
  'Tensile Lab (Zeeshan Hassan)':              'in_testing_tensile',
  'XRF Lab (Khurram Jahangeer)':                 'in_testing_xrf',
  'OES Lab (Jahangeer)':               'in_testing_oes',
  'Electronics Lab (Lab Technician)':  'in_testing_electronics',
};

// ── Hexagon mark — "sample cross-section" — the CTMS signature ──
function HexMark({ size = 40 }) {
  return (
    <svg width={size} height={size} viewBox="0 0 100 100" aria-hidden="true">
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="#262B33" />
      <polygon points="50,4 93,27 93,73 50,96 7,73 7,27" fill="none" stroke="#C2703D" strokeWidth="5" />
      <circle cx="50" cy="50" r="16" fill="#C2703D" />
    </svg>
  );
}

// ── Status → color mapping for "certification stamp" badges ─────
const STAMP_COLORS = {
  'Waiting for Mapping Routing':            { c: '#D9A23B', bg: '#FBF1DD' },
  'Tensile Lab (Zeeshan Hassan)':           { c: '#2A9D8F', bg: '#CFEDE9' },
  'XRF Lab (Khurram Jahangeer)':            { c: '#2A9D8F', bg: '#CFEDE9' },
  'OES Lab (Jahangeer)':                    { c: '#2A9D8F', bg: '#CFEDE9' },
  'Electronics Lab (Lab Technician)':       { c: '#2A9D8F', bg: '#CFEDE9' },
  'Testing Complete - Awaiting Report':     { c: '#C2703D', bg: '#F3E1D3' },
  'Dispatched':                             { c: '#4D7C6F', bg: '#E2F0EC' },
};
function stampStyle(status) {
  const m = STAMP_COLORS[status] || { c: '#786F63', bg: '#EFEBE5' };
  return {
    display: 'inline-block', border: `1.5px solid ${m.c}`, color: m.c, background: m.bg,
    borderRadius: '4px', padding: '3px 10px', fontSize: '10.5px', fontWeight: '700',
    letterSpacing: '0.4px', textTransform: 'uppercase', transform: 'rotate(-1.5deg)',
    fontFamily: "'IBM Plex Mono', monospace",
  };
}

function Dashboard({ user, userId = 0, role = 'admin', labStream = '', consultancyAccess = true, isSuperadmin = false, canTechnicalReview = false, workingMode = 'guided', onWorkingModeChange = () => {}, onLogout }) {

  // ── Active Tab ─────────────────────────────────────────────
  const [activeTab, setActiveTab] = useState(() => {
    if (new URLSearchParams(window.location.search).get('view') === 'chat') return 'chat';
    if (role === 'tester')     return 'testing';
    if (role === 'mapping')    return 'mapping';
    if (role === 'reception')  return 'intake';
    if (role === 'deo')     return 'reports';
    if (role === 'hod' || role === 'pa') return 'hod_view';
    return 'intake';
  });

  const isReadOnly = role === 'hod' || role === 'pa';

  // ─────────────────────────────────────────────────────────────
  // PHASE 1 — INTAKE STATE
  // ─────────────────────────────────────────────────────────────
  const [intakeType,   setIntakeType]   = useState('CHEQUE');
  const [currentLot,   setCurrentLot]   = useState('');
  // Lot-level details captured while making the sheet — printed on the
  // final report. Customer Name = the company that submitted the
  // samples (Example Client etc.); Item Description / Grade Match may stay
  // blank. (Signed reports never carry a verification link — it is
  // printed automatically only on computer-generated reports.)
  const [lotCustomerName, setLotCustomerName] = useState('');
  const [lotItemDesc,     setLotItemDesc]     = useState('');
  const [lotGradeMatch,   setLotGradeMatch]   = useState('');
  // Promised delivery (days) — drives the TAT/overdue board. Blank = track manually.
  const [lotSlaDays,      setLotSlaDays]      = useState('');
  const [isGenerating, setIsGenerating] = useState(false);
  const [isSaving,     setIsSaving]     = useState(false);
  const [message,      setMessage]      = useState('');
  const [columns,      setColumns]      = useState(defaultHeaders.CHEQUE);

  const createEmptyGrid = (rows, cols) =>
    Array(rows).fill(null).map(() => Array(cols).fill(''));
  const [gridData, setGridData] = useState(createEmptyGrid(50, defaultHeaders.CHEQUE.length));

  // System-generated Sr. No — one entry per gridData row, auto-numbered
  // 1, 2, 3... by default. Never typed by the user. "Duplicate row"
  // inserts a suffixed value here (1212A, 1212B) instead of a plain
  // increment, then everything below it renumbers to stay sequential.
  const createSrNumbers = (rows) => Array(rows).fill(null).map((_, i) => String(i + 1));
  const [srNumbers, setSrNumbers] = useState(createSrNumbers(50));

  const intakeDraftKey = `ctms:intake-draft:v1:${userId || String(user || 'user').toLowerCase().replace(/[^a-z0-9]+/g, '_')}`;
  const intakeDraftValue = { intakeType, currentLot, lotCustomerName, lotItemDesc, lotGradeMatch, lotSlaDays, columns, gridData, srNumbers };
  const restoreIntakeDraft = useCallback((draft) => {
    if (!draft || typeof draft !== 'object') return;
    if (typeof draft.intakeType === 'string' && defaultHeaders[draft.intakeType]) setIntakeType(draft.intakeType);
    setCurrentLot(typeof draft.currentLot === 'string' ? draft.currentLot : '');
    setLotCustomerName(typeof draft.lotCustomerName === 'string' ? draft.lotCustomerName : '');
    setLotItemDesc(typeof draft.lotItemDesc === 'string' ? draft.lotItemDesc : '');
    setLotGradeMatch(typeof draft.lotGradeMatch === 'string' ? draft.lotGradeMatch : '');
    setLotSlaDays(typeof draft.lotSlaDays === 'string' ? draft.lotSlaDays : '');
    if (Array.isArray(draft.columns) && draft.columns.length) setColumns(draft.columns);
    if (Array.isArray(draft.gridData)) setGridData(draft.gridData);
    if (Array.isArray(draft.srNumbers)) setSrNumbers(draft.srNumbers);
  }, []);
  const shouldSaveIntakeDraft = useCallback(draft => Boolean(draft?.currentLot), []);
  const intakeRecovery = useDraftRecovery(intakeDraftKey, intakeDraftValue, restoreIntakeDraft, shouldSaveIntakeDraft);

  // NOTE: we deliberately do NOT auto-renumber the whole sheet after
  // an insert/delete. Only the specific source/duplicate pair gets
  // touched (source becomes "57A", duplicate becomes "57B") — every
  // other row in the sheet keeps its existing number exactly as-is.
  // Renumbering the entire sheet on every edit would shift unrelated
  // rows out from under data the technician already typed.

  // Fail-safe local ledger — always kept in sync
  const [allLots, setAllLots] = useState(() => {
    try { const s = localStorage.getItem('ctms_lots_ledger'); return s ? JSON.parse(s) : []; }
    catch { return []; }
  });
  useEffect(() => {
    try { localStorage.setItem('ctms_lots_ledger', JSON.stringify(allLots)); } catch {}
  }, [allLots]);

  // Reset grid when lot type changes and no active lot
  useEffect(() => {
    if (!currentLot) {
      setColumns(defaultHeaders[intakeType]);
      setGridData(createEmptyGrid(50, defaultHeaders[intakeType].length));
      setSrNumbers(createSrNumbers(50));
    }
  }, [intakeType, currentLot]);

  // Generate lot number — try PHP backend, fallback to local
  const [isFullScreenIntake, setIsFullScreenIntake] = useState(false);

  const handleGenerateLot = async () => {
    if (isReadOnly) return;
    setIsGenerating(true);
    setMessage('');
    setLastSavedLot(null); // clear the previous "ready to download" card before starting fresh
    const date    = new Date();
    const dd      = date.getDate();          // no zero-padding: 2 not 02
    const mm      = date.getMonth() + 1;     // no zero-padding: 6 not 06
    const yyyy    = date.getFullYear();
    const seq     = allLots.filter(l => l.lot_type === intakeType).length + 1; // no zero-padding
    const fallback = `LOT-${seq}-${dd}-${mm}-${yyyy}`; // e.g. LOT-1-2-6-2026
    try {
      const res = await createLot({ lot_type: intakeType, generate_number_only: true });
      setCurrentLot(res.data.lot_number || fallback);
    } catch {
      setCurrentLot(fallback);
    }
    setGridData(createEmptyGrid(50, columns.length));
    setSrNumbers(createSrNumbers(50));
    setMessage(`✅ Lot initialized. Enter sample data below.`);
    setIsGenerating(false);
    setIsFullScreenIntake(true); // open full-page entry mode for fast keyboard-driven typing
  };

  const handleDiscardGrid = () => {
    if (window.confirm('Discard this grid? All unsaved data will be lost.')) {
      intakeRecovery.clearDraft();
      setCurrentLot('');
      setMessage('🗑️ Grid discarded.');
      setIsFullScreenIntake(false);
      clearRowSelection();
    }
  };

  const addColumn = () => {
    const n = prompt('New column name:');
    if (n) { setColumns([...columns, n]); setGridData(gridData.map(r => [...r, ''])); }
  };
  const removeColumn = (i) => {
    if (window.confirm(`Delete "${columns[i]}" column?`)) {
      setColumns(columns.filter((_,j) => j !== i));
      setGridData(gridData.map(r => r.filter((_,j) => j !== i)));
    }
  };
  const renameColumn = (i) => {
    const n = prompt('Rename column:', columns[i]);
    if (n?.trim()) { const c=[...columns]; c[i]=n.trim(); setColumns(c); }
  };
  // Append rows — the operator types HOW MANY more rows are needed
  // (a 600-sample lot is one "600" + Enter, not thirty "+20" clicks).
  // Sr. No keeps auto-numbering from where the sheet left off.
  const [appendCount, setAppendCount] = useState(20);
  const addRows = (n) => {
    const count = Math.max(1, Math.min(5000, parseInt(n ?? appendCount, 10) || 20));
    setGridData(prev => [...prev, ...createEmptyGrid(count, columns.length)]);
    setSrNumbers(prev => [...prev, ...createSrNumbers(count).map((_, i) => String(prev.length + i + 1))]);
  };

  // ── Multi-row selection & bulk delete ────────────────────────
  // A pasted sheet often brings extra rows the operator doesn't want;
  // deleting them one by one was the single biggest intake friction.
  const [selectedRows, setSelectedRows] = useState(() => new Set());
  const clearRowSelection = () => setSelectedRows(new Set());
  const toggleRowSelect = (idx) => setSelectedRows(prev => {
    const next = new Set(prev);
    if (next.has(idx)) next.delete(idx); else next.add(idx);
    return next;
  });
  const allRowsSelected = gridData.length > 0 && selectedRows.size === gridData.length;
  const toggleAllRows = () => {
    if (allRowsSelected) clearRowSelection();
    else setSelectedRows(new Set(gridData.map((_, i) => i)));
  };
  const deleteSelectedRows = () => {
    if (selectedRows.size === 0) return;
    if (!window.confirm(`Delete ${selectedRows.size} selected row(s)? This cannot be undone.`)) return;
    const keep = gridData.map((_, i) => i).filter(i => !selectedRows.has(i));
    setGridData(keep.map(i => gridData[i]));
    setSrNumbers(keep.map(i => srNumbers[i]));
    clearRowSelection();
  };

  // ── Duplicate row — for the "one SD No, multiple Request Nos" case ──
  // When a single physical sample needs several separate test
  // requests logged (e.g. SD 228782 with Request Nos 44363, 44364,
  // 44365), this copies the SD No / Reference Number forward into
  // a freshly inserted row directly below, clears Request No and
  // Material Description for editing, and auto-suffixes the
  // system-generated Sr. No (1212 -> 1212A -> 1212B...) to match
  // how this was done manually on paper before. Rows after the
  // insert point are renumbered to stay sequential.
  const handleDuplicateRow = (rIdx) => {
    const sdNoIdx   = columns.findIndex(c => c === 'SD No');
    const refNoIdx  = columns.findIndex(c => c === 'Reference Number');
    const reqNoIdx  = columns.findIndex(c => c === 'Request No');
    const descIdx   = columns.findIndex(c => c === 'Material Description');

    const sourceRow = gridData[rIdx];
    const newRow = Array(columns.length).fill('');

    // Carry SD No / Reference Number forward — these stay the same
    // across all rows for this one physical sample
    if (sdNoIdx  !== -1) newRow[sdNoIdx]  = sourceRow[sdNoIdx];
    if (refNoIdx !== -1) newRow[refNoIdx] = sourceRow[refNoIdx];

    // Request No and Description are left blank — different per
    // request, even though the sample (SD No) is the same
    if (reqNoIdx !== -1) newRow[reqNoIdx] = '';
    if (descIdx  !== -1) newRow[descIdx]  = '';

    const g = [...gridData];
    g.splice(rIdx + 1, 0, newRow);
    setGridData(g);

    // Both the SOURCE row and the new row get suffixed — duplicating
    // plain "57" turns it into "57A" (source) + "57B" (new), not
    // "57" (unchanged) + "57A". Duplicating an already-suffixed row
    // like "57B" continues the sequence: "57B" stays, new row "57C".
    const sourceSr = srNumbers[rIdx] || String(rIdx + 1);
    const baseSr   = sourceSr.match(/^(\d+)/)?.[1] || String(rIdx + 1);
    const existingSuffix = sourceSr.match(/^\d+([A-Z]?)$/)?.[1] || '';

    let sourceNewSr, newRowSr;
    if (existingSuffix) {
      // Source already has a letter — keep it, new row gets the next one
      sourceNewSr = sourceSr;
      newRowSr    = `${baseSr}${String.fromCharCode(existingSuffix.charCodeAt(0) + 1)}`;
    } else {
      // First duplication of a plain row — source becomes "A", new row becomes "B"
      sourceNewSr = `${baseSr}A`;
      newRowSr    = `${baseSr}B`;
    }

    const sr = [...srNumbers];
    sr[rIdx] = sourceNewSr;       // rename the source row in place
    sr.splice(rIdx + 1, 0, newRowSr); // insert the new row right after
    setSrNumbers(sr);
  };

  // ── Delete row — removes a row entirely, and correctly reverses
  // the suffix logic above: deleting the LAST suffixed sibling of a
  // group (e.g. deleting "57B" when "57A" is its only sibling) reverts
  // the remaining row back to its original plain number ("57A" -> "57").
  // Deleting a row that has other siblings just removes that row and
  // leaves the rest of the group's letters as they are.
  const handleDeleteRow = (rIdx) => {
    if (gridData.length <= 1) return; // never delete the last row
    if (!window.confirm(`Delete row ${srNumbers[rIdx]}? This cannot be undone.`)) return;

    const deletedSr = srNumbers[rIdx] || '';
    const baseMatch = deletedSr.match(/^(\d+)([A-Z]?)$/);
    const base      = baseMatch?.[1];

    const g = gridData.filter((_, i) => i !== rIdx);
    const sr = srNumbers.filter((_, i) => i !== rIdx);

    if (base) {
      // Find any remaining rows that share this same base number
      const siblingIndexes = sr
        .map((val, i) => ({ val, i }))
        .filter(({ val }) => val.match(/^(\d+)([A-Z]?)$/)?.[1] === base);

      if (siblingIndexes.length === 1) {
        // Only one sibling left — it was part of a duplicate pair
        // that's now down to one row, so drop the letter suffix
        // entirely and restore the plain original number.
        sr[siblingIndexes[0].i] = base;
      }
      // If 2+ siblings remain, leave their letters as-is — we don't
      // renumber A/B/C automatically since that could confuse a
      // technician mid-entry; the remaining letters still uniquely
      // identify each row.
    }

    setGridData(g);
    setSrNumbers(sr);
  };

  const handleCellChange = (rIdx, cIdx, val) => {
    if (numericColumns.includes(columns[cIdx])) val = val.replace(/[^0-9]/g, '');
    const g = gridData.map(r => [...r]);
    g[rIdx][cIdx] = val;
    // ELECTRONICS: if Mode = ONLINE, offer to drop Code column
    if (columns[cIdx] === 'Mode' && intakeType === 'ELECTRONICS' && val === 'ONLINE') {
      const codeIdx = columns.findIndex(c => c === 'Code');
      if (codeIdx !== -1 && window.confirm("ONLINE mode selected. Remove the 'Code' column automatically?")) {
        setColumns(columns.filter((_,i) => i !== codeIdx));
        setGridData(g.map(r => r.filter((_,i) => i !== codeIdx)));
        return;
      }
    }
    setGridData(g);
  };

  const handlePaste = (e, startRow, startCol) => {
    e.preventDefault();
    const anchor = selectionRange
      ? { row: Math.min(selectionRange.start.row, selectionRange.end.row), col: Math.min(selectionRange.start.col, selectionRange.end.col) }
      : { row: startRow, col: startCol };

    let rows = e.clipboardData.getData('text').split('\n').map(r => r.split('\t'));
    // Remove trailing empty row that some clipboard sources add
    if (rows.length > 1 && rows[rows.length - 1].every(c => c.replace(/\r/g,'') === '')) rows.pop();

    // If user selected a range taller than the copied data (e.g. copied 1 row
    // but selected 20 rows to fill), repeat the copied rows to fill the whole
    // selection — same as Excel's "flood fill" paste behavior.
    if (selectionRange) {
      const selHeight = Math.abs(selectionRange.end.row - selectionRange.start.row) + 1;
      if (selHeight > rows.length && rows.length > 0) {
        const repeated = [];
        for (let i = 0; i < selHeight; i++) repeated.push([...rows[i % rows.length]]);
        rows = repeated;
      }
    }

    const g = gridData.map(r => [...r]);
    const sr = [...srNumbers];
    let grew = false;
    while (anchor.row + rows.length > g.length) {
      g.push(Array(columns.length).fill(''));
      // FIX: Sr. No must grow with the grid — a 67-row paste onto a
      // 50-row sheet left the extra 17 rows permanently unnumbered.
      sr.push(String(sr.length + 1));
      grew = true;
    }
    if (grew) setSrNumbers(sr);
    rows.forEach((row, i) => row.forEach((cell, j) => {
      if (anchor.row+i < g.length && anchor.col+j < columns.length) {
        let v = cell.replace(/\r/g,'');
        if (numericColumns.includes(columns[anchor.col+j])) v = v.replace(/[^0-9]/g,'');
        g[anchor.row+i][anchor.col+j] = v;
      }
    }));
    setGridData(g);
  };

  // ── Excel-style range selection ──────────────────────────────
  // Lets a technician click-drag across multiple cells (like in
  // Excel), then Ctrl+C / Ctrl+V to copy that whole block and
  // paste it elsewhere — e.g. copy 10 finished rows and paste them
  // into the next 10 blank rows in one motion, instead of doing it
  // cell by cell. This sits alongside the existing single-cell
  // typing/Tab/Arrow/Enter behavior without changing it: a plain
  // click-and-release (no drag) clears the range and behaves
  // exactly as before.
  const [selectionRange, setSelectionRange] = useState(null); // { start:{row,col}, end:{row,col} }
  const [isDragSelecting, setIsDragSelecting] = useState(false);

  // Separate range-select state for Phase 3 lab grid — same logic as
  // the intake grid but isolated so the two grids don't interfere
  const [labSelRange, setLabSelRange] = useState(null);
  const [labIsDragging, setLabIsDragging] = useState(false);

  // Cheque grid range selection — same pattern as intake + lab grids
  const [chqSelRange, setChqSelRange] = useState(null);
  const [chqIsDragging, setChqIsDragging] = useState(false);
  const isChqCellInRange = (rIdx, cIdx) => {
    if (!chqSelRange) return false;
    const r0 = Math.min(chqSelRange.start.row, chqSelRange.end.row);
    const r1 = Math.max(chqSelRange.start.row, chqSelRange.end.row);
    const c0 = Math.min(chqSelRange.start.col, chqSelRange.end.col);
    const c1 = Math.max(chqSelRange.start.col, chqSelRange.end.col);
    return rIdx >= r0 && rIdx <= r1 && cIdx >= c0 && cIdx <= c1;
  };
  const isChqRangeMultiCell = () => chqSelRange && (chqSelRange.start.row !== chqSelRange.end.row || chqSelRange.start.col !== chqSelRange.end.col);
  const handleChqCellMouseDown = (rIdx, cIdx) => { setChqIsDragging(true); setChqSelRange({ start:{row:rIdx,col:cIdx}, end:{row:rIdx,col:cIdx} }); };
  const handleChqCellMouseEnter = (rIdx, cIdx) => { if (!chqIsDragging) return; setChqSelRange(prev => ({ ...prev, end:{row:rIdx,col:cIdx} })); };
  const handleChqGridCopy = (e) => {
    if (!chqSelRange || !isChqRangeMultiCell()) return;
    e.preventDefault();
    const r0 = Math.min(chqSelRange.start.row, chqSelRange.end.row);
    const r1 = Math.max(chqSelRange.start.row, chqSelRange.end.row);
    const c0 = Math.min(chqSelRange.start.col, chqSelRange.end.col);
    const c1 = Math.max(chqSelRange.start.col, chqSelRange.end.col);
    const lines = [];
    for (let r = r0; r <= r1; r++) {
      const line = [];
      for (let c = c0; c <= c1; c++) line.push(chequeGrid[r]?.[c] ?? '');
      lines.push(line.join('\t'));
    }
    e.clipboardData.setData('text/plain', lines.join('\n'));
  };

  const isLabCellInRange = (rIdx, cIdx) => {
    if (!labSelRange) return false;
    const r0 = Math.min(labSelRange.start.row, labSelRange.end.row);
    const r1 = Math.max(labSelRange.start.row, labSelRange.end.row);
    const c0 = Math.min(labSelRange.start.col, labSelRange.end.col);
    const c1 = Math.max(labSelRange.start.col, labSelRange.end.col);
    return rIdx >= r0 && rIdx <= r1 && cIdx >= c0 && cIdx <= c1;
  };
  const isLabRangeMultiCell = () => {
    if (!labSelRange) return false;
    return labSelRange.start.row !== labSelRange.end.row || labSelRange.start.col !== labSelRange.end.col;
  };
  const handleLabCellMouseDown = (rIdx, cIdx) => {
    setLabIsDragging(true);
    setLabSelRange({ start: { row: rIdx, col: cIdx }, end: { row: rIdx, col: cIdx } });
  };
  const handleLabCellMouseEnter = (rIdx, cIdx) => {
    if (!labIsDragging || !labSelRange) return;
    setLabSelRange(prev => ({ ...prev, end: { row: rIdx, col: cIdx } }));
  };

  const isCellInRange = (rIdx, cIdx) => {
    if (!selectionRange) return false;
    const r0 = Math.min(selectionRange.start.row, selectionRange.end.row);
    const r1 = Math.max(selectionRange.start.row, selectionRange.end.row);
    const c0 = Math.min(selectionRange.start.col, selectionRange.end.col);
    const c1 = Math.max(selectionRange.start.col, selectionRange.end.col);
    return rIdx >= r0 && rIdx <= r1 && cIdx >= c0 && cIdx <= c1;
  };

  // Only a true RANGE (2+ cells) suppresses normal typing — a single
  // click+release still focuses the cell and lets you type normally.
  const isRangeMultiCell = () => {
    if (!selectionRange) return false;
    return selectionRange.start.row !== selectionRange.end.row || selectionRange.start.col !== selectionRange.end.col;
  };

  const handleCellMouseDown = (rIdx, cIdx) => {
    setIsDragSelecting(true);
    setSelectionRange({ start: { row: rIdx, col: cIdx }, end: { row: rIdx, col: cIdx } });
  };
  const handleCellMouseEnter = (rIdx, cIdx) => {
    if (!isDragSelecting || !selectionRange) return;
    setSelectionRange(prev => ({ ...prev, end: { row: rIdx, col: cIdx } }));
  };
  // mouseup needs to be caught anywhere on the page, since the drag
  // can end while the cursor is no longer over a table cell
  useEffect(() => {
    const stopDrag = () => { setIsDragSelecting(false); setLabIsDragging(false); setChqIsDragging(false); };
    window.addEventListener('mouseup', stopDrag);
    return () => window.removeEventListener('mouseup', stopDrag);
  }, []);

  // Copy the selected range to the clipboard as tab/newline-delimited
  // text — the same format Excel uses, so pasting back into Excel
  // (or back into this grid) works correctly either direction.
  const handleGridCopy = (e) => {
    if (!selectionRange || !isRangeMultiCell()) return; // let native copy handle single-cell text selection
    e.preventDefault();
    const r0 = Math.min(selectionRange.start.row, selectionRange.end.row);
    const r1 = Math.max(selectionRange.start.row, selectionRange.end.row);
    const c0 = Math.min(selectionRange.start.col, selectionRange.end.col);
    const c1 = Math.max(selectionRange.start.col, selectionRange.end.col);
    const lines = [];
    for (let r = r0; r <= r1; r++) {
      const line = [];
      for (let c = c0; c <= c1; c++) line.push(gridData[r]?.[c] ?? '');
      lines.push(line.join('\t'));
    }
    e.clipboardData.setData('text/plain', lines.join('\n'));
  };

  // Copy handler for Phase 3 lab measurement grid — reads from
  // testGridData (keyed by sample.id + column name) instead of the
  // intake grid's flat gridData array
  const handleLabGridCopy = (e) => {
    if (!labSelRange || !isLabRangeMultiCell()) return;
    e.preventDefault();
    const samples = activeTestingLot?.samples || [];
    const labCols  = getLabColumns();
    const r0 = Math.min(labSelRange.start.row, labSelRange.end.row);
    const r1 = Math.max(labSelRange.start.row, labSelRange.end.row);
    const c0 = Math.min(labSelRange.start.col, labSelRange.end.col);
    const c1 = Math.max(labSelRange.start.col, labSelRange.end.col);
    const lines = [];
    for (let r = r0; r <= r1; r++) {
      const sampleId = samples[r]?.id;
      const line = [];
      for (let c = c0; c <= c1; c++) {
        line.push(testGridData[sampleId]?.[labCols[c]] ?? '');
      }
      lines.push(line.join('\t'));
    }
    e.clipboardData.setData('text/plain', lines.join('\n'));
  };

  // Paste handler for Phase 3 lab measurement grid
  const handleLabGridPaste = (e, startRow, startCol) => {
    e.preventDefault();
    const anchor = isLabRangeMultiCell()
      ? { row: Math.min(labSelRange.start.row, labSelRange.end.row), col: Math.min(labSelRange.start.col, labSelRange.end.col) }
      : { row: startRow, col: startCol };
    const samples = activeTestingLot?.samples || [];
    const labCols  = getLabColumns();
    const rows = e.clipboardData.getData('text').split('\n').map(r => r.split('\t'));
    rows.forEach((row, i) => row.forEach((cell, j) => {
      const sampleId = samples[anchor.row + i]?.id;
      const col = labCols[anchor.col + j];
      if (sampleId && col) {
        handleTestValueChange(sampleId, col, cell.replace(/\r/g, ''));
      }
    }));
  };

  const handleKeyDown = (e, rIdx, cIdx) => {
    const moves = { ArrowUp:[-1,0], ArrowDown:[1,0], ArrowLeft:[0,-1], ArrowRight:[0,1] };
    if (moves[e.key]) {
      e.preventDefault();
      const [dr, dc] = moves[e.key];
      const nr = Math.max(0, Math.min(gridData.length-1, rIdx+dr));
      const nc = Math.max(0, Math.min(columns.length-1, cIdx+dc));
      document.getElementById(`cell-${nr}-${nc}`)?.focus();
      return;
    }
    // Enter moves down one row (same column) — spreadsheet-style entry
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      const nr = Math.min(gridData.length-1, rIdx+1);
      document.getElementById(`cell-${nr}-${cIdx}`)?.focus();
    }
  };

  // Generic "Enter moves to next field" handler for simple top-to-bottom
  // forms (cheque entry, user management) — prevents accidental form
  // submission on Enter and instead jumps focus to the next labeled input.
  const handleFormEnterNav = (e, nextFieldId) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (nextFieldId) document.getElementById(nextFieldId)?.focus();
      else e.target.form?.requestSubmit?.();
    }
  };

  // Save lot + samples to PHP backend (with local fallback)
  const [lastSavedLot, setLastSavedLot] = useState(null); // holds the just-saved lot so Phase 1 can show an immediate "Download Excel" card

  const handleSaveLot = async () => {
    if (isReadOnly) return;
    setIsSaving(true);
    setMessage('Saving to database...');

    const activeRowIndexes = gridData
      .map((r, idx) => ({ r, idx }))
      .filter(({ r }) => r.some(c => c.trim() !== ''));

    if (activeRowIndexes.length === 0) {
      setMessage('⚠️ Cannot save an empty sheet.'); setIsSaving(false); return;
    }

    const jsonSamples = activeRowIndexes.map(({ r, idx }) => {
      const obj = { 'S. No': srNumbers[idx] || String(idx + 1) };
      columns.forEach((col, i) => { obj[col] = r[i]; });
      // Merge the lot-level details into every row — they persist in
      // extra_data and feed the printed report fields.
      if (lotCustomerName.trim()) obj['Customer Name'] = lotCustomerName.trim();
      if (lotItemDesc.trim())     obj['Item Description'] = lotItemDesc.trim();
      if (lotGradeMatch.trim())   obj['Grade Match'] = lotGradeMatch.trim();
      return obj;
    });

    // Build local object for the fail-safe cache
    const localStatus = intakeType === 'ELECTRONICS'
      ? 'Electronics Lab (Lab Technician)'
      : 'Waiting for Mapping Routing';

    const localLot = {
      id:       Date.now(),
      lot_name: currentLot,
      lot_type: intakeType,
      samples:  jsonSamples.map((data, idx) => ({
        id:     Date.now() + idx + 1,
        status: localStatus,
        data,
      })),
    };

    let savedLotForDownload = localLot;

    try {
      const lotRes = await createLot({
        lot_number: currentLot, lot_type: intakeType, agency_type: intakeType,
        sla_days: parseInt(lotSlaDays, 10) || 0,   // TAT: promised = today + N days
      });
      const lotId  = lotRes.data?.id || lotRes.data?.lot_id;
      if (!lotId) throw new Error('Lot saved but no ID returned from server.');

      try {
        await createSamples({ lot_id: lotId, lot_type: intakeType, samples: jsonSamples });
        setMessage(lotRes.data?.notification_warning
          ? `⚠️ Lot saved, but notifications could not be recorded. ${lotRes.data.notification_warning}`
          : `✅ Saved to database! Lot: ${currentLot} — ${jsonSamples.length} samples recorded.`);
      } catch (sampleErr) {
        const detail = sampleErr.response?.data?.error || sampleErr.response?.data?.details || sampleErr.message;
        setMessage(`⚠️ Lot created but samples failed: ${detail}`);
      }
      savedLotForDownload = { ...localLot, id: lotId };
    } catch (err) {
      const detail = err.response?.data?.error || err.response?.data?.details || err.message;
      setMessage(`✅ Backend offline — saved to local fail-safe cache. (${detail})`);
    }

    // Always update local cache regardless of backend status
    setAllLots(prev => [...prev, localLot]);
    intakeRecovery.clearDraft();
    setLastSavedLot(savedLotForDownload);
    setCurrentLot('');
    setLotCustomerName(''); setLotItemDesc(''); setLotGradeMatch(''); setLotSlaDays('');
    clearRowSelection();
    setGridData(createEmptyGrid(50, columns.length));
    setSrNumbers(createSrNumbers(50));
    setIsSaving(false);
    setIsFullScreenIntake(false);
  };

  // ─────────────────────────────────────────────────────────────
  // PHASE 2 — MAPPING STATE
  // ─────────────────────────────────────────────────────────────
  const [pendingLots,    setPendingLots]    = useState([]);
  const [isLoadingP2,    setIsLoadingP2]    = useState(false);
  const [selectedIds,    setSelectedIds]    = useState([]);
  const [targetStreams,  setTargetStreams]  = useState([]); // multi-select: a sample can need 2+ labs at once
  const [isRouting,      setIsRouting]      = useState(false);
  const [routingSuccess, setRoutingSuccess] = useState(false);

  useEffect(() => {
    if (activeTab === 'mapping') { fetchPendingLots(); setSelectedIds([]); setRoutingSuccess(false); }
  }, [activeTab]);

  const fetchPendingLots = async () => {
    setIsLoadingP2(true);
    const filterWaiting = (lots) =>
      lots.map(l => ({ ...l, samples: l.samples.filter(s => s.status === 'Waiting for Mapping Routing') }))
          .filter(l => l.samples.length > 0);
    try {
      const res   = await fetchAllLots();
      const backend = (res.data || []).map(lot => ({
        id:       lot.id,
        lot_name: lot.lot_number,
        lot_type: lot.agency_type,
        samples:  (lot.samples || []).map(s => ({
          id:     s.id,
          status: mapPhpStatusToLocal(s.status),
          data:   s.extra_data || buildSampleDataDisplay(s),
        })),
      }));
      const backendNames = new Set(backend.map(l => l.lot_name));
      const localOnly    = allLots.filter(l => !backendNames.has(l.lot_name));
      setPendingLots(filterWaiting([...backend, ...localOnly]));
    } catch {
      setPendingLots(filterWaiting(allLots));
    } finally {
      setIsLoadingP2(false);
    }
  };

  // Convert PHP 10-step status to our frontend display status
  const mapPhpStatusToLocal = (phpStatus) => {
    const map = {
      received:           'Waiting for Mapping Routing',
      payment_pending:    'Waiting for Mapping Routing',
      payment_cleared:    'Waiting for Mapping Routing',
      lot_assigned:       'Waiting for Mapping Routing',
      in_preparation:     'Waiting for Mapping Routing',
      in_testing:         'In Testing', // sample is routed and at least one lab assignment is pending/in_progress
      in_testing_tensile: 'Tensile Lab (Zeeshan Hassan)',
      in_testing_xrf:     'XRF Lab (Khurram Jahangeer)',
      in_testing_oes:     'OES Lab (Jahangeer)',
      in_testing_electronics: 'Electronics Lab (Lab Technician)',
      data_entry:         'Testing Complete - Awaiting Report',
      report_ready:       'Testing Complete - Awaiting Report',
      signed:             'Testing Complete - Awaiting Report',
      dispatched:         'Dispatched',
    };
    return map[phpStatus] || phpStatus;
  };

  const buildSampleDataDisplay = (s) => ({
    'SD No':                s.sd_number      || '',
    'Material Description': s.description    || s.material_name || '',
    'Category':             s.sample_category || '',
    'Agency':               s.agency_type    || '',
  });

  const toggleSample   = (id) => setSelectedIds(prev =>
    prev.includes(id) ? prev.filter(x => x !== id) : [...prev, id]);
  const toggleAllInLot = (samples) => {
    const ids   = samples.map(s => s.id);
    const allSel = ids.every(id => selectedIds.includes(id));
    setSelectedIds(allSel
      ? selectedIds.filter(id => !ids.includes(id))
      : [...new Set([...selectedIds, ...ids])]);
  };

  const handleExecuteRouting = async () => {
    if (!selectedIds.length || !targetStreams.length || isReadOnly) return;
    setIsRouting(true);

    // Local cache: tag with a combined label so Phase 3 queue
    // filtering (which checks per-lab) still works via the
    // backend's lab_assignments array once it syncs. For the
    // offline fallback we just mark "In Testing" generically —
    // the per-lab breakdown only exists once synced to the DB.
    setAllLots(prev => prev.map(lot => ({
      ...lot,
      samples: lot.samples.map(s =>
        selectedIds.includes(s.id) ? { ...s, status: 'In Testing', lab_assignments: targetStreams.map(ls => ({ lab_stream: ls, status: 'pending' })) } : s),
    })));

    try {
      // Send ALL selected labs at once — one sample can be
      // assigned to multiple labs (e.g. Tensile AND OES) and the
      // report won't be ready until every assigned lab finishes.
      await updateSampleStatus({ sample_ids: selectedIds, lab_streams: targetStreams });
    } catch {
      // Local cache already updated — data safe, will sync later
    }

    setSelectedIds([]);
    setTargetStreams([]);
    setRoutingSuccess(true);
    fetchPendingLots();
    setTimeout(() => setRoutingSuccess(false), 4000);
    setIsRouting(false);
  };

  const handleDeleteLot = async (id, name) => {
    if (isReadOnly) return;
    if (!window.confirm(`⚠️ Permanently delete ${name} and ALL its samples?`)) return;
    // Remove from local cache immediately for instant UI feedback
    setAllLots(prev => prev.filter(l => (l.id !== id && l.lot_name !== name)));
    try {
      const { deleteLot } = await import('./api');
      await deleteLot(id);
    } catch (e) {
      // Backend delete failed — lot will reappear on next fetch
      // This is expected for local-only lots (offline fallback) with fake ids
    }
    fetchPendingLots();
  };

  // ─────────────────────────────────────────────────────────────
  // PHASE 3 — LAB TESTING STATE
  // ─────────────────────────────────────────────────────────────
  const [testerLots,         setTesterLots]         = useState([]);
  const [isLoadingP3,        setIsLoadingP3]         = useState(false);
  const [activeTestingLot,   setActiveTestingLot]   = useState(null);
  const [testGridData,       setTestGridData]        = useState({});
  const [labResultFields,    setLabResultFields]    = useState(null);
  const [newLabField,        setNewLabField]        = useState({ label: '', unit: '' });
  // Electronics-only: one sample → many Example Client parameter rows
  const [elecParamData,      setElecParamData]       = useState({});
  const [copiedLabRow, setCopiedLabRow] = useState(null);
  const [isSubmitting,       setIsSubmitting]        = useState(false);
  const [submitMsg,          setSubmitMsg]           = useState('');
  const [instrumentImport,   setInstrumentImport]    = useState(null);
  const [adminLabStream,     setAdminLabStream]      = useState('Tensile Lab (Zeeshan Hassan)');

  const currentLabStream = role === 'admin' ? adminLabStream : labStream;
  const [labDraftLot, setLabDraftLot] = useState(null);
  const labDraftKey = `ctms:lab-draft:v1:${userId || String(user || 'user').toLowerCase().replace(/[^a-z0-9]+/g, '_')}:${currentLabStream || 'unassigned'}`;
  const labDraftValue = { lot: activeTestingLot || labDraftLot, testGridData, elecParamData, stream: currentLabStream };
  const restoreLabDraft = useCallback((draft) => {
    if (!draft || draft.stream !== currentLabStream || !draft.lot) return;
    setLabDraftLot(draft.lot);
    setActiveTestingLot(draft.lot);
    setTestGridData(draft.testGridData && typeof draft.testGridData === 'object' ? draft.testGridData : {});
    setElecParamData(draft.elecParamData && typeof draft.elecParamData === 'object' ? draft.elecParamData : {});
  }, [currentLabStream]);
  const shouldSaveLabDraft = useCallback(draft => Boolean(!isReadOnly && draft?.lot && draft.stream === currentLabStream), [currentLabStream, isReadOnly]);
  const labRecovery = useDraftRecovery(labDraftKey, labDraftValue, restoreLabDraft, shouldSaveLabDraft);

  useEffect(() => {
    if (activeTab === 'testing') fetchTesterQueue();
  }, [activeTab, adminLabStream]);

  const fetchTesterQueue = async () => {
    setIsLoadingP3(true);
    const stream = role === 'admin' ? adminLabStream : labStream;

    // A sample belongs in THIS lab's queue if it has a lab_assignments
    // row for `stream` that isn't complete yet — regardless of how
    // many OTHER labs it's also waiting on. This is what makes a
    // sample needing both Tensile AND OES show up in both queues
    // simultaneously, and disappear from each independently as that
    // specific lab finishes its part.
    const filterFn = (lots) => lots
      .map(l => ({
        ...l,
        samples: l.samples.filter(s => {
          const myAssignment = (s.lab_assignments || []).find(a => a.lab_stream === stream);
          if (myAssignment) return myAssignment.status !== 'complete';
          // Legacy fallback: samples routed before multi-lab existed
          // still use the old single-status string match
          return s.status === stream || s.status === (STREAM_TO_STATUS[stream] || '');
        }),
      }))
      .filter(l => l.samples.length > 0);

    try {
      const res     = await fetchAllLots();
      const backend = (res.data || []).map(lot => ({
        id: lot.id, lot_name: lot.lot_number, lot_type: lot.agency_type,
        samples: (lot.samples || []).map(s => ({
          id: s.id, status: mapPhpStatusToLocal(s.status),
          lab_assignments: s.lab_assignments || [],
          data: s.extra_data || buildSampleDataDisplay(s),
        })),
      }));
      const names    = new Set(backend.map(l => l.lot_name));
      const localOnly = allLots.filter(l => !names.has(l.lot_name));
      setTesterLots(filterFn([...backend, ...localOnly]));
    } catch {
      setTesterLots(filterFn(allLots));
    } finally {
      setIsLoadingP3(false);
    }
  };

  // Column schema per lab
  const getCurrentLabType = () => {
    const stream = role === 'admin' ? adminLabStream : labStream;
    if (stream.includes('XRF')) return 'xrf';
    if (stream.includes('OES')) return 'oes';
    if (stream.includes('Tensile')) return 'tensile';
    return null;
  };
  const getLabColumns = () => {
    if (Array.isArray(labResultFields)) return labResultFields.filter(f => Number(f.is_active) === 1).map(f => f.field_key);
    const type = getCurrentLabType();
    if (type === 'tensile') return ['Dia (mm)', 'Size', 'Cross Section Area (mm²)', 'Gauge Length (mm)', 'Yield Strength (MPa)', 'Ultimate Tensile Strength (MPa)', 'Elongation %'];
    if (type === 'xrf') return ['Fe','C','Si','Mn','Cr','Ni','Mo','P','S','Cu'];
    if (type === 'oes') return ['Fe','C','Si','Mn','Cr','Ni','S','P','Mo','Cu','Sn'];
    return ['Test Parameter', 'Specified Range', 'Observed Value', 'Remarks'];
  };
  const getLabColumnLabel = (key) => {
    const field = (labResultFields || []).find(f => f.field_key === key && Number(f.is_active) === 1);
    if (field) return field.label + (field.unit && !field.label.includes(field.unit) ? ` (${field.unit})` : '');
    return ['Fe','C','Si','Mn','Cr','Ni','Mo','P','S','Cu','Sn'].includes(key) ? `${key} %` : key;
  };

  const prepareInstrumentImport = async (file) => {
    setSubmitMsg('');
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) { setSubmitMsg('Import file is larger than 5 MB.'); return; }
    try {
      const parsed = parseInstrumentCsv(await file.text());
      const sampleIndex = findInstrumentHeader(parsed.headers, 'sample');
      if (sampleIndex < 0) throw new Error('A sample reference column is required (for example, Sample Ref or Reference Number).');
      const stream = currentLabStream;
      const isElec = stream.includes('Electronics');
      const fieldMappings = isElec
        ? ['parameter', 'range', 'observed', 'remarks'].map(kind => ({ kind, index: findInstrumentHeader(parsed.headers, kind) })).filter(mapping => mapping.index >= 0)
        : getLabColumns().map(key => {
          const matchIndex = parsed.headers.findIndex(header => {
            const normalized = normalizeImportHeader(header);
            return normalized === normalizeImportHeader(key) || normalized === normalizeImportHeader(getLabColumnLabel(key));
          });
          return matchIndex >= 0 ? { kind: key, index: matchIndex } : null;
        }).filter(Boolean);
      if (isElec && !fieldMappings.some(mapping => mapping.kind === 'parameter')) throw new Error('Electronics import needs a Parameter or Element column.');
      if (!isElec && !fieldMappings.length) throw new Error('No columns match the active lab fields. Include field names shown in the entry sheet.');
      const sampleByRef = new Map();
      (activeTestingLot?.samples || []).forEach(sample => {
        [sample.data['Reference Number'], sample.data['SD No'], sample.id].forEach(ref => {
          const key = String(ref || '').trim().toLowerCase();
          if (key && !sampleByRef.has(key)) sampleByRef.set(key, sample.id);
        });
      });
      const mappedRows = parsed.rows.map((values, index) => ({
        line: index + 2,
        values,
        sampleId: sampleByRef.get(String(values[sampleIndex] || '').trim().toLowerCase()) || null,
      }));
      const knownIndexes = new Set([sampleIndex, ...fieldMappings.map(mapping => mapping.index)]);
      setInstrumentImport({
        fileName: file.name,
        headers: parsed.headers,
        rows: mappedRows,
        fieldMappings,
        isElec,
        unknownHeaders: parsed.headers.filter((_, index) => !knownIndexes.has(index)),
      });
    } catch (error) {
      setInstrumentImport(null);
      setSubmitMsg(error.message || 'Could not read this instrument export.');
    }
  };

  const applyInstrumentImport = () => {
    if (!instrumentImport || isReadOnly) return;
    const matched = instrumentImport.rows.filter(row => row.sampleId);
    if (!matched.length) { setSubmitMsg('No sample references in this file matched the open lot.'); return; }
    if (instrumentImport.isElec) {
      setElecParamData(previous => {
        const next = { ...previous };
        matched.forEach(row => {
          const [paramMap, rangeMap, observedMap, remarksMap] = ['parameter', 'range', 'observed', 'remarks'].map(kind => instrumentImport.fieldMappings.find(mapping => mapping.kind === kind));
          const incoming = {
            parameter: paramMap ? row.values[paramMap.index] : '',
            range: rangeMap ? row.values[rangeMap.index] : '',
            observed: observedMap ? row.values[observedMap.index] : '',
            remarks: remarksMap ? row.values[remarksMap.index] : '',
          };
          if (!incoming.parameter.trim()) return;
          const rows = [...(next[row.sampleId] || [])].map(item => ({ ...item }));
          const existingIndex = rows.findIndex(item => normalizeImportHeader(item.parameter) === normalizeImportHeader(incoming.parameter));
          if (existingIndex >= 0) rows[existingIndex] = { ...rows[existingIndex], ...Object.fromEntries(Object.entries(incoming).filter(([, value]) => value !== '')) };
          else rows.push(incoming);
          next[row.sampleId] = rows;
        });
        return next;
      });
    } else {
      setTestGridData(previous => {
        const next = { ...previous };
        matched.forEach(row => {
          const values = { ...(previous[row.sampleId] || {}) };
          instrumentImport.fieldMappings.forEach(mapping => {
            const value = row.values[mapping.index];
            if (value !== '') values[mapping.kind] = value;
          });
          next[row.sampleId] = values;
        });
        return next;
      });
    }
    setSubmitMsg(`Imported readings for ${matched.length} row${matched.length === 1 ? '' : 's'}; ${instrumentImport.rows.length - matched.length} unmatched row${instrumentImport.rows.length - matched.length === 1 ? '' : 's'} skipped. Review the values before saving.`);
    setInstrumentImport(null);
  };

  useEffect(() => {
    if (!activeTestingLot || !['xrf', 'oes', 'tensile'].some(type => currentLabStream.toLowerCase().includes(type))) return;
    if (labResultFields !== null) return;
    const type = currentLabStream.toLowerCase().includes('xrf') ? 'xrf' : currentLabStream.toLowerCase().includes('oes') ? 'oes' : 'tensile';
    let active = true;
    fetchResultFields(type).then(response => {
      if (active) setLabResultFields(response.data?.fields || []);
    }).catch(() => {
      if (active) setLabResultFields(null);
    });
    return () => { active = false; };
  }, [activeTestingLot, currentLabStream, labResultFields]);

  const openLabSheet = async (lot) => {
    const hasMatchingDraft = String(labDraftLot?.id || '') === String(lot.id || '') && Boolean(labRecovery.recovered || labRecovery.savedAt);
    if (!hasMatchingDraft) {
      labRecovery.clearDraft();
      setTestGridData({});
      setElecParamData({});
    }
    setLabDraftLot(lot);
    setActiveTestingLot(lot);
    setCopiedLabRow(null);
    setInstrumentImport(null);
    setLabResultFields(null);
    setNewLabField({ label: '', unit: '' });
    const stream = role === 'admin' ? adminLabStream : labStream;
    if (stream.includes('Electronics')) initElecParamsForLot(lot);
    else {
      setElecParamData({});
      const type = stream.includes('XRF') ? 'xrf' : stream.includes('OES') ? 'oes' : 'tensile';
      try {
        const response = await fetchResultFields(type);
        setLabResultFields(response.data?.fields || []);
      } catch {
        setLabResultFields(null);
      }
    }
  };

  const manageLabField = async (action, field = null) => {
    const type = getCurrentLabType();
    if (!type || isReadOnly) return;
    const payload = { action, ...(field || {}) };
    if (action === 'add') {
      const value = newLabField.label.trim();
      if (!value) return;
      if (type === 'xrf' || type === 'oes') payload.field_key = value;
      else payload.label = value;
      payload.unit = newLabField.unit.trim();
    }
    try {
      const response = await saveResultField(type, payload);
      setLabResultFields(response.data?.fields || []);
      setNewLabField({ label: '', unit: '' });
    } catch (error) {
      setSubmitMsg(error.response?.data?.error || 'Could not update lab fields.');
    }
  };

  // Auto-calculate Cross Section Area from Dia
  const handleTestValueChange = (sampleId, col, val) => {
    setTestGridData(prev => {
      const updated = { ...prev, [sampleId]: { ...(prev[sampleId] || {}), [col]: val } };
      // Auto-calc Cross Section Area when Dia changes
      if (col === 'Dia (mm)') {
        const dia = parseFloat(val);
        if (!isNaN(dia) && dia > 0) {
          const area = ((Math.PI / 4) * dia * dia).toFixed(3);
          updated[sampleId]['Cross Section Area (mm²)'] = area;
        }
      }
      return updated;
    });
  };

  // Copy/paste whole-sample data — technicians often measure one
  // sample and apply the same values to many samples in the lot.
  // copiedLabRow holds the full column set of one sample; the row
  // buttons and the bulk "apply to all" button paste it anywhere.
  const copyLabRow = (sample) => {
    const stream = role === 'admin' ? adminLabStream : labStream;
    if (stream.includes('Electronics')) {
      const rows = elecParamData[sample.id] || [];
      if (!rows.length) return;
      setCopiedLabRow({
        label: sample.data['Reference Number'] || sample.data['SD No'] || `SMP-${sample.id}`,
        elec: true,
        values: rows.map(r => ({ ...r })),
      });
      return;
    }
    const values = testGridData[sample.id] || {};
    if (!Object.keys(values).length) return;
    setCopiedLabRow({ label: sample.data['Reference Number'] || sample.data['SD No'] || `SMP-${sample.id}`, values: { ...values } });
  };
  const pasteLabRow = (targetSampleId) => {
    if (!copiedLabRow) return;
    if (copiedLabRow.elec) {
      setElecParamData(prev => ({ ...prev, [targetSampleId]: copiedLabRow.values.map(r => ({ ...r })) }));
      return;
    }
    setTestGridData(prev => ({ ...prev, [targetSampleId]: { ...copiedLabRow.values } }));
  };
  const pasteLabRowToAll = () => {
    if (!copiedLabRow || !activeTestingLot) return;
    if (!window.confirm(`Paste the copied data (${copiedLabRow.label}) into ALL ${activeTestingLot.samples.length} samples? This overwrites existing values.`)) return;
    if (copiedLabRow.elec) {
      setElecParamData(prev => {
        const next = { ...prev };
        activeTestingLot.samples.forEach(s => { next[s.id] = copiedLabRow.values.map(r => ({ ...r })); });
        return next;
      });
      return;
    }
    setTestGridData(prev => {
      const next = { ...prev };
      activeTestingLot.samples.forEach(s => { next[s.id] = { ...copiedLabRow.values }; });
      return next;
    });
  };

  const initElecParamsForLot = (lot) => {
    const next = {};
    (lot.samples || []).forEach(s => {
      next[s.id] = elecPresetRows(s.data?.['Material Description'] || '');
    });
    setElecParamData(next);
  };
  const updateElecParam = (sampleId, rowIdx, field, val) => {
    setElecParamData(prev => {
      const rows = [...(prev[sampleId] || [emptyElecRow()])];
      rows[rowIdx] = { ...rows[rowIdx], [field]: val };
      return { ...prev, [sampleId]: rows };
    });
  };
  const addElecParamRow = (sampleId) => {
    setElecParamData(prev => ({
      ...prev,
      [sampleId]: [...(prev[sampleId] || []), emptyElecRow()],
    }));
  };
  const removeElecParamRow = (sampleId, rowIdx) => {
    setElecParamData(prev => {
      const rows = [...(prev[sampleId] || [])];
      if (rows.length <= 1) return prev;
      rows.splice(rowIdx, 1);
      return { ...prev, [sampleId]: rows };
    });
  };
  const loadElecPreset = (sampleId, material) => {
    setElecParamData(prev => ({ ...prev, [sampleId]: elecPresetRows(material) }));
  };

  // Arrow/Enter navigation for the Phase 3 lab measurement grid —
  // mirrors handleKeyDown's behavior for the intake grid, using
  // lab-grid-${sampleRow}-${colIndex} as the id scheme.
  const handleLabGridKeyDown = (e, rIdx, cIdx, totalRows, totalCols) => {
    const moves = { ArrowUp:[-1,0], ArrowDown:[1,0], ArrowLeft:[0,-1], ArrowRight:[0,1] };
    if (moves[e.key]) {
      e.preventDefault();
      const [dr, dc] = moves[e.key];
      const nr = Math.max(0, Math.min(totalRows-1, rIdx+dr));
      const nc = Math.max(0, Math.min(totalCols-1, cIdx+dc));
      document.getElementById(`lab-cell-${nr}-${nc}`)?.focus();
      return;
    }
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      const nr = Math.min(totalRows-1, rIdx+1);
      document.getElementById(`lab-cell-${nr}-${cIdx}`)?.focus();
    }
  };

  const handleSubmitTestResults = async () => {
    if (isReadOnly || !activeTestingLot) return;
    setIsSubmitting(true);
    setSubmitMsg('');
    const stream     = role === 'admin' ? adminLabStream : labStream;
    const isElec     = stream.includes('Electronics');

    const payload = {
      lot_id:   activeTestingLot.id,
      lot_name: activeTestingLot.lot_name,
      lab_stream: stream,
      results:  activeTestingLot.samples.map(s => ({
        sample_id: s.id,
        values: isElec
          ? { params: (elecParamData[s.id] || []).filter(r => (r.parameter || '').trim() !== '') }
          : (testGridData[s.id] || {}),
      })),
    };

    // Update local cache — mark these samples as complete
    setAllLots(prev => prev.map(lot => {
      if (lot.lot_name !== activeTestingLot.lot_name) return lot;
      return {
        ...lot,
        samples: lot.samples.map(s => ({ ...s, status: 'Testing Complete - Awaiting Report' })),
      };
    }));

    let savedToServer = false;
    try {
      await submitTestResults(payload);
      savedToServer = true;
      // results.php already sets sample status to 'data_entry' once
      // all lab assignments for the sample are complete — no need to
      // call updateSampleStatus here (which would fail anyway since
      // samples.php PUT now only accepts lab_streams routing, not
      // a raw status string)
      setSubmitMsg('✅ Results saved to database and forwarded to Phase 4 report queue.');
    } catch {
      setSubmitMsg('✅ Results saved locally. Will sync to database when server is available.');
    } finally {
      if (savedToServer) {
        labRecovery.clearDraft();
        setLabDraftLot(null);
      }
      setIsSubmitting(false);
      setTimeout(() => { setActiveTestingLot(null); fetchTesterQueue(); }, 2000);
    }
  };

  // ─────────────────────────────────────────────────────────────
  // PHASE 4 — REPORTS STATE
  // ─────────────────────────────────────────────────────────────
  const [reportLots,     setReportLots]     = useState([]);
  const [isLoadingP4,    setIsLoadingP4]    = useState(false);
  const [generatingSamplePdf,  setGeneratingSamplePdf]  = useState(null);
  const [reportViewerUrl, setReportViewerUrl] = useState('');
  const [workflowReports, setWorkflowReports] = useState([]);
  const [workflowError, setWorkflowError] = useState('');
  const [workflowLoading, setWorkflowLoading] = useState(false);
  const [workflowSettings, setWorkflowSettings] = useState({ require_technical_review: true, reviewer_ids: [] });
  const [workflowReviewers, setWorkflowReviewers] = useState([]);
  const [selectedReviewerIds, setSelectedReviewerIds] = useState([]);
  const [workflowSaving, setWorkflowSaving] = useState(false);
  const [workflowCanConfigure, setWorkflowCanConfigure] = useState(false);
  const [workflowCanReview, setWorkflowCanReview] = useState(false);
  const [lotAccessLots, setLotAccessLots] = useState([]);
  const [lotAccessLevels, setLotAccessLevels] = useState({});
  const [lotAccessQuery, setLotAccessQuery] = useState('');
  const [lotAccessLoading, setLotAccessLoading] = useState(false);
  const [custodyLots, setCustodyLots] = useState([]);
  const [custodyLotId, setCustodyLotId] = useState('');
  const [custodySamples, setCustodySamples] = useState([]);
  const [custodySampleId, setCustodySampleId] = useState('');
  const [custodyEvents, setCustodyEvents] = useState([]);
  const [custodyForm, setCustodyForm] = useState({ event_type: 'stored', location: '', details: '', barcode_value: '' });
  const [custodyLoading, setCustodyLoading] = useState(false);
  const [custodyMessage, setCustodyMessage] = useState('');

  useEffect(() => {
    if (activeTab === 'reports') fetchReportQueue();
    if (activeTab === 'reports' || activeTab === 'users') loadReportWorkflow();
    if (activeTab === 'custody') loadCustodyLots();
    if (activeTab === 'report_search') runReportSearch('', rsYear);
    if (activeTab === 'intake') loadTemplates();
    if (activeTab === 'ops') loadOpsBoard();
    if (activeTab === 'finance') loadFinance();
  }, [activeTab]);

  const fetchReportQueue = async () => {
    setIsLoadingP4(true);
    const filterComplete = (lots) => lots
      .map(l => ({ ...l, samples: l.samples.filter(s => s.status === 'Testing Complete - Awaiting Report') }))
      .filter(l => l.samples.length > 0);
    try {
      const res     = await fetchAllLots();
      // Only use real DB lots in Phase 4 — local-only lots (stored in
      // allLots cache with a fake Date.now() id) aren't in the database
      // yet so report_generate.php would return "Lot not found" for them.
      const backend = (res.data || []).map(lot => ({
        id: lot.id, lot_name: lot.lot_number, lot_type: lot.agency_type,
        samples: (lot.samples || []).map(s => ({
          id: s.id, status: mapPhpStatusToLocal(s.status),
          data: s.extra_data || buildSampleDataDisplay(s),
          sd_number: s.sd_number || '',
          material_name: s.client_name || (s.extra_data && s.extra_data['Material Description']) || '',  // D-07 fix: lots listing returns client_name (Material Description) on every sample, so buttons read 'SD-123 (Client/Description)' instead of 'Sample'
        })),
      }));
      setReportLots(filterComplete(backend)); // no localOnly fallback here — PDF needs real DB id
    } catch {
      setReportLots([]); // can't generate PDFs without a DB connection
    } finally {
      setIsLoadingP4(false);
    }
  };

  const handleGenerateSamplePDF = async (sampleId) => {
    setGeneratingSamplePdf(sampleId);
    try {
      // The one-time ticket stays in the embedded viewer; no extra CTMS tab.
      const url = await getDownloadTicket('report_generate.php', { sample_id: sampleId });
      setReportViewerUrl(url);
    } catch (err) {
      alert(err.response?.data?.error || err.message || 'Could not open report. Make sure XAMPP is running.');
    } finally {
      setGeneratingSamplePdf(null);
    }
  };

  const loadReportWorkflow = async () => {
    setWorkflowLoading(true);
    setWorkflowError('');
    try {
      const res = await fetchReportWorkflow();
      const data = res.data || {};
      setWorkflowReports(data.reports || []);
      setWorkflowCanConfigure(!!data.can_configure);
      setWorkflowCanReview(!!data.can_review);
      if (data.settings) {
        setWorkflowSettings(data.settings);
        setSelectedReviewerIds((data.settings.reviewer_ids || []).map(Number));
      }
      setWorkflowReviewers(data.reviewers || []);
    } catch (err) {
      setWorkflowError(err.response?.data?.error || 'Could not load report approval workflow.');
    } finally {
      setWorkflowLoading(false);
    }
  };

  const handleReportWorkflowAction = async (report, action) => {
    let notes = '';
    if (action === 'return' || action === 'reopen') {
      notes = window.prompt(`Why is report ${report.report_number} being ${action === 'reopen' ? 'reopened' : 'returned'} for correction?`, '') || '';
      if (!notes.trim()) return;
    }
    try {
      await updateReportWorkflow({ report_id: Number(report.report_id), action, notes });
      await loadReportWorkflow();
      if (action === 'approve') alert('Technical review approved. The report is now issued.');
    } catch (err) {
      alert(err.response?.data?.error || 'The report workflow could not be updated.');
    }
  };

  const handleSaveReportWorkflow = async () => {
    setWorkflowSaving(true);
    setWorkflowError('');
    try {
      await configureReportWorkflow({
        require_technical_review: !!workflowSettings.require_technical_review,
        reviewer_ids: selectedReviewerIds.map(Number),
      });
      await loadReportWorkflow();
      setWorkflowError('✅ Approval workflow settings saved.');
    } catch (err) {
      setWorkflowError(err.response?.data?.error || 'Could not save report workflow settings.');
    } finally {
      setWorkflowSaving(false);
    }
  };

  const loadCustodyLots = async () => {
    setCustodyLoading(true);
    setCustodyMessage('');
    try {
      const res = await fetchAllLots();
      const lots = (res.data || []).map(l => ({ id: Number(l.id), label: l.lot_number, samples: l.samples || [] }));
      setCustodyLots(lots);
      const currentId = lots.some(l => String(l.id) === String(custodyLotId)) ? String(custodyLotId) : String(lots[0]?.id || '');
      setCustodyLotId(currentId);
      if (currentId) await loadCustodySamples(currentId);
      else { setCustodySamples([]); setCustodySampleId(''); setCustodyEvents([]); }
    } catch (err) {
      setCustodyMessage(err.response?.data?.error || 'Could not load sample lots.');
    } finally { setCustodyLoading(false); }
  };

  const loadCustodySamples = async (lotId) => {
    if (!lotId) return;
    setCustodyLoading(true);
    try {
      const res = await fetchCustodySamples(Number(lotId));
      const samples = res.data?.samples || [];
      setCustodySamples(samples);
      const currentId = samples.some(s => String(s.id) === String(custodySampleId)) ? String(custodySampleId) : String(samples[0]?.id || '');
      setCustodySampleId(currentId);
      if (currentId) await loadCustodyTimeline(currentId);
      else setCustodyEvents([]);
    } catch (err) {
      setCustodyMessage(err.response?.data?.error || 'Could not load samples for this lot.');
    } finally { setCustodyLoading(false); }
  };

  const loadCustodyTimeline = async (sampleId) => {
    if (!sampleId) return;
    setCustodyLoading(true);
    try {
      const res = await fetchCustodyTimeline(Number(sampleId));
      setCustodyEvents(res.data?.events || []);
    } catch (err) {
      setCustodyMessage(err.response?.data?.error || 'Could not load custody history.');
      setCustodyEvents([]);
    } finally { setCustodyLoading(false); }
  };

  const handleRecordCustodyEvent = async (e) => {
    e.preventDefault();
    if (!custodySampleId) return;
    setCustodyMessage('');
    try {
      await recordCustodyEvent({ sample_id: Number(custodySampleId), ...custodyForm });
      setCustodyMessage('✅ Custody event recorded.');
      setCustodyForm({ event_type: 'stored', location: '', details: '', barcode_value: '' });
      await loadCustodyTimeline(custodySampleId);
    } catch (err) {
      setCustodyMessage(`❌ ${err.response?.data?.error || 'Could not record the custody event.'}`);
    }
  };


  // ── LOT ARCHIVE (ZIP) & EMAIL DISPATCH MODAL STATE ──
  const [archiveFormatModal, setArchiveFormatModal] = useState(null);
  const [archiveFormat, setArchiveFormat] = useState('pdf');
  const [archiveBusy, setArchiveBusy] = useState(false);
  const [emailModalOpen, setEmailModalOpen] = useState(false);
  const [emailModalLoading, setEmailModalLoading] = useState(false);
  const [emailDispatching, setEmailDispatching] = useState(false);
  const [activeEmailLot, setActiveEmailLot] = useState(null);
  const [emailPreviewData, setEmailPreviewData] = useState(null);
  const [emailRecipient, setEmailRecipient] = useState('');
  const [emailCustomSubject, setEmailCustomSubject] = useState('');
  const [emailUserConfirmed, setEmailUserConfirmed] = useState(false);
  const [emailDispatchMsg, setEmailDispatchMsg] = useState('');
  const [emailDispatchErr, setEmailDispatchErr] = useState('');

  const openArchiveFormatModal = (lotId, lotName) => {
    setArchiveFormat('pdf');
    setArchiveFormatModal({ lotId, lotName });
  };

  const buildSelectedArchive = async () => {
    if (!archiveFormatModal || archiveBusy) return;
    setArchiveBusy(true);
    await handleDownloadLotZip(archiveFormatModal.lotId, archiveFormat);
    setArchiveBusy(false);
    setArchiveFormatModal(null);
  };

  const openEmailDispatchModal = async (lot) => {
    setActiveEmailLot(lot);
    setEmailModalOpen(true);
    setEmailModalLoading(true);
    setEmailDispatchMsg('');
    setEmailDispatchErr('');
    setEmailUserConfirmed(false);
    try {
      const res = await previewEmailDispatch(lot.id);
      setEmailPreviewData(res.data);
      setEmailRecipient(res.data.recipient_email || '');
      setEmailCustomSubject(res.data.suggested_subject || '');
    } catch (err) {
      setEmailDispatchErr(err.response?.data?.error || err.message || 'Could not prepare email preview.');
    } finally {
      setEmailModalLoading(false);
    }
  };

  const handleDownloadLotZip = async (lotId, format = 'pdf') => {
    try {
      const res = await buildLotArchiveZip(lotId, format);
      const downloadUrl = res.data?.download_url;
      if (!downloadUrl) throw new Error(res.data?.error || 'The archive download link was not created.');
      const file = await downloadLotArchiveZip(downloadUrl);
      if (!file.data || file.data.size === 0) throw new Error('The server returned an empty ZIP file. Please check the server error log.');
      const signature = new Uint8Array(await file.data.slice(0, 4).arrayBuffer());
      if (signature[0] !== 0x50 || signature[1] !== 0x4b) throw new Error('The downloaded file is not a valid ZIP archive.');
      const contentType = file.headers?.['content-type'] || '';
      if (!contentType.toLowerCase().includes('application/zip') && !contentType.toLowerCase().includes('application/octet-stream')) {
        const message = await file.data.text();
        let detail = message;
        try { detail = JSON.parse(message).error || message; } catch {}
        throw new Error(detail || 'The server did not return a ZIP archive.');
      }
      const disposition = file.headers?.['content-disposition'] || '';
      const encodedName = disposition.match(/filename\*=UTF-8''([^;]+)/i)?.[1];
      const plainName = disposition.match(/filename="?([^";]+)"?/i)?.[1];
      const filename = encodedName ? decodeURIComponent(encodedName) : (plainName || `CTMS-archive-${lotId}.zip`);
      const blobUrl = URL.createObjectURL(file.data);
      const anchor = document.createElement('a');
      anchor.href = blobUrl;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      anchor.remove();
      window.setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
    } catch (err) {
      alert('Could not download ZIP archive: ' + (err.response?.data?.error || err.message));
    }
  };

  const handleSendEmailDispatch = async () => {
    if (!emailUserConfirmed) {
      alert('Please check the confirmation box before sending.');
      return;
    }
    if (!emailRecipient || !emailRecipient.includes('@')) {
      alert('Please enter a valid recipient email address.');
      return;
    }
    setEmailDispatching(true);
    setEmailDispatchMsg('');
    setEmailDispatchErr('');
    try {
      const res = await sendEmailDispatch(activeEmailLot.id, emailRecipient, true, emailCustomSubject);
      setEmailDispatchMsg(res.data.message || 'Email successfully sent!');
    } catch (err) {
      setEmailDispatchErr(err.response?.data?.error || err.message || 'Email dispatch failed.');
    } finally {
      setEmailDispatching(false);
    }
  };

  // ─────────────────────────────────────────────────────────────
  // REPORT SEARCH — registry of every generated report (steel +
  // electronics). One box matches report no, lot no, sample type
  // (e.g. "handfree"), SD / reference no and client name; optional
  // year filter. Like Windows Explorer search.
  // ─────────────────────────────────────────────────────────────
  const [rsQuery,    setRsQuery]    = useState('');
  const [rsYear,     setRsYear]     = useState('');
  const [rsResults,  setRsResults]  = useState([]);
  const [rsYears,    setRsYears]    = useState([]);
  const [rsLoading,  setRsLoading]  = useState(false);
  const [rsSearched, setRsSearched] = useState(false);
  // Dispatch register filter — all | open (still in lab) | done (handed over)
  const [rsDispatch, setRsDispatch] = useState('all');

  const runReportSearch = async (q = rsQuery, y = rsYear, dispatch = rsDispatch) => {
    setRsLoading(true); setRsSearched(true);
    try {
      const res = await fetchReportArchive(q.trim(), y, 200, dispatch);
      const d = res.data || {};
      setRsResults(d.results || []);
      if (Array.isArray(d.years) && d.years.length) setRsYears(d.years);
    } catch {
      setRsResults([]);
    } finally {
      setRsLoading(false);
    }
  };

  const handleDispatchToggle = async (row) => {
    try {
      if (row.dispatched_at) {
        if (!window.confirm(`Move ${row.report_number} back to "in laboratory"?`)) return;
        await undispatchReport(row.id);
      } else {
        const who = window.prompt(`Dispatch ${row.report_number} — received by (name / reference):`, '') ?? null;
        if (who === null) return;   // cancelled
        await dispatchReport({ id: row.id, notes: who.trim() });
      }
      runReportSearch();
    } catch (err) {
      alert(err.response?.data?.error || 'Dispatch update failed.');
    }
  };

  const openArchivedReport = async (sampleId) => {
    try {
      const url = await getDownloadTicket('report_generate.php', { sample_id: sampleId });
      setReportViewerUrl(url);
    } catch (err) {
      alert(err.response?.data?.error || err.message || 'Could not open report.');
    }
  };

  // ─────────────────────────────────────────────────────────────
  // OPS BOARD — TAT (promised delivery) + equipment calibration
  // ─────────────────────────────────────────────────────────────
  const [tatLots,        setTatLots]        = useState([]);
  const [tatLoading,     setTatLoading]     = useState(false);
  const [tatOverdueOnly, setTatOverdueOnly] = useState(false);
  const [equipList,      setEquipList]      = useState([]);
  const [equipLoading,   setEquipLoading]   = useState(false);
  const [equipForm,      setEquipForm]      = useState(null); // null | {…} editing row
  const [equipMsg,       setEquipMsg]       = useState('');

  const loadOpsBoard = async (overdueOnly = tatOverdueOnly) => {
    setTatLoading(true); setEquipLoading(true);
    try {
      const r = await fetchTatBoard(overdueOnly ? 1 : 0);
      setTatLots(r.data?.lots || []);
    } catch { setTatLots([]); }
    finally { setTatLoading(false); }
    try {
      const r = await fetchEquipment();
      setEquipList(r.data?.equipment || []);
    } catch { setEquipList([]); }
    finally { setEquipLoading(false); }
  };

  const saveEquipmentRow = async (e) => {
    e?.preventDefault?.();
    try {
      await saveEquipment(equipForm);
      setEquipMsg('✅ Saved.'); setEquipForm(null); loadOpsBoard();
    } catch (err) {
      setEquipMsg('❌ ' + (err.response?.data?.error || 'Save failed.'));
    }
  };

  // ─────────────────────────────────────────────────────────────
  // Example Client RECEIPTS (lump-sum pool) + AGEING
  // ─────────────────────────────────────────────────────────────
  const [receipts,        setReceipts]        = useState([]);
  const [pendingPoolLots, setPendingPoolLots] = useState([]);
  const [receiptForm,     setReceiptForm]     = useState({ payer: 'Example Client', amount: '', rate_per_sample: '5200', reference: '', receipt_date: '', note: '' });
  const [poolMsg,         setPoolMsg]         = useState('');
  const [ageingRows,      setAgeingRows]      = useState([]);
  const [isLoadingFin,    setIsLoadingFin]    = useState(false);

  const loadFinance = async () => {
    setIsLoadingFin(true);
    try {
      const [r1, r2] = await Promise.all([fetchReceipts(), fetchAgeing()]);
      setReceipts(r1.data?.receipts || []);
      setPendingPoolLots(r1.data?.pending_lots || []);
      setAgeingRows(r2.data?.clients || []);
    } catch { setPoolMsg('⚠️ Could not load finance data.'); }
    finally { setIsLoadingFin(false); }
  };

  const handleAddReceipt = async (e) => {
    e.preventDefault();
    if (finBusy) return;
    if (!(parseFloat(receiptForm.amount) > 0)) { setPoolMsg('⚠️ Enter the receipt amount.'); return; }
    setPoolMsg(''); setFinBusy(true);
    try {
      const r = await addReceipt(receiptForm);
      setPoolMsg(`✅ Receipt saved — covers ${r.data?.samples_covered} sample(s) at the given rate.`);
      setReceiptForm({ payer: 'Example Client', amount: '', rate_per_sample: '5200', reference: '', receipt_date: '', note: '' });
      loadFinance();
    } catch (err) {
      setPoolMsg('❌ ' + (err.response?.data?.error || 'Save failed.'));
    } finally { setFinBusy(false); }
  };

  const handleAllocateLot = async (receiptId, lotId) => {
    if (finBusy) return;
    setPoolMsg(''); setFinBusy(true);
    try {
      const r = await allocateReceipt({ receipt_id: receiptId, lot_id: lotId });
      setPoolMsg(`✅ Lot allocated — ${r.data?.allocated_samples} sample(s); ${r.data?.remaining} left in this receipt.`);
      loadFinance();
    } catch (err) {
      setPoolMsg('❌ ' + (err.response?.data?.error || 'Allocation failed.'));
    } finally { setFinBusy(false); }
  };

  // ─────────────────────────────────────────────────────────────
  // LOT TEMPLATES — pre-fill intake details for recurring clients
  // ─────────────────────────────────────────────────────────────
  const [templates,        setTemplates]        = useState([]);
  const [showSaveTemplate, setShowSaveTemplate] = useState(false);
  const [templateName,     setTemplateName]     = useState('');

  const loadTemplates = async () => {
    try {
      const r = await fetchTemplates();
      setTemplates(r.data?.templates || []);
    } catch { /* optional */ }
  };

  const handleSaveTemplate = async () => {
    if (!templateName.trim()) { setMessage('⚠️ Give the template a name.'); return; }
    try {
      await saveTemplate({
        name: templateName.trim(), lot_type: intakeType,
        customer_name: lotCustomerName, item_desc: lotItemDesc,
        grade_match: lotGradeMatch, sla_days: lotSlaDays || null,
      });
      setShowSaveTemplate(false); setTemplateName('');
      setMessage('✅ Template saved.'); loadTemplates();
    } catch (err) {
      setMessage('❌ ' + (err.response?.data?.error || 'Save failed.'));
    }
  };

  const applyTemplate = (t) => {
    if (!t) return;
    // A12: warn before discarding details the operator already typed.
    const hasTyped = lotCustomerName.trim() || lotItemDesc.trim() || lotGradeMatch.trim();
    if (hasTyped && !window.confirm('Loading this template will replace the customer/item/grade details you have typed. Continue?')) return;
    setIntakeType(t.lot_type || 'CHEQUE');
    setLotCustomerName(t.customer_name || '');
    setLotItemDesc(t.item_desc || '');
    setLotGradeMatch(t.grade_match || '');
    setLotSlaDays(t.sla_days || '');
  };

  // ─────────────────────────────────────────────────────────────
  // HOD ANALYTICS STATE
  // ─────────────────────────────────────────────────────────────
  const [analytics,       setAnalytics]       = useState(null);
  const [trailData,       setTrailData]       = useState(null);
  const [isLoadingHOD,    setIsLoadingHOD]    = useState(false);
  const [notifications,   setNotifications]   = useState([]);
  const [authSecurityAlerts, setAuthSecurityAlerts] = useState(null);
  const [staffNotifications, setStaffNotifications] = useState([]);
  const [staffUnreadCount, setStaffUnreadCount] = useState(0);
  const [notificationBellOpen, setNotificationBellOpen] = useState(false);
  const [announcementTitle, setAnnouncementTitle] = useState('');
  const [announcementMessage, setAnnouncementMessage] = useState('');
  const [announcementRoles, setAnnouncementRoles] = useState(['all']);
  const [announcementExcludeRoles, setAnnouncementExcludeRoles] = useState([]);
  const [announcementHistory, setAnnouncementHistory] = useState([]);
  const [announcementReceipts, setAnnouncementReceipts] = useState(null);
  const [selectedAnnouncement, setSelectedAnnouncement] = useState(null);
  const [announcementBusy, setAnnouncementBusy] = useState(false);
  const [announcementFeedback, setAnnouncementFeedback] = useState('');

  const loadStaffNotifications = async () => {
    if (['hod', 'chairman'].includes(role)) return;
    try {
      const res = await fetchStaffNotifications();
      setStaffNotifications(res.data?.notifications || []);
      setStaffUnreadCount(Number(res.data?.unread_count) || 0);
    } catch { /* Keep the last successful notification list during brief network loss. */ }
  };

  useEffect(() => {
    if (['hod', 'chairman'].includes(role)) return;
    let disposed = false;
    let timer = null;
    let inFlight = false;
    const load = async () => {
      if (disposed || inFlight || document.visibilityState !== 'visible') return;
      inFlight = true;
      try {
        const res = await fetchStaffNotifications();
        if (!disposed) {
          setStaffNotifications(res.data?.notifications || []);
          setStaffUnreadCount(Number(res.data?.unread_count) || 0);
        }
      } catch { /* Retain cached list until the API is reachable again. */ }
      finally {
        inFlight = false;
        if (!disposed && document.visibilityState === 'visible') timer = window.setTimeout(load, 20000);
      }
    };
    const onVisibility = () => {
      if (timer) window.clearTimeout(timer);
      if (document.visibilityState === 'visible') load();
    };
    load();
    document.addEventListener('visibilitychange', onVisibility);
    return () => { disposed = true; if (timer) window.clearTimeout(timer); document.removeEventListener('visibilitychange', onVisibility); };
  }, [role]);

  const loadAnnouncementHistory = async () => {
    try {
      const res = await fetchAnnouncementHistory();
      setAnnouncementHistory(res.data?.announcements || []);
    } catch (err) { setAnnouncementFeedback(err.response?.data?.error || 'Could not load announcement history.'); }
  };

  useEffect(() => {
    if (activeTab === 'announcements' && role === 'admin') loadAnnouncementHistory();
  }, [activeTab, role]);

  const handlePublishAnnouncement = async (event) => {
    event.preventDefault();
    if (announcementBusy) return;
    setAnnouncementBusy(true); setAnnouncementFeedback('');
    try {
      const res = await publishAnnouncement({ title: announcementTitle, message: announcementMessage, roles: announcementRoles, exclude_roles: announcementExcludeRoles });
      setAnnouncementFeedback(`Announcement delivered to ${res.data?.recipient_count || 0} employee(s).`);
      setAnnouncementTitle(''); setAnnouncementMessage(''); setAnnouncementRoles(['all']); setAnnouncementExcludeRoles([]);
      await loadAnnouncementHistory();
    } catch (err) { setAnnouncementFeedback(err.response?.data?.error || 'The announcement could not be published.'); }
    finally { setAnnouncementBusy(false); }
  };

  const handleOpenAnnouncementReceipts = async (announcement) => {
    setSelectedAnnouncement(announcement); setAnnouncementReceipts(null); setAnnouncementFeedback('');
    try {
      const res = await fetchAnnouncementReceipts(announcement.id);
      setAnnouncementReceipts(res.data?.receipts || []);
    } catch (err) { setAnnouncementFeedback(err.response?.data?.error || 'Could not load read receipts.'); }
  };

  const handleReadStaffNotification = async (item) => {
    if (item.read_at) return;
    try {
      await markStaffNotificationRead(item.id);
      setStaffNotifications(current => current.map(entry => entry.id === item.id ? { ...entry, read_at: new Date().toISOString() } : entry));
      await loadStaffNotifications();
    } catch { /* Do not mark locally if the server did not record the receipt. */ }
  };

  useEffect(() => {
    if (role !== 'admin') return;
    let disposed = false;
    let timer;
    const load = async () => {
      try {
        const res = await fetchAuthSecurityAlerts();
        if (!disposed) setAuthSecurityAlerts(res.data || null);
      } catch { if (!disposed) setAuthSecurityAlerts(null); }
      if (!disposed && document.visibilityState === 'visible') timer = window.setTimeout(load, 30000);
    };
    load();
    const onVisibility = () => { if (document.visibilityState === 'visible') load(); };
    document.addEventListener('visibilitychange', onVisibility);
    return () => { disposed = true; window.clearTimeout(timer); document.removeEventListener('visibilitychange', onVisibility); };
  }, [role]);

  const handleResetMfa = async (target) => {
    if (!isSuperadmin || !window.confirm(`Reset authenticator enrollment for ${target.full_name}? They will set it up again at their next sign-in.`)) return;
    try {
      await resetUserMfa(target.id);
      alert(`MFA enrollment was reset for ${target.full_name}.`);
    } catch (err) { alert(err.response?.data?.error || 'Could not reset MFA enrollment.'); }
  };

  useEffect(() => {
    if (activeTab === 'hod_view') fetchHodAnalytics();
  }, [activeTab]);

  // Load notifications for admin — runs immediately on mount, then
  // again every 20 seconds. A one-time mount-only check (the
  // previous version) meant that if the admin's dashboard was
  // already open in a browser tab when someone submitted a forgot-
  // password request, the badge would never update until the admin
  // logged out and back in. Polling fixes that without needing a
  // full page refresh.
  useEffect(() => {
    if (role !== 'admin') return;
    let disposed = false;
    let requestInFlight = false;
    let timer = null;
    const scheduleNext = () => {
      if (!disposed && document.visibilityState === 'visible') {
        timer = window.setTimeout(loadNotifs, 20000);
      }
    };
    const loadNotifs = async () => {
      if (disposed || requestInFlight || document.visibilityState !== 'visible') return;
      requestInFlight = true;
      try {
        const res = await fetchCheques('hmbl', null);
        if (!disposed) setNotifications(res.data?.notifications || []);
      } catch {}
      finally { requestInFlight = false; scheduleNext(); }
    };
    const onVisibilityChange = () => {
      if (timer !== null) window.clearTimeout(timer);
      timer = null;
      if (document.visibilityState === 'visible') loadNotifs();
    };
    loadNotifs();
    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => {
      disposed = true;
      if (timer !== null) window.clearTimeout(timer);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [role]);

  const fetchHodAnalytics = async () => {
    setIsLoadingHOD(true);
    try {
      const res = await fetchAnalytics();
      setAnalytics(res.data);
    } catch {
      // Build live stats from local cache as fallback
      const totals = allLots.reduce((acc, lot) => {
        lot.samples.forEach(s => {
          if (s.status === 'Waiting for Mapping Routing') acc.pending_mapping++;
          else if (s.status.includes('Lab'))              acc.in_testing++;
          else if (s.status.includes('Complete'))         acc.awaiting_report++;
          else if (s.status === 'Dispatched')             acc.dispatched++;
        });
        return acc;
      }, { pending_mapping: 0, in_testing: 0, awaiting_report: 0, dispatched: 0 });

      setAnalytics({
        lots_today:       allLots.filter(l => {
          const d = new Date(); const s = `${d.getFullYear()}${String(d.getMonth()+1).padStart(2,'0')}${String(d.getDate()).padStart(2,'0')}`;
          return l.lot_name?.includes(s);
        }).length,
        total_lots:       allLots.length,
        ...totals,
        recent_lots:      allLots.slice(-10).reverse().map(l => ({
          lot_number:    l.lot_name,
          agency_type:   l.lot_type,
          total_samples: l.samples.length,
          pending:       l.samples.filter(s => s.status === 'Waiting for Mapping Routing').length,
          in_testing:    l.samples.filter(s => s.status.includes('Lab')).length,
          completed:     l.samples.filter(s => s.status.includes('Complete') || s.status === 'Dispatched').length,
        })),
      });
    } finally {
      setIsLoadingHOD(false);
    }
  };

  // ─────────────────────────────────────────────────────────────
  // CHEQUE / PAYORDER MODULE (Admin only)
  // ─────────────────────────────────────────────────────────────
  const [chequeTab,      setChequeTab]      = useState('hmbl');
  const [chequeDate,     setChequeDate]     = useState(localYmd());
  const [cheques,        setCheques]        = useState([]);
  const [isLoadingChq,   setIsLoadingChq]   = useState(false);
  const [chequeForm,     setChequeForm]     = useState({ cheque_no:'', cheque_date:'', bank_name:'', amount:'', remarks:'' });
  const [editingCheque,  setEditingCheque]  = useState(null);
  const [chequeMsg,      setChequeMsg]      = useState('');
  // A15: visible feedback when a typed date can't be parsed
  const [chequeDateWarn, setChequeDateWarn] = useState('');
  const [isFullScreenCheque, setIsFullScreenCheque] = useState(false);
  // Banks remembered for the datalist suggestions — loaded from
  // master_banks + every bank name ever used on a cheque.
  const [bankSuggestions, setBankSuggestions] = useState([]);

  // Cheque grid — each row: [cheque_no, cheque_date, bank_name, amount, remarks]
  // bank_name column hidden for HMBL (auto-filled as Habib Metropolitan Bank)
  const CHEQUE_COLS_HMBL  = ['Cheque / PO No.', 'Cheque Date', 'Amount (Rs.)', 'Remarks'];
  const CHEQUE_COLS_OTHER = ['Cheque / PO No.', 'Cheque Date', 'Bank Name', 'Amount (Rs.)', 'Remarks'];
  const chequeGridCols = () => chequeTab === 'hmbl' ? CHEQUE_COLS_HMBL : CHEQUE_COLS_OTHER;
  const createEmptyChequeGrid = (rows) => Array(rows).fill(null).map(() => Array(chequeGridCols().length).fill(''));
  const [chequeGrid,     setChequeGrid]     = useState(createEmptyChequeGrid(30));
  const [chequeGridSrNos,setChequeGridSrNos]= useState(Array(30).fill(null).map((_,i)=>String(i+1)));

  // ── Invoice / Quotation / Client state ───────────────────────
  const [invoices,       setInvoices]       = useState([]);
  const [quotations,     setQuotations]     = useState([]);
  const [clientList,     setClientList]     = useState([]);
  const [clientHistory,  setClientHistory]  = useState(null);
  const [priceMaster,    setPriceMaster]    = useState([]);
  const [sysSettings,    setSysSettings]    = useState({});
  const [clientAnalytics,setClientAnalytics]= useState([]);
  const [isLoadingInv,   setIsLoadingInv]   = useState(false);
  const [invMsg,         setInvMsg]         = useState('');
  const [invSubTab,      setInvSubTab]      = useState('invoices'); // invoices|quotations|clients|prices|analytics
  const [showInvForm,    setShowInvForm]    = useState(false); // create invoice
  const [showQuoForm,    setShowQuoForm]    = useState(false); // create quotation
  const [payingInvoice,  setPayingInvoice]  = useState(null); // invoice being marked paid
  const [expandedInv,    setExpandedInv]    = useState(null);

  const EMPTY_FORM = {
    company_name:'', contact_person:'', lot_id:'', client_id:'',
    notes:'', discount_amount:'0', discount_reason:'',
    discount_approved_by:'', due_date:'',
    items:[{ description:'', service_code:'', quantity:1, unit_price:'', override_price:'', override_reason:'' }],
  };
  const [invForm, setInvForm] = useState(EMPTY_FORM);
  const [payForm, setPayForm] = useState({ payment_method:'bank_voucher', payment_reference:'', payment_date:'', payment_notes:'' });

  // ── My Lots / Archive / Audit state ──────────────────────────
  const [myLots,         setMyLots]         = useState([]);
  const [archivedLots,   setArchivedLots]   = useState([]);
  const [isLoadingMyLots, setIsLoadingMyLots] = useState(false);
  const [auditQuery,     setAuditQuery]     = useState('');
  const [auditResults,   setAuditResults]   = useState(null);
  const [recentActivity, setRecentActivity] = useState(null);
  const [recentModule,   setRecentModule]   = useState('cheque_records');
  const [isLoadingRecent,setIsLoadingRecent]= useState(false);
  const [isSearchingAudit, setIsSearchingAudit] = useState(false);
  const [archiveMsg,     setArchiveMsg]     = useState('');
  const [expandedLot,    setExpandedLot]    = useState(null); // lot id currently expanded in My Lots
  // Issue 2 fix: admin can correct sample details AFTER a lot is saved
  // (typo in SD No, wrong material…) — server-audited, admin-only.
  const [editingLotId,   setEditingLotId]   = useState(null);
  const [editRows,       setEditRows]       = useState({}); // sampleId -> {sd_number, reference_number, request_number, customer_name, material_description}
  const [isSavingEdits,  setIsSavingEdits]  = useState(false);
  const [editMsg,        setEditMsg]        = useState('');

  const startEditSamples = (lot) => {
    try {
      setEditingLotId(lot.id);
      setEditMsg('');
      const rows = {};
      (lot.samples || []).forEach(s => {
        rows[s.id] = {
          sd_number:            s.sd_number ?? s.extra_data?.['SD No'] ?? '',
          reference_number:     s.reference_number ?? s.extra_data?.['Reference Number'] ?? '',
          request_number:       s.request_number ?? s.extra_data?.['Request No'] ?? '',
          customer_name:        (s.client_name && s.client_name !== (s.extra_data?.['Material Description'] || '')) ? s.client_name : '',
          material_description: s.extra_data?.['Material Description'] || s.client_name || '',
        };
      });
      setEditRows(rows);
    } catch (err) {
      // never crash the screen — surface the problem inside the panel
      setEditingLotId(lot.id);
      setEditMsg('❌ Could not prepare the editor: ' + (err?.message || 'unknown error'));
    }
  };

  const saveEditedSamples = async (lot) => {
    const edits = Object.entries(editRows).map(([id, r]) => ({ id: Number(id), ...r }));
    setIsSavingEdits(true); setEditMsg('');
    try {
      const r = await updateSampleStatus({ action: 'edit_details', edits });
      setEditMsg(`✅ ${r.data?.updated ?? edits.length} sample(s) updated. Download the sheet again to see the corrected data.`);
      setEditingLotId(null);
      await fetchMyLots();
    } catch (err) {
      setEditMsg('❌ ' + (err.response?.data?.error || 'Save failed.'));
    } finally { setIsSavingEdits(false); }
  };
  const [correctionForm, setCorrectionForm] = useState(null); // { sample_id, lab_stream, note }

  useEffect(() => { if (activeTab === 'cheques') loadCheques(); }, [activeTab, chequeTab, chequeDate]);
  useEffect(() => { if (activeTab === 'invoices') loadInvoiceTab(); }, [activeTab, invSubTab]);

  const loadInvoiceTab = async () => {
    setIsLoadingInv(true); setInvMsg('');
    const load = async (fn, setter) => {
      try { const r = await fn(); setter(Array.isArray(r.data) ? r.data : (r.data || [])); }
      catch(e) { console.warn('Invoice load partial failure:', e.response?.data?.error || e.message); }
    };
    if (invSubTab === 'invoices')   await load(fetchInvoices,       setInvoices);
    if (invSubTab === 'quotations') await load(fetchQuotations,     setQuotations);
    if (invSubTab === 'clients')    await load(fetchClients,        setClientList);
    if (invSubTab === 'prices')     await load(fetchPriceMaster,    setPriceMaster);
    if (invSubTab === 'analytics')  await load(fetchClientAnalytics,setClientAnalytics);
    try { const sr = await fetchSettings(); setSysSettings(sr.data || {}); } catch {}
    setIsLoadingInv(false);
  };

  const reloadInvoices = async () => {
    try {
      const r = await fetchInvoices();
      setInvoices(Array.isArray(r.data) ? r.data : []);
    } catch(e) {
      setInvMsg(`⚠️ Reload failed — is invoice.php in ctms_server/api/?`);
    }
  };

  const handleClientSearch = async (name) => {
    if (!name || name.length < 2) { setClientHistory(null); return; }
    try { const r = await fetchClientHistory(name); setClientHistory(r.data); } catch {}
  };

  const addInvItem = () => setInvForm(f => ({ ...f, items:[...f.items,{ description:'', service_code:'', quantity:1, unit_price:'', override_price:'', override_reason:'' }] }));
  const removeInvItem = (i) => setInvForm(f => ({ ...f, items:f.items.filter((_,idx)=>idx!==i) }));
  const updateInvItem = (i, field, val) => setInvForm(f => {
    const items = [...f.items]; items[i]={...items[i],[field]:val}; return {...f,items};
  });
  const suggestPrice = (i, code) => {
    const pm = priceMaster.find(p=>p.service_code===code);
    if (pm) updateInvItem(i,'unit_price',pm.unit_price);
    updateInvItem(i,'service_code',code);
  };

  const calcPreview = () => {
    let sub=0;
    invForm.items.forEach(item=>{
      const p = (item.override_price!==''&&item.override_price!=null) ? parseFloat(item.override_price||0) : parseFloat(item.unit_price||0);
      sub += p * parseFloat(item.quantity||1);
    });
    const disc = parseFloat(invForm.discount_amount||0);
    const rate = parseFloat(sysSettings.service_tax_rate||13);
    const taxable = Math.max(0, sub-disc);
    const tax = Math.round(taxable*rate)/100;
    return { sub, disc, tax, grand: taxable+tax, rate };
  };

  // H2/A-series: double-submit protection for every financial action —
  // a double-click must never create a second invoice/receipt/payment.
  const [finBusy, setFinBusy] = useState(false);

  const handleCreateInvoice = async (e) => {
    e.preventDefault();
    if (finBusy) return;
    setInvMsg('');
    // Audit fix A7: mirror the server's item validation so operators
    // see the problem before submission.
    const draftItems = (invForm.items || []).filter(it => (it.description || '').trim() !== '');
    for (let i = 0; i < draftItems.length; i++) {
      const q = parseFloat(draftItems[i].quantity);
      const p = parseFloat(draftItems[i].override_price !== '' && draftItems[i].override_price != null ? draftItems[i].override_price : draftItems[i].unit_price);
      if (!(q > 0)) { setInvMsg(`⚠️ Item #${i + 1}: quantity must be greater than zero.`); return; }
      if (!(p >= 0) || isNaN(p)) { setInvMsg(`⚠️ Item #${i + 1}: unit price must be a number (0 or more).`); return; }
    }
    setFinBusy(true);
    try {
      const payload = {
        ...invForm,
        lot_id: invForm.lot_id || null,
        client_id: invForm.client_id || null,
        discount_approved_by: invForm.discount_approved_by || null,
        items: draftItems,
      };
      if (!payload.company_name?.trim()) {
        setInvMsg('⚠️ Company name is required.');
        return;
      }
      if (payload.items.length === 0) {
        setInvMsg('⚠️ Add at least one line item with a description.');
        return;
      }
      const r = await createInvoice(payload);
      let invNum = r.data?.inv_number;
      let grand  = parseFloat(r.data?.totals?.grand_total ?? 0);
      if (!invNum && r.data?.id) {
        // Audit fix: the id can arrive as string vs number — compare loosely
        const wantId = Number(r.data.id);
        const list = await fetchInvoices();
        const created = (Array.isArray(list.data) ? list.data : []).find(i => Number(i.id) === wantId);
        invNum = created?.inv_number;
        grand  = parseFloat(created?.grand_total ?? grand);
      }
      if (!invNum) {
        // Audit fix H2: the invoice WAS created — never invite a retry.
        setInvMsg(`✅ Invoice was created (ID ${r.data?.id}), but its number could not be read. Check the invoice list before creating another.`);
        setShowInvForm(false);
        setInvForm(EMPTY_FORM);
        setClientHistory(null);
        await reloadInvoices();
        return;
      }
      setInvMsg(`✅ Invoice ${invNum} created. Grand total: Rs. ${grand.toLocaleString()}`);
      setShowInvForm(false);
      setInvForm(EMPTY_FORM);
      setClientHistory(null);
      await reloadInvoices();
    } catch(e) { setInvMsg(`⚠️ ${e.response?.data?.error || e.response?.data?.details || e.message}`); }
    finally { setFinBusy(false); }
  };

  const handleCreateQuotation = async (e) => {
    e.preventDefault();
    if (finBusy) return;
    setInvMsg(''); setFinBusy(true);
    try {
      const r = await createQuotation({ ...invForm });
      setInvMsg(`✅ Quotation ${r.data.quo_number} created.`);
      setShowQuoForm(false);
      setInvForm(EMPTY_FORM);
      loadInvoiceTab();
    } catch(e) { setInvMsg(`⚠️ ${e.response?.data?.error||e.message}`); }
    finally { setFinBusy(false); }
  };

  const handleMarkPaid = async (e) => {
    e.preventDefault();
    if (finBusy) return;
    // Audit fix A1: a payment needs a real method, reference and date.
    if (!payForm.payment_reference.trim()) { setInvMsg('⚠️ Payment reference / voucher number is required.'); return; }
    if (!/^\d{4}-\d{2}-\d{2}$/.test(payForm.payment_date)) { setInvMsg('⚠️ Select the payment date.'); return; }
    setInvMsg(''); setFinBusy(true);
    try {
      await markInvoicePaid({ ...payForm, id: payingInvoice.id });
      setInvMsg(`✅ Invoice ${payingInvoice.inv_number} marked as paid.`);
      setPayingInvoice(null);
      await reloadInvoices();
    } catch(e) { setInvMsg(`⚠️ ${e.response?.data?.error||e.message}`); }
    finally { setFinBusy(false); }
  };

  const handleConvertToInvoice = async (quo) => {
    if (finBusy) return;
    if (!window.confirm(`Convert quotation ${quo.quo_number} to invoice?`)) return;
    setFinBusy(true);
    try {
      const r = await createInvoice({
        quotation_id: quo.id, client_id: quo.client_id, lot_id: quo.lot_id,
        company_name: quo.company_name, contact_person: quo.contact_person,
        discount_amount: quo.discount_amount, discount_reason: quo.discount_reason,
        notes: quo.notes, items: quo.items,
      });
      setInvMsg(`✅ Invoice ${r.data.inv_number} created from quotation ${quo.quo_number}.`);
      loadInvoiceTab();
    } catch(e) { setInvMsg(`⚠️ ${e.response?.data?.error||e.message}`); }
    finally { setFinBusy(false); }
  };
  useEffect(() => {
    if (activeTab === 'my_lots') fetchMyLots();
    if (activeTab === 'archive') fetchArchivedLots();
  }, [activeTab]);

  const fetchMyLots = async () => {
    setIsLoadingMyLots(true);
    try {
      const res = await fetchAllLots();
      // Guard against non-array responses (e.g. API error object)
      const data = Array.isArray(res.data) ? res.data : [];
      setMyLots(data);
    } catch (e) {
      console.error('fetchMyLots error:', e?.response?.data || e.message);
      setMyLots([]);
      setArchiveMsg('⚠️ Could not load lots — check XAMPP is running and lots.php is updated.');
    }
    finally { setIsLoadingMyLots(false); }
  };

  const fetchArchivedLots = async () => {
    setIsLoadingMyLots(true);
    try {
      const res = await fetchLotsArchived();
      const data = Array.isArray(res.data) ? res.data : [];
      setArchivedLots(data.filter(l => l.status === 'archived' || l.archived_at));
    } catch (e) {
      console.error('fetchArchivedLots error:', e?.response?.data || e.message);
      setArchivedLots([]);
    }
    finally { setIsLoadingMyLots(false); }
  };

  const handleArchiveLot = async (lot) => {
    const note = window.prompt(`Archive lot ${lot.lot_number}?\n\nOptional note (e.g. "Dispatched to Example Client"):`) ?? '';
    if (note === null) return; // user cancelled
    setArchiveMsg('');
    try {
      await archiveLot({ lot_id: lot.id, note });
      setArchiveMsg(`✅ Lot ${lot.lot_number} archived successfully.`);
      fetchMyLots();
      if (activeTab === 'archive') fetchArchivedLots();
    } catch (e) {
      setArchiveMsg(`⚠️ Could not archive: ${e.response?.data?.error || e.message}`);
    }
  };

  const handleUnarchiveLot = async (lot) => {
    if (!window.confirm(`Restore lot ${lot.lot_number} from archive? It will re-appear in the active list.`)) return;
    try {
      await unarchiveLot({ lot_id: lot.id });
      setArchiveMsg(`✅ Lot ${lot.lot_number} restored.`);
      fetchArchivedLots();
    } catch (e) {
      setArchiveMsg(`⚠️ ${e.response?.data?.error || e.message}`);
    }
  };

  const handleFlagCorrection = async () => {
    if (!correctionForm?.sample_id || !correctionForm?.lab_stream) return;
    try {
      await flagCorrection(correctionForm);
      setArchiveMsg(`✅ Sample sent to ${correctionForm.lab_stream} for correction.`);
      setCorrectionForm(null);
      fetchArchivedLots();
    } catch (e) {
      setArchiveMsg(`⚠️ ${e.response?.data?.error || e.message}`);
    }
  };

  const handleAuditSearch = async () => {
    if (!auditQuery.trim()) return;
    setIsSearchingAudit(true);
    try {
      const res = await searchAudit(auditQuery.trim());
      setAuditResults(res.data?.results || []);
    } catch { setAuditResults([]); }
    finally { setIsSearchingAudit(false); }
  };

  // Browse all recent activity for a module — e.g. every cheque
  // created today, without needing to know a specific cheque number
  const handleBrowseRecent = async () => {
    setIsLoadingRecent(true);
    try {
      const res = await fetchRecentAudit(`&module=${recentModule}`);
      setRecentActivity(res.data?.entries || []);
    } catch { setRecentActivity([]); }
    finally { setIsLoadingRecent(false); }
  };

  const loadCheques = async () => {
    setIsLoadingChq(true);
    try {
      const res = await fetchCheques(chequeTab, chequeDate);
      setCheques(res.data.cheques || []);
      setNotifications(res.data.notifications || []);
    } catch { setChequeMsg('⚠️ Could not load data. Check XAMPP.'); }
    finally { setIsLoadingChq(false); }
    // Bank suggestions for the datalist — master list + banks actually
    // used (sorted by frequency). Loaded once per tab visit, cheap.
    try {
      const r = await fetchCheques('banks', '');
      const banks = r.data?.banks || [];
      if (banks.length) {
        setBankSuggestions(banks);
        // Pre-fill with the last bank used this session/browser if the
        // form is still empty — one less field to touch on entry #2+.
        setChequeForm(f => (f.bank_name ? f : { ...f, bank_name: localStorage.getItem('ctms_last_bank') || '' }));
      }
    } catch { /* suggestions are optional — never block entry */ }
  };

  const handleSaveCheque = async (e) => {
    e.preventDefault();
    setChequeMsg('');
    try {
      const payload = { ...chequeForm, cheque_date: smartDate(chequeForm.cheque_date) || chequeForm.cheque_date, cheque_type: chequeTab, entry_date: chequeDate };
      if (editingCheque) {
        await updateCheque({ ...payload, id: editingCheque });
        setChequeMsg('✅ Entry updated.');
        setChequeForm({ cheque_no:'', cheque_date:'', bank_name:'', amount:'', remarks:'' });
      } else {
        await saveCheque(payload);
        setChequeMsg('✅ Cheque entry saved.');
        // Keep date + bank for the next entry — a run of cheques is
        // almost always same-day, same-bank. Only the number and
        // amount need clearing.
        setChequeForm(f => ({ cheque_no:'', cheque_date:f.cheque_date, bank_name:f.bank_name, amount:'', remarks:'' }));
        // Remember the bank across sessions too
        if (payload.bank_name) localStorage.setItem('ctms_last_bank', payload.bank_name);
      }
      setEditingCheque(null);
      loadCheques();
    } catch(err) { setChequeMsg('❌ ' + (err.response?.data?.error || 'Save failed.')); }
  };

  // ── Full-screen cheque grid handlers ─────────────────────────
  const handleChequeGridChange = (rIdx, cIdx, val) => {
    setChequeGrid(prev => {
      const g = prev.map(r=>[...r]);
      g[rIdx][cIdx] = val;
      return g;
    });
  };

  // Tab/Enter on the Cheque Date column normalizes whatever the user
  // typed (22-4-26, 220426, 22 Apr 26 …) to YYYY-MM-DD — same smart
  // parsing as the single-entry form.
  const handleChequeGridDateBlur = (rIdx, cIdx) => {
    const cols = chequeGridCols();
    if (cols[cIdx] !== 'Cheque Date') return;
    const raw = chequeGrid[rIdx]?.[cIdx] || '';
    const parsed = smartDate(raw);
    if (parsed && parsed !== raw) handleChequeGridChange(rIdx, cIdx, parsed);
  };

  const handleChequeGridPaste = (e, startRow, startCol) => {
    e.preventDefault();
    const rows = e.clipboardData.getData('text').split('\n').map(r=>r.split('\t'));
    setChequeGrid(prev => {
      const g = prev.map(r=>[...r]);
      while (startRow + rows.length > g.length) g.push(Array(chequeGridCols().length).fill(''));
      rows.forEach((row,i) => row.forEach((cell,j) => {
        if (startRow+i < g.length && startCol+j < chequeGridCols().length)
          g[startRow+i][startCol+j] = cell.replace(/\r/g,'');
      }));
      return g;
    });
  };

  const handleChequeGridKeyDown = (e, rIdx, cIdx) => {
    const moves = { ArrowUp:[-1,0], ArrowDown:[1,0], ArrowLeft:[0,-1], ArrowRight:[0,1] };
    const cols = chequeGridCols().length;
    if (moves[e.key]) {
      e.preventDefault();
      const [dr,dc] = moves[e.key];
      const nr = Math.max(0, Math.min(chequeGrid.length-1, rIdx+dr));
      const nc = Math.max(0, Math.min(cols-1, cIdx+dc));
      document.getElementById(`chq-cell-${nr}-${nc}`)?.focus();
      return;
    }
    if (e.key==='Enter' && !e.shiftKey) {
      e.preventDefault();
      const nr = Math.min(chequeGrid.length-1, rIdx+1);
      document.getElementById(`chq-cell-${nr}-${cIdx}`)?.focus();
    }
  };

  // Calculate sheet totals from the grid
  const chequeGridTotals = () => {
    const cols = chequeGridCols();
    const amtIdx = cols.indexOf('Amount (Rs.)');
    let total = 0; let count = 0;
    chequeGrid.forEach(row => {
      const val = parseFloat(row[amtIdx]?.replace(/,/g,'') || 0);
      if (val > 0) { total += val; count++; }
    });
    return { total, count };
  };

  // Save all non-empty rows from the grid to the database
  const handleSaveChequeGrid = async () => {
    const cols = chequeGridCols();
    const chequeNoIdx = cols.indexOf('Cheque / PO No.');
    const dateIdx     = cols.indexOf('Cheque Date');
    const bankIdx     = cols.indexOf('Bank Name');
    const amtIdx      = cols.indexOf('Amount (Rs.)');
    const remIdx      = cols.indexOf('Remarks');

    const activeRows = chequeGrid.filter(row => row[chequeNoIdx]?.trim() && row[amtIdx]?.trim());
    if (activeRows.length === 0) { setChequeMsg('⚠️ No entries to save — fill in at least Cheque No and Amount.'); return; }

    setChequeMsg('Saving...');
    let saved = 0; let failed = 0;
    for (const row of activeRows) {
      try {
        await saveCheque({
          cheque_no:   row[chequeNoIdx]?.trim() || '',
          // Safety net — the blur handler already normalized, but a
          // pasted or hand-typed date that was never blurred still
          // parses here. Unparseable text falls back to the sheet date.
          cheque_date: smartDate(row[dateIdx]?.trim()) || chequeDate,
          bank_name:   chequeTab === 'hmbl' ? 'HABIB METROPOLITAN BANK' : (row[bankIdx]?.trim() || ''),
          amount:      row[amtIdx]?.replace(/,/g,'') || '0',
          remarks:     row[remIdx]?.trim() || '',
          cheque_type: chequeTab,
          entry_date:  chequeDate,
        });
        saved++;
      } catch { failed++; }
    }

    setChequeMsg(failed > 0
      ? `⚠️ ${saved} saved, ${failed} failed. Check the table for details.`
      : `✅ ${saved} entr${saved===1?'y':'ies'} saved successfully.`
    );
    setChequeGrid(createEmptyChequeGrid(30));
    setChequeGridSrNos(Array(30).fill(null).map((_,i)=>String(i+1)));
    setIsFullScreenCheque(false);
    loadCheques();
  };

  const handleDeleteCheque = async (id) => {
    if (!window.confirm('Delete this entry?')) return;
    await deleteCheque(id);
    loadCheques();
  };

  const handlePrintCheques = async () => {
    try {
      const url = await getDownloadTicket('generate_cheques.php', { type: chequeTab, date: chequeDate });
      window.open(url, '_blank');
    } catch (err) {
      alert(err.response?.data?.error || err.message || 'Could not generate the submission sheet.');
    }
  };

  // ── Print Lot as Excel ────────────────────────────────────────
  const isLocalLotId = (id) => {
    if (!id) return true;
    const n = Number(id);
    return !Number.isFinite(n) || n <= 0 || n > 1e9; // timestamp cache ids are ~1.7e12
  };

  const handlePrintLotExcel = async (lot) => {
    const lotName = lot.lot_name || lot.lot_number || '';
    let lotId = lot.id;

    // Resolve real DB id when lot was saved to local cache first
    if (isLocalLotId(lotId) && lotName) {
      try {
        const res = await fetchAllLots();
        const match = (Array.isArray(res.data) ? res.data : []).find(l => l.lot_number === lotName);
        if (match?.id) lotId = match.id;
      } catch { /* fall through to local export */ }
    }

    if (!isLocalLotId(lotId)) {
      try {
        const url = await getDownloadTicket('generate_lot.php', { lot_id: lotId });
        window.open(url, '_blank');
      } catch (err) {
        alert(err.response?.data?.error || err.message || 'Could not generate the lot sheet.');
      }
      return;
    }

    // True local-only lot — plain tab export (no server record yet)
    const rows = [['Sr#','SD No','Reference No','Request No','Material Description','Mode','Remarks']];
    (lot.samples || []).forEach((s, i) => {
      const d = s.data || s.extra_data || {};
      rows.push([
        i + 1,
        d['SD No'] || d['S. No'] || d['S.No'] || '',
        d['Reference Number'] || '',
        d['Request No'] || '',
        d['Material Description'] || s.client_name || '',
        d['Mode'] || '',
        d['Remarks'] || '',
      ]);
    });
    const csv = rows.map(r => r.join('\t')).join('\n');
    const blob = new Blob([csv], { type: 'text/tab-separated-values' });
    const a = document.createElement('a');
    const blobUrl = URL.createObjectURL(blob);
    a.href = blobUrl;
    a.download = (lotName || 'lot') + '.xls';
    document.body.appendChild(a);
    a.click();
    a.remove();
    window.setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
  };

  const handlePrintInvoice = async (inv, format = 'html') => {
    try {
      const url = await getDownloadTicket('generate_invoice.php', { id: inv.id, format });
      window.open(url, '_blank');
    } catch (err) {
      alert(err.response?.data?.error || err.message || 'Could not open the invoice.');
    }
  };

  // ─────────────────────────────────────────────────────────────
  // USER MANAGEMENT STATE (Admin only — connected to PHP)
  // ─────────────────────────────────────────────────────────────
  const [systemUsers,   setSystemUsers]   = useState([]);
  const [isLoadingUsers,setIsLoadingUsers] = useState(false);
  const [userForm,      setUserForm]      = useState({ id: null, full_name: '', employee_id: '', password: '', role: 'technician', lab_stream: '', consultancy_access: false, consultancy_role: 'viewer', consultancy_can_create_invoice: false, can_technical_review: false, lot_access_restricted: false });
  const [isEditingUser, setIsEditingUser] = useState(false);
  const [userMsg,       setUserMsg]       = useState('');

  useEffect(() => {
    if (activeTab === 'users') loadUsers();
  }, [activeTab]);

  const loadUsers = async () => {
    setIsLoadingUsers(true);
    try {
      const res = await fetchUsers();
      setSystemUsers(res.data || []);
      if (isSuperadmin || workflowCanConfigure) {
        const lotRes = await fetchAllLots();
        setLotAccessLots((lotRes.data || []).map(l => ({ id: Number(l.id), lot_number: l.lot_number, agency_type: l.agency_type, lot_date: l.lot_date, status: l.status })));
      }
    } catch {
      setUserMsg('⚠️ Could not load users from database. Check XAMPP connection.');
    } finally {
      setIsLoadingUsers(false);
    }
  };

  const loadLotAccessConfig = async (userId) => {
    if (!userId || (!isSuperadmin && !workflowCanConfigure)) return;
    setLotAccessLoading(true);
    try {
      const res = await fetchLotAccessConfig(userId);
      const data = res.data || {};
      setLotAccessLots(data.lots || []);
      setLotAccessLevels(Object.fromEntries((data.assignments || []).map(a => [String(a.lot_id), a.access_level])));
      setUserForm(prev => ({ ...prev, lot_access_restricted: !!Number(data.user?.lot_access_restricted) }));
    } catch (err) {
      setUserMsg(`⚠️ ${err.response?.data?.error || 'Could not load this user’s lot permissions.'}`);
    } finally { setLotAccessLoading(false); }
  };

  const handleSaveUser = async (e) => {
    e.preventDefault();
    setUserMsg('');
    try {
      const payload = { ...userForm };
      if (!isSuperadmin && !workflowCanConfigure) delete payload.can_technical_review;
      // Invoice creation is the Super Administrator's to assign. Other managers
      // can save the rest of the account without touching this permission.
      if (!isSuperadmin) delete payload.consultancy_can_create_invoice;
      delete payload.lot_access_restricted;
      let savedUserId = Number(userForm.id || 0);
      if (isEditingUser) {
        await updateUser(payload);
        setUserMsg(`✅ ${userForm.full_name}'s profile updated in database.`);
      } else {
        const created = await createUser(payload);
        savedUserId = Number(created.data?.id || 0);
        setUserMsg(`✅ Account created for ${userForm.full_name}. The user must set a new password at first sign-in.`);
      }
      if ((isSuperadmin || workflowCanConfigure) && savedUserId) {
        await saveLotAccessConfig({
          user_id: savedUserId,
          restricted: !!userForm.lot_access_restricted,
          assignments: Object.entries(lotAccessLevels).map(([lot_id, access_level]) => ({ lot_id: Number(lot_id), access_level })),
        });
      }
      setIsEditingUser(false);
      setUserForm({ id: null, full_name: '', employee_id: '', password: '', role: 'technician', lab_stream: '', consultancy_access: false, consultancy_role: 'viewer', consultancy_can_create_invoice: false, can_technical_review: false, lot_access_restricted: false });
      setLotAccessLevels({});
      loadUsers();
    } catch (err) {
      setUserMsg(`❌ ${err.response?.data?.error || 'Failed to save user. Check server.'}`);
    }
  };

  const handleDeleteUser = async (u) => {
    if (!window.confirm(`Delete account for ${u.full_name}? This cannot be undone.`)) return;
    try {
      await deleteUser(u.id);
      setUserMsg(`✅ ${u.full_name}'s account removed from database.`);
      loadUsers();
    } catch {
      setUserMsg('❌ Failed to delete user.');
    }
  };

  const handleEditUser = (u) => {
    setUserForm({ id: u.id, full_name: u.full_name, employee_id: u.employee_id, password: '', role: u.role, lab_stream: u.lab_stream || '', consultancy_access: !!Number(u.consultancy_access), consultancy_role: u.consultancy_role || 'viewer', consultancy_can_create_invoice: !!Number(u.consultancy_can_create_invoice), can_technical_review: !!Number(u.can_technical_review), lot_access_restricted: !!Number(u.lot_access_restricted) });
    setLotAccessLevels({});
    setLotAccessQuery('');
    setIsEditingUser(true);
    if (isSuperadmin || workflowCanConfigure) loadLotAccessConfig(u.id);
  };

  const handleResetPassword = async (u) => {
    const newPass = prompt(`Enter a new password for ${u.full_name} (at least 8 characters):`);
    if (!newPass?.trim()) return;
    if (newPass.trim().length < 8 || newPass.trim().length > 72) {
      alert('Password must be between 8 and 72 characters.');
      return;
    }
    try {
      await updateUser({ id: u.id, new_password: newPass.trim() });
      alert(`✅ Password for ${u.full_name} updated in the database successfully.`);
    } catch {
      alert('❌ Could not update password. Check XAMPP connection.');
    }
  };

  // ─────────────────────────────────────────────────────────────
  // RENDER
  // ─────────────────────────────────────────────────────────────
  // ─────────────────────────────────────────────────────────────
  // RENDER
  // ─────────────────────────────────────────────────────────────
  const PANEL_TITLES = {
    intake:   'Phase 01 · Sample Intake',
    mapping:  'Phase 02 · Mapping & Routing',
    testing:  'Phase 03 · Laboratory Testing',
    reports:  'Phase 04 · Reports & Dispatch',
    custody:  'Sample Chain of Custody',
    report_search: 'Report Search · Registry',
    ops: 'Ops Board · Delivery & Calibration',
    finance: 'Example Client Payments · Receipts & Ageing',
    hod_view: 'Executive Dashboard',
    cheques:  'Financial Records',
    invoices: 'Invoices & Quotations',
    my_lots:  'My Lots',
    archive:  'Archives',
    audit:    'Audit Trail',
    users:    'User Management',
    announcements: 'Announcements & Read Receipts',
  };

  // ── FULL-SCREEN INTAKE MODE ────────────────────────────────────
  // Opens edge-to-edge the instant a lot is created — sidebar and
  // topbar hidden so the operator can type fast, switch fields
  // with Tab, move between cells with arrow keys, and never lose
  // focus to anything else on screen. Esc or Save/Discard exits.
  if (isFullScreenIntake && currentLot) {
    return (
      <div style={S.fullScreenIntake} onKeyDown={e => { if (e.key === 'Escape') setIsFullScreenIntake(false); }}>
        <div style={S.fsTopbar}>
          <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
            <HexMark size={30} />
            <div style={S.lotBadge}>{currentLot}</div>
            {intakeType === 'ELECTRONICS' && (
              <span style={stampStyle('Electronics Lab (Lab Technician)')}>Bypass Mode</span>
            )}
            <input value={lotCustomerName} onChange={e => setLotCustomerName(e.target.value)}
              placeholder="Customer / Company (e.g. Example Client) *"
              style={{ ...S.fieldInput, width: '220px', fontWeight: 600 }} />
            <input value={lotItemDesc} onChange={e => setLotItemDesc(e.target.value)}
              placeholder="Item Description (optional)" style={{ ...S.fieldInput, width: '200px' }} />
            <input value={lotGradeMatch} onChange={e => setLotGradeMatch(e.target.value)}
              placeholder="Grade Match (optional)" style={{ ...S.fieldInput, width: '180px' }} />
            <input type="number" min="1" value={lotSlaDays} onChange={e => setLotSlaDays(e.target.value)}
              placeholder="Delivery in days" title="Promised delivery — drives the overdue board"
              style={{ ...S.fieldInput, width: '130px' }} />
            <select value="" onChange={e => applyTemplate(templates.find(t => String(t.id) === e.target.value))} style={{ ...S.select, width: '170px' }}>
              <option value="">Load template…</option>
              {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
            <button onClick={() => setShowSaveTemplate(true)} style={S.btnOutlineSm} title="Save these details as a template">Save as template</button>
          </div>
          {showSaveTemplate && (
            <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginTop: '8px' }}>
              <input value={templateName} onChange={e => setTemplateName(e.target.value)} autoFocus
                placeholder="Template name (e.g. Example Client — Handfree)" style={{ ...S.fieldInput, width: '240px' }}
                onKeyDown={e => { if (e.key === 'Enter') handleSaveTemplate(); if (e.key === 'Escape') setShowSaveTemplate(false); }} />
              <button onClick={handleSaveTemplate} style={S.btnPrimary}>Save</button>
              <button onClick={() => setShowSaveTemplate(false)} style={S.btnOutlineSm}>Cancel</button>
            </div>
          )}
          <div style={{ display: 'flex', gap: '10px' }}>
            <button onClick={addColumn} style={S.btnSecondary}>+ Column</button>
            <button onClick={handleDiscardGrid} style={S.btnDanger}>Discard</button>
            <button onClick={handleSaveLot} disabled={isSaving} style={S.btnSuccess}>
              {isSaving ? 'Saving…' : 'Save to Database'}
            </button>
            <button onClick={() => setIsFullScreenIntake(false)} style={S.btnOutlineSm}>
              Minimize ✕
            </button>
          </div>
        </div>

        {message && (
          <div style={{ ...S.msgBox, ...(S.msgTone(message)), margin: '0 24px 12px' }}>
            {message}
          </div>
        )}

        <div style={{ ...S.fsGridWrap, userSelect: isDragSelecting ? 'none' : 'auto' }}>
          <table style={S.table} onCopy={handleGridCopy}>
            <thead>
              <tr>
                <th style={{ ...S.th, width: '34px' }}>
                  <input type="checkbox" checked={allRowsSelected} onChange={toggleAllRows}
                    title="Select all rows" style={{ cursor: 'pointer' }} />
                </th>
                <th style={{ ...S.th, width: '60px' }}>S. No</th>
                {columns.map((col, i) => (
                  <th key={i} style={{ ...S.th, minWidth: '170px' }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '6px' }}>
                      <span onDoubleClick={() => renameColumn(i)} style={{ cursor: 'pointer' }} title="Double-click to rename">{col}</span>
                      <span onClick={() => removeColumn(i)} style={S.colRemove} title="Remove column">✕</span>
                    </div>
                  </th>
                ))}
                <th style={{ ...S.th, width: '44px' }} title="Duplicate row"></th>
                <th style={{ ...S.th, width: '44px' }} title="Delete row"></th>
              </tr>
            </thead>
            <tbody>
              {gridData.map((row, rIdx) => (
                <tr key={rIdx}>
                  <td style={{ ...S.rowNumClean, background: selectedRows.has(rIdx) ? 'rgba(194,112,61,0.12)' : undefined }}>
                    <input type="checkbox" checked={selectedRows.has(rIdx)}
                      onChange={() => toggleRowSelect(rIdx)} style={{ cursor: 'pointer' }} />
                  </td>
                  <td style={S.rowNumClean}>{srNumbers[rIdx]}</td>
                  {row.map((cell, cIdx) => (
                    <td key={cIdx}
                      style={isCellInRange(rIdx, cIdx) ? S.gridCellSelected : S.gridCell}
                      onMouseDown={() => handleCellMouseDown(rIdx, cIdx)}
                      onMouseEnter={() => handleCellMouseEnter(rIdx, cIdx)}>
                      {columns[cIdx] === 'Mode' && intakeType === 'ELECTRONICS' ? (
                        <select id={`cell-${rIdx}-${cIdx}`} value={cell}
                          onChange={e => handleCellChange(rIdx, cIdx, e.target.value)}
                          style={S.cellInput}>
                          <option value=""></option>
                          <option value="ONLINE">ONLINE</option>
                          <option value="CHEQUE">CHEQUE</option>
                        </select>
                      ) : (
                        <textarea id={`cell-${rIdx}-${cIdx}`} value={cell} rows={1}
                          onChange={e => handleCellChange(rIdx, cIdx, e.target.value)}
                          onPaste={e => handlePaste(e, rIdx, cIdx)}
                          onKeyDown={e => handleKeyDown(e, rIdx, cIdx)}
                          readOnly={isRangeMultiCell()}
                          style={S.cellInput}
                        />
                      )}
                    </td>
                  ))}
                  <td style={S.dupCell} onClick={() => handleDuplicateRow(rIdx)} title="Duplicate this row — same SD No, new Request No (for samples needing multiple test requests)">
                    <span style={S.dupIcon}>⧉</span>
                  </td>
                  <td style={S.delCell} onClick={() => handleDeleteRow(rIdx)} title="Delete this row">
                    <span style={S.delIcon}>✕</span>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>

        <div style={{ padding: '12px 24px', display: 'flex', gap: '10px', alignItems: 'center' }}>
          <input type="number" min="1" max="5000" value={appendCount}
            onChange={e => setAppendCount(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addRows(); } }}
            title="How many rows to add"
            style={{ ...S.fieldInput, width: '90px', padding: '7px 10px' }} />
          <button onClick={() => addRows()} style={S.btnSecondary}>+ Add rows</button>
          {selectedRows.size > 0 && (
            <button onClick={deleteSelectedRows} style={{ ...S.btnDanger, background: 'var(--rust)', color: '#fff' }}>
              🗑 Delete selected ({selectedRows.size})
            </button>
          )}
          <span style={S.fsHint}>Type the count (e.g. 600) and press Add — Sr. No continues automatically · Tab / Arrow keys to navigate · Drag to select range · Ctrl+C copy · Ctrl+V paste · To repeat-fill: drag-select destination rows first, then Ctrl+V · Esc to minimize</span>
        </div>
      </div>
    );
  }

  // ── FULL-SCREEN CHEQUE / PAYORDER GRID ────────────────────────
  if (isFullScreenCheque) {
    const cols = chequeGridCols();
    const totals = chequeGridTotals();
    return (
      <div style={S.fullScreenIntake} onKeyDown={e=>{ if(e.key==='Escape') setIsFullScreenCheque(false); }}>
        <div style={S.fsTopbar}>
          <div style={{ display:'flex', alignItems:'center', gap:'14px', flexWrap:'wrap' }}>
            <HexMark size={30} />
            <div>
              <div style={{ fontWeight:'700', fontSize:'14px' }}>
                {chequeTab === 'hmbl' ? 'Habib Metropolitan Bank' : 'Other Banks'} — Payorder Entry
              </div>
              <div style={{ fontSize:'11px', color:'var(--text-muted)' }}>
                Date: {chequeDate} · {totals.count} entr{totals.count===1?'y':'ies'} · Total: Rs. {totals.total.toLocaleString()}
              </div>
            </div>
            {/* Sheet number display */}
            <div style={{ background:'var(--paper)', border:'1px solid var(--border)', borderRadius:'6px', padding:'6px 12px', fontSize:'12px', fontFamily:"'IBM Plex Mono',monospace" }}>
              Sheet {Math.ceil((cheques.length + totals.count) / 40)} · entries {cheques.length + 1}–{cheques.length + totals.count}
            </div>
          </div>
          <div style={{ display:'flex', gap:'10px', alignItems:'center' }}>
            <input type="number" min="1" max="5000" value={appendCount}
              onChange={e => setAppendCount(e.target.value)}
              onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); setChequeGrid(prev=>[...prev,...createEmptyChequeGrid(Math.max(1, parseInt(appendCount,10)||20))]); } }}
              title="How many rows to add"              style={{ ...S.fieldInput, width: '80px', padding: '7px 10px' }} />
            <button onClick={()=>setChequeGrid(prev=>[...prev,...createEmptyChequeGrid(Math.max(1, parseInt(appendCount,10)||20))])} style={S.btnSecondary}>+ Add rows</button>
            <button onClick={()=>{ setIsFullScreenCheque(false); setChequeGrid(createEmptyChequeGrid(30)); }} style={S.btnDanger}>Discard</button>
            <button onClick={handleSaveChequeGrid} style={S.btnSuccess}>Save all to database</button>
            <button onClick={()=>setIsFullScreenCheque(false)} style={S.btnOutlineSm}>Minimize ✕</button>
          </div>
        </div>

        {chequeMsg && (
          <div style={{ ...S.msgBox, ...(S.msgTone(chequeMsg)), margin:'0 24px 10px' }}>
            {chequeMsg}
          </div>
        )}

        <div style={{ ...S.fsGridWrap, userSelect: chqIsDragging ? 'none' : 'auto' }}>
          <table style={S.table} onCopy={handleChqGridCopy}>
            <thead>
              <tr>
                <th style={{ ...S.th, width:'50px' }}>S. No</th>
                {cols.map((col,i) => (
                  <th key={i} style={{ ...S.th, minWidth: col==='Amount (Rs.)'?'140px':col==='Remarks'?'200px':'160px' }}>{col}</th>
                ))}
                <th style={{ ...S.th, width:'80px', background:'var(--teal)' }}>Amount total</th>
              </tr>
            </thead>
            <tbody>
              {chequeGrid.map((row, rIdx) => {
                const amtIdx = cols.indexOf('Amount (Rs.)');
                const rowAmt = parseFloat(row[amtIdx]?.replace(/,/g,'') || 0);
                const sheetBoundary = (cheques.length + rIdx + 1) % 40 === 0;
                return (
                  <tr key={rIdx} style={sheetBoundary ? { background:'var(--amber-light)', borderBottom:'2px solid var(--amber)' } : rIdx%2 ? S.rowAlt : undefined}>
                    <td style={S.rowNumClean}>{cheques.length + rIdx + 1}</td>
                    {row.map((cell, cIdx) => (
                      <td key={cIdx}
                        style={isChqCellInRange(rIdx,cIdx) ? S.gridCellSelected : S.gridCell}
                        onMouseDown={() => handleChqCellMouseDown(rIdx, cIdx)}
                        onMouseEnter={() => handleChqCellMouseEnter(rIdx, cIdx)}>
                        <textarea
                          id={`chq-cell-${rIdx}-${cIdx}`}
                          value={cell} rows={1}
                          readOnly={isChqRangeMultiCell()}
                          onChange={e => handleChequeGridChange(rIdx, cIdx, e.target.value)}
                          onPaste={e => {
                            // Use range anchor if multi-cell selected
                            const anchor = isChqRangeMultiCell()
                              ? { row: Math.min(chqSelRange.start.row, chqSelRange.end.row), col: Math.min(chqSelRange.start.col, chqSelRange.end.col) }
                              : { row: rIdx, col: cIdx };
                            handleChequeGridPaste(e, anchor.row, anchor.col);
                          }}
                          onKeyDown={e => handleChequeGridKeyDown(e, rIdx, cIdx)}
                          onBlur={() => handleChequeGridDateBlur(rIdx, cIdx)}
                          style={{ ...S.cellInput, fontFamily: cols[cIdx]==='Amount (Rs.)' ? "'IBM Plex Mono',monospace" : 'inherit', fontWeight: cols[cIdx]==='Amount (Rs.)' ? '700' : '400', color: cols[cIdx]==='Amount (Rs.)' ? 'var(--teal)' : 'var(--text)' }}
                        />
                      </td>
                    ))}
                    <td style={{ ...S.td, textAlign:'right', fontFamily:"'IBM Plex Mono',monospace", fontWeight:'700', color: rowAmt>0?'var(--teal)':'var(--text-soft)', fontSize:'12px' }}>
                      {rowAmt > 0 ? `Rs. ${rowAmt.toLocaleString()}` : ''}
                    </td>
                  </tr>
                );
              })}
            </tbody>
            <tfoot>
              <tr style={{ background:'var(--ink)', color:'#fff' }}>
                <td colSpan={cols.length + 1} style={{ padding:'10px 14px', textAlign:'right', fontWeight:'700', fontSize:'14px', fontFamily:"'IBM Plex Mono',monospace" }}>
                  Total entries: {totals.count} &nbsp;&nbsp; Grand Total: Rs. {totals.total.toLocaleString()}
                </td>
                <td style={{ padding:'10px 14px', textAlign:'right', fontWeight:'800', fontSize:'15px', color:'var(--copper-light)', fontFamily:"'IBM Plex Mono',monospace" }}>
                  Rs. {totals.total.toLocaleString()}
                </td>
              </tr>
            </tfoot>
          </table>
        </div>

        <div style={{ padding:'10px 24px' }}>
          <span style={S.fsHint}>Tab / Enter to move between cells · Arrow keys to navigate · Ctrl+C/V for copy-paste · Sheet boundary highlighted every 40 entries · Esc to minimize</span>
        </div>
        <InstitutionFooter style={S.appFooter} />
      </div>
    );
  }

  return (
    <div style={S.shell}>

      {/* ══════════════════════ SIDEBAR ══════════════════════ */}
      <aside style={S.sidebar}>
        <div>
          <div style={S.brandRow}>
            <HexMark size={38} />
            <div>
              <div style={S.brandName}>CTMS Core</div>
              <div style={S.brandSub}>Metallurgical Eng.</div>
            </div>
          </div>

          <div style={S.userCard}>
            <div style={S.avatar}>{(user || 'A')[0].toUpperCase()}</div>
            <div style={{ minWidth: 0, overflow: 'hidden' }}>
              <div style={S.userName}>{user}</div>
              <div style={S.userRole}>
                {role.toUpperCase()}{labStream ? ` · ${labStream.split('(')[0].trim()}` : ''}
              </div>
            </div>
          </div>
        </div>

        <nav style={S.nav}>
          {['admin', 'hod', 'pa', 'reception'].includes(role) && (
            <div onClick={() => setActiveTab('intake')} style={activeTab === 'intake' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>01</span><span>Intake</span>
            </div>
          )}
          {['admin', 'mapping', 'hod', 'pa'].includes(role) && (
            <div onClick={() => setActiveTab('mapping')} style={activeTab === 'mapping' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>02</span><span>Mapping</span>
            </div>
          )}
          {['admin', 'tester', 'hod', 'pa'].includes(role) && (
            <div onClick={() => setActiveTab('testing')} style={activeTab === 'testing' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>03</span><span>Lab Testing</span>
            </div>
          )}
          {(['admin', 'deo', 'hod', 'pa'].includes(role) || canTechnicalReview || workflowCanReview) && (
            <div onClick={() => setActiveTab('reports')} style={activeTab === 'reports' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>04</span><span>Reports</span>
            </div>
          )}
          {['admin', 'hod', 'pa', 'reception', 'mapping', 'tester', 'deo'].includes(role) && (
            <div onClick={() => setActiveTab('custody')} style={activeTab === 'custody' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>04a</span><span>Sample Custody</span>
            </div>
          )}
          {['admin', 'deo', 'hod', 'pa'].includes(role) && (
            <div onClick={() => setActiveTab('report_search')} style={activeTab === 'report_search' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>04b</span><span>Report Search</span>
            </div>
          )}
          {['admin', 'hod', 'pa'].includes(role) && (
            <div onClick={() => setActiveTab('ops')} style={activeTab === 'ops' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>04c</span><span>Ops Board</span>
            </div>
          )}
          {role === 'admin' && (
            <div onClick={() => setActiveTab('finance')} style={activeTab === 'finance' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navNum}>05b</span><span>Example Client Payments</span>
            </div>
          )}

          <div onClick={() => setActiveTab('chat')} role="button" tabIndex={0}
            onKeyDown={event => { if (event.key === 'Enter' || event.key === ' ') setActiveTab('chat'); }}
            style={activeTab === 'chat' ? { ...S.navItem, ...S.navActive } : S.navItem}>
            <span style={S.navIcon}>◈</span><span>Team Chat</span>
          </div>

          <div style={S.navDivider} />

          {['admin', 'hod', 'pa'].includes(role) && (
            <div onClick={() => setActiveTab('hod_view')} style={activeTab === 'hod_view' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navIcon}>◈</span><span>Dashboard</span>
            </div>
          )}
          {role === 'admin' && (
            <div onClick={() => setActiveTab('users')} style={activeTab === 'users' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navIcon}>◈</span><span>Users</span>
            </div>
          )}
          {role === 'admin' && (
            <div onClick={() => setActiveTab('announcements')} style={activeTab === 'announcements' ? { ...S.navItem, ...S.navActive } : S.navItem}>
              <span style={S.navIcon}>◈</span><span>Announcements</span>
            </div>
          )}
          {role === 'admin' && (
            <>
              <div onClick={() => setActiveTab('cheques')} style={activeTab === 'cheques' ? { ...S.navItem, ...S.navActive } : S.navItem}>
                <span style={S.navIcon}>◈</span><span>Financial</span>
                {notifications.length > 0 && <span style={S.navBadge}>{notifications.length}</span>}
              </div>
              <div onClick={() => setActiveTab('invoices')} style={activeTab === 'invoices' ? { ...S.navItem, ...S.navActive } : S.navItem}>
                <span style={S.navIcon}>◈</span><span>Invoices</span>
              </div>
              <div onClick={() => setActiveTab('my_lots')} style={activeTab === 'my_lots' ? { ...S.navItem, ...S.navActive } : S.navItem}>
                <span style={S.navIcon}>◈</span><span>My Lots</span>
              </div>
              <div onClick={() => setActiveTab('archive')} style={activeTab === 'archive' ? { ...S.navItem, ...S.navActive } : S.navItem}>
                <span style={S.navIcon}>◈</span><span>Archives</span>
              </div>
              <div onClick={() => setActiveTab('audit')} style={activeTab === 'audit' ? { ...S.navItem, ...S.navActive } : S.navItem}>
                <span style={S.navIcon}>◈</span><span>Audit Trail</span>
                {((authSecurityAlerts?.ip_alerts?.length || 0) + (authSecurityAlerts?.account_alerts?.length || 0)) > 0 && <span style={{ ...S.navBadge, background: 'var(--rust)' }}>{(authSecurityAlerts.ip_alerts.length || 0) + (authSecurityAlerts.account_alerts.length || 0)}</span>}
              </div>
            </>
          )}

          <div style={S.navDivider} />

          {/*
            Consultancy platform.
            A separate application that shares this login: opening the page asks
            the CTMS API for a single-use sign-in link using the token already
            held here, so nobody is asked for a password twice. It opens in a new
            tab so CTMS work in progress is not lost.

            BASE_URL resolves to '/' under `npm run dev` and to the dist folder
            when built, so the one path works both ways.
          */}
          {consultancyAccess && <a
            href={`${import.meta.env.BASE_URL}consultancy.html`.replace(/\/{2,}/g, '/') + `?return_to=${encodeURIComponent(window.location.href)}&mode=${encodeURIComponent(workingMode)}`}
            target="_blank"
            rel="noopener"
            style={{ ...S.navItem, textDecoration: 'none' }}
          >
            <span style={S.navIcon}>◈</span>
            <span>Consultancy</span>
            <span style={{ marginLeft: 'auto', fontSize: 10, opacity: 0.55 }}>↗</span>
          </a>}
        </nav>

        <button onClick={onLogout} style={S.logoutBtn}>Sign out</button>
      </aside>

      {/* ══════════════════════ MAIN ══════════════════════ */}
      <div style={S.main}>

        <header style={S.topbar}>
          <div>
            <div style={S.topbarTitle}>{PANEL_TITLES[activeTab] || 'Dashboard'}</div>
            <div style={S.topbarMeta}>
              {user} · {role.toUpperCase()}
              {labStream ? ` · ${labStream}` : ''}
              {isReadOnly ? ' · Read-only' : ''}
            </div>
          </div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
            <label style={{ display: 'flex', alignItems: 'center', gap: 7, fontSize: 11, color: 'var(--text-muted)' }}>
              Working mode
              <select aria-label="Working mode" value={workingMode}
                onChange={e => onWorkingModeChange(e.target.value)}
                title="Guided shows task descriptions. Direct keeps the work area concise."
                style={{ ...S.select, minWidth: 105, padding: '6px 8px', fontSize: 11 }}>
                <option value="guided">Guided</option>
                <option value="direct">Direct</option>
              </select>
            </label>
            {!['hod', 'chairman'].includes(role) && (
              <div style={{ position: 'relative' }}>
                <button type="button" aria-label={`Notifications${staffUnreadCount ? `, ${staffUnreadCount} unread` : ''}`}
                  aria-expanded={notificationBellOpen} onClick={() => { setNotificationBellOpen(open => !open); loadStaffNotifications(); }}
                  style={{ ...S.btnTinyOutline, minWidth: 42, height: 36, fontSize: 17, position: 'relative' }}>
                  🔔
                  {staffUnreadCount > 0 && <span style={{ position: 'absolute', top: -6, right: -6, minWidth: 18, height: 18, borderRadius: 10, padding: '0 4px', background: 'var(--rust)', color: '#fff', fontSize: 10, lineHeight: '18px', fontWeight: 800 }}>{staffUnreadCount > 99 ? '99+' : staffUnreadCount}</span>}
                </button>
                {notificationBellOpen && <div role="dialog" aria-label="Notifications" style={{ position: 'absolute', zIndex: 1000, isolation: 'isolate', right: 0, top: 44, width: 'min(390px, calc(100vw - 32px))', maxHeight: 440, overflowY: 'auto', padding: 12, backgroundColor: 'var(--bg, #fff)', color: 'var(--text-h, #16171d)', border: '1px solid var(--border, #dedee3)', borderRadius: 10, boxShadow: '0 12px 34px rgba(0,0,0,.24)' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 8 }}>
                    <strong style={{ fontSize: 13 }}>Notifications</strong>
                    <button type="button" onClick={() => setNotificationBellOpen(false)} style={S.btnTinyOutline}>Close</button>
                  </div>
                  {staffNotifications.length === 0 ? <div style={{ color: 'var(--text-muted)', fontSize: 12, padding: '14px 4px' }}>No notifications yet.</div> : staffNotifications.map(item => (
                    <button key={item.id} type="button" onClick={() => handleReadStaffNotification(item)}
                      style={{ display: 'block', textAlign: 'left', width: '100%', border: 0, borderRadius: 7, padding: '10px 9px', marginTop: 5, cursor: 'pointer', color: 'var(--text-h, #16171d)', backgroundColor: item.read_at ? 'var(--bg, #fff)' : 'var(--code-bg, #f4f3ec)' }}>
                      <span style={{ display: 'flex', gap: 8, alignItems: 'baseline', justifyContent: 'space-between' }}>
                        <strong style={{ fontSize: 12 }}>{item.title}</strong>
                        {!item.read_at && <span style={{ color: 'var(--rust)', fontSize: 10, fontWeight: 800 }}>NEW</span>}
                      </span>
                      <span style={{ display: 'block', marginTop: 5, fontSize: 12, lineHeight: 1.5, color: 'var(--text, #555)', whiteSpace: 'pre-wrap' }}>{item.message}</span>
                      <span style={{ display: 'block', marginTop: 5, fontSize: 10, color: 'var(--text, #555)' }}>{item.author_name || 'CTMS'} · {item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleString() : ''}</span>
                    </button>
                  ))}
                </div>}
              </div>
            )}
            <div style={S.sessionBadge}>● Session active</div>
          </div>
        </header>

        <div style={{ ...S.content, '--ctms-panel-desc-display': workingMode === 'direct' ? 'none' : 'block' }}
          className={`working-mode-${workingMode}`}>

          {activeTab === 'announcements' && role === 'admin' && (
            <div style={{ display: 'grid', gap: 16 }}>
              <section style={S.panel}>
                <h2 style={{ margin: '0 0 6px', fontSize: 17 }}>Send an announcement</h2>
                <p style={S.panelDesc}>Employees receive this in the notification bell. HOD and Chairman accounts are excluded. Delivery and read receipts stay in the system history.</p>
                <form onSubmit={handlePublishAnnouncement} style={{ display: 'grid', gap: 12, maxWidth: 850 }}>
                  <label style={{ display: 'grid', gap: 5, fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>TITLE
                    <input required maxLength={200} value={announcementTitle} onChange={e => setAnnouncementTitle(e.target.value)} style={S.fieldInput} placeholder="For example: Lab maintenance on Friday" />
                  </label>
                  <label style={{ display: 'grid', gap: 5, fontSize: 11, fontWeight: 700, color: 'var(--text-muted)' }}>MESSAGE
                    <textarea required maxLength={5000} rows={5} value={announcementMessage} onChange={e => setAnnouncementMessage(e.target.value)} style={{ ...S.fieldInput, resize: 'vertical', lineHeight: 1.5 }} placeholder="Write the instructions or information employees need." />
                    <span style={{ textAlign: 'right', fontWeight: 400 }}>{announcementMessage.length}/5000</span>
                  </label>
                  <fieldset style={{ border: '1px solid var(--border)', borderRadius: 8, padding: 12 }}>
                    <legend style={{ padding: '0 5px', fontSize: 11, fontWeight: 700 }}>AUDIENCE</legend>
                    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '9px 18px' }}>
                      {[
                        ['all', 'All staff except HOD / Chairman'], ['superadmin', 'Super Admin'], ['admin', 'Administrators'],
                        ['mapping', 'Mapping'], ['reception', 'Reception'], ['pa', 'PA'], ['technician', 'Technicians'], ['deo', 'Data / Reports staff'],
                      ].map(([value, label]) => {
                        const checked = announcementRoles.includes(value);
                        return <label key={value} style={{ display: 'inline-flex', gap: 6, alignItems: 'center', fontSize: 12 }}>
                          <input type="checkbox" checked={checked} onChange={() => {
                            if (value === 'all') setAnnouncementRoles(checked ? [] : ['all']);
                            else setAnnouncementRoles(current => {
                              const filtered = current.filter(item => item !== 'all');
                              return checked ? filtered.filter(item => item !== value) : [...filtered, value];
                            });
                          }} />{label}
                        </label>;
                      })}
                    </div>
                    {announcementRoles.includes('all') && <div style={{ marginTop: 12, paddingTop: 10, borderTop: '1px solid var(--border)' }}>
                      <div style={{ fontSize: 11, fontWeight: 700, marginBottom: 7 }}>EXCLUDE FROM ALL STAFF</div>
                      <div style={{ display: 'flex', flexWrap: 'wrap', gap: '9px 18px' }}>
                        {[
                          ['superadmin', 'Super Admin'], ['admin', 'Administrators'], ['mapping', 'Mapping'],
                          ['reception', 'Reception'], ['pa', 'PA'], ['technician', 'Technicians'], ['deo', 'Data / Reports staff'],
                        ].map(([value, label]) => <label key={`exclude-${value}`} style={{ display: 'inline-flex', gap: 6, alignItems: 'center', fontSize: 12 }}>
                          <input type="checkbox" checked={announcementExcludeRoles.includes(value)} onChange={() => setAnnouncementExcludeRoles(current => current.includes(value) ? current.filter(item => item !== value) : [...current, value])} />{label}
                        </label>)}
                      </div>
                    </div>}
                    <div style={{ marginTop: 7, fontSize: 10, color: 'var(--text-muted)' }}>Choose one or more groups. HOD and Chairman are always excluded.</div>
                  </fieldset>
                  <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
                    <button type="submit" disabled={announcementBusy || !announcementRoles.length} style={S.btnPrimary}>{announcementBusy ? 'Publishing…' : 'Publish announcement'}</button>
                    {announcementFeedback && <span role="status" style={{ fontSize: 12, color: announcementFeedback.startsWith('Could not') || announcementFeedback.startsWith('The announcement') ? 'var(--rust)' : 'var(--text-muted)' }}>{announcementFeedback}</span>}
                  </div>
                </form>
              </section>

              <section style={S.panel}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 12, flexWrap: 'wrap' }}>
                  <div><h2 style={{ margin: '0 0 4px', fontSize: 17 }}>Announcement history</h2><p style={S.panelDesc}>Review who received each notice and when each person read it.</p></div>
                  <button type="button" onClick={loadAnnouncementHistory} style={S.btnTinyOutline}>Refresh</button>
                </div>
                {announcementHistory.length === 0 ? <div style={{ padding: 14, color: 'var(--text-muted)', fontSize: 12 }}>No announcements have been sent.</div> : <div style={{ display: 'grid', gap: 8 }}>
                  {announcementHistory.map(item => <div key={item.id} style={{ border: '1px solid var(--border)', borderRadius: 8, padding: 12, display: 'flex', justifyContent: 'space-between', gap: 14, alignItems: 'center', flexWrap: 'wrap' }}>
                    <div style={{ minWidth: 220, flex: 1 }}>
                      <strong style={{ fontSize: 13 }}>{item.title}</strong>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 4 }}>{item.kind === 'lot_created' ? 'Automatic lot notice' : `By ${item.author_name || 'Administrator'}`} · {item.created_at ? new Date(item.created_at.replace(' ', 'T')).toLocaleString() : ''}</div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 6, whiteSpace: 'pre-wrap' }}>{item.message}</div>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 10 }}>
                      <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>{item.read_count}/{item.recipient_count} read</span>
                      <button type="button" onClick={() => handleOpenAnnouncementReceipts(item)} style={S.btnTinyOutline}>Read receipts</button>
                    </div>
                  </div>)}
                </div>}
                {selectedAnnouncement && announcementReceipts && <div style={{ marginTop: 14, borderTop: '1px solid var(--border)', paddingTop: 12 }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, alignItems: 'center', marginBottom: 9 }}>
                    <strong style={{ fontSize: 13 }}>Receipts: {selectedAnnouncement.title} ({announcementReceipts.filter(row => row.read_at).length}/{announcementReceipts.length} read)</strong>
                    <button type="button" onClick={() => { setSelectedAnnouncement(null); setAnnouncementReceipts(null); }} style={S.btnTinyOutline}>Close</button>
                  </div>
                  {announcementReceipts.length === 0 ? <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>No recipients recorded.</div> : <div style={{ maxHeight: 330, overflow: 'auto' }}>{announcementReceipts.map(row => <div key={row.user_id} style={{ display: 'grid', gridTemplateColumns: 'minmax(130px,1.2fr) minmax(100px,1fr) minmax(95px,.7fr)', gap: 8, padding: '8px 4px', borderBottom: '1px solid var(--border)', fontSize: 11 }}>
                    <span>{row.full_name || 'Employee'}<small style={{ display: 'block', color: 'var(--text-muted)' }}>{row.employee_id || row.role}</small></span>
                    <span style={{ color: row.read_at ? 'var(--teal, #16827a)' : 'var(--text-muted)' }}>{row.read_at ? 'Read' : 'Unread'}</span>
                    <span style={{ color: 'var(--text-muted)' }}>{row.read_at ? new Date(row.read_at.replace(' ', 'T')).toLocaleString() : '—'}</span>
                  </div>)}</div>}
                </div>}
              </section>
            </div>
          )}

          {activeTab === 'chat' && <SecureChat userId={userId} userName={user} userRole={role} />}

          {/* ═══════════════ PHASE 1 — INTAKE ═══════════════ */}
          {activeTab === 'intake' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Create a new lot, enter sample data, and commit it to the database.</p>
              {intakeRecovery.recovered && currentLot && <div role="status" style={{ ...S.msgBox, ...S.msgSuccess, marginBottom: 12 }}>Recovered an unsaved intake draft for {currentLot}. Changes save automatically on this device. <button onClick={() => { if (window.confirm('Discard this recovered intake draft?')) handleDiscardGrid(); }} style={S.btnTinyOutline}>Discard draft</button></div>}
              {!intakeRecovery.recovered && currentLot && intakeRecovery.savedAt && <div style={{ color: 'var(--text-muted)', fontSize: 11, marginBottom: 8 }}>Draft saved on this device at {new Date(intakeRecovery.savedAt).toLocaleTimeString()}.</div>}
              {intakeRecovery.saveError && <div role="alert" style={{ ...S.msgBox, ...S.msgError, marginBottom: 12 }}>{intakeRecovery.saveError}</div>}

              <div style={S.toolbar}>
                <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                  <select value={intakeType} onChange={e => setIntakeType(e.target.value)}
                    style={S.select} disabled={!!currentLot || isReadOnly}>
                    <option value="CHEQUE">Cheque Registry</option>
                    <option value="ONLINE">Online High-Priority</option>
                    <option value="ELECTRONICS">Electronics Direct-Pass</option>
                    <option value="MIXED">Mixed (Cheque + Online)</option>
                  </select>
                  <button onClick={handleGenerateLot}
                    disabled={isGenerating || !!currentLot || isReadOnly} style={S.btnPrimary}>
                    {isGenerating ? 'Generating…' : '+ Create New Lot'}
                  </button>
                </div>
                {currentLot && !isReadOnly && (
                  <div style={{ display: 'flex', gap: '8px', flexWrap: 'wrap' }}>
                    <button onClick={addColumn} style={S.btnSecondary}>+ Column</button>
                    <button onClick={handleDiscardGrid} style={S.btnDanger}>Discard</button>
                    <button onClick={handleSaveLot} disabled={isSaving} style={S.btnSuccess}>
                      {isSaving ? 'Saving…' : 'Save to Database'}
                    </button>
                  </div>
                )}
              </div>

              {message && (
                <div style={{ ...S.msgBox, ...(S.msgTone(message)) }}>
                  {message}
                </div>
              )}

              {currentLot ? (
                <div>
                  <div style={S.lotBadgeRow}>
                    <div style={S.lotBadge}>{currentLot}</div>
                    {intakeType === 'ELECTRONICS' && (
                      <span style={{ ...stampStyle('Electronics Lab (Lab Technician)') }}>Bypass Mode</span>
                    )}
                  </div>

                  {/* Lot-level details — asked once at sheet-making time, printed on the final report */}
                  <div style={{ display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'flex-end', margin: '10px 0 12px' }}>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '12px', fontWeight: 600 }}>
                      Customer / Company Name *
                      <input value={lotCustomerName} onChange={e => setLotCustomerName(e.target.value)}
                        placeholder="e.g. Example Client" style={{ ...S.fieldInput, minWidth: '180px' }} />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '12px', fontWeight: 600 }}>
                      Item Description (optional)
                      <input value={lotItemDesc} onChange={e => setLotItemDesc(e.target.value)}
                        placeholder="e.g. 12 mm dia deformed bar" style={{ ...S.fieldInput, minWidth: '180px' }} />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '12px', fontWeight: 600 }}>
                      Grade Match (optional)
                      <input value={lotGradeMatch} onChange={e => setLotGradeMatch(e.target.value)}
                        placeholder="e.g. ASTM A615 Grade 60" style={{ ...S.fieldInput, minWidth: '180px' }} />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '12px', fontWeight: 600 }}>
                      Delivery promised in (days)
                      <input type="number" min="1" value={lotSlaDays} onChange={e => setLotSlaDays(e.target.value)}
                        placeholder="e.g. 3" title="Promised delivery — drives the overdue board"
                        style={{ ...S.fieldInput, width: '110px' }} />
                    </label>
                    <label style={{ display: 'flex', flexDirection: 'column', gap: '4px', fontSize: '12px', fontWeight: 600 }}>
                      Template
                      <select value="" onChange={e => applyTemplate(templates.find(t => String(t.id) === e.target.value))}
                        style={{ ...S.select, minWidth: '150px' }}>
                        <option value="">Load template…</option>
                        {templates.map(t => <option key={t.id} value={t.id}>{t.name}</option>)}
                      </select>
                    </label>
                    <button onClick={() => setShowSaveTemplate(true)} style={S.btnOutlineSm}
                      title="Save these details as a reusable template">Save as template</button>
                  </div>
                  {showSaveTemplate && (
                    <div style={{ display: 'flex', gap: '8px', alignItems: 'center', marginBottom: '12px' }}>
                      <input value={templateName} onChange={e => setTemplateName(e.target.value)} autoFocus
                        placeholder="Template name (e.g. Example Client — Handfree)" style={{ ...S.fieldInput, width: '240px' }}
                        onKeyDown={e => { if (e.key === 'Enter') handleSaveTemplate(); if (e.key === 'Escape') setShowSaveTemplate(false); }} />
                      <button onClick={handleSaveTemplate} style={S.btnPrimary}>Save</button>
                      <button onClick={() => setShowSaveTemplate(false)} style={S.btnOutlineSm}>Cancel</button>
                    </div>
                  )}

                  {/* Large spreadsheet-style grid — fills available height */}
                  <div style={{ ...S.intakeGridWrap, userSelect: isDragSelecting ? 'none' : 'auto' }}>
                    <table style={S.table} onCopy={handleGridCopy}>
                      <thead>
                        <tr>
                          <th style={{ ...S.th, width: '34px' }}>
                            <input type="checkbox" checked={allRowsSelected} onChange={toggleAllRows}
                              title="Select all rows" style={{ cursor: 'pointer' }} />
                          </th>
                          <th style={{ ...S.th, width: '60px' }}>S. No</th>
                          {columns.map((col, i) => (
                            <th key={i} style={{ ...S.th, minWidth: '160px' }}>
                              <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: '6px' }}>
                                <span onDoubleClick={() => !isReadOnly && renameColumn(i)}
                                  style={{ cursor: 'pointer' }} title="Double-click to rename">
                                  {col}
                                </span>
                                {!isReadOnly && (
                                  <span onClick={() => removeColumn(i)} style={S.colRemove} title="Remove column">✕</span>
                                )}
                              </div>
                            </th>
                          ))}
                          {!isReadOnly && <th style={{ ...S.th, width: '44px' }} title="Duplicate row"></th>}
                          {!isReadOnly && <th style={{ ...S.th, width: '44px' }} title="Delete row"></th>}
                        </tr>
                      </thead>
                      <tbody>
                        {gridData.map((row, rIdx) => (
                          <tr key={rIdx}>
                            <td style={{ ...S.rowNumClean, background: selectedRows.has(rIdx) ? 'rgba(194,112,61,0.12)' : undefined }}>
                              <input type="checkbox" checked={selectedRows.has(rIdx)}
                                onChange={() => toggleRowSelect(rIdx)} style={{ cursor: 'pointer' }} />
                            </td>
                            <td style={S.rowNumClean}>{srNumbers[rIdx]}</td>
                            {row.map((cell, cIdx) => (
                              <td key={cIdx}
                                style={isCellInRange(rIdx, cIdx) ? S.gridCellSelected : S.gridCell}
                                onMouseDown={() => !isReadOnly && handleCellMouseDown(rIdx, cIdx)}
                                onMouseEnter={() => !isReadOnly && handleCellMouseEnter(rIdx, cIdx)}>
                                {columns[cIdx] === 'Mode' && intakeType === 'ELECTRONICS' ? (
                                  <select
                                    id={`cell-${rIdx}-${cIdx}`}
                                    value={cell} disabled={isReadOnly}
                                    onChange={e => handleCellChange(rIdx, cIdx, e.target.value)}
                                    style={S.cellInput}>
                                    <option value=""></option>
                                    <option value="ONLINE">ONLINE</option>
                                    <option value="CHEQUE">CHEQUE</option>
                                  </select>
                                ) : (
                                  <textarea
                                    id={`cell-${rIdx}-${cIdx}`}
                                    value={cell} rows={1} disabled={isReadOnly}
                                    onChange={e => handleCellChange(rIdx, cIdx, e.target.value)}
                                    onPaste={e => handlePaste(e, rIdx, cIdx)}
                                    onKeyDown={e => handleKeyDown(e, rIdx, cIdx)}
                                    readOnly={isRangeMultiCell()}
                                    style={S.cellInput}
                                  />
                                )}
                              </td>
                            ))}
                            {!isReadOnly && (
                              <td style={S.dupCell} onClick={() => handleDuplicateRow(rIdx)} title="Duplicate this row — same SD No, new Request No">
                                <span style={S.dupIcon}>⧉</span>
                              </td>
                            )}
                            {!isReadOnly && (
                              <td style={S.delCell} onClick={() => handleDeleteRow(rIdx)} title="Delete this row">
                                <span style={S.delIcon}>✕</span>
                              </td>
                            )}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>

                  {!isReadOnly && (
                    <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginTop: '12px' }}>
                      <input type="number" min="1" max="5000" value={appendCount}
                        onChange={e => setAppendCount(e.target.value)}
                        onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addRows(); } }}
                        title="How many rows to add"
                        style={{ ...S.fieldInput, width: '90px', padding: '7px 10px' }} />
                      <button onClick={() => addRows()} style={S.btnSecondary}>+ Add rows</button>
                      {selectedRows.size > 0 && (
                        <button onClick={deleteSelectedRows} style={{ ...S.btnDanger, background: 'var(--rust)', color: '#fff' }}>
                          🗑 Delete selected ({selectedRows.size})
                        </button>
                      )}
                    </div>
                  )}
                </div>
              ) : lastSavedLot ? (
                <div style={S.readyToDownloadCard}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: '14px' }}>
                    <HexMark size={40} />
                    <div>
                      <div style={S.lotName}>{lastSavedLot.lot_name}</div>
                      <p style={S.lotCardMeta}>
                        {lastSavedLot.samples.length} sample(s) saved — ready to hand to the peon for marking.
                      </p>
                    </div>
                  </div>
                  <div style={{ display: 'flex', gap: '10px' }}>
                    <button onClick={() => handlePrintLotExcel(lastSavedLot)} style={S.btnSuccess}>
                      Download Excel sheet
                    </button>
                    <button onClick={() => setLastSavedLot(null)} style={S.btnOutlineSm}>Dismiss</button>
                  </div>
                </div>
              ) : (
                <div style={S.empty}>
                  <HexMark size={44} />
                  <p style={{ marginTop: '14px' }}>Create a new lot to open the data-entry grid.</p>
                </div>
              )}
            </div>
          )}

          {/* ═══════════════ PHASE 2 — MAPPING ═══════════════ */}
          {activeTab === 'mapping' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Select samples and dispatch them to the appropriate laboratory.</p>

              {routingSuccess && (
                <div style={{ ...S.msgBox, ...S.msgSuccess }}>
                  Routing successful — samples dispatched to the lab queue.
                </div>
              )}

              {selectedIds.length > 0 && !isReadOnly && (
                <div style={S.routingBar}>
                  <span><strong>{selectedIds.length}</strong> sample(s) selected</span>
                  <div style={{ display: 'flex', gap: '14px', alignItems: 'center', flexWrap: 'wrap' }}>
                    <div style={S.labCheckGroup}>
                      {[
                        ['Tensile Lab (Zeeshan Hassan)', 'Tensile'],
                        ['XRF Lab (Khurram Jahangeer)',  'XRF'],
                        ['OES Lab (Jahangeer)',           'OES'],
                      ].map(([val, label]) => (
                        <label key={val} style={S.labCheckLabel}>
                          <input type="checkbox"
                            checked={targetStreams.includes(val)}
                            onChange={() => setTargetStreams(prev =>
                              prev.includes(val) ? prev.filter(x => x !== val) : [...prev, val])}
                          />
                          {label}
                        </label>
                      ))}
                    </div>
                    <button onClick={handleExecuteRouting} disabled={isRouting || !targetStreams.length} style={S.btnPrimary}>
                      {isRouting ? 'Routing…' : targetStreams.length > 1 ? `Dispatch to ${targetStreams.length} labs` : 'Dispatch to lab'}
                    </button>
                  </div>
                </div>
              )}

              {isLoadingP2 ? (
                <div style={S.loading}>Loading lots from database…</div>
              ) : pendingLots.length === 0 ? (
                <div style={S.empty}>
                  <HexMark size={44} />
                  <h3 style={S.emptyTitle}>All samples have been routed</h3>
                  <p>Nothing is waiting for mapping allocation right now.</p>
                </div>
              ) : (
                pendingLots.map(lot => {
                  const allSel = lot.samples.every(s => selectedIds.includes(s.id));
                  return (
                    <div key={lot.id} style={S.lotBox}>
                      <div style={S.lotBoxHeader}>
                        <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                          <span style={S.lotName}>{lot.lot_name}</span>
                          <span style={S.lotTypeBadge}>{lot.lot_type}</span>
                        </div>
                        <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                          <button onClick={() => handlePrintLotExcel(lot)} style={S.btnOutlineSm}>
                            Download Excel
                          </button>
                          {!isReadOnly && (
                            <>
                              <button onClick={() => toggleAllInLot(lot.samples)} style={S.btnOutlineSm}>
                                {allSel ? 'Deselect all' : 'Select all'}
                              </button>
                              <button onClick={() => handleDeleteLot(lot.id, lot.lot_name)} style={S.btnDangerSm}>
                                Delete
                              </button>
                            </>
                          )}
                          <span style={S.countBadge}>{lot.samples.length} samples</span>
                        </div>
                      </div>
                      <div style={S.tableWrap}>
                        <table style={S.table}>
                          <thead>
                            <tr>
                              {!isReadOnly && <th style={{ ...S.th, width: '40px' }}></th>}
                              <th style={S.th}>Status</th>
                              {lot.samples[0] && Object.keys(lot.samples[0].data).map((h, i) => (
                                <th key={i} style={S.th}>{h}</th>
                              ))}
                            </tr>
                          </thead>
                          <tbody>
                            {lot.samples.map((sample, ri) => (
                              <tr key={sample.id} style={selectedIds.includes(sample.id) ? S.rowSelected : (ri % 2 ? S.rowAlt : undefined)}>
                                {!isReadOnly && (
                                  <td style={S.td}>
                                    <input type="checkbox"
                                      checked={selectedIds.includes(sample.id)}
                                      onChange={() => toggleSample(sample.id)}
                                      style={{ transform: 'scale(1.25)', cursor: 'pointer' }}
                                    />
                                  </td>
                                )}
                                <td style={S.td}>
                                  <span style={stampStyle(sample.status)}>{sample.status}</span>
                                </td>
                                {Object.values(sample.data).map((v, i) => (
                                  <td key={i} style={S.td}>{v || '—'}</td>
                                ))}
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  );
                })
              )}
            </div>
          )}

          {/* ═══════════════ PHASE 3 — LAB TESTING ═══════════════ */}
          {activeTab === 'testing' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Enter test measurements. Cross-section area is calculated automatically from diameter.</p>

              {role === 'admin' && (
                <div style={S.adminStreamBar}>
                  <label style={S.adminStreamLabel}>Viewing as lab:</label>
                  <select value={adminLabStream}
      onChange={e => { labRecovery.clearDraft(); setLabDraftLot(null); setActiveTestingLot(null); setTestGridData({}); setElecParamData({}); setInstrumentImport(null); setAdminLabStream(e.target.value); setLabResultFields(null); }}
                    style={S.select}>
                    <option value="Tensile Lab (Zeeshan Hassan)">Tensile Lab — Zeeshan Hassan</option>
                    <option value="XRF Lab (Khurram Jahangeer)">XRF Lab — Khurram Jahangeer</option>
                    <option value="OES Lab (Jahangeer)">OES Lab — Jahangeer</option>
                    <option value="Electronics Lab (Lab Technician)">Electronics Lab — Lab Technician</option>
                  </select>
                </div>
              )}

              {activeTestingLot ? (
                <div>
                  <div style={S.testHeader}>
                    <div>
                      <span style={S.lotName}>{activeTestingLot.lot_name}</span>
                      <span style={S.lotTypeBadge}>{activeTestingLot.samples.length} samples</span>
                    </div>
                    <button onClick={() => setActiveTestingLot(null)} style={S.btnSecondary}>← Back to queue</button>
                  </div>

                  {submitMsg && (
                    <div style={{ ...S.msgBox, ...(submitMsg.startsWith('Could not') ? S.msgError : S.msgSuccess), marginBottom: '16px' }}>{submitMsg}</div>
                  )}
                  {labRecovery.recovered && <div role="status" style={{ ...S.msgBox, ...S.msgSuccess, marginBottom: 12 }}>Recovered unsaved readings for this lot. They are stored on this device and will be removed after a successful save. <button onClick={() => { if (window.confirm('Discard these unsaved readings?')) { labRecovery.clearDraft(); setLabDraftLot(null); setActiveTestingLot(null); setTestGridData({}); setElecParamData({}); } }} style={S.btnTinyOutline}>Discard draft</button></div>}
                  {!labRecovery.recovered && labRecovery.savedAt && <div style={{ color: 'var(--text-muted)', fontSize: 11, marginBottom: 8 }}>Readings draft saved on this device at {new Date(labRecovery.savedAt).toLocaleTimeString()}.</div>}
                  {labRecovery.saveError && <div role="alert" style={{ ...S.msgBox, ...S.msgError, marginBottom: 12 }}>{labRecovery.saveError}</div>}

                  {!isReadOnly && <div style={{ ...S.card, marginBottom: 16, padding: 14 }}>
                    <div style={{ fontWeight: 700, marginBottom: 4 }}>Import instrument readings from CSV</div>
                    <div style={{ color: 'var(--text-muted)', fontSize: 12, marginBottom: 9 }}>Use a CSV or tab-separated instrument export. Match rows by sample reference, review the preview, then import. This works without a direct instrument connection.</div>
                    <label style={{ display: 'inline-flex', gap: 8, alignItems: 'center', flexWrap: 'wrap', fontSize: 12 }}>
                      <input type="file" accept=".csv,.txt,text/csv,text/plain" onChange={event => { prepareInstrumentImport(event.target.files?.[0]); event.target.value = ''; }} />
                      <span style={{ color: 'var(--text-muted)' }}>Maximum 5 MB and 5,000 readings per import.</span>
                    </label>
                    {instrumentImport && <div style={{ marginTop: 12, borderTop: '1px solid var(--border)', paddingTop: 10 }}>
                      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
                        <div><strong>{instrumentImport.fileName}</strong> · {instrumentImport.rows.filter(row => row.sampleId).length} rows matched, {instrumentImport.rows.filter(row => !row.sampleId).length} unmatched</div>
                        <div style={{ display: 'flex', gap: 7 }}><button type="button" onClick={() => setInstrumentImport(null)} style={S.btnSecondary}>Cancel</button><button type="button" onClick={applyInstrumentImport} disabled={!instrumentImport.rows.some(row => row.sampleId)} style={S.btnSuccess}>Import matched readings</button></div>
                      </div>
                      {instrumentImport.unknownHeaders.length > 0 && <div style={{ color: 'var(--text-muted)', fontSize: 11, marginTop: 5 }}>Columns ignored: {instrumentImport.unknownHeaders.join(', ')}</div>}
                      <div style={{ maxHeight: 170, overflow: 'auto', marginTop: 8 }}><table style={S.table}><thead><tr>{instrumentImport.headers.map((header, index) => <th key={index} style={S.th}>{header}</th>)}</tr></thead><tbody>{instrumentImport.rows.slice(0, 5).map(row => <tr key={row.line} style={row.sampleId ? undefined : { opacity: .55 }}>{instrumentImport.headers.map((_, index) => <td key={index} style={S.td}>{row.values[index]}</td>)}</tr>)}</tbody></table></div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 5 }}>Import adds new readings or updates matching element names. Blank cells leave existing values untouched.</div>
                    </div>}
                  </div>}

                  {getCurrentLabType() && (
                    <div style={{ ...S.card, marginBottom: '16px', padding: '14px', borderLeft: '4px solid var(--teal)' }}>
                      <div style={{ fontWeight: 700, marginBottom: 5 }}>Fields used for this {getCurrentLabType().toUpperCase()} test</div>
                      <div style={{ color: 'var(--text-muted)', fontSize: 12, marginBottom: 10 }}>
                        Add, rename, or remove fields. Active fields become entry columns and are included in saved results and reports.
                      </div>
                      {!isReadOnly && <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap', alignItems: 'end', marginBottom: 10 }}>
                        <label style={{ fontSize: 11 }}>{['xrf','oes'].includes(getCurrentLabType()) ? 'Element symbol' : 'Measurement field'}
                          <input value={newLabField.label} onChange={e => setNewLabField(v => ({ ...v, label: e.target.value }))} placeholder={['xrf','oes'].includes(getCurrentLabType()) ? 'e.g. Al' : 'e.g. Reduction of Area'} style={{ padding: '7px 9px', border: '1px solid var(--border)', borderRadius: 5, display: 'block', minWidth: 190 }} />
                        </label>
                        {getCurrentLabType() === 'tensile' && <label style={{ fontSize: 11 }}>Unit
                          <input value={newLabField.unit} onChange={e => setNewLabField(v => ({ ...v, unit: e.target.value }))} placeholder="e.g. % or mm" style={{ padding: '7px 9px', border: '1px solid var(--border)', borderRadius: 5, display: 'block', width: 110 }} />
                        </label>}
                        <button onClick={() => manageLabField('add')} disabled={!newLabField.label.trim()} style={S.btnSecondary}>+ Add field</button>
                      </div>}
                      <div style={{ display: 'flex', gap: 7, flexWrap: 'wrap' }}>
                        {(labResultFields || []).map(field => <div key={field.id} style={{ display: 'flex', gap: 4, alignItems: 'center', padding: '4px 6px', border: '1px solid var(--border)', borderRadius: 5, opacity: Number(field.is_active) ? 1 : .55 }}>
                          <input value={field.label} disabled={isReadOnly || !Number(field.is_active)} aria-label={`Report label for ${field.field_key}`} onChange={e => setLabResultFields(prev => prev.map(f => f.id === field.id ? { ...f, label: e.target.value } : f))} style={{ width: 145, padding: '4px', border: '1px solid var(--border)', borderRadius: 4 }} />
                          {!isReadOnly && Number(field.is_active) === 1 && <button onClick={() => manageLabField('update', { id: field.id, label: field.label, unit: field.unit || '' })} style={S.btnTinyOutline} title="Save field label">Save</button>}
                          {!isReadOnly && <button onClick={() => manageLabField(Number(field.is_active) ? 'delete' : 'restore', { id: field.id })} style={S.btnTinyOutline} title={Number(field.is_active) ? 'Remove field' : 'Restore field'}>{Number(field.is_active) ? '×' : 'Restore'}</button>}
                        </div>)}
                      </div>
                    </div>
                  )}

                  {/* Electronics: multi-parameter rows per sample (Example Client checklist) */}
                  {(role === 'admin' ? adminLabStream : labStream).includes('Electronics') ? (
                    <div style={{ display: 'flex', flexDirection: 'column', gap: '18px' }}>
                      {activeTestingLot.samples.map((sample) => {
                        const rows = elecParamData[sample.id] || [emptyElecRow()];
                        const mat = sample.data['Material Description'] || '';
                        return (
                          <div key={sample.id} style={{ border: '1px solid var(--border)', borderRadius: '8px', overflow: 'hidden' }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '10px 14px', background: 'var(--surface-2, #f4f4f2)', flexWrap: 'wrap', gap: '8px' }}>
                              <div>
                                <span style={{ fontWeight: 700, fontFamily: "'IBM Plex Mono', monospace" }}>
                                  {sample.data['Reference Number'] || sample.data['SD No'] || `SMP-${sample.id}`}
                                </span>
                                <span style={{ marginLeft: '10px', color: 'var(--text-muted)', fontSize: '13px' }}>{mat || '—'}</span>
                              </div>
                              <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                <button type="button" onClick={() => copyLabRow(sample)} style={S.btnTinyOutline} title="Copy all parameter rows">⧉ Copy</button>
                                <button type="button" onClick={() => pasteLabRow(sample.id)} disabled={!copiedLabRow?.elec} style={{ ...S.btnTinyOutline, opacity: copiedLabRow?.elec ? 1 : 0.4 }}>⇩ Paste</button>
                                <button type="button" onClick={() => loadElecPreset(sample.id, mat)} disabled={isReadOnly} style={S.btnTinyOutline}>Load preset</button>
                                <button type="button" onClick={() => addElecParamRow(sample.id)} disabled={isReadOnly} style={S.btnTinyOutline}>+ Row</button>
                              </div>
                            </div>
                            <div style={S.intakeGridWrap}>
                              <table style={S.table}>
                                <thead>
                                  <tr>
                                    <th style={{ ...S.th, width: '44px' }}>#</th>
                                    <th style={{ ...S.th, ...S.thAccent, minWidth: '200px' }}>Test Parameter</th>
                                    <th style={{ ...S.th, ...S.thAccent, minWidth: '140px' }}>Specified Range</th>
                                    <th style={{ ...S.th, ...S.thAccent, minWidth: '140px' }}>Observed Value</th>
                                    <th style={{ ...S.th, ...S.thAccent, minWidth: '120px' }}>Remarks</th>
                                    <th style={{ ...S.th, width: '44px' }}></th>
                                  </tr>
                                </thead>
                                <tbody>
                                  {rows.map((row, ri) => (
                                    <tr key={ri} style={ri % 2 ? S.rowAlt : undefined}>
                                      <td style={{ ...S.td, textAlign: 'center', color: 'var(--text-muted)' }}>{String(ri + 1).padStart(2, '0')}</td>
                                      {['parameter', 'range', 'observed', 'remarks'].map((field) => (
                                        <td key={field} style={{ ...S.td, padding: 0 }}>
                                          <input
                                            type="text" disabled={isReadOnly}
                                            value={row[field] || ''}
                                            onChange={e => updateElecParam(sample.id, ri, field, e.target.value)}
                                            placeholder="—"
                                            style={S.cellInputLg}
                                          />
                                        </td>
                                      ))}
                                      <td style={S.td}>
                                        <button type="button" disabled={isReadOnly || rows.length <= 1}
                                          onClick={() => removeElecParamRow(sample.id, ri)}
                                          style={{ ...S.btnTinyOutline, opacity: rows.length <= 1 ? 0.35 : 1 }}
                                          title="Remove row">✕</button>
                                      </td>
                                    </tr>
                                  ))}
                                </tbody>
                              </table>
                            </div>
                          </div>
                        );
                      })}
                    </div>
                  ) : (
                  <div style={{ ...S.intakeGridWrap, userSelect: labIsDragging ? 'none' : 'auto' }}>
                    <table style={S.table} onCopy={handleLabGridCopy}>
                      <thead>
                        <tr>
                          <th style={{ ...S.th, width: '70px' }}>Copy</th>
                          <th style={S.th}>Sample Ref</th>
                          <th style={S.th}>Description</th>
                          {getLabColumns().map((col, i) => (
                            <th key={col} style={{ ...S.th, ...S.thAccent, minWidth: '140px' }}>{getLabColumnLabel(col)}</th>
                          ))}
                        </tr>
                      </thead>
                      <tbody>
                        {activeTestingLot.samples.map((sample, ri) => (
                          <tr key={sample.id} style={ri % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, whiteSpace: 'nowrap' }}>
                              <button onClick={() => copyLabRow(sample)} title="Copy this sample's full data"
                                style={{ ...S.btnTinyOutline, marginRight: '4px' }}>⧉</button>
                              <button onClick={() => pasteLabRow(sample.id)} disabled={!copiedLabRow}
                                title="Paste copied sample data into this row"
                                style={{ ...S.btnTinyOutline, opacity: copiedLabRow ? 1 : 0.4 }}>⇩</button>
                            </td>
                            <td style={{ ...S.td, fontWeight: '700', fontFamily: "'IBM Plex Mono', monospace", whiteSpace: 'nowrap' }}>
                              {sample.data['Reference Number'] || sample.data['SD No'] || `SMP-${sample.id}`}
                            </td>
                            <td style={{ ...S.td, color: 'var(--text-muted)' }}>
                              {sample.data['Material Description'] || '—'}
                            </td>
                            {getLabColumns().map((col, i) => (
                              <td key={i}
                                style={isLabCellInRange(ri, i) ? { ...S.td, padding: 0, border: '1px solid var(--copper)', background: 'rgba(194,112,61,0.10)' } : { ...S.td, padding: 0 }}
                                onMouseDown={() => !isReadOnly && handleLabCellMouseDown(ri, i)}
                                onMouseEnter={() => !isReadOnly && handleLabCellMouseEnter(ri, i)}>
                                <input
                                  id={`lab-cell-${ri}-${i}`}
                                  type="text" disabled={isReadOnly}
                                  value={testGridData[sample.id]?.[col] || ''}
                                  onChange={e => handleTestValueChange(sample.id, col, e.target.value)}
                                  onKeyDown={e => handleLabGridKeyDown(e, ri, i, activeTestingLot.samples.length, getLabColumns().length)}
                                  onPaste={e => handleLabGridPaste(e, ri, i)}
                                  readOnly={isLabRangeMultiCell()}
                                  placeholder="—"
                                  style={S.cellInputLg}
                                />
                              </td>
                            ))}
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  )}

                  {!isReadOnly && (
                    <div style={{ marginTop: '16px', textAlign: 'right', display: 'flex', justifyContent: 'flex-end', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                      {copiedLabRow && (
                        <>
                          <span style={{ fontSize: '11.5px', color: 'var(--text-muted)' }}>
                            Copied: <strong>{copiedLabRow.label}</strong>
                          </span>
                          <button onClick={pasteLabRowToAll} style={S.btnSecondary}>Paste to ALL samples</button>
                        </>
                      )}
                      <button onClick={handleSubmitTestResults} disabled={isSubmitting} style={S.btnSuccess}>
                        {isSubmitting ? 'Saving…' : 'Submit results & send to Reports'}
                      </button>
                    </div>
                  )}
                </div>
              ) : (
                isLoadingP3 ? (
                  <div style={S.loading}>Loading lab queue from database…</div>
                ) : testerLots.length === 0 ? (
                  <div style={S.empty}>
                    <HexMark size={44} />
                    <h3 style={S.emptyTitle}>Queue is clear</h3>
                    <p>No samples are assigned to this lab station yet.</p>
                  </div>
                ) : (
                  <div style={S.cardGrid}>
                    {testerLots.map(lot => (
                      <div key={lot.id} style={S.lotCard}>
                        <div style={S.lotName}>{lot.lot_name}</div>
                        <p style={S.lotCardMeta}>{lot.samples.length} sample(s) awaiting measurement</p>
                        <button onClick={() => {
                          openLabSheet(lot);
                        }} style={S.btnPrimary}>
                          Open lab sheet
                        </button>
                      </div>
                    ))}
                  </div>
                )
              )}
            </div>
          )}

          {/* ═══════════════ PHASE 4 — REPORTS ═══════════════ */}
          {activeTab === 'custody' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Track sample receipt, lab assignment, testing completion, storage moves, and client dispatch. Every event is timestamped by the server and tied to the staff account. Barcode input is ready for a future scanner; a keyboard-wedge scanner can populate the same field.</p>
              {custodyMessage && <div style={{ ...S.msgBox, ...(S.msgTone(custodyMessage)) }}>{custodyMessage}</div>}
              <div style={{ display: 'grid', gridTemplateColumns: 'minmax(220px, 1fr) minmax(260px, 1.2fr)', gap: 14, marginBottom: 16 }}>
                <div style={S.field}>
                  <label style={S.fieldLabel}>Lot</label>
                  <select value={custodyLotId} onChange={e => { setCustodyLotId(e.target.value); setCustodySampleId(''); setCustodyEvents([]); loadCustodySamples(e.target.value); }} style={S.fieldInput}>
                    <option value="">Select a lot…</option>
                    {custodyLots.map(l => <option key={l.id} value={l.id}>{l.label}</option>)}
                  </select>
                </div>
                <div style={S.field}>
                  <label style={S.fieldLabel}>Sample</label>
                  <select value={custodySampleId} onChange={e => { setCustodySampleId(e.target.value); loadCustodyTimeline(e.target.value); }} style={S.fieldInput} disabled={!custodyLotId}>
                    <option value="">Select a sample…</option>
                    {custodySamples.map(s => <option key={s.id} value={s.id}>{s.sd_number || s.reference_number || s.request_number || `Sample #${s.id}`} · {s.client_name || 'Sample'}</option>)}
                  </select>
                </div>
              </div>
              {custodyLoading && <div style={S.loading}>Loading custody records…</div>}
              {custodySampleId && (
                <div style={{ display: 'grid', gridTemplateColumns: 'minmax(280px, .85fr) minmax(320px, 1.15fr)', gap: 18, alignItems: 'start' }}>
                  <form onSubmit={handleRecordCustodyEvent} style={S.chequeForm}>
                    <h3 style={S.formTitle}>Record custody event</h3>
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Event</label>
                      <select value={custodyForm.event_type} onChange={e => setCustodyForm({ ...custodyForm, event_type: e.target.value })} style={S.fieldInput}>
                        <option value="stored">Storage location update</option>
                        <option value="testing_started">Testing started</option>
                        <option value="note">Custody note</option>
                        <option value="barcode_scanned">Barcode entered / scanned</option>
                      </select>
                    </div>
                    {(custodyForm.event_type === 'stored' || custodyForm.event_type === 'testing_started') && <div style={S.field}>
                      <label style={S.fieldLabel}>{custodyForm.event_type === 'stored' ? 'Storage location' : 'Testing location'}</label>
                      <input required={custodyForm.event_type === 'stored'} value={custodyForm.location} onChange={e => setCustodyForm({ ...custodyForm, location: e.target.value })} placeholder="e.g., Lab 2 · Cabinet B" style={S.fieldInput} />
                    </div>}
                    {custodyForm.event_type === 'barcode_scanned' && <div style={S.field}>
                      <label style={S.fieldLabel}>Barcode / scanner value</label>
                      <input required value={custodyForm.barcode_value} onChange={e => setCustodyForm({ ...custodyForm, barcode_value: e.target.value })} placeholder="Type or scan the sample label" style={S.fieldInput} autoComplete="off" />
                    </div>}
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Details</label>
                      <textarea value={custodyForm.details} onChange={e => setCustodyForm({ ...custodyForm, details: e.target.value })} rows={3} maxLength={2000} placeholder="Optional handling note" style={{ ...S.fieldInput, resize: 'vertical' }} />
                    </div>
                    <button type="submit" style={S.btnPrimary}>Add to custody history</button>
                    <div style={{ ...S.trailHint, marginTop: 8 }}>Events cannot be edited or removed; corrections are added as a new note.</div>
                  </form>
                  <div style={{ ...S.trailPanel, marginTop: 0, maxHeight: 560, overflowY: 'auto' }}>
                    <h3 style={S.trailTitle}>Custody timeline</h3>
                    {custodyEvents.length === 0 ? <div style={S.trailHint}>No custody events are recorded for this sample.</div> : custodyEvents.map((ev, i) => (
                      <div key={ev.id} style={{ display: 'grid', gridTemplateColumns: '18px 1fr', gap: 9, padding: '10px 0', borderBottom: i === custodyEvents.length - 1 ? 0 : '1px solid var(--border)' }}>
                        <span style={{ width: 10, height: 10, marginTop: 4, borderRadius: '50%', background: ev.event_type === 'dispatched' ? 'var(--teal)' : 'var(--copper)' }} />
                        <div><strong style={{ fontSize: 12.5 }}>{String(ev.event_type || '').replace(/_/g, ' ').toUpperCase()}</strong><div style={{ fontSize: 11, color: 'var(--text-soft)', marginTop: 3 }}>{ev.actor_name || 'Former staff account'} · {ev.event_at ? new Date(ev.event_at.replace(' ', 'T')).toLocaleString() : '—'}</div>{ev.location && <div style={{ fontSize: 12, marginTop: 3 }}><strong>Location:</strong> {ev.location}</div>}{ev.barcode_value && <div style={{ fontSize: 12, marginTop: 3 }}><strong>Barcode:</strong> {ev.barcode_value}</div>}{ev.details && <div style={{ fontSize: 12, marginTop: 3, whiteSpace: 'pre-wrap' }}>{ev.details}</div>}</div>
                      </div>
                    ))}
                  </div>
                </div>
              )}
            </div>
          )}

          {activeTab === 'reports' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Open a report to view it. CTMS automatically creates and saves its PDF in the background; ZIP and email dispatch also generate PDFs for older reports when needed.</p>

              <div style={{ ...S.trailSearchBox, marginBottom: 18 }}>
                <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                  <div>
                    <h3 style={{ ...S.trailTitle, marginBottom: 4 }}>Report approval queue</h3>
                    <div style={S.trailHint}>Draft → Technical Review → Issued. Returned reports include the reviewer’s correction note.</div>
                  </div>
                  <button type="button" onClick={loadReportWorkflow} disabled={workflowLoading} style={S.btnOutlineSm}>{workflowLoading ? 'Refreshing…' : 'Refresh approvals'}</button>
                </div>
                {workflowError && <div style={{ marginTop: 10, color: workflowError.startsWith('✅') ? 'var(--teal)' : 'var(--rust)', fontSize: 12 }}>{workflowError}</div>}
                {workflowReports.length === 0 ? (
                  <div style={{ marginTop: 12, fontSize: 12.5, color: 'var(--text-muted)' }}>No generated reports are waiting in the workflow. Open a completed report below to create its draft.</div>
                ) : (
                  <div style={{ ...S.tableWrap, marginTop: 12 }}>
                    <table style={S.table}>
                      <thead><tr>
                        <th style={S.th}>Report / Sample</th><th style={S.th}>Stage</th><th style={S.th}>History</th><th style={S.th}>Actions</th>
                      </tr></thead>
                      <tbody>{workflowReports.map((r, i) => (
                        <tr key={r.report_id} style={i % 2 ? S.rowAlt : undefined}>
                          <td style={S.td}><strong>{r.report_number}</strong><div style={{ fontSize: 11, color: 'var(--text-soft)' }}>{r.lot_number} · {r.sd_number || r.reference_number || `Sample #${r.sample_id}`}</div></td>
                          <td style={S.td}><span style={S.roleBadge}>{String(r.stage || 'draft').replace(/_/g, ' ').toUpperCase()}</span><div style={{ marginTop: 4, fontSize: 10.5, color: 'var(--text-soft)' }}>Revision {r.revision_no || 1}</div>{r.stage === 'changes_requested' && r.review_notes && <div style={{ maxWidth: 240, marginTop: 5, color: 'var(--rust)', fontSize: 11 }}>{r.review_notes}</div>}</td>
                          <td style={{ ...S.td, fontSize: 11 }}>Submitted: {r.submitted_by_name || '—'}<br />Reviewed: {r.reviewer_name || '—'}{r.issued_by_name ? <><br />Issued: {r.issued_by_name}</> : null}<details style={{ marginTop: 5 }}><summary style={{ cursor: 'pointer', color: 'var(--teal)' }}>Revision history</summary><div style={{ marginTop: 4 }}>{(r.history || []).map((event, ei) => <div key={ei} style={{ marginTop: 4, paddingLeft: 6, borderLeft: '2px solid var(--border)' }}><strong>{String(event.action || '').replace(/_/g, ' ')}</strong> · {event.actor_name || 'System'}<br /><span style={{ color: 'var(--text-soft)' }}>{new Date(event.created_at).toLocaleString()} · {event.from_stage || 'new'} → {event.to_stage}</span>{event.notes && <div>{event.notes}</div>}</div>)}</div></details></td>
                          <td style={S.td}><div style={{ display: 'flex', flexWrap: 'wrap', gap: 6 }}>
                            <button type="button" onClick={async () => { try { const url = await getDownloadTicket('report_generate.php', { sample_id: Number(r.sample_id) }); setReportViewerUrl(url); } catch (err) { alert(err.response?.data?.error || 'Could not open report preview.'); } }} style={S.btnTinyOutline}>Preview</button>
                            {['admin', 'deo'].includes(role) && ['draft', 'changes_requested'].includes(r.stage) && <button type="button" onClick={() => handleReportWorkflowAction(r, 'submit')} style={S.btnTinyOutline}>Submit for review</button>}
                            {(canTechnicalReview || workflowCanReview) && r.stage === 'technical_review' && <>
                              <button type="button" onClick={() => handleReportWorkflowAction(r, 'approve')} style={S.btnTinyOutline}>Approve &amp; issue</button>
                              <button type="button" onClick={() => handleReportWorkflowAction(r, 'return')} style={S.btnTinyDanger}>Return</button>
                            </>}
                            {role === 'admin' && r.stage === 'issued' && <button type="button" onClick={() => handleReportWorkflowAction(r, 'reopen')} style={S.btnTinyDanger}>Reopen for correction</button>}
                          </div></td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                )}
              </div>

              {isLoadingP4 ? (
                <div style={S.loading}>Loading completed lots…</div>
              ) : reportLots.length === 0 ? (
                <div style={S.empty}>
                  <HexMark size={44} />
                  <h3 style={S.emptyTitle}>Report queue is empty</h3>
                  <p>Reports appear here once technicians submit results in Phase 3.</p>
                </div>
              ) : (
                <div style={S.cardGrid}>
                  {reportLots.map(lot => (
                    <div key={lot.id} style={{ ...S.lotCard, borderLeftColor: 'var(--teal)' }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', marginBottom: '8px' }}>
                        <span style={S.lotName}>{lot.lot_name}</span>
                        <span style={stampStyle('Dispatched')}>Complete</span>
                      </div>
                      <p style={S.lotCardMeta}>Type: <strong>{lot.lot_type}</strong></p>
                      <p style={S.lotCardMeta}>{lot.samples.length} sample(s) with results</p>
                      {/* Bulk Lot 200+ Actions: Archive ZIP & Email Dispatch */}
                      <div style={{ marginTop: '12px', paddingTop: '10px', borderTop: '1px dashed #dcdcdc', display: 'flex', gap: '8px' }}>
                        <button
                          onClick={() => openArchiveFormatModal(lot.id, lot.lot_name)}
                          style={{ ...S.btnOutline, flex: 1, fontSize: '11.5px', padding: '6px 8px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '4px' }}>
                          📦 Archive (.ZIP)
                        </button>
                        <button
                          onClick={() => openEmailDispatchModal(lot)}
                          style={{ ...S.btnPrimary, flex: 1, fontSize: '11.5px', padding: '6px 8px', display: 'flex', alignItems: 'center', justifyContent: 'center', gap: '4px', background: '#1b2430' }}>
                          ✉️ Email Dispatch
                        </button>
                      </div>

                      {/* Per-sample report buttons */}
                      <div style={{ marginTop: '10px', display: 'flex', flexDirection: 'column', gap: '5px' }}>
                        {lot.samples.map(s => (
                          <button key={s.id}
                            onClick={() => handleGenerateSamplePDF(s.id)}
                            disabled={generatingSamplePdf === s.id}
                            style={{ ...S.btnSuccess, width: '100%', fontSize: '12px', padding: '5px 8px' }}>
                            {generatingSamplePdf === s.id ? 'Opening…' : `Open Report — ${s.sd_number || 'Sample #' + s.id} (${s.material_name || 'Sample'})`}
                          </button>
                        ))}
                      </div>
                    </div>
                  ))}
                </div>
              )}

              {/* ── EMAIL DISPATCH & PRE-SEND CONFIRMATION MODAL ── */}
              {emailModalOpen && (
                <div style={{
                  position: 'fixed', top: 0, left: 0, right: 0, bottom: 0,
                  background: 'rgba(0,0,0,0.5)', zIndex: 9999,
                  display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '20px'
                }}>
                  <div style={{
                    background: '#fff', borderRadius: '8px', maxWidth: '750px', width: '100%',
                    maxHeight: '90vh', overflowY: 'auto', padding: '24px', boxShadow: '0 10px 30px rgba(0,0,0,0.2)'
                  }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', borderBottom: '1px solid #eee', paddingBottom: '12px' }}>
                      <h3 style={{ margin: 0, fontSize: '18px', color: '#1b2430' }}>
                        ✉️ Official Email Dispatch — {activeEmailLot?.lot_name}
                      </h3>
                      <button onClick={() => setEmailModalOpen(false)} style={{ background: 'none', border: 'none', fontSize: '20px', cursor: 'pointer' }}>×</button>
                    </div>

                    {emailModalLoading ? (
                      <div style={{ padding: '30px', textAlign: 'center', color: '#666' }}>Preparing lot manifest, anomaly checks & subject formulation…</div>
                    ) : (
                      <div>
                        {/* Notice for unconfigured Google App password */}
                        {emailPreviewData && !emailPreviewData.credentials_configured && (
                          <div style={{ background: '#FFF8E1', border: '1px solid #FFE082', borderRadius: '6px', padding: '12px', marginBottom: '16px', fontSize: '12.5px', color: '#856404' }}>
                            <strong>⚠️ Mail Configuration Pending:</strong> Configure the sender address and SMTP credentials in a private server-side mail configuration file, then set <code>CTMS_MAIL_CONFIG_PATH</code>.
                            You can review the preview, subject, and anomaly checks now. See <strong>EMAIL_CONFIGURATION_GUIDE.md</strong> for setup guidance.
                          </div>
                        )}

                        {/* Anomaly Alerts */}
                        {emailPreviewData?.has_anomalies && (
                          <div style={{ background: '#FDECEA', border: '1px solid #F5C6CB', borderRadius: '6px', padding: '12px', marginBottom: '16px', fontSize: '12.5px', color: '#721C24' }}>
                            <strong>⚠️ Pre-Send Anomaly Detection Warning:</strong>
                            <ul style={{ margin: '6px 0 0 18px', padding: 0 }}>
                              {emailPreviewData.anomalies.map((anom, idx) => (
                                <li key={idx}>{anom}</li>
                              ))}
                            </ul>
                            <div style={{ marginTop: '6px', fontSize: '11.5px', color: '#491217' }}>
                              Please verify whether all tests have been properly submitted before dispatching to the client.
                            </div>
                          </div>
                        )}

                        {emailDispatchMsg && (
                          <div style={{ background: '#D4EDDA', color: '#155724', padding: '10px 14px', borderRadius: '6px', marginBottom: '14px', fontSize: '13px' }}>
                            ✓ {emailDispatchMsg}
                          </div>
                        )}
                        {emailDispatchErr && (
                          <div style={{ background: '#F8D7DA', color: '#721C24', padding: '10px 14px', borderRadius: '6px', marginBottom: '14px', fontSize: '13px' }}>
                            ✗ {emailDispatchErr}
                          </div>
                        )}

                        {/* Recipient Input */}
                        <div style={{ marginBottom: '14px' }}>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '5px' }}>
                            Recipient Email Address: <span style={{ color: 'red' }}>*</span>
                          </label>
                          <input
                            type="email"
                            value={emailRecipient}
                            onChange={(e) => setEmailRecipient(e.target.value)}
                            placeholder="e.g. billing@example.org"
                            style={{ ...S.fieldInput, width: '100%' }}
                          />
                        </div>

                        {/* Subject Input with Auto-Generated Formulation */}
                        <div style={{ marginBottom: '14px' }}>
                          <label style={{ display: 'block', fontSize: '12px', fontWeight: 'bold', marginBottom: '5px' }}>
                            Email Subject Line (Auto-Formulated):
                          </label>
                          <input
                            type="text"
                            value={emailCustomSubject}
                            onChange={(e) => setEmailCustomSubject(e.target.value)}
                            style={{ ...S.fieldInput, width: '100%', fontFamily: 'monospace', fontSize: '12px' }}
                          />
                          <div style={{ fontSize: '11px', color: '#666', marginTop: '4px' }}>
                            Includes: Lot No · Total Samples ({emailPreviewData?.total_samples}) · Delivery Date ({emailPreviewData?.delivery_date}) · SD/Ref Numbers · Request Numbers
                          </div>
                        </div>

                        {/* Attached Archive Indicator */}
                        <div style={{ background: '#f8f9fa', border: '1px solid #eee', borderRadius: '6px', padding: '10px 14px', marginBottom: '16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                          <div>
                            <span style={{ fontSize: '12px', fontWeight: 'bold', display: 'block' }}>📎 Attached Compressed Archive:</span>
                            <span style={{ fontSize: '11.5px', fontFamily: 'monospace', color: '#444' }}>
                              {activeEmailLot?.lot_name} - {emailPreviewData?.total_samples} Samples - Delivery {emailPreviewData?.delivery_date}.zip
                            </span>
                          </div>
                          <button
                            type="button"
                            onClick={() => handleDownloadLotZip(activeEmailLot.id)}
                            style={{ ...S.btnOutline, fontSize: '11px', padding: '4px 8px' }}>
                            Download Archive
                          </button>
                        </div>

                        {/* Pre-Send User Confirmation Checkbox */}
                        <div style={{ background: '#EEF2F6', border: '1px solid #CFD8DC', borderRadius: '6px', padding: '12px', marginBottom: '18px' }}>
                          <label style={{ display: 'flex', alignItems: 'flex-start', gap: '8px', cursor: 'pointer', fontSize: '13px', fontWeight: '600', color: '#1b2430' }}>
                            <input
                              type="checkbox"
                              checked={emailUserConfirmed}
                              onChange={(e) => setEmailUserConfirmed(e.target.checked)}
                              style={{ marginTop: '2px' }}
                            />
                            <span>
                              I have reviewed the sample breakdown, verified the recipient, checked for any anomalies, and confirm sending this official dispatch.
                            </span>
                          </label>
                        </div>

                        {/* Action buttons */}
                        <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '10px' }}>
                          <button
                            type="button"
                            onClick={() => setEmailModalOpen(false)}
                            style={{ ...S.btnOutline, padding: '8px 16px' }}>
                            Close
                          </button>
                          <button
                            type="button"
                            onClick={handleSendEmailDispatch}
                            disabled={emailDispatching || !emailUserConfirmed}
                            style={{
                              ...S.btnPrimary,
                              padding: '8px 20px',
                              background: emailUserConfirmed ? '#1b2430' : '#888',
                              cursor: emailUserConfirmed ? 'pointer' : 'not-allowed'
                            }}>
                            {emailDispatching ? 'Transmitting via Gmail SMTP…' : '🚀 Confirm & Send Dispatch'}
                          </button>
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}
              {archiveFormatModal && (
                <div style={{ position: 'fixed', inset: 0, zIndex: 10002, background: 'rgba(0,0,0,.5)', display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }}>
                  <div role="dialog" aria-modal="true" aria-labelledby="archive-format-title" style={{ background: '#fff', borderRadius: 8, width: 'min(460px, 100%)', padding: 22, boxShadow: '0 10px 30px rgba(0,0,0,.2)' }}>
                    <h3 id="archive-format-title" style={{ margin: '0 0 6px', color: '#1b2430' }}>Download report archive</h3>
                    <p style={{ margin: '0 0 16px', color: '#64748b', fontSize: 13 }}>{archiveFormatModal.lotName}</p>
                    <div style={{ display: 'grid', gap: 10, marginBottom: 20 }}>
                      {[
                        ['pdf', 'PDF files'],
                        ['word', 'Word files'],
                        ['both', 'PDF and Word files'],
                      ].map(([value, label]) => (
                        <label key={value} style={{ display: 'flex', alignItems: 'center', gap: 9, cursor: 'pointer', fontSize: 14 }}>
                          <input type="radio" name="archive-format" value={value} checked={archiveFormat === value} onChange={() => setArchiveFormat(value)} />
                          {label}
                        </label>
                      ))}
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                      <button type="button" style={S.btnOutline} disabled={archiveBusy} onClick={() => setArchiveFormatModal(null)}>Cancel</button>
                      <button type="button" style={S.btnPrimary} disabled={archiveBusy} onClick={buildSelectedArchive}>{archiveBusy ? 'Preparing archive…' : 'Download ZIP'}</button>
                    </div>
                  </div>
                </div>
              )}
              {reportViewerUrl && (
                <div style={{ position: 'fixed', inset: 0, zIndex: 10001, background: 'rgba(15,23,42,.68)', padding: '3vh 3vw', display: 'flex', flexDirection: 'column' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#1b2430', color: '#fff', padding: '8px 14px', borderRadius: '8px 8px 0 0' }}>
                    <strong>CTMS Report</strong>
                    <button type="button" onClick={() => setReportViewerUrl('')} style={{ border: 0, background: 'transparent', color: '#fff', fontSize: 24, cursor: 'pointer', lineHeight: 1 }}>×</button>
                  </div>
                  <iframe title="CTMS report viewer" src={reportViewerUrl} style={{ flex: 1, width: '100%', border: 0, background: '#fff', borderRadius: '0 0 8px 8px' }} />
                </div>
              )}
            </div>
          )}

          {/* ═══════════════ REPORT SEARCH (Explorer-style) ═══════════════ */}
          {activeTab === 'report_search' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>
                Search every generated report — electronics (EL-HF, EL-GS…), OES-T, X-T, tensile — by
                sample type, report no, lot no, SD / reference no, or client. Filter by year, or use the
                dispatch filter to keep the dispatch book.
              </p>
              <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', alignItems: 'center', marginBottom: '16px' }}>
                <input
                  type="text"
                  value={rsQuery}
                  onChange={e => setRsQuery(e.target.value)}
                  onKeyDown={e => { if (e.key === 'Enter') runReportSearch(); }}
                  placeholder="e.g. handfree · EL-HF · 245689 · LOT-2-18-9-2026 · OES-T"
                  style={{ ...S.fieldInput, flex: '1 1 280px', minWidth: '220px' }}
                />
                <select value={rsYear} onChange={e => setRsYear(e.target.value)} style={{ ...S.select, minWidth: '120px' }}>
                  <option value="">All years</option>
                  {rsYears.map(y => <option key={y} value={y}>{y}</option>)}
                </select>
                <select value={rsDispatch} onChange={e => { setRsDispatch(e.target.value); runReportSearch(rsQuery, rsYear, e.target.value); }} style={{ ...S.select, minWidth: '170px' }}>
                  <option value="all">All reports</option>
                  <option value="open">In laboratory (not dispatched)</option>
                  <option value="done">Dispatched</option>
                </select>
                <button onClick={() => runReportSearch()} disabled={rsLoading} style={S.btnPrimary}>
                  {rsLoading ? 'Searching…' : 'Search'}
                </button>
                <button onClick={() => { setRsQuery(''); setRsYear(''); setRsDispatch('all'); runReportSearch('', '', 'all'); }} style={S.btnSecondary}>
                  Show all
                </button>
                <button onClick={() => window.print()} style={S.btnSecondary} title="Print the current list as the dispatch book">🖨 Print list</button>
              </div>

              {rsLoading ? (
                <div style={S.loading}>Searching report registry…</div>
              ) : !rsSearched ? (
                <div style={S.empty}>
                  <HexMark size={44} />
                  <h3 style={S.emptyTitle}>Type anything to search</h3>
                  <p>Like Windows Explorer — one box matches report number, lot, sample type, SD/ref, and client.</p>
                </div>
              ) : rsResults.length === 0 ? (
                <div style={S.empty}>
                  <HexMark size={44} />
                  <h3 style={S.emptyTitle}>No matching reports</h3>
                  <p>Try a broader term, clear the year filter, or generate a report first (Phase 04).</p>
                </div>
              ) : (
                <>
                  <p style={{ fontSize: '12.5px', color: 'var(--text-muted)', marginBottom: '10px' }}>
                    {rsResults.length} result{rsResults.length === 1 ? '' : 's'}
                    {rsQuery.trim() ? <> for “<strong>{rsQuery.trim()}</strong>”</> : null}
                    {rsYear ? <> in <strong>{rsYear}</strong></> : null}
                  </p>
                  <div style={S.tableWrap}>
                    <table style={S.table}>
                      <thead>
                        <tr>
                          <th style={S.th}>Report No</th>
                          <th style={S.th}>Series</th>
                          <th style={S.th}>Sample Type</th>
                          <th style={S.th}>Lot No</th>
                          <th style={S.th}>SD / Ref</th>
                          <th style={S.th}>Client</th>
                          <th style={S.th}>Verify Code</th>
                          <th style={S.th}>Date</th>
                          <th style={S.th}>Dispatched</th>
                          <th style={S.th}></th>
                        </tr>
                      </thead>
                      <tbody>
                        {rsResults.map((r, i) => (
                          <tr key={r.id || i} style={i % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontWeight: 700 }}>
                              {r.report_number || '—'}
                            </td>
                            <td style={S.td}>{r.format_used || '—'}</td>
                            <td style={S.td}>{r.sample_type || '—'}</td>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{r.lot_number || '—'}</td>
                            <td style={S.td}>{r.identifier || '—'}</td>
                            <td style={S.td}>{r.client_name || '—'}</td>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{r.checksum_hash || '—'}</td>
                            <td style={S.td}>
                              {r.generated_at ? new Date(r.generated_at).toLocaleDateString() : '—'}
                            </td>
                            <td style={{ ...S.td, whiteSpace: 'nowrap' }}>
                              {r.dispatched_at ? (
                                <span style={{ color: 'var(--teal)', fontWeight: 700, fontSize: '11.5px' }} title={r.dispatch_notes || ''}>
                                  ✓ {new Date(r.dispatched_at).toLocaleDateString()}
                                </span>
                              ) : (
                                <span style={{ color: 'var(--text-muted)', fontSize: '11.5px' }}>In lab</span>
                              )}
                            </td>
                            <td style={S.td}>
                              <div style={{ display: 'flex', gap: '6px' }}>
                                {r.sample_id ? (
                                  <button onClick={() => openArchivedReport(r.sample_id)} style={S.btnOutlineSm}>
                                    Open
                                  </button>
                                ) : null}
                                {['admin', 'deo'].includes(role) && (
                                  <button onClick={() => handleDispatchToggle(r)} style={S.btnOutlineSm}>
                                    {r.dispatched_at ? 'Undo' : 'Dispatch'}
                                  </button>
                                )}
                              </div>
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              )}
              {reportViewerUrl && (
                <div style={{ position: 'fixed', inset: 0, zIndex: 10001, background: 'rgba(15,23,42,.68)', padding: '3vh 3vw', display: 'flex', flexDirection: 'column' }}>
                  <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', background: '#1b2430', color: '#fff', padding: '8px 14px', borderRadius: '8px 8px 0 0' }}>
                    <strong>CTMS Report</strong>
                    <button type="button" onClick={() => setReportViewerUrl('')} style={{ border: 0, background: 'transparent', color: '#fff', fontSize: 24, cursor: 'pointer', lineHeight: 1 }}>×</button>
                  </div>
                  <iframe title="CTMS report viewer" src={reportViewerUrl} style={{ flex: 1, width: '100%', border: 0, background: '#fff', borderRadius: '0 0 8px 8px' }} />
                </div>
              )}
            </div>
          )}

          {/* ═══════════════ OPS BOARD — TAT + CALIBRATION ═══════════════ */}
          {activeTab === 'ops' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>
                Turnaround tracker — lots with a promised delivery date, most urgent first.
                Equipment below: calibration due dates, red = overdue (audit-critical).
              </p>

              <div style={{ display: 'flex', gap: '10px', alignItems: 'center', marginBottom: '12px' }}>
                <label style={{ display: 'flex', alignItems: 'center', gap: '6px', fontSize: '12.5px', fontWeight: 600 }}>
                  <input type="checkbox" checked={tatOverdueOnly}
                    onChange={e => { setTatOverdueOnly(e.target.checked); loadOpsBoard(e.target.checked); }} />
                  Show overdue / due today only
                </label>
                {tatLoading && <span style={{ fontSize: '12px', color: 'var(--text-muted)' }}>Loading…</span>}
              </div>

              {tatLots.length === 0 && !tatLoading ? (
                <div style={{ ...S.empty, marginBottom: '18px' }}>
                  <h3 style={S.emptyTitle}>Nothing overdue</h3>
                  <p>Lots appear here once created with a "Delivery promised in (days)" value.</p>
                </div>
              ) : (
                <div style={S.tableWrap}>
                  <table style={S.table}>
                    <thead><tr>
                      <th style={S.th}>Lot No</th>
                      <th style={S.th}>Type</th>
                      <th style={S.th}>Lot Date</th>
                      <th style={S.th}>Promised</th>
                      <th style={S.th}>Progress</th>
                      <th style={S.th}>Status</th>
                    </tr></thead>
                    <tbody>
                      {tatLots.map((l, i) => {
                        const done = Number(l.done_samples), total = Number(l.total_samples);
                        const over = Number(l.days_over);
                        const complete = total > 0 && done >= total;
                        // A19: due-today is its own state, NOT a subset of overdue —
                        // "OVERDUE 0d" was misleading for a lot that isn't late yet.
                        const overdue  = !complete && over > 0;
                        const dueToday = !complete && over === 0;
                        return (
                          <tr key={l.id} style={i % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{l.lot_number}</td>
                            <td style={S.td}>{l.agency_type}</td>
                            <td style={S.td}>{l.lot_date ? new Date(l.lot_date).toLocaleDateString() : '—'}</td>
                            <td style={S.td}>{l.promised_date ? new Date(l.promised_date).toLocaleDateString() : '—'}</td>
                            <td style={S.td}>{done}/{total} samples</td>
                            <td style={{ ...S.td, fontWeight: 700, color: overdue ? 'var(--rust)' : dueToday ? '#8a6116' : 'var(--teal)' }}>
                              {complete ? '✓ Complete' : overdue ? `OVERDUE ${over}d` : dueToday ? 'Due today' : `On track (${over < 0 ? -over : over}d left)`}
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}

              <h4 style={{ ...S.sectionHeading, marginTop: '26px' }}>Equipment calibration register</h4>
              {equipMsg && <div style={{ ...S.msgBox, marginBottom: '10px' }}>{equipMsg}</div>}
              {equipForm && (
                <form onSubmit={saveEquipmentRow} style={{ display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '10px', marginBottom: '14px', alignItems: 'end' }}>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Name *
                    <input required value={equipForm.name || ''} onChange={e => setEquipForm({ ...equipForm, name: e.target.value })} style={S.fieldInput} placeholder="e.g. UTM-600kN" /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Type
                    <input value={equipForm.instrument_type || ''} onChange={e => setEquipForm({ ...equipForm, instrument_type: e.target.value })} style={S.fieldInput} placeholder="Tensile / XRF / OES" /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Serial no
                    <input value={equipForm.serial_no || ''} onChange={e => setEquipForm({ ...equipForm, serial_no: e.target.value })} style={S.fieldInput} /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Location
                    <input value={equipForm.location || ''} onChange={e => setEquipForm({ ...equipForm, location: e.target.value })} style={S.fieldInput} /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Last calibrated
                    <input type="date" value={equipForm.last_calibrated || ''} onChange={e => setEquipForm({ ...equipForm, last_calibrated: e.target.value })} style={S.fieldInput} /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Next due
                    <input type="date" value={equipForm.next_due || ''} onChange={e => setEquipForm({ ...equipForm, next_due: e.target.value })} style={S.fieldInput} /></label>
                  <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Certificate no
                    <input value={equipForm.cert_no || ''} onChange={e => setEquipForm({ ...equipForm, cert_no: e.target.value })} style={S.fieldInput} /></label>
                  <div style={{ display: 'flex', gap: '8px' }}>
                    <button type="submit" style={S.btnPrimary}>Save</button>
                    <button type="button" onClick={() => setEquipForm(null)} style={S.btnOutlineSm}>Cancel</button>
                  </div>
                </form>
              )}
              <div style={{ display: 'flex', gap: '10px', marginBottom: '10px' }}>
                <button onClick={() => setEquipForm({ name: '', instrument_type: '', serial_no: '', location: '', last_calibrated: '', next_due: '', cert_no: '', notes: '' })} style={S.btnPrimary}>
                  + Add equipment
                </button>
              </div>
              {equipLoading ? <div style={S.loading}>Loading…</div> : (
                <div style={S.tableWrap}>
                  <table style={S.table}>
                    <thead><tr>
                      <th style={S.th}>Equipment</th><th style={S.th}>Type</th><th style={S.th}>Serial</th>
                      <th style={S.th}>Last calib.</th><th style={S.th}>Next due</th><th style={S.th}>Cert no</th><th style={S.th}>Status</th><th style={S.th}></th>
                    </tr></thead>
                    <tbody>
                      {equipList.length === 0 && (
                        <tr><td style={S.td} colSpan={8}>No equipment registered yet.</td></tr>
                      )}
                      {equipList.map((eq, i) => {
                        const d = eq.days_to_due == null ? null : Number(eq.days_to_due);
                        const status = d === null ? '—' : d < 0 ? `OVERDUE ${-d}d` : d <= 30 ? `Due in ${d}d` : 'OK';
                        const color = d === null ? 'var(--text-muted)' : d < 0 ? 'var(--rust)' : d <= 30 ? '#8a6116' : 'var(--teal)';
                        return (
                          <tr key={eq.id} style={i % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, fontWeight: 700 }}>{eq.name}</td>
                            <td style={S.td}>{eq.instrument_type || '—'}</td>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{eq.serial_no || '—'}</td>
                            <td style={S.td}>{eq.last_calibrated ? new Date(eq.last_calibrated).toLocaleDateString() : '—'}</td>
                            <td style={S.td}>{eq.next_due ? new Date(eq.next_due).toLocaleDateString() : '—'}</td>
                            <td style={S.td}>{eq.cert_no || '—'}</td>
                            <td style={{ ...S.td, fontWeight: 700, color }}>{status}</td>
                            <td style={S.td}>
                              <div style={{ display: 'flex', gap: '6px' }}>
                                <button onClick={() => setEquipForm({ ...eq })} style={S.btnTinyOutline}>Edit</button>
                                <button onClick={() => { if (window.confirm(`Remove ${eq.name}?`)) deleteEquipment(eq.id).then(loadOpsBoard); }} style={S.btnTinyDanger}>✕</button>
                              </div>
                            </td>
                          </tr>
                        );
                      })}
                    </tbody>
                  </table>
                </div>
              )}
            </div>
          )}

          {/* ═══════════════ Example Client PAYMENTS — receipts pool + ageing ═══════════════ */}
          {activeTab === 'finance' && (
            <div style={S.panel}>
              {isLoadingFin && (
                <div style={{ ...S.msgBox, ...S.msgWarn, marginBottom: '12px' }}>Refreshing finance data…</div>
              )}
              <p style={S.panelDesc}>
                Example Client pays the university a <strong>lump sum</strong> (e.g. Rs. 338,000 for samples at Rs. 5,200 each).
                Record the receipt here, then allocate its coverage to lots — the pool tracks how many samples are paid for.
              </p>

              {poolMsg && <div style={{ ...S.msgBox, ...(S.msgTone(poolMsg)) }}>{poolMsg}</div>}

              <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '20px', marginBottom: '20px' }}>
                {/* Add receipt */}
                <form onSubmit={handleAddReceipt} style={{ display: 'flex', flexDirection: 'column', gap: '10px' }}>
                  <h4 style={S.formTitle}>Record lump-sum receipt</h4>
                  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '10px' }}>
                    <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Payer
                      <input value={receiptForm.payer} onChange={e => setReceiptForm({ ...receiptForm, payer: e.target.value })} style={S.fieldInput} /></label>
                    <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Amount (Rs.) *
                      <input required type="number" min="1" value={receiptForm.amount} onChange={e => setReceiptForm({ ...receiptForm, amount: e.target.value })} style={S.fieldInput} placeholder="338000" /></label>
                    <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Rate per sample (Rs.)
                      <input type="number" min="0" value={receiptForm.rate_per_sample} onChange={e => setReceiptForm({ ...receiptForm, rate_per_sample: e.target.value })} style={S.fieldInput} placeholder="5200" /></label>
                    <label style={{ fontSize: '11.5px', fontWeight: 600 }}>Receipt date
                      <input type="date" value={receiptForm.receipt_date} onChange={e => setReceiptForm({ ...receiptForm, receipt_date: e.target.value })} style={S.fieldInput} /></label>
                    <label style={{ fontSize: '11.5px', fontWeight: 600, gridColumn: '1 / -1' }}>Reference (bank slip / screenshot ref.)
                      <input value={receiptForm.reference} onChange={e => setReceiptForm({ ...receiptForm, reference: e.target.value })} style={S.fieldInput} /></label>
                  </div>
                  <button type="submit" disabled={finBusy} style={{ ...S.btnPrimary, opacity: finBusy ? 0.6 : 1 }}>{finBusy ? 'Saving…' : 'Save receipt'}</button>
                </form>

                {/* Receipts list */}
                <div>
                  <h4 style={S.formTitle}>Receipts pool</h4>
                  <div style={S.tableWrap}>
                    <table style={S.table}>
                      <thead><tr>
                        <th style={S.th}>Date</th><th style={S.th}>Payer</th><th style={S.th}>Amount</th>
                        <th style={S.th}>Covers</th><th style={S.th}>Used</th><th style={S.th}>Free</th>
                      </tr></thead>
                      <tbody>
                        {receipts.length === 0 && <tr><td style={S.td} colSpan={6}>No receipts yet.</td></tr>}
                        {receipts.map((r, i) => {
                          const used = Number(r.samples_used || 0);
                          const cov = Number(r.samples_covered || 0);
                          return (
                            <tr key={r.id} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={S.td}>{r.receipt_date ? new Date(r.receipt_date).toLocaleDateString() : '—'}</td>
                              <td style={S.td}>{r.payer}</td>
                              <td style={{ ...S.td, fontWeight: 700 }}>Rs. {parseFloat(r.amount).toLocaleString()}</td>
                              <td style={S.td}>{cov}</td>
                              <td style={S.td}>{used}</td>
                              <td style={{ ...S.td, fontWeight: 700, color: cov - used > 0 ? 'var(--teal)' : 'var(--text-muted)' }}>{cov - used}</td>
                            </tr>
                          );
                        })}
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <h4 style={S.sectionHeading}>Unallocated Example Client lots — pick a receipt to cover</h4>
              <div style={S.tableWrap}>
                <table style={S.table}>
                  <thead><tr>
                    <th style={S.th}>Lot No</th><th style={S.th}>Created</th><th style={S.th}>Samples</th><th style={S.th}>Cover with receipt</th>
                  </tr></thead>
                  <tbody>
                    {pendingPoolLots.length === 0 && <tr><td style={S.td} colSpan={4}>All Example Client lots are allocated, or none exist yet.</td></tr>}
                    {pendingPoolLots.map(l => (
                      <tr key={l.id}>
                        <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{l.lot_number}</td>
                        <td style={S.td}>{new Date(l.created_at).toLocaleDateString()}</td>
                        <td style={S.td}>{l.samples}</td>
                        <td style={S.td}>
                          <select value="" onChange={e => e.target.value && handleAllocateLot(Number(e.target.value), l.id)} style={{ ...S.select, minWidth: '240px' }}>
                            <option value="">Choose receipt…</option>
                            {receipts.filter(r => (Number(r.samples_covered) - Number(r.samples_used)) >= Number(l.samples))
                              .map(r => (
                                <option key={r.id} value={r.id}>
                                  {r.payer} — Rs. {parseFloat(r.amount).toLocaleString()} ({Number(r.samples_covered) - Number(r.samples_used)} free)
                                </option>
                              ))}
                          </select>
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <h4 style={{ ...S.sectionHeading, marginTop: '24px' }}>Invoice ageing — outstanding per client</h4>
              <div style={S.tableWrap}>
                <table style={S.table}>
                  <thead><tr>
                    <th style={S.th}>Client</th><th style={S.th}>Open inv.</th><th style={S.th}>Total due</th>
                    <th style={S.th}>0–30 d</th><th style={S.th}>30–60 d</th><th style={S.th}>60–90 d</th><th style={S.th}>90+ d</th><th style={S.th}>Oldest</th>
                  </tr></thead>
                  <tbody>
                    {ageingRows.length === 0 && <tr><td style={S.td} colSpan={8}>Nothing outstanding. 🎉</td></tr>}
                    {ageingRows.map((c, i) => (
                      <tr key={c.company} style={i % 2 ? S.rowAlt : undefined}>
                        <td style={{ ...S.td, fontWeight: 700 }}>{c.company}</td>
                        <td style={S.td}>{c.invoices}</td>
                        <td style={{ ...S.td, fontWeight: 700, color: 'var(--rust)' }}>Rs. {c.total.toLocaleString()}</td>
                        <td style={S.td}>{c.b0 ? `Rs. ${c.b0.toLocaleString()}` : '—'}</td>
                        <td style={S.td}>{c.b30 ? `Rs. ${c.b30.toLocaleString()}` : '—'}</td>
                        <td style={{ ...S.td, color: c.b60 ? 'var(--rust)' : undefined }}>{c.b60 ? `Rs. ${c.b60.toLocaleString()}` : '—'}</td>
                        <td style={{ ...S.td, color: c.b90 ? 'var(--rust)' : undefined, fontWeight: c.b90 ? 700 : 400 }}>{c.b90 ? `Rs. ${c.b90.toLocaleString()}` : '—'}</td>
                        <td style={S.td}>{c.oldest != null ? `${c.oldest} days` : '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}

          {/* ═══════════════ HOD / EXECUTIVE DASHBOARD ═══════════════ */}
          {activeTab === 'hod_view' && (
            <div style={S.panel}>
              <div style={S.toolbar}>
                <p style={S.panelDesc}>Real-time throughput metrics, pulled directly from the database.</p>
                <button onClick={fetchHodAnalytics} style={S.btnOutlineSm}>Refresh</button>
              </div>

              {/* Lot Trail Search */}
              <div style={S.trailSearchBox}>
                <h4 style={S.trailTitle}>Lot audit trail</h4>
                <div style={{ display: 'flex', gap: '12px', alignItems: 'center', flexWrap: 'wrap' }}>
                  <select onChange={async (e) => {
                    const lotName = e.target.value;
                    if (!lotName) { setTrailData(null); return; }
                    try {
                      const { fetchAllLots } = await import('./api');
                      const res = await fetchAllLots();
                      // A18: an error object must not look like "no trail"
                      const list = Array.isArray(res.data) ? res.data : [];
                      const lot = list.find(l => l.lot_number === lotName);
                      if (lot) setTrailData(lot);
                      else setTrailData(null);
                    } catch { setTrailData(null); }
                  }}
                    style={{ ...S.select, minWidth: '280px' }}>
                    <option value="">Select a lot to view its trail…</option>
                    {(analytics?.recent_lots || []).map(l => (
                      <option key={l.lot_number} value={l.lot_number}>
                        {l.lot_number} · {l.total_samples} samples · {l.agency_type}
                      </option>
                    ))}
                  </select>
                  <span style={S.trailHint}>Every status change for the selected lot, with timestamps</span>
                </div>

                {trailData && (
                  <div style={S.trailPanel}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '12px' }}>
                      <span style={S.lotName}>{trailData.lot_number}</span>
                      <button onClick={() => setTrailData(null)} style={S.btnOutlineSm}>Close</button>
                    </div>
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead>
                          <tr>
                            <th style={S.th}>#</th>
                            <th style={S.th}>SD No / Ref</th>
                            <th style={S.th}>Description</th>
                            <th style={S.th}>Current Status</th>
                            <th style={S.th}>Last Updated</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(trailData.samples || []).map((s, i) => (
                            <tr key={s.id} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={{ ...S.td, fontWeight: '700' }}>{i + 1}</td>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>
                                {s.sd_number || (s.extra_data || {})['SD No'] || s.id}
                              </td>
                              <td style={S.td}>{s.client_name || (s.extra_data || {})['Material Description'] || '—'}</td>
                              <td style={S.td}><span style={stampStyle(s.status)}>{s.status}</span></td>
                              <td style={{ ...S.td, color: 'var(--text-soft)', fontSize: '11.5px' }}>
                                {s.updated_at || s.created_at || '—'}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  </div>
                )}
              </div>

              {/* KPI cards + recent activity */}
              {isLoadingHOD ? (
                <div style={S.loading}>Loading live analytics…</div>
              ) : analytics ? (
                <>
                  <div style={S.kpiGrid}>
                    {[
                      { label: 'Lots Today',      value: analytics.lots_today },
                      { label: 'Pending Mapping', value: analytics.pending_mapping },
                      { label: 'In Lab Testing',  value: analytics.in_testing },
                      { label: 'Awaiting Report',  value: analytics.awaiting_report },
                    ].map((kpi, i) => (
                      <div key={i} style={S.kpiCard}>
                        <div style={S.kpiLabel}>{kpi.label}</div>
                        <div style={S.kpiValue}>{kpi.value ?? '—'}</div>
                      </div>
                    ))}
                  </div>

                  <h4 style={S.sectionHeading}>Recent lot activity</h4>
                  <div style={S.tableWrap}>
                    <table style={S.table}>
                      <thead>
                        <tr>
                          <th style={S.th}>Lot Number</th>
                          <th style={S.th}>Type</th>
                          <th style={S.th}>Total</th>
                          <th style={S.th}>Pending</th>
                          <th style={S.th}>In Testing</th>
                          <th style={S.th}>Completed</th>
                        </tr>
                      </thead>
                      <tbody>
                        {(analytics.recent_lots || []).map((l, i) => (
                          <tr key={i} style={i % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, fontWeight: '700', fontFamily: "'IBM Plex Mono', monospace" }}>{l.lot_number}</td>
                            <td style={S.td}>{l.agency_type}</td>
                            <td style={{ ...S.td, textAlign: 'center' }}>{l.total_samples}</td>
                            <td style={{ ...S.td, textAlign: 'center', color: 'var(--amber)' }}>{l.pending}</td>
                            <td style={{ ...S.td, textAlign: 'center', color: 'var(--teal)' }}>{l.in_testing}</td>
                            <td style={{ ...S.td, textAlign: 'center', color: 'var(--copper)', fontWeight: '700' }}>{l.completed}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                </>
              ) : (
                <div style={S.empty}>Could not load analytics. Check the XAMPP connection.</div>
              )}
            </div>
          )}

          {/* ═══════════════ FINANCIAL RECORDS (Admin only) ═══════════════ */}
          {activeTab === 'cheques' && role === 'admin' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>Visible to admin only. Enter cheque data, edit entries, and print the submission sheet.</p>

              {notifications.length > 0 && (
                <div style={S.notifyBox}>
                  <strong style={{ color: 'var(--copper-dim)' }}>{notifications.length} pending password reset request(s)</strong>
                  {notifications.map(n => (
                    <div key={n.id} style={{ ...S.notifyRow, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                      <span>
                        {n.message}
                        <span style={{ color: 'var(--text-soft)', marginLeft: '8px', fontSize: '11px' }}>{new Date(n.created_at).toLocaleString()}</span>
                      </span>
                      <button
                        onClick={async () => {
                          // A2: only remove from the queue when the server
                          // confirms — a failed call must not delete a
                          // password-reset request the admin hasn't handled.
                          try {
                            await markNotificationRead(n.id);
                            setNotifications(prev => prev.filter(x => x.id !== n.id));
                          } catch {
                            alert('Could not mark as read — the notification stays in the queue.');
                          }
                        }}
                        style={{ marginLeft: '12px', padding: '3px 10px', fontSize: '11px', background: 'var(--teal)', color: '#fff', border: 'none', borderRadius: '4px', cursor: 'pointer', fontWeight: '700', whiteSpace: 'nowrap' }}
                        title="Mark as handled — removes from this list">
                        ✓ Done
                      </button>
                    </div>
                  ))}
                </div>
              )}

              {chequeMsg && (
                <div style={{ ...S.msgBox, ...(S.msgTone(chequeMsg)) }}>
                  {chequeMsg}
                </div>
              )}

              <div style={S.toolbar}>
                <div style={{ display: 'flex', borderRadius: '8px', overflow: 'hidden', border: '1px solid var(--border)' }}>
                  {[['hmbl', 'Habib Metro'], ['other', 'Other Banks']].map(([t, label]) => (
                    <button key={t} onClick={() => {
                      // A14: a typed but unsaved grid must not vanish silently
                      const hasTyped = chequeGrid.some(r => r.some(c => c.trim() !== ''));
                      if (hasTyped && !window.confirm('Switching tabs clears the entries you have typed. Continue?')) return;
                      setChequeTab(t); setChequeGrid(createEmptyChequeGrid(30)); setChequeGridSrNos(Array(30).fill(null).map((_,i)=>String(i+1)));
                    }} style={chequeTab === t ? S.tabBtnActive : S.tabBtn}>
                      {label}
                    </button>
                  ))}
                </div>
                <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                  <input type="date" value={chequeDate} onChange={e => setChequeDate(e.target.value)} style={S.select} />
                  <button onClick={loadCheques} style={S.btnOutlineSm}>Refresh</button>
                  {/* Full-page grid for fast bulk entry — same UX as lot creation */}
                  <button onClick={() => {
                    // A14: confirm before wiping typed single-entry data
                    const hasTyped = Object.values(chequeForm).some(v => String(v).trim() !== '');
                    if (hasTyped && !window.confirm('Opening bulk entry clears the single-entry form. Continue?')) return;
                    setChequeGrid(createEmptyChequeGrid(30)); setIsFullScreenCheque(true); setChequeMsg('');
                  }} style={S.btnPrimary}>
                    + Bulk entry (full page)
                  </button>
                  {cheques.length > 0 && (
                    <button onClick={handlePrintCheques} style={S.btnSuccess}>Download submission sheet</button>
                  )}
                </div>
              </div>

              <div style={S.chequeLayout}>
                {/* Entry Form — large, comfortable inputs */}
                <form onSubmit={handleSaveCheque} style={S.chequeForm}>
                  <h4 style={S.formTitle}>{editingCheque ? 'Edit entry' : 'Add cheque'}</h4>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Cheque / PO number</label>
                    <input id="chq-cheque_no" required type="text" placeholder="e.g., 18624571"
                      value={chequeForm.cheque_no} onChange={e => setChequeForm({ ...chequeForm, cheque_no: e.target.value })}
                      onKeyDown={e => handleFormEnterNav(e, 'chq-cheque_date')}
                      style={S.fieldInput} />
                  </div>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Cheque date <span style={{ fontWeight: 400, textTransform: 'none', color: 'var(--text-soft)' }}>(type 22-4-26, any style)</span></label>
                    {/* Smart date — type it any way you write it (22-4-26,
                        22/04/2026, 22 Apr 26 …); it normalizes on Tab/Enter.
                        Falls back to a date picker via the calendar icon. */}
                    <input id="chq-cheque_date" required type="text" inputMode="numeric"
                      autoComplete="off" placeholder="22-4-26"
                      value={chequeForm.cheque_date}
                      onChange={e => setChequeForm({ ...chequeForm, cheque_date: e.target.value })}
                      onBlur={e => {
                        // A15: never fail silently — say why the date wasn't accepted
                        const v = e.target.value.trim();
                        const p = smartDate(v);
                        if (p) { setChequeForm(f => ({ ...f, cheque_date: p })); setChequeDateWarn(''); }
                        else if (v) setChequeDateWarn(`"${v}" was not recognised as a date — use 22-4-26 style, or the calendar below. It will be saved as the sheet date.`);
                      }}
                      onKeyDown={e => {
                        if (e.key === 'Enter' || e.key === 'Tab') {
                          const p = smartDate(e.target.value);
                          if (p) setChequeForm(f => ({ ...f, cheque_date: p }));
                        }
                        if (e.key === 'Enter') handleFormEnterNav(e, chequeTab === 'other' ? 'chq-bank_name' : 'chq-amount');
                      }}
                      onFocus={e => e.target.select()}
                      style={S.fieldInput} />
                    <input type="date" style={{ ...S.fieldInput, marginTop: '6px', color: 'var(--text-muted)' }}
                      value={/^\d{4}-\d{2}-\d{2}$/.test(chequeForm.cheque_date) ? chequeForm.cheque_date : ''}
                      onChange={e => setChequeForm(f => ({ ...f, cheque_date: e.target.value }))}
                      title="Or pick from calendar" />
                    {chequeDateWarn && (
                      <div style={{ fontSize: '11.5px', color: '#8a6116', background: 'var(--amber-light)', border: '1px solid #ecd9ac', borderRadius: '6px', padding: '6px 10px', marginTop: '6px' }}>
                        ⚠ {chequeDateWarn}
                      </div>
                    )}
                  </div>

                  {chequeTab === 'other' && (
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Bank name <span style={{ fontWeight: 400, textTransform: 'none', color: 'var(--text-soft)' }}>(type a letter to see list; last used stays filled)</span></label>
                      {/* Suggestions from master_banks + every bank ever used;
                         after the first cheque the name stays filled so a run
                         of same-bank cheques needs zero retyping. */}
                      <input id="chq-bank_name" required type="text" placeholder="e.g., MEEZAN BANK" list="bank-suggestions"
                        autoComplete="off"
                        value={chequeForm.bank_name} onChange={e => setChequeForm({ ...chequeForm, bank_name: e.target.value.toUpperCase() })}
                        onKeyDown={e => handleFormEnterNav(e, 'chq-amount')}
                        style={S.fieldInput} />
                      <datalist id="bank-suggestions">
                        {bankSuggestions.map(b => <option key={b} value={b} />)}
                      </datalist>
                    </div>
                  )}

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Amount (Rs.)</label>
                    <input id="chq-amount" required type="number" min="1" placeholder="e.g., 10000"
                      value={chequeForm.amount} onChange={e => setChequeForm({ ...chequeForm, amount: e.target.value })}
                      onKeyDown={e => handleFormEnterNav(e, 'chq-remarks')}
                      style={S.fieldInput} />
                  </div>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>
                      Sheet number <span style={{ fontWeight: '400', textTransform: 'none', color: 'var(--text-soft)' }}>(auto)</span>
                    </label>
                    <div style={S.autoSheetBox} title="Assigned automatically — 40 entries per printed sheet, matching the submission sheet template. Entry 41 starts Sheet 2, entry 81 starts Sheet 3, and so on.">
                      Sheet {Math.ceil((cheques.length + 1) / 40)} · entry #{cheques.length + 1} of this sheet's 40
                    </div>
                  </div>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Remarks</label>
                    <input id="chq-remarks" type="text" placeholder="Optional note"
                      value={chequeForm.remarks} onChange={e => setChequeForm({ ...chequeForm, remarks: e.target.value })}
                      onKeyDown={e => handleFormEnterNav(e, null)}
                      style={S.fieldInput} />
                  </div>

                  <div style={{ display: 'flex', gap: '10px', marginTop: '6px' }}>
                    <button type="submit" style={S.btnPrimary}>{editingCheque ? 'Update' : 'Add entry'}</button>
                    {editingCheque && (
                      <button type="button" style={S.btnSecondary}
                        onClick={() => { setEditingCheque(null); setChequeForm({ cheque_no: '', cheque_date: '', bank_name: '', amount: '', remarks: '' }); }}>
                        Cancel
                      </button>
                    )}
                  </div>
                </form>

                {/* Cheque Table */}
                <div>
                  {isLoadingChq ? <div style={S.loading}>Loading…</div> : (
                    <>
                      {cheques.length > 0 && (
                        <div style={S.totalsRow}>
                          <span>
                            <strong>{cheques.length}</strong> entries · Total{' '}
                            <strong style={{ color: 'var(--teal)' }}>Rs. {cheques.reduce((s, c) => s + parseFloat(c.amount || 0), 0).toLocaleString()}</strong>
                          </span>
                          <span style={S.totalsWords}>{numberToWords(cheques.reduce((s, c) => s + parseFloat(c.amount || 0), 0))}</span>
                        </div>
                      )}
                      <div style={S.tableWrap}>
                        <table style={S.table}>
                          <thead>
                            <tr>
                              <th style={S.th}>S.#</th>
                              <th style={S.th}>Cheque No.</th>
                              <th style={S.th}>Date</th>
                              <th style={S.th}>Bank</th>
                              <th style={S.th}>Amount (Rs.)</th>
                              <th style={S.th}>Sheet</th>
                              <th style={S.th}>Remarks</th>
                              <th style={S.th}>Actions</th>
                            </tr>
                          </thead>
                          <tbody>
                            {cheques.length === 0 ? (
                              <tr><td colSpan="8" style={S.empty}>No entries for this date. Add your first cheque.</td></tr>
                            ) : cheques.map((c, i) => (
                              <tr key={c.id} style={i % 2 ? S.rowAlt : undefined}>
                                <td style={{ ...S.td, fontWeight: '700' }}>{c.serial_no}</td>
                                <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{c.cheque_no}</td>
                                <td style={S.td}>{c.cheque_date}</td>
                                <td style={{ ...S.td, fontSize: '12px' }}>{c.bank_name}</td>
                                <td style={{ ...S.td, fontWeight: '700', color: 'var(--teal)' }}>{parseFloat(c.amount).toLocaleString()}</td>
                                <td style={{ ...S.td, textAlign: 'center' }}>{c.sheet_no}</td>
                                <td style={{ ...S.td, fontSize: '12px', color: 'var(--text-muted)' }}>{c.remarks || '—'}</td>
                                <td style={S.td}>
                                  <div style={{ display: 'flex', gap: '6px' }}>
                                    <button onClick={() => { setEditingCheque(c.id); setChequeForm({ cheque_no: c.cheque_no, cheque_date: c.cheque_date, bank_name: c.bank_name, amount: c.amount, remarks: c.remarks || '' }); }}
                                      style={S.btnTinyOutline}>Edit</button>
                                    <button onClick={() => handleDeleteCheque(c.id)} style={S.btnTinyDanger}>Delete</button>
                                  </div>
                                </td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* ═══════════════ USER MANAGEMENT (Admin only) ═══════════════ */}
          {activeTab === 'users' && role === 'admin' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>
                All changes are saved directly to the database. Set a unique temporary password for each new account; the user must change it at first sign-in.
              </p>

              {workflowCanConfigure && (
                <div style={{ ...S.trailSearchBox, marginBottom: 18 }}>
                  <h3 style={S.trailTitle}>Report approval design</h3>
                  <p style={{ ...S.trailHint, margin: '0 0 12px' }}>Configure the CTMS report flow. Technical Reviewer approval issues the report; there is no fixed HOD approval step.</p>
                  <label style={{ display: 'flex', alignItems: 'center', gap: 8, fontSize: 12.5, fontWeight: 700, marginBottom: 12 }}>
                    <input type="checkbox" checked={!!workflowSettings.require_technical_review}
                      onChange={e => setWorkflowSettings({ ...workflowSettings, require_technical_review: e.target.checked })} />
                    Require Technical Review before reports are issued
                  </label>
                  <label style={{ ...S.fieldLabel, textTransform: 'none' }}>Assigned Technical Reviewers</label>
                  <select multiple size={Math.min(5, Math.max(2, workflowReviewers.length))}
                    value={selectedReviewerIds.map(String)}
                    onChange={e => setSelectedReviewerIds(Array.from(e.target.selectedOptions, o => Number(o.value)))}
                    style={{ ...S.fieldInput, maxWidth: 520, minHeight: 92 }}>
                    {workflowReviewers.map(r => <option key={r.id} value={r.id}>{r.full_name} · {r.employee_id}</option>)}
                  </select>
                  <div style={{ ...S.trailHint, marginTop: 5 }}>First assign the separate Technical Reviewer permission to a user below, then select them here. Use Ctrl-click to select multiple reviewers.</div>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginTop: 12 }}>
                    <button type="button" onClick={handleSaveReportWorkflow} disabled={workflowSaving} style={S.btnPrimary}>{workflowSaving ? 'Saving…' : 'Save approval workflow'}</button>
                    {workflowError && <span style={{ color: workflowError.startsWith('✅') ? 'var(--teal)' : 'var(--rust)', fontSize: 12 }}>{workflowError}</span>}
                  </div>
                </div>
              )}

              {userMsg && (
                <div style={{ ...S.msgBox, ...(S.msgTone(userMsg)) }}>{userMsg}</div>
              )}

              <div style={S.usersLayout}>
                <form onSubmit={handleSaveUser} style={S.chequeForm}>
                  <h4 style={S.formTitle}>{isEditingUser ? 'Edit user' : 'Add new user'}</h4>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Full name</label>
                    <input id="usr-full_name" type="text" required placeholder="e.g., Technical Reviewer"
                      value={userForm.full_name} onChange={e => setUserForm({ ...userForm, full_name: e.target.value })}
                      onKeyDown={e => handleFormEnterNav(e, 'usr-employee_id')}
                      style={S.fieldInput} />
                  </div>

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Email address</label>
                    <input id="usr-employee_id" type="text" required placeholder="e.g., Reviewer.khan@Example University.edu.pk"
                      value={userForm.employee_id} onChange={e => setUserForm({ ...userForm, employee_id: e.target.value })}
                      onKeyDown={e => handleFormEnterNav(e, 'usr-role')}
                      style={S.fieldInput} />
                  </div>

                  {!isEditingUser && (
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Temporary password</label>
                      <input id="usr-password" type="password" required minLength={8} maxLength={72}
                        autoComplete="new-password" placeholder="Set a unique temporary password"
                        value={userForm.password} onChange={e => setUserForm({ ...userForm, password: e.target.value })}
                        onKeyDown={e => handleFormEnterNav(e, 'usr-role')}
                        style={S.fieldInput} />
                    </div>
                  )}

                  <div style={S.field}>
                    <label style={S.fieldLabel}>Role</label>
                    <select id="usr-role" value={userForm.role} onChange={e => setUserForm({ ...userForm, role: e.target.value, ...(e.target.value === 'superadmin' ? { lot_access_restricted: false } : {}) })} style={S.fieldInput}>
                      <option value="superadmin">System Administrator</option>
                      <option value="hod">Head of Department</option>
                      <option value="reception">Reception Officer</option>
                      <option value="mapping">Mapping Officer</option>
                      <option value="technician">Lab Technician</option>
                      <option value="deo">Data Entry Operator</option>
                    </select>
                  </div>

                  {userForm.role === 'technician' && (
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Lab stream</label>
                      <select value={userForm.lab_stream} onChange={e => setUserForm({ ...userForm, lab_stream: e.target.value })} style={S.fieldInput}>
                        <option value="">Select lab…</option>
                        <option value="Tensile Lab (Zeeshan Hassan)">Tensile Lab</option>
                        <option value="XRF Lab (Khurram Jahangeer)">XRF Chemical Lab</option>
                        <option value="OES Lab (Jahangeer)">OES Spectroscopy Lab</option>
                        <option value="Electronics Lab (Lab Technician)">Electronics Lab</option>
                      </select>
                    </div>
                  )}

                  {workflowCanConfigure && (
                    <div style={{ ...S.field, padding: 12, border: '1px solid var(--border)', borderRadius: 7 }}>
                      <label style={{ ...S.fieldLabel, display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none' }}>
                        <input type="checkbox" checked={!!userForm.can_technical_review}
                          onChange={e => setUserForm({ ...userForm, can_technical_review: e.target.checked })} />
                        Technical Reviewer permission
                      </label>
                      <div style={{ fontSize: 11, color: 'var(--text-soft)', marginLeft: 22 }}>
                        Adds report-review authority without changing the person’s laboratory or CTMS role.
                      </div>
                    </div>
                  )}

                  <div style={{ ...S.field, padding: 12, border: '1px solid var(--border)', borderRadius: 7 }}>
                    <label style={{ ...S.fieldLabel, display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none' }}>
                      <input type="checkbox" checked={!!userForm.consultancy_access}
                        onChange={e => setUserForm({ ...userForm, consultancy_access: e.target.checked })} />
                      Allow access to Consultancy
                    </label>
                    <div style={{ fontSize: 11, color: 'var(--text-soft)', margin: '4px 0 8px 22px' }}>
                      This permission is separate from the main CTMS role.
                    </div>
                    <label style={S.fieldLabel}>Consultancy role</label>
                    <select value={userForm.consultancy_role || 'viewer'} disabled={!userForm.consultancy_access}
                      onChange={e => setUserForm({ ...userForm, consultancy_role: e.target.value })} style={S.fieldInput}>
                      <option value="viewer">Viewer</option>
                      <option value="reception">Reception</option>
                      <option value="technician">Technician</option>
                      <option value="professor">Professor / Approver</option>
                      <option value="admin">Administrator</option>
                      <option value="superadmin">Super Administrator</option>
                    </select>
                    <label style={{ ...S.fieldLabel, display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none', marginTop: 10 }}>
                      <input type="checkbox" disabled={!userForm.consultancy_access || !isSuperadmin || userForm.role === 'superadmin' || userForm.consultancy_role === 'superadmin'} checked={!!userForm.consultancy_can_create_invoice || userForm.role === 'superadmin' || userForm.consultancy_role === 'superadmin'}
                        onChange={e => setUserForm({ ...userForm, consultancy_can_create_invoice: e.target.checked })} />
                      Can create Consultancy invoices
                    </label>
                    <div style={{ fontSize: 11, color: 'var(--text-soft)', marginLeft: 22 }}>
                      Only a Super Administrator can assign this. Super Administrators always keep it, so they can hand it to someone else while the PA is away. Everyone else — including Consultancy administrators — can still view invoice details for testing and reports.
                    </div>
                  </div>

                  {(workflowCanConfigure || isSuperadmin) && (
                    <div style={{ ...S.field, padding: 12, border: '1px solid var(--border)', borderRadius: 7 }}>
                      <label style={{ ...S.fieldLabel, display: 'flex', alignItems: 'center', gap: 8, textTransform: 'none' }}>
                        <input type="checkbox" disabled={userForm.role === 'superadmin'} checked={!!userForm.lot_access_restricted}
                          onChange={e => setUserForm({ ...userForm, lot_access_restricted: e.target.checked })} />
                        Restrict this account to assigned lots
                      </label>
                      <div style={{ fontSize: 11, color: 'var(--text-soft)', margin: '4px 0 8px 22px' }}>
                        The person’s existing CTMS role still controls actions. Lot permission only narrows which lots they can view, edit, or manage.
                      </div>
                      {userForm.lot_access_restricted && userForm.role !== 'superadmin' && <>
                        <input value={lotAccessQuery} onChange={e => setLotAccessQuery(e.target.value)} placeholder="Filter lots…" style={{ ...S.fieldInput, marginBottom: 7 }} />
                        <div style={{ maxHeight: 230, overflow: 'auto', border: '1px solid var(--border)', borderRadius: 5, background: '#fff' }}>
                          {lotAccessLots.filter(l => `${l.lot_number} ${l.agency_type} ${l.lot_date}`.toLowerCase().includes(lotAccessQuery.toLowerCase())).map(l => {
                            const key = String(l.id); const level = lotAccessLevels[key] || '';
                            return <div key={key} style={{ display: 'grid', gridTemplateColumns: 'minmax(0,1fr) 120px', gap: 8, alignItems: 'center', padding: '7px 9px', borderBottom: '1px solid var(--border)' }}>
                              <label style={{ display: 'flex', alignItems: 'center', gap: 7, minWidth: 0, fontSize: 11.5 }}>
                                <input type="checkbox" checked={!!level} onChange={e => setLotAccessLevels(prev => { const next = { ...prev }; if (e.target.checked) next[key] = 'view'; else delete next[key]; return next; })} />
                                <span style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{l.lot_number} · {l.agency_type}</span>
                              </label>
                              <select aria-label={`Permission for lot ${l.lot_number}`} value={level || 'view'} disabled={!level}
                                onChange={e => setLotAccessLevels(prev => ({ ...prev, [key]: e.target.value }))} style={{ ...S.fieldInput, padding: '5px', fontSize: 11 }}>
                                <option value="view">View</option><option value="edit">Edit lab data</option><option value="manage">Manage lot</option>
                              </select>
                            </div>;
                          })}
                          {lotAccessLots.length === 0 && <div style={{ padding: 10, fontSize: 11 }}>No active lots are available.</div>}
                        </div>
                        <div style={{ fontSize: 10.5, color: 'var(--text-soft)', marginTop: 5 }}>{Object.keys(lotAccessLevels).length} lot(s) selected. Selecting none blocks all lot access.</div>
                      </>}
                      {lotAccessLoading && <div style={{ fontSize: 11, marginTop: 5 }}>Loading the selected user’s lot permissions…</div>}
                    </div>
                  )}

                  <div style={{ display: 'flex', gap: '10px', marginTop: '6px' }}>
                    <button type="submit" disabled={lotAccessLoading} style={S.btnPrimary}>{isEditingUser ? 'Save changes' : 'Create account'}</button>
                    {isEditingUser && (
                      <button type="button" style={S.btnSecondary}
                        onClick={() => { setIsEditingUser(false); setUserForm({ id: null, full_name: '', employee_id: '', password: '', role: 'technician', lab_stream: '', consultancy_access: false, consultancy_role: 'viewer', consultancy_can_create_invoice: false, can_technical_review: false, lot_access_restricted: false }); setLotAccessLevels({}); }}>
                        Cancel
                      </button>
                    )}
                  </div>
                </form>

                <div>
                  {isLoadingUsers ? (
                    <div style={S.loading}>Loading users from database…</div>
                  ) : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead>
                          <tr>
                            <th style={S.th}>Name &amp; Login</th>
                            <th style={S.th}>Role</th>
                            <th style={S.th}>Report Review</th>
                            <th style={S.th}>Lot Access</th>
                            <th style={S.th}>Consultancy</th>
                            <th style={S.th}>Status</th>
                            <th style={S.th}>Actions</th>
                          </tr>
                        </thead>
                        <tbody>
                          {systemUsers.map((u, i) => (
                            <tr key={u.id} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={S.td}>
                                <div style={{ fontWeight: '700' }}>{u.full_name}</div>
                                <div style={{ fontSize: '11px', color: 'var(--text-soft)', fontFamily: "'IBM Plex Mono', monospace" }}>{u.employee_id}</div>
                              </td>
                              <td style={S.td}>
                                <span style={S.roleBadge}>{(u.role || '').toUpperCase()}</span>
                              </td>
                              <td style={S.td}><span style={{ color: Number(u.can_technical_review) ? 'var(--teal)' : 'var(--text-soft)', fontSize: 11, fontWeight: 700 }}>{Number(u.can_technical_review) ? 'TECHNICAL REVIEWER' : '—'}</span></td>
                              <td style={S.td}><span style={{ color: Number(u.lot_access_restricted) ? 'var(--amber)' : 'var(--teal)', fontSize: 11, fontWeight: 700 }}>{Number(u.lot_access_restricted) ? `Restricted · ${Number(u.assigned_lot_count || 0)} lot(s)` : 'All role-permitted lots'}</span></td>
                              <td style={S.td}>
                                <span style={{ color: Number(u.consultancy_access) ? 'var(--teal)' : 'var(--rust)', fontWeight: '700', fontSize: '12px' }}>
                                  {Number(u.consultancy_access) ? `${u.consultancy_role || 'viewer'}${Number(u.consultancy_can_create_invoice) || u.role === 'superadmin' || u.consultancy_role === 'superadmin' ? ' · Invoice creation' : ' · Testing/reports only'}` : 'Blocked'}
                                </span>
                              </td>
                              <td style={S.td}>
                                <span style={{ color: u.is_active ? 'var(--teal)' : 'var(--rust)', fontWeight: '700', fontSize: '12px' }}>
                                  {u.is_active ? 'Active' : 'Inactive'}
                                </span>
                              </td>
                              <td style={S.td}>
                                <div style={{ display: 'flex', gap: '6px', flexWrap: 'wrap' }}>
                                  <button onClick={() => handleEditUser(u)}
                                    style={S.btnTinyOutline}>Edit</button>
                                  <button onClick={() => handleResetPassword(u)} style={S.btnTinyOutline}>Reset pass</button>
                                  {isSuperadmin && Number(u.id) !== Number(userId) && <button onClick={() => handleResetMfa(u)} style={S.btnTinyOutline} title="Clear this user's authenticator enrollment">Reset MFA</button>}
                                  <button onClick={() => handleDeleteUser(u)} style={S.btnTinyDanger}>Delete</button>
                                </div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              </div>
            </div>
          )}

          {/* ═══════ INVOICES & QUOTATIONS (Admin) ═══════ */}
          {activeTab === 'invoices' && role === 'admin' && (
            <div style={S.panel}>

              {/* Sub-tab bar */}
              <div style={{ display:'flex', gap:'0', borderRadius:'8px', overflow:'hidden', border:'1px solid var(--border)', marginBottom:'16px', width:'fit-content' }}>
                {[['invoices','Invoices'],['quotations','Quotations'],['clients','Clients'],['prices','Price List'],['analytics','Analytics']].map(([t,label])=>(
                  <button key={t} onClick={()=>{ setInvSubTab(t); }} style={invSubTab===t ? S.tabBtnActive : S.tabBtn}>{label}</button>
                ))}
              </div>

              {invMsg && <div style={{ ...S.msgBox, ...(S.msgTone(invMsg)) }}>{invMsg}</div>}

              {/* ── INVOICES sub-tab ── */}
              {invSubTab === 'invoices' && (
                <div>
                  <div style={S.toolbar}>
                    <p style={S.panelDesc}>All issued invoices. Mark as paid when bank slip received.</p>
                    <button onClick={()=>{ setShowInvForm(true); setShowQuoForm(false); setInvForm(EMPTY_FORM); setClientHistory(null); }} style={S.btnPrimary}>+ New Invoice</button>
                  </div>

                  {/* Create invoice form */}
                  {showInvForm && (
                    <form onSubmit={handleCreateInvoice} style={{ ...S.trailSearchBox, marginBottom:'20px' }}>
                      <h4 style={S.trailTitle}>New Invoice</h4>

                      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'12px', marginBottom:'12px' }}>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Company name *</label>
                          <input required type="text" placeholder="e.g. Example Client, ABC Industries"
                            value={invForm.company_name}
                            onChange={e=>{ setInvForm(f=>({...f,company_name:e.target.value})); handleClientSearch(e.target.value); }}
                            style={S.fieldInput} />
                          {/* Auto-suggest from history */}
                          {clientHistory?.analytics?.total_invoices > 0 && (
                            <div style={{ fontSize:'11px', color:'var(--teal)', marginTop:'4px' }}>
                              Returning client — {clientHistory.analytics.total_invoices} previous invoice(s), Rs. {parseFloat(clientHistory.analytics.total_paid||0).toLocaleString()} total paid
                            </div>
                          )}
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Contact person</label>
                          <input type="text" placeholder="Engineer name / representative"
                            value={invForm.contact_person} onChange={e=>setInvForm(f=>({...f,contact_person:e.target.value}))} style={S.fieldInput} />
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Linked lot (optional)</label>
                          <select value={invForm.lot_id} onChange={e=>setInvForm(f=>({...f,lot_id:e.target.value}))} style={S.fieldInput}>
                            <option value="">— not linked to a lot —</option>
                            {allLots.map(l=><option key={l.id||l.lot_name} value={l.id||''}>{l.lot_name||l.lot_number}</option>)}
                          </select>
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Due date</label>
                          <input type="date" value={invForm.due_date} onChange={e=>setInvForm(f=>({...f,due_date:e.target.value}))} style={S.fieldInput} />
                        </div>
                      </div>

                      {/* Line items */}
                      <label style={{ ...S.fieldLabel, marginBottom:'8px', display:'block' }}>Services / Test items</label>
                      {invForm.items.map((item,i)=>(
                        <div key={i} style={{ display:'grid', gridTemplateColumns:'160px 1fr 80px 100px 100px 28px', gap:'8px', marginBottom:'8px', alignItems:'end' }}>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Test type</label>
                            <select value={item.service_code} onChange={e=>suggestPrice(i,e.target.value)} style={S.fieldInput}>
                              <option value="">Custom…</option>
                              {priceMaster.map(p=><option key={p.service_code} value={p.service_code}>{p.service_name}</option>)}
                            </select>
                          </div>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Description *</label>
                            <input required type="text" value={item.description} onChange={e=>updateInvItem(i,'description',e.target.value)} style={S.fieldInput} placeholder="e.g. Tensile Test — 3 samples rebar" />
                          </div>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Qty</label>
                            <input type="number" min="1" value={item.quantity} onChange={e=>updateInvItem(i,'quantity',e.target.value)} style={S.fieldInput} />
                          </div>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Unit price (Rs.)</label>
                            <input type="number" min="0" value={item.unit_price} onChange={e=>updateInvItem(i,'unit_price',e.target.value)} style={S.fieldInput} />
                          </div>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>HOD concession</label>
                            <input type="number" min="0" value={item.override_price} onChange={e=>updateInvItem(i,'override_price',e.target.value)} style={{ ...S.fieldInput, color:'var(--rust)' }} placeholder="Override Rs." />
                          </div>
                          <button type="button" onClick={()=>removeInvItem(i)} style={{ ...S.btnTinyDanger, alignSelf:'flex-end', height:'38px' }}>✕</button>
                        </div>
                      ))}
                      <button type="button" onClick={addInvItem} style={{ ...S.btnOutlineSm, marginBottom:'16px' }}>+ Add line</button>

                      {/* Discount + preview */}
                      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'12px', marginBottom:'16px' }}>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Invoice-level discount (Rs.)</label>
                          <input type="number" min="0" value={invForm.discount_amount}
                            onChange={e=>setInvForm(f=>({...f,discount_amount:e.target.value}))} style={S.fieldInput} />
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Discount reason</label>
                          <input type="text" value={invForm.discount_reason}
                            onChange={e=>setInvForm(f=>({...f,discount_reason:e.target.value}))} style={S.fieldInput}
                            placeholder="HOD discretion / Client reference / Contract…" />
                        </div>
                      </div>

                      {/* Live totals preview */}
                      {(()=>{ const t=calcPreview(); return (
                        <div style={{ background:'var(--paper)', border:'1px solid var(--border)', borderRadius:'8px', padding:'14px 18px', marginBottom:'16px', fontSize:'13px' }}>
                          <div style={{ display:'flex', justifyContent:'space-between', marginBottom:'4px' }}><span>Subtotal</span><strong>Rs. {t.sub.toLocaleString()}</strong></div>
                          {t.disc>0 && <div style={{ display:'flex', justifyContent:'space-between', marginBottom:'4px', color:'var(--rust)' }}><span>Discount</span><strong>− Rs. {t.disc.toLocaleString()}</strong></div>}
                          <div style={{ display:'flex', justifyContent:'space-between', marginBottom:'4px', color:'var(--text-muted)' }}><span>Sindh Service Tax ({t.rate}%)</span><strong>Rs. {t.tax.toLocaleString()}</strong></div>
                          <div style={{ display:'flex', justifyContent:'space-between', borderTop:'2px solid var(--border)', paddingTop:'8px', fontSize:'15px' }}><strong>Grand Total</strong><strong style={{ color:'var(--teal)' }}>Rs. {t.grand.toLocaleString()}</strong></div>
                        </div>
                      ); })()}

                      <div style={{ display:'flex', gap:'10px' }}>
                        <button type="submit" disabled={finBusy} style={{ ...S.btnSuccess, opacity: finBusy ? 0.6 : 1 }}>{finBusy ? 'Creating…' : 'Create Invoice'}</button>
                        <button type="button" onClick={()=>setShowInvForm(false)} style={S.btnSecondary}>Cancel</button>
                      </div>
                    </form>
                  )}

                  {/* Pay modal */}
                  {payingInvoice && (
                    <form onSubmit={handleMarkPaid} style={{ ...S.trailSearchBox, border:'1px solid var(--teal)', background:'var(--teal-light)', marginBottom:'16px' }}>
                      <h4 style={{ ...S.trailTitle, color:'var(--teal)' }}>Mark {payingInvoice.inv_number} as Paid</h4>
                      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr 1fr', gap:'12px' }}>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Payment method</label>
                          <select value={payForm.payment_method} onChange={e=>setPayForm(f=>({...f,payment_method:e.target.value}))} style={S.fieldInput}>
                            <option value="bank_voucher">Bank Voucher</option>
                            <option value="online_transfer">Online Transfer</option>
                            <option value="other">Other</option>
                          </select>
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Reference / Voucher No. *</label>
                          <input type="text" required value={payForm.payment_reference} onChange={e=>setPayForm(f=>({...f,payment_reference:e.target.value}))} style={S.fieldInput} placeholder="e.g. Bank slip #18624571" />
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Payment date *</label>
                          <input type="date" required value={payForm.payment_date} onChange={e=>setPayForm(f=>({...f,payment_date:e.target.value}))} style={S.fieldInput} />
                        </div>
                      </div>
                      <div style={S.field}>
                        <label style={S.fieldLabel}>Notes</label>
                        <input type="text" value={payForm.payment_notes} onChange={e=>setPayForm(f=>({...f,payment_notes:e.target.value}))} style={S.fieldInput} placeholder="Optional — e.g. slip verified by Zia" />
                      </div>
                      <div style={{ display:'flex', gap:'10px', marginTop:'8px' }}>
                        <button type="submit" disabled={finBusy} style={{ ...S.btnSuccess, opacity: finBusy ? 0.6 : 1 }}>{finBusy ? 'Confirming…' : 'Confirm Payment'}</button>
                        <button type="button" onClick={()=>setPayingInvoice(null)} style={S.btnSecondary}>Cancel</button>
                      </div>
                    </form>
                  )}

                  {/* Invoice list */}
                  {isLoadingInv ? <div style={S.loading}>Loading…</div> : invoices.length===0 ? (
                    <div style={S.empty}><HexMark size={44}/><p style={{marginTop:'14px'}}>No invoices yet. Create your first one above.</p></div>
                  ) : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead><tr>
                          <th style={S.th}>Invoice No.</th>
                          <th style={S.th}>Company</th>
                          <th style={S.th}>Date</th>
                          <th style={S.th}>Grand Total</th>
                          <th style={S.th}>Status</th>
                          <th style={S.th}>Actions</th>
                        </tr></thead>
                        <tbody>
                          {invoices.map((inv,i)=>(
                            <tr key={inv.id} style={i%2?S.rowAlt:undefined}>
                              <td style={{ ...S.td, fontFamily:"'IBM Plex Mono',monospace", fontWeight:'700' }}>{inv.inv_number}</td>
                              <td style={S.td}>
                                <div style={{fontWeight:'600'}}>{inv.company_name}</div>
                                {inv.contact_person && <div style={{fontSize:'11px',color:'var(--text-muted)'}}>{inv.contact_person}</div>}
                              </td>
                              <td style={{ ...S.td, fontSize:'12px' }}>{new Date(inv.created_at).toLocaleDateString()}</td>
                              <td style={{ ...S.td, fontWeight:'700', color:'var(--teal)' }}>Rs. {parseFloat(inv.grand_total||0).toLocaleString()}</td>
                              <td style={S.td}>
                                <span style={{
                                  padding:'3px 10px', borderRadius:'4px', fontSize:'11px', fontWeight:'700',
                                  background: inv.status==='paid'?'var(--teal-light)':inv.status==='cancelled'?'var(--rust-light)':'var(--amber-light)',
                                  color: inv.status==='paid'?'var(--teal)':inv.status==='cancelled'?'var(--rust)':'var(--copper-dim)',
                                }}>
                                  {inv.status?.toUpperCase()}
                                </span>
                              </td>
                              <td style={S.td}>
                                <div style={{display:'flex',gap:'6px',flexWrap:'wrap'}}>
                                  <button onClick={()=>handlePrintInvoice(inv)} style={{ ...S.btnTinyOutline, color:'#1b2a4a', borderColor:'#1b2a4a' }}>
                                    Print
                                  </button>
                                  <button onClick={()=>setExpandedInv(expandedInv===inv.id?null:inv.id)} style={S.btnTinyOutline}>
                                    {expandedInv===inv.id?'Hide':'Details'}
                                  </button>
                                  {inv.status!=='paid' && inv.status!=='cancelled' && (
                                    <button onClick={()=>{ setPayingInvoice(inv); setPayForm({payment_method:'bank_voucher',payment_reference:'',payment_date:localYmd(),payment_notes:''}); }} style={{ ...S.btnTinyOutline, color:'var(--teal)', borderColor:'var(--teal)' }}>
                                      Mark Paid
                                    </button>
                                  )}
                                  {inv.status!=='paid' && (
                                    <button onClick={()=>{ if(window.confirm('Cancel this invoice?')) { updateInvoiceStatus({id:inv.id,status:'cancelled'}).then(loadInvoiceTab); }}} style={S.btnTinyDanger}>
                                      Cancel
                                    </button>
                                  )}
                                </div>
                                {expandedInv===inv.id && (
                                  <div style={{marginTop:'10px', background:'var(--paper)', borderRadius:'6px', padding:'10px', fontSize:'12px'}}>
                                    {(inv.items||[]).map((item,j)=>(
                                      <div key={j} style={{display:'flex',justifyContent:'space-between',padding:'3px 0',borderBottom:'1px solid var(--border)'}}>
                                        <span>{item.description} × {item.quantity}</span>
                                        <span style={{fontWeight:'700'}}>Rs. {parseFloat(item.line_total||0).toLocaleString()}</span>
                                      </div>
                                    ))}
                                    <div style={{display:'flex',justifyContent:'space-between',paddingTop:'6px',color:'var(--text-muted)'}}>
                                      <span>Service Tax ({inv.service_tax_rate}%)</span>
                                      <span>Rs. {parseFloat(inv.service_tax_amount||0).toLocaleString()}</span>
                                    </div>
                                    {parseFloat(inv.discount_amount||0)>0 && (
                                      <div style={{display:'flex',justifyContent:'space-between',color:'var(--rust)'}}>
                                        <span>Discount ({inv.discount_reason||'HOD discretion'})</span>
                                        <span>− Rs. {parseFloat(inv.discount_amount).toLocaleString()}</span>
                                      </div>
                                    )}
                                    {inv.payment_reference && <div style={{marginTop:'6px',color:'var(--teal)'}}>Payment ref: {inv.payment_reference} ({inv.payment_method}) on {inv.payment_date}</div>}
                                    {inv.payment_notes && <div style={{color:'var(--text-muted)'}}>{inv.payment_notes}</div>}
                                  </div>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              {/* ── QUOTATIONS sub-tab ── */}
              {invSubTab === 'quotations' && (
                <div>
                  <div style={S.toolbar}>
                    <p style={S.panelDesc}>Quotations sent to clients. Accept/reject, or convert to invoice.</p>
                    <button onClick={()=>{ setShowQuoForm(true); setShowInvForm(false); setInvForm(EMPTY_FORM); setClientHistory(null); }} style={S.btnPrimary}>+ New Quotation</button>
                  </div>

                  {showQuoForm && (
                    <form onSubmit={handleCreateQuotation} style={{ ...S.trailSearchBox, marginBottom:'20px' }}>
                      <h4 style={S.trailTitle}>New Quotation</h4>
                      <div style={{ display:'grid', gridTemplateColumns:'1fr 1fr', gap:'12px', marginBottom:'12px' }}>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Company name *</label>
                          <input required type="text" value={invForm.company_name}
                            onChange={e=>{ setInvForm(f=>({...f,company_name:e.target.value})); handleClientSearch(e.target.value); }}
                            style={S.fieldInput} />
                        </div>
                        <div style={S.field}>
                          <label style={S.fieldLabel}>Valid until</label>
                          <input type="date" value={invForm.due_date} onChange={e=>setInvForm(f=>({...f,due_date:e.target.value}))} style={S.fieldInput} />
                        </div>
                      </div>
                      {invForm.items.map((item,i)=>(
                        <div key={i} style={{ display:'grid', gridTemplateColumns:'160px 1fr 80px 100px 28px', gap:'8px', marginBottom:'8px', alignItems:'end' }}>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Test type</label>
                            <select value={item.service_code} onChange={e=>suggestPrice(i,e.target.value)} style={S.fieldInput}>
                              <option value="">Custom…</option>
                              {priceMaster.map(p=><option key={p.service_code} value={p.service_code}>{p.service_name}</option>)}
                            </select>
                          </div>
                          <div>
                            <label style={{ ...S.fieldLabel, fontSize:'10px' }}>Description *</label>
                            <input required type="text" value={item.description} onChange={e=>updateInvItem(i,'description',e.target.value)} style={S.fieldInput} />
                          </div>
                          <div><label style={{ ...S.fieldLabel, fontSize:'10px' }}>Qty</label><input type="number" min="1" value={item.quantity} onChange={e=>updateInvItem(i,'quantity',e.target.value)} style={S.fieldInput}/></div>
                          <div><label style={{ ...S.fieldLabel, fontSize:'10px' }}>Price (Rs.)</label><input type="number" min="0" value={item.unit_price} onChange={e=>updateInvItem(i,'unit_price',e.target.value)} style={S.fieldInput}/></div>
                          <button type="button" onClick={()=>removeInvItem(i)} style={{ ...S.btnTinyDanger, alignSelf:'flex-end', height:'38px' }}>✕</button>
                        </div>
                      ))}
                      <button type="button" onClick={addInvItem} style={{ ...S.btnOutlineSm, marginBottom:'12px' }}>+ Add line</button>
                      {(()=>{ const t=calcPreview(); return (
                        <div style={{ background:'var(--paper)', border:'1px solid var(--border)', borderRadius:'8px', padding:'12px 16px', marginBottom:'12px', fontSize:'13px' }}>
                          <div style={{display:'flex',justifyContent:'space-between'}}><span>Subtotal</span><strong>Rs. {t.sub.toLocaleString()}</strong></div>
                          <div style={{display:'flex',justifyContent:'space-between',color:'var(--text-muted)'}}><span>Service Tax ({t.rate}%)</span><strong>Rs. {t.tax.toLocaleString()}</strong></div>
                          <div style={{display:'flex',justifyContent:'space-between',borderTop:'2px solid var(--border)',paddingTop:'8px'}}><strong>Grand Total</strong><strong style={{color:'var(--teal)'}}>Rs. {t.grand.toLocaleString()}</strong></div>
                        </div>
                      ); })()}
                      <div style={{display:'flex',gap:'10px'}}>
                        <button type="submit" disabled={finBusy} style={{ ...S.btnPrimary, opacity: finBusy ? 0.6 : 1 }}>{finBusy ? 'Creating…' : 'Create Quotation'}</button>
                        <button type="button" onClick={()=>setShowQuoForm(false)} style={S.btnSecondary}>Cancel</button>
                      </div>
                    </form>
                  )}

                  {isLoadingInv ? <div style={S.loading}>Loading…</div> : quotations.length===0 ? (
                    <div style={S.empty}><HexMark size={44}/><p style={{marginTop:'14px'}}>No quotations yet.</p></div>
                  ) : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead><tr>
                          <th style={S.th}>Quo. No.</th>
                          <th style={S.th}>Company</th>
                          <th style={S.th}>Grand Total</th>
                          <th style={S.th}>Valid Until</th>
                          <th style={S.th}>Status</th>
                          <th style={S.th}>Actions</th>
                        </tr></thead>
                        <tbody>
                          {quotations.map((q,i)=>(
                            <tr key={q.id} style={i%2?S.rowAlt:undefined}>
                              <td style={{ ...S.td, fontFamily:"'IBM Plex Mono',monospace", fontWeight:'700' }}>{q.quo_number}</td>
                              <td style={S.td}>{q.company_name}</td>
                              <td style={{ ...S.td, fontWeight:'700', color:'var(--teal)' }}>Rs. {parseFloat(q.grand_total||0).toLocaleString()}</td>
                              <td style={{ ...S.td, fontSize:'12px' }}>{q.valid_until||'—'}</td>
                              <td style={S.td}><span style={{padding:'3px 10px',borderRadius:'4px',fontSize:'11px',fontWeight:'700',background:'var(--amber-light)',color:'var(--copper-dim)'}}>{q.status?.toUpperCase()}</span></td>
                              <td style={S.td}>
                                <div style={{display:'flex',gap:'6px'}}>
                                  {q.status==='draft'&&<button onClick={()=>updateQuotationStatus({id:q.id,status:'sent'}).then(loadInvoiceTab)} style={S.btnTinyOutline}>Mark Sent</button>}
                                  {q.status==='sent'&&<button onClick={()=>updateQuotationStatus({id:q.id,status:'accepted'}).then(loadInvoiceTab)} style={S.btnTinyOutline}>Accepted</button>}
                                  {q.status==='accepted'&&q.status!=='converted'&&<button onClick={()=>handleConvertToInvoice(q)} style={{ ...S.btnTinyOutline, color:'var(--teal)', borderColor:'var(--teal)' }}>→ Invoice</button>}
                                  <button onClick={()=>deleteQuotation(q.id).then(loadInvoiceTab)} style={S.btnTinyDanger}>Delete</button>
                                </div>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              {/* ── CLIENTS sub-tab ── */}
              {invSubTab === 'clients' && (
                <div>
                  <p style={S.panelDesc}>All companies that have received invoices. Example Client is exempt from invoicing.</p>
                  {isLoadingInv ? <div style={S.loading}>Loading…</div> : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead><tr>
                          <th style={S.th}>Company</th>
                          <th style={S.th}>Contact</th>
                          <th style={S.th}>Type</th>
                          <th style={S.th}>Invoices</th>
                          <th style={S.th}>Phone</th>
                        </tr></thead>
                        <tbody>
                          {clientList.map((c,i)=>(
                            <tr key={c.id} style={i%2?S.rowAlt:undefined}>
                              <td style={{ ...S.td, fontWeight:'700' }}>{c.company_name}</td>
                              <td style={S.td}>{c.contact_person||'—'}</td>
                              <td style={S.td}>{c.is_special_client?<span style={{color:'var(--copper)',fontWeight:'700'}}>Example Client (exempt)</span>:'Regular'}</td>
                              <td style={S.td}>—</td>
                              <td style={S.td}>{c.phone||'—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

              {/* ── PRICE LIST sub-tab ── */}
              {invSubTab === 'prices' && (
                <div>
                  <p style={S.panelDesc}>Standard test rates. Update prices here — all future invoices auto-suggest these rates. Existing invoices are unaffected.</p>
                  <p style={{ ...S.panelDesc, color:'var(--amber)', fontWeight:'600' }}>⚠ Price changes require HOD approval — log that approval separately in the audit trail.</p>
                  {isLoadingInv ? <div style={S.loading}>Loading…</div> : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead><tr>
                          <th style={S.th}>Service</th>
                          <th style={S.th}>Category</th>
                          <th style={S.th}>Unit</th>
                          <th style={S.th}>Rate (Rs.)</th>
                          <th style={S.th}>Save</th>
                        </tr></thead>
                        <tbody>
                          {priceMaster.map((p,i)=>(
                            <tr key={p.id} style={i%2?S.rowAlt:undefined}>
                              <td style={{ ...S.td, fontWeight:'700' }}>{p.service_name}</td>
                              <td style={{ ...S.td, textTransform:'uppercase', fontSize:'11px' }}>{p.category}</td>
                              <td style={{ ...S.td, fontSize:'11px' }}>{p.unit?.replace('_',' ')}</td>
                              <td style={S.td}>
                                <input type="number" min="0" defaultValue={p.unit_price}
                                  id={`price_${p.id}`}
                                  style={{ ...S.fieldInput, width:'130px', fontFamily:"'IBM Plex Mono',monospace", fontWeight:'700' }} />
                              </td>
                              <td style={S.td}>
                                <button onClick={()=>{
                                  const val = document.getElementById(`price_${p.id}`)?.value;
                                  updatePriceItem({id:p.id,unit_price:val}).then(()=>{ setInvMsg('✅ Price updated.'); loadInvoiceTab(); });
                                }} style={S.btnTinyOutline}>Save</button>
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                  <div style={{ marginTop:'20px', ...S.trailSearchBox }}>
                    <h4 style={S.trailTitle}>Tax & system settings</h4>
                    <div style={{display:'grid',gridTemplateColumns:'1fr 1fr',gap:'12px'}}>
                      {Object.entries(sysSettings).filter(([k])=>['service_tax_rate','bank_account_title','bank_account_no','bank_name'].includes(k)).map(([k,v])=>(
                        <div key={k} style={S.field}>
                          <label style={S.fieldLabel}>{k.replace(/_/g,' ')}</label>
                          <div style={{display:'flex',gap:'8px'}}>
                            <input type="text" defaultValue={v} id={`setting_${k}`} style={S.fieldInput} />
                            <button onClick={()=>{
                              const val = document.getElementById(`setting_${k}`)?.value;
                              updateSetting({setting_key:k,setting_value:val}).then(()=>setInvMsg('✅ Setting saved.'));
                            }} style={S.btnTinyOutline}>Save</button>
                          </div>
                        </div>
                      ))}
                    </div>
                  </div>
                </div>
              )}

              {/* ── ANALYTICS sub-tab ── */}
              {invSubTab === 'analytics' && (
                <div>
                  <p style={S.panelDesc}>Business intelligence — which companies bring the most work and how much revenue they generate.</p>
                  {isLoadingInv ? <div style={S.loading}>Loading…</div> : clientAnalytics.length===0 ? (
                    <div style={S.empty}><p>No invoice data yet. Create invoices to build analytics.</p></div>
                  ) : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead><tr>
                          <th style={S.th}>Company</th>
                          <th style={S.th}>Invoices</th>
                          <th style={S.th}>Total Paid (Rs.)</th>
                          <th style={S.th}>Outstanding (Rs.)</th>
                          <th style={S.th}>Lots</th>
                          <th style={S.th}>Samples</th>
                          <th style={S.th}>Last Visit</th>
                        </tr></thead>
                        <tbody>
                          {clientAnalytics.map((c,i)=>(
                            <tr key={c.id} style={i%2?S.rowAlt:undefined}>
                              <td style={{ ...S.td, fontWeight:'700' }}>{c.company_name}</td>
                              <td style={{ ...S.td, textAlign:'center' }}>{c.total_invoices}</td>
                              <td style={{ ...S.td, fontWeight:'700', color:'var(--teal)' }}>Rs. {parseFloat(c.total_paid||0).toLocaleString()}</td>
                              <td style={{ ...S.td, color: parseFloat(c.total_outstanding||0)>0?'var(--rust)':'var(--text)' }}>Rs. {parseFloat(c.total_outstanding||0).toLocaleString()}</td>
                              <td style={{ ...S.td, textAlign:'center' }}>{c.total_lots||0}</td>
                              <td style={{ ...S.td, textAlign:'center' }}>{c.total_samples||0}</td>
                              <td style={{ ...S.td, fontSize:'11.5px', color:'var(--text-muted)' }}>{c.last_invoice_date ? new Date(c.last_invoice_date).toLocaleDateString() : '—'}</td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              )}

            </div>
          )}

          {/* ═══════════════ MY LOTS (Admin) ═══════════════ */}
          {activeTab === 'my_lots' && role === 'admin' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>All lots you have created. Expand a lot to view its sample data. Archive a lot once it has been dispatched to the client.</p>

              {archiveMsg && <div style={{ ...S.msgBox, ...(S.msgTone(archiveMsg)) }}>{archiveMsg}</div>}

              {isLoadingMyLots ? <div style={S.loading}>Loading lots…</div> : myLots.length === 0 ? (
                <div style={S.empty}><HexMark size={44} /><p style={{ marginTop: '14px' }}>No lots created yet.</p></div>
              ) : myLots.map(lot => (
                <div key={lot.id} style={S.lotBox}>
                  <div style={S.lotBoxHeader}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                      <span style={S.lotName}>{lot.lot_number}</span>
                      <span style={S.lotTypeBadge}>{lot.agency_type}</span>
                      <span style={S.countBadge}>{lot.total_samples} samples</span>
                      {lot.created_by_name && <span style={{ fontSize: '11px', color: 'var(--text-muted)' }}>by {lot.created_by_name}</span>}
                    </div>
                    <div style={{ display: 'flex', gap: '8px', alignItems: 'center' }}>
                      <button onClick={() => handlePrintLotExcel({ ...lot, lot_name: lot.lot_number })} style={S.btnOutlineSm}>
                        Download Excel
                      </button>
                      <button onClick={() => setExpandedLot(expandedLot === lot.id ? null : lot.id)} style={S.btnOutlineSm}>
                        {expandedLot === lot.id ? 'Collapse' : 'View samples'}
                      </button>
                      <button onClick={() => { if (editingLotId === lot.id) { setEditingLotId(null); } else { startEditSamples(lot); setExpandedLot(lot.id); } }}
                        style={{ ...S.btnOutlineSm, color: editingLotId === lot.id ? 'var(--rust)' : 'var(--teal)', borderColor: editingLotId === lot.id ? 'var(--rust)' : undefined }}>
                        {editingLotId === lot.id ? 'Cancel edit' : '✎ Edit samples'}
                      </button>
                      <button onClick={() => handleArchiveLot(lot)} style={{ ...S.btnOutlineSm, color: 'var(--copper)', borderColor: 'var(--copper)' }}>
                        Archive
                      </button>
                    </div>
                  </div>
                  {editingLotId === lot.id && editMsg && (
                    <div style={{ ...S.msgBox, ...(S.msgTone(editMsg)), margin: '10px 16px 0' }}>{editMsg}</div>
                  )}

                  {expandedLot === lot.id && (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead>
                          <tr>
                            <th style={S.th}>S. No</th>
                            <th style={S.th}>SD No</th>
                            <th style={S.th}>Reference No</th>
                            <th style={S.th}>Request No</th>
                            <th style={S.th}>Customer</th>
                            <th style={S.th}>Material Description</th>
                            <th style={S.th}>Mode</th>
                            <th style={S.th}>Status</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(lot.samples || []).map((s, i) => {
                            const editing = editingLotId === lot.id;
                            const row = editRows[s.id] || {};
                            return (
                            <tr key={s.id} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontWeight: '700' }}>{i + 1}</td>
                              {editing ? (
                                <>
                                  <td><input value={row.sd_number ?? ''} onChange={e => setEditRows(p => ({ ...p, [s.id]: { ...p[s.id], sd_number: e.target.value } }))} style={{ ...S.fieldInput, padding: '4px 8px', fontFamily: "'IBM Plex Mono', monospace" }} /></td>
                                  <td><input value={row.reference_number ?? ''} onChange={e => setEditRows(p => ({ ...p, [s.id]: { ...p[s.id], reference_number: e.target.value } }))} style={{ ...S.fieldInput, padding: '4px 8px' }} /></td>
                                  <td><input value={row.request_number ?? ''} onChange={e => setEditRows(p => ({ ...p, [s.id]: { ...p[s.id], request_number: e.target.value } }))} style={{ ...S.fieldInput, padding: '4px 8px' }} /></td>
                                  <td><input value={row.customer_name ?? ''} onChange={e => setEditRows(p => ({ ...p, [s.id]: { ...p[s.id], customer_name: e.target.value } }))} style={{ ...S.fieldInput, padding: '4px 8px' }} placeholder="e.g. Example Client" /></td>
                                  <td><input value={row.material_description ?? ''} onChange={e => setEditRows(p => ({ ...p, [s.id]: { ...p[s.id], material_description: e.target.value } }))} style={{ ...S.fieldInput, padding: '4px 8px' }} placeholder="e.g. Cold Reduced Carbon Steel Sheet" /></td>
                                </>
                              ) : (
                                <>
                                  <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{s.sd_number || s.extra_data?.['SD No'] || '—'}</td>
                                  <td style={S.td}>{s.reference_number || s.extra_data?.['Reference Number'] || '—'}</td>
                                  <td style={S.td}>{s.request_number || s.extra_data?.['Request No'] || '—'}</td>
                                  <td style={S.td}>{(s.client_name && s.client_name !== (s.extra_data?.['Material Description'] || '')) ? s.client_name : '—'}</td>
                                  <td style={S.td}>{s.extra_data?.['Material Description'] || s.client_name || '—'}</td>
                                </>
                              )}
                              <td style={{ ...S.td, textAlign: 'center' }}>{s.delivery_type || s.extra_data?.['Mode'] || '—'}</td>
                              <td style={S.td}><span style={stampStyle(s.status)}>{s.status}</span></td>
                            </tr>
                            );
                          })}
                        </tbody>
                      </table>
                      {editingLotId === lot.id && (
                        <div style={{ padding: '10px 16px', display: 'flex', gap: '10px', alignItems: 'center', borderTop: '1px solid var(--border)' }}>
                          <button onClick={() => saveEditedSamples(lot)} disabled={isSavingEdits} style={{ ...S.btnSuccess, opacity: isSavingEdits ? 0.6 : 1 }}>
                            {isSavingEdits ? 'Saving…' : '💾 Save changes'}
                          </button>
                          <button onClick={() => setEditingLotId(null)} style={S.btnSecondary}>Cancel</button>
                          <span style={{ fontSize: '11.5px', color: 'var(--text-muted)' }}>
                            Every change is recorded in the audit trail with the previous values.
                          </span>
                        </div>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          {/* ═══════════════ ARCHIVES (Admin) ═══════════════ */}
          {activeTab === 'archive' && role === 'admin' && (
            <div style={S.panel}>
              <p style={S.panelDesc}>
                Archived lots are locked — samples cannot be edited accidentally. If a company disputes a result,
                find the sample below, select which lab's data needs correcting, and send it directly back to
                that technician's Phase 3 queue. The original result is preserved in the audit trail.
              </p>

              {archiveMsg && <div style={{ ...S.msgBox, ...(S.msgTone(archiveMsg)) }}>{archiveMsg}</div>}

              {correctionForm && (
                <div style={{ ...S.trailSearchBox, border: '1px solid var(--amber)', background: 'var(--amber-light)' }}>
                  <h4 style={{ ...S.trailTitle, color: 'var(--amber)' }}>Flag sample for correction</h4>
                  <div style={{ display: 'flex', gap: '10px', flexWrap: 'wrap', alignItems: 'flex-end' }}>
                    <div style={S.field}>
                      <label style={S.fieldLabel}>Lab to re-do the test</label>
                      <select value={correctionForm.lab_stream || ''} onChange={e => setCorrectionForm(p => ({ ...p, lab_stream: e.target.value }))} style={S.fieldInput}>
                        <option value="">Select lab…</option>
                        <option value="Tensile Lab (Zeeshan Hassan)">Tensile Lab — Zeeshan Hassan</option>
                        <option value="XRF Lab (Khurram Jahangeer)">XRF Lab — Khurram Jahangeer</option>
                        <option value="OES Lab (Jahangeer)">OES Lab — Jahangeer</option>
                        <option value="Electronics Lab (Lab Technician)">Electronics Lab — Lab Technician</option>
                      </select>
                    </div>
                    <div style={{ ...S.field, flex: 1 }}>
                      <label style={S.fieldLabel}>Reason / note to technician</label>
                      <input type="text" placeholder="e.g. Company claims tensile strength value is incorrect"
                        value={correctionForm.note || ''} onChange={e => setCorrectionForm(p => ({ ...p, note: e.target.value }))}
                        style={S.fieldInput} />
                    </div>
                    <button onClick={handleFlagCorrection} style={S.btnPrimary}>Send for correction</button>
                    <button onClick={() => setCorrectionForm(null)} style={S.btnSecondary}>Cancel</button>
                  </div>
                </div>
              )}

              {isLoadingMyLots ? <div style={S.loading}>Loading archived lots…</div> : archivedLots.length === 0 ? (
                <div style={S.empty}><HexMark size={44} /><p style={{ marginTop: '14px' }}>No archived lots yet. Archive a completed lot from "My Lots".</p></div>
              ) : archivedLots.map(lot => (
                <div key={lot.id} style={{ ...S.lotBox, borderLeft: '4px solid var(--copper-dim)' }}>
                  <div style={S.lotBoxHeader}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: '10px' }}>
                      <span style={S.lotName}>{lot.lot_number}</span>
                      <span style={{ ...S.lotTypeBadge, background: 'var(--amber-light)', color: 'var(--copper-dim)' }}>ARCHIVED</span>
                      <span style={S.countBadge}>{lot.total_samples} samples</span>
                      {lot.archived_at && <span style={{ fontSize: '11px', color: 'var(--text-muted)' }}>Archived {new Date(lot.archived_at).toLocaleDateString()}</span>}
                    </div>
                    <div style={{ display: 'flex', gap: '8px' }}>
                      <button onClick={() => setExpandedLot(expandedLot === lot.id ? null : lot.id)} style={S.btnOutlineSm}>
                        {expandedLot === lot.id ? 'Collapse' : 'View samples'}
                      </button>
                      <button onClick={() => handleUnarchiveLot(lot)} style={S.btnSecondary}>Restore</button>
                    </div>
                  </div>
                  {lot.archive_note && <div style={{ padding: '8px 16px', fontSize: '12px', color: 'var(--text-muted)', fontStyle: 'italic' }}>Note: {lot.archive_note}</div>}

                  {expandedLot === lot.id && (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead>
                          <tr>
                            <th style={S.th}>S. No</th>
                            <th style={S.th}>SD No</th>
                            <th style={S.th}>Reference No</th>
                            <th style={S.th}>Request No</th>
                            <th style={S.th}>Description</th>
                            <th style={S.th}>Status</th>
                            <th style={S.th}>Correction</th>
                          </tr>
                        </thead>
                        <tbody>
                          {(lot.samples || []).map((s, i) => (
                            <tr key={s.id} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontWeight: '700' }}>{i + 1}</td>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace" }}>{s.sd_number || '—'}</td>
                              <td style={S.td}>{s.reference_number || '—'}</td>
                              <td style={S.td}>{s.request_number || '—'}</td>
                              <td style={S.td}>{s.client_name || '—'}</td>
                              <td style={S.td}><span style={stampStyle(s.status)}>{s.status}</span></td>
                              <td style={S.td}>
                                {s.correction_flag ? (
                                  <span style={{ color: 'var(--amber)', fontWeight: '700', fontSize: '11.5px' }}>⚠ Correction pending</span>
                                ) : (
                                  <button onClick={() => setCorrectionForm({ sample_id: s.id, lab_stream: '', note: '' })}
                                    style={S.btnTinyOutline}>Flag for correction</button>
                                )}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

          {/* ═══════════════ AUDIT TRAIL (Admin) ═══════════════ */}
          {activeTab === 'audit' && role === 'admin' && (
            <div style={S.panel}>
              <div style={{ ...S.trailSearchBox, marginBottom: 16 }}>
                <h4 style={S.trailTitle}>Login security alerts · last 24 hours</h4>
                {!authSecurityAlerts ? <div style={{ fontSize: 12, color: 'var(--text-muted)' }}>Security events are unavailable right now.</div> : (
                  <>
                    <div style={{ fontSize: 12, color: 'var(--text-muted)', marginBottom: 10 }}>This panel refreshes every 30 seconds while open. Alerts are stored on the CTMS server and work without internet access.</div>
                    {authSecurityAlerts.ip_alerts?.length === 0 && authSecurityAlerts.account_alerts?.length === 0
                      ? <div style={{ color: 'var(--teal)', fontWeight: 700, fontSize: 12 }}>No suspicious login pattern detected in the last 15 minutes.</div>
                      : <div style={{ display: 'grid', gap: 7, marginBottom: 10 }}>
                        {(authSecurityAlerts.ip_alerts || []).map(a => <div key={`ip-${a.ip_address}`} style={{ ...S.msgBox, ...S.msgError, margin: 0 }}>Source {a.ip_address}: {a.attempts} failed attempts across {a.accounts} account(s), latest {new Date(a.latest).toLocaleString()}.</div>)}
                        {(authSecurityAlerts.account_alerts || []).map(a => <div key={`acct-${a.identifier}`} style={{ ...S.msgBox, ...S.msgError, margin: 0 }}>Account {a.identifier}: {a.attempts} failed attempts from {a.source_ips} source address(es), latest {new Date(a.latest).toLocaleString()}.</div>)}
                      </div>}
                    <details>
                      <summary style={{ cursor: 'pointer', fontSize: 12, fontWeight: 700 }}>Recent failed sign-in events ({authSecurityAlerts.events?.length || 0})</summary>
                      <div style={{ ...S.tableWrap, marginTop: 8 }}><table style={S.table}><thead><tr><th style={S.th}>Time</th><th style={S.th}>Account</th><th style={S.th}>Source</th><th style={S.th}>Event</th></tr></thead><tbody>
                        {(authSecurityAlerts.events || []).slice(0, 30).map(event => <tr key={event.id}><td style={S.td}>{new Date(event.created_at).toLocaleString()}</td><td style={S.td}>{event.identifier || '—'}</td><td style={S.td}>{event.ip_address || '—'}</td><td style={S.td}>{event.event_type}</td></tr>)}
                      </tbody></table></div>
                    </details>
                  </>
                )}
              </div>
              <p style={S.panelDesc}>
                Search any SD No, Reference No, or lot number to see a complete record of every action taken
                on that sample — who entered data, when, from which computer (IP address). Use this to
                investigate disputes and verify technician submissions.
              </p>

              <div style={S.trailSearchBox}>
                <h4 style={S.trailTitle}>Search by SD No / Reference No / Lot No</h4>
                <div style={{ display: 'flex', gap: '10px', alignItems: 'center' }}>
                  <input type="text" placeholder="e.g. 228782 or LOT-1-2-6-2026"
                    value={auditQuery} onChange={e => setAuditQuery(e.target.value)}
                    onKeyDown={e => e.key === 'Enter' && handleAuditSearch()}
                    style={{ ...S.fieldInput, maxWidth: '360px' }} />
                  <button onClick={handleAuditSearch} disabled={isSearchingAudit} style={S.btnPrimary}>
                    {isSearchingAudit ? 'Searching…' : 'Search'}
                  </button>
                  {auditResults && (
                    <span style={{ fontSize: '12.5px', color: 'var(--text-muted)' }}>
                      {auditResults.length} sample(s) found
                    </span>
                  )}
                </div>
              </div>

              {/* Browse recent activity by module — for bulk review */}
              <div style={{ ...S.trailSearchBox, marginTop: '16px' }}>
                <h4 style={S.trailTitle}>Browse recent activity (no search term needed)</h4>
                <div style={{ display: 'flex', gap: '10px', alignItems: 'center', flexWrap: 'wrap' }}>
                  <select value={recentModule} onChange={e => setRecentModule(e.target.value)} style={S.select}>
                    <option value="cheque_records">Cheque / Payorder entries</option>
                    <option value="lots">Lot creation</option>
                    <option value="samples">Sample changes</option>
                    <option value="invoices">Invoices</option>
                    <option value="quotations">Quotations</option>
                    <option value="users">User management</option>
                  </select>
                  <button onClick={handleBrowseRecent} disabled={isLoadingRecent} style={S.btnPrimary}>
                    {isLoadingRecent ? 'Loading…' : 'Browse'}
                  </button>
                  {recentActivity && (
                    <span style={{ fontSize: '12.5px', color: 'var(--text-muted)' }}>{recentActivity.length} entries (most recent 500)</span>
                  )}
                </div>

                {recentActivity && recentActivity.length === 0 && (
                  <div style={{ marginTop: '12px', color: 'var(--text-muted)', fontSize: '13px' }}>No activity logged for this module yet.</div>
                )}

                {recentActivity && recentActivity.length > 0 && (
                  <div style={{ ...S.tableWrap, marginTop: '14px' }}>
                    <table style={S.table}>
                      <thead>
                        <tr>
                          <th style={S.th}>Date / Time</th>
                          <th style={S.th}>Action</th>
                          <th style={S.th}>By</th>
                          <th style={S.th}>Role</th>
                          <th style={S.th}>IP Address</th>
                          <th style={S.th}>Detail</th>
                        </tr>
                      </thead>
                      <tbody>
                        {recentActivity.map((entry, i) => (
                          <tr key={entry.id || i} style={i % 2 ? S.rowAlt : undefined}>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontSize: '11.5px', whiteSpace: 'nowrap' }}>
                              {new Date(entry.created_at || entry.action_time).toLocaleString()}
                            </td>
                            <td style={{ ...S.td, fontWeight: '700', fontSize: '11px', color: 'var(--copper-dim)', textTransform: 'uppercase' }}>
                              {(entry.action || '').replace(/_/g, ' ')}
                            </td>
                            <td style={S.td}>{entry.actor_name || '—'}</td>
                            <td style={{ ...S.td, fontSize: '11px', textTransform: 'uppercase' }}>{entry.actor_role || '—'}</td>
                            <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontSize: '11.5px' }}>{entry.ip_address || '—'}</td>
                            <td style={{ ...S.td, fontSize: '11px', color: 'var(--text-muted)', maxWidth: '320px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                              {entry.new_value ? JSON.stringify(JSON.parse(entry.new_value || '{}')) : '—'}
                            </td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                )}
              </div>

              {auditResults && auditResults.length === 0 && (
                <div style={S.empty}>No records found for "{auditQuery}". Try a different SD No or Reference No.</div>
              )}

              {auditResults && auditResults.map(sample => (
                <div key={sample.id} style={{ ...S.lotBox, marginTop: '16px' }}>
                  <div style={S.lotBoxHeader}>
                    <div>
                      <span style={S.lotName}>{sample.sd_number || sample.reference_number || `Sample #${sample.id}`}</span>
                      <span style={S.lotTypeBadge}>{sample.lot_number}</span>
                      {sample.correction_flag && <span style={{ marginLeft: '8px', color: 'var(--amber)', fontWeight: '700', fontSize: '11.5px' }}>⚠ Correction pending</span>}
                    </div>
                    <span style={stampStyle(sample.status)}>{sample.status}</span>
                  </div>

                  <div style={{ padding: '10px 16px', background: '#FBFAF8', borderBottom: '1px solid var(--border)', fontSize: '12.5px', display: 'flex', gap: '24px', flexWrap: 'wrap' }}>
                    <span><strong>SD No:</strong> {sample.sd_number || '—'}</span>
                    <span><strong>Reference:</strong> {sample.reference_number || '—'}</span>
                    <span><strong>Request:</strong> {sample.request_number || '—'}</span>
                    <span><strong>Description:</strong> {sample.client_name || '—'}</span>
                    <span><strong>Created:</strong> {sample.created_at}</span>
                  </div>

                  {(sample.audit_trail || []).length === 0 ? (
                    <div style={{ padding: '14px 16px', fontSize: '12.5px', color: 'var(--text-muted)' }}>No audit entries for this sample.</div>
                  ) : (
                    <div style={S.tableWrap}>
                      <table style={S.table}>
                        <thead>
                          <tr>
                            <th style={S.th}>Date / Time</th>
                            <th style={S.th}>Action</th>
                            <th style={S.th}>By</th>
                            <th style={S.th}>Role</th>
                            <th style={S.th}>IP Address</th>
                            <th style={S.th}>Detail</th>
                          </tr>
                        </thead>
                        <tbody>
                          {sample.audit_trail.map((entry, i) => (
                            <tr key={entry.id || i} style={i % 2 ? S.rowAlt : undefined}>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontSize: '11.5px', whiteSpace: 'nowrap' }}>
                                {new Date(entry.created_at || entry.action_time).toLocaleString()}
                              </td>
                              <td style={{ ...S.td, fontWeight: '700', fontSize: '11px', color: 'var(--copper-dim)', textTransform: 'uppercase' }}>
                                {(entry.action || '').replace(/_/g, ' ')}
                              </td>
                              <td style={S.td}>{entry.actor_name || '—'}</td>
                              <td style={{ ...S.td, fontSize: '11px', textTransform: 'uppercase' }}>{entry.actor_role || '—'}</td>
                              <td style={{ ...S.td, fontFamily: "'IBM Plex Mono', monospace", fontSize: '11.5px' }}>
                                {entry.ip_address || '—'}
                              </td>
                              <td style={{ ...S.td, fontSize: '11px', color: 'var(--text-muted)', maxWidth: '300px', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                {entry.new_value ? JSON.stringify(JSON.parse(entry.new_value || '{}')) : '—'}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}

        </div>{/* end content */}
        <InstitutionFooter style={S.appFooter} />
      </div>{/* end main */}
    </div>
  );
}

// ── Styles — Graphite / Copper / Teal / Paper system ──────────────
const S = {
  // AUDIT: message tone is decided by explicit emoji convention — ✅ success,
  // ⚠ warning, anything else error. (Previously a server error containing ✅
  // would render green.)
  msgTone: (m) => {
    const s = String(m || '');
    return s.includes('✅') ? S.msgSuccess : s.includes('⚠') ? S.msgWarn : S.msgError;
  },
  // SHELL — CSS Grid, no flex-shrink ambiguity, no blank gaps
  shell: {
    display: 'grid', gridTemplateColumns: '272px 1fr',
    width: '100%', height: '100vh', overflow: 'hidden',
    fontFamily: "'IBM Plex Sans', sans-serif", background: 'var(--paper)',
  },

  // FULL-SCREEN INTAKE MODE
  fullScreenIntake: { width: '100%', height: '100vh', background: 'var(--paper)', display: 'flex', flexDirection: 'column', overflow: 'hidden' },
  fsTopbar: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '14px 24px', background: 'var(--paper-card)', borderBottom: '1px solid var(--border)', flexWrap: 'wrap', gap: '10px' },
  fsGridWrap: { flex: 1, overflow: 'auto', margin: '14px 24px', border: '1px solid var(--border)', borderRadius: '8px' },
  fsHint: { marginLeft: '16px', fontSize: '11.5px', color: 'var(--text-soft)' },

  // SIDEBAR
  sidebar: {
    background: 'var(--ink)', display: 'flex', flexDirection: 'column',
    justifyContent: 'space-between', height: '100%', overflow: 'hidden',
  },
  brandRow: { display: 'flex', alignItems: 'center', gap: '12px', padding: '22px 18px', borderBottom: '1px solid rgba(255,255,255,0.07)' },
  brandName: { color: '#fff', fontWeight: '700', fontSize: '15px', lineHeight: '1.2' },
  brandSub: { color: 'var(--text-soft)', fontSize: '10px', marginTop: '2px', textTransform: 'uppercase', letterSpacing: '0.5px' },
  userCard: { display: 'flex', alignItems: 'center', gap: '10px', padding: '14px 18px', borderBottom: '1px solid rgba(255,255,255,0.07)', background: 'rgba(255,255,255,0.02)' },
  avatar: { width: '36px', height: '36px', borderRadius: '8px', background: 'var(--copper)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: '700', fontSize: '15px', flexShrink: 0 },
  userName: { color: '#fff', fontWeight: '600', fontSize: '12.5px', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis' },
  userRole: { color: 'var(--text-soft)', fontSize: '10px', marginTop: '2px', textTransform: 'uppercase', letterSpacing: '0.4px' },

  nav: { flex: 1, overflowY: 'auto', padding: '10px 0' },
  navItem: {
    display: 'flex', alignItems: 'center', gap: '12px', padding: '11px 18px',
    color: 'var(--text-soft)', cursor: 'pointer', fontSize: '13px', fontWeight: '500',
    borderLeft: '3px solid transparent', transition: 'all .12s ease',
  },
  navActive: { background: 'rgba(194,112,61,0.12)', color: '#fff', fontWeight: '700', borderLeftColor: 'var(--copper)' },
  navNum: { fontFamily: "'IBM Plex Mono', monospace", fontSize: '11px', color: 'var(--copper)', fontWeight: '700', width: '20px' },
  navIcon: { fontSize: '13px', color: 'var(--copper)', width: '20px', textAlign: 'center' },
  navBadge: { marginLeft: 'auto', background: 'var(--rust)', color: '#fff', borderRadius: '10px', padding: '1px 7px', fontSize: '10px', fontWeight: '700' },
  navDivider: { height: '1px', background: 'rgba(255,255,255,0.07)', margin: '10px 18px' },

  logoutBtn: {
    margin: '16px', padding: '11px', background: 'transparent', color: 'var(--text-soft)',
    border: '1px solid rgba(255,255,255,0.12)', borderRadius: '8px', cursor: 'pointer',
    fontWeight: '600', fontSize: '12.5px',
  },

  // MAIN
  main: { display: 'grid', gridTemplateRows: '60px minmax(0, 1fr) auto', height: '100%', overflow: 'hidden', minWidth: 0 },
  appFooter: {
    flexShrink: 0, padding: '6px 10px', color: 'var(--text-muted)',
    background: 'var(--paper-card)', borderTop: '1px solid var(--border)',
  },
  topbar: {
    display: 'flex', justifyContent: 'space-between', alignItems: 'center',
    padding: '0 28px', background: 'var(--paper-card)', borderBottom: '1px solid var(--border)',
  },
  topbarTitle: { fontSize: '15px', fontWeight: '700', color: 'var(--text)' },
  topbarMeta: { fontSize: '11px', color: 'var(--text-muted)', marginTop: '1px' },
  sessionBadge: {
    fontSize: '11px', fontWeight: '600', color: 'var(--teal)', background: 'var(--teal-light)',
    padding: '5px 12px', borderRadius: '20px',
  },
  content: { overflowY: 'auto', padding: '22px 28px' },

  // PANEL
  panel: {
    background: 'var(--paper-card)', borderRadius: '10px', border: '1px solid var(--border)',
    padding: '22px', display: 'flex', flexDirection: 'column', minHeight: 'calc(100vh - 60px - 44px)',
  },
  panelDesc: { display: 'var(--ctms-panel-desc-display, block)', margin: '0 0 16px', color: 'var(--text-muted)', fontSize: '13px' },
  sectionHeading: { margin: '20px 0 10px', fontSize: '13px', fontWeight: '700', color: 'var(--text)', textTransform: 'uppercase', letterSpacing: '0.4px' },

  // TOOLBAR / FORM CONTROLS
  toolbar: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', flexWrap: 'wrap', gap: '12px' },
  select: { padding: '9px 12px', fontSize: '13px', borderRadius: '7px', border: '1px solid var(--border)', background: '#fff', outline: 'none', cursor: 'pointer', fontFamily: 'inherit', color: 'var(--text)' },

  // BUTTONS
  btnPrimary: { padding: '10px 20px', background: 'var(--ink)', color: '#fff', border: 'none', borderRadius: '7px', cursor: 'pointer', fontWeight: '700', fontSize: '13px' },
  btnSecondary: { padding: '10px 18px', background: 'var(--paper)', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: '7px', cursor: 'pointer', fontWeight: '600', fontSize: '13px' },
  btnSuccess: { padding: '10px 20px', background: 'var(--teal)', color: '#fff', border: 'none', borderRadius: '7px', cursor: 'pointer', fontWeight: '700', fontSize: '13px' },
  btnDanger: { padding: '10px 18px', background: 'var(--rust-light)', color: 'var(--rust)', border: '1px solid #F0BDB4', borderRadius: '7px', cursor: 'pointer', fontWeight: '600', fontSize: '13px' },
  btnDangerSm: { padding: '7px 14px', background: 'var(--rust-light)', color: 'var(--rust)', border: '1px solid #F0BDB4', borderRadius: '6px', cursor: 'pointer', fontWeight: '600', fontSize: '11.5px' },
  btnOutlineSm: { padding: '7px 14px', background: '#fff', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: '6px', cursor: 'pointer', fontWeight: '600', fontSize: '11.5px' },
  btnTinyOutline: { padding: '4px 10px', background: '#fff', color: 'var(--text)', border: '1px solid var(--border)', borderRadius: '5px', cursor: 'pointer', fontWeight: '600', fontSize: '11px' },
  btnTinyDanger: { padding: '4px 10px', background: 'var(--rust-light)', color: 'var(--rust)', border: '1px solid #F0BDB4', borderRadius: '5px', cursor: 'pointer', fontWeight: '600', fontSize: '11px' },

  // MESSAGES
  msgBox: { padding: '12px 16px', borderRadius: '8px', fontWeight: '600', fontSize: '13px', marginBottom: '16px', border: '1px solid' },
  msgSuccess: { background: 'var(--teal-light)', color: '#1F5D54', borderColor: '#A9DCD5' },
  msgError: { background: 'var(--rust-light)', color: '#8C2F24', borderColor: '#F0BDB4' },
  msgWarn:  { background: 'var(--amber-light)', color: '#8a6116', borderColor: '#ecd9ac' },
  loading: { textAlign: 'center', padding: '60px 20px', color: 'var(--text-muted)', fontSize: '13px' },
  empty: { textAlign: 'center', padding: '60px 20px', color: 'var(--text-soft)' },
  emptyTitle: { fontSize: '15px', color: 'var(--text)', margin: '12px 0 6px', fontWeight: '700' },

  // TABLES
  tableWrap: { border: '1px solid var(--border)', borderRadius: '8px', overflow: 'auto', maxHeight: 'calc(100vh - 380px)' },
  table: { width: '100%', borderCollapse: 'collapse', minWidth: '700px', fontSize: '13px' },
  th: {
    background: 'var(--ink)', color: 'var(--copper-light)', padding: '10px 14px', textAlign: 'left',
    fontSize: '11px', fontWeight: '700', textTransform: 'uppercase', letterSpacing: '0.4px',
    position: 'sticky', top: 0, zIndex: 1, whiteSpace: 'nowrap',
  },
  thAccent: { background: 'var(--copper-dim)', color: '#fff' },
  td: { padding: '9px 14px', borderBottom: '1px solid var(--border)', verticalAlign: 'middle' },
  rowAlt: { background: '#FBFAF8' },
  rowSelected: { background: 'var(--teal-light)' },
  rowNum: { background: '#FBFAF8', borderBottom: '1px solid var(--border)', borderRight: '1px solid var(--border)', textAlign: 'center', fontSize: '11px', color: 'var(--text-soft)', fontFamily: "'IBM Plex Mono', monospace" },
  rowNumClean: { background: '#FBFAF8', borderBottom: '1px solid var(--border)', borderRight: '1px solid var(--border)', textAlign: 'center', fontSize: '12px', fontWeight: '700', color: 'var(--text)', fontFamily: "'IBM Plex Mono', monospace", padding: '8px 6px' },
  dupCell: { background: '#fff', borderBottom: '1px solid var(--border)', textAlign: 'center', cursor: 'pointer', verticalAlign: 'middle' },
  dupIcon: { fontSize: '13px', color: 'var(--copper)', opacity: 0.75 },
  delCell: { background: '#fff', borderBottom: '1px solid var(--border)', textAlign: 'center', cursor: 'pointer', verticalAlign: 'middle' },
  delIcon: { fontSize: '12px', color: 'var(--rust)', opacity: 0.75, fontWeight: '700' },
  colRemove: { cursor: 'pointer', color: 'var(--rust)', fontWeight: '700', flexShrink: 0 },

  // INTAKE / LAB GRID — large spreadsheet area, fills available height
  intakeGridWrap: { border: '1px solid var(--border)', borderRadius: '8px', overflow: 'auto', maxHeight: 'calc(100vh - 280px)', flex: 1 },
  gridCell: { padding: 0, border: '1px solid var(--border)' },
  gridCellSelected: { padding: 0, border: '1px solid var(--copper)', background: 'rgba(194,112,61,0.12)' },
  cellInput: { width: '100%', minWidth: '160px', border: 'none', outline: 'none', padding: '10px 12px', fontSize: '14px', background: 'transparent', resize: 'none', minHeight: '40px', boxSizing: 'border-box', fontFamily: 'inherit', color: 'var(--text)' },
  cellInputLg: { width: '100%', minWidth: '140px', border: 'none', outline: 'none', padding: '11px 12px', fontSize: '14px', background: 'transparent', boxSizing: 'border-box', fontFamily: "'IBM Plex Mono', monospace", fontWeight: '600', color: 'var(--copper-dim)' },

  // LOT BOXES / CARDS
  lotBox: { marginBottom: '22px', border: '1px solid var(--border)', borderRadius: '8px', overflow: 'hidden' },
  lotBoxHeader: { background: '#FBFAF8', padding: '12px 16px', borderBottom: '1px solid var(--border)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '8px' },
  lotName: { fontFamily: "'IBM Plex Mono', monospace", fontWeight: '700', fontSize: '13px', color: 'var(--text)' },
  lotTypeBadge: { background: 'var(--paper)', color: 'var(--text-muted)', padding: '3px 9px', borderRadius: '4px', fontSize: '10.5px', fontWeight: '700', marginLeft: '10px', border: '1px solid var(--border)' },
  countBadge: { background: 'var(--ink)', color: 'var(--copper-light)', padding: '4px 12px', borderRadius: '20px', fontSize: '11px', fontWeight: '700' },
  lotBadgeRow: { display: 'flex', alignItems: 'center', gap: '12px', marginBottom: '14px' },
  lotBadge: { display: 'inline-block', background: 'var(--ink)', color: '#fff', padding: '6px 14px', borderRadius: '6px', fontSize: '13px', fontWeight: '700', fontFamily: "'IBM Plex Mono', monospace", letterSpacing: '0.5px' },

  cardGrid: { display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))', gap: '16px' },
  lotCard: { border: '1px solid var(--border)', borderLeft: '4px solid var(--copper)', padding: '18px', borderRadius: '8px', background: '#FBFAF8' },
  readyToDownloadCard: { border: '1px solid var(--teal)', borderLeft: '4px solid var(--teal)', padding: '20px 24px', borderRadius: '8px', background: 'var(--teal-light)', display: 'flex', justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', gap: '14px' },
  lotCardMeta: { fontSize: '12.5px', color: 'var(--text-muted)', margin: '6px 0 12px' },

  // PHASE 3 admin stream bar / test header
  adminStreamBar: { background: '#FBFAF8', border: '1px solid var(--border)', padding: '12px 16px', borderRadius: '8px', marginBottom: '16px', display: 'flex', alignItems: 'center', gap: '12px' },
  adminStreamLabel: { fontWeight: '700', fontSize: '12.5px', color: 'var(--text-muted)' },
  testHeader: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '14px' },
  routingBar: { background: 'var(--teal-light)', border: '1px solid #A9DCD5', padding: '12px 18px', borderRadius: '8px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '16px', fontSize: '13px', flexWrap: 'wrap', gap: '10px' },
  labCheckGroup: { display: 'flex', gap: '12px', alignItems: 'center', background: '#fff', padding: '7px 14px', borderRadius: '7px', border: '1px solid var(--border)' },
  labCheckLabel: { display: 'flex', alignItems: 'center', gap: '5px', fontSize: '12.5px', fontWeight: '600', color: 'var(--text)', cursor: 'pointer' },

  // HOD TRAIL
  trailSearchBox: { background: '#FBFAF8', border: '1px solid var(--border)', borderRadius: '8px', padding: '16px', marginBottom: '20px' },
  trailTitle: { margin: '0 0 10px', fontSize: '13px', fontWeight: '700', color: 'var(--text)' },
  trailHint: { fontSize: '12px', color: 'var(--text-muted)' },
  trailPanel: { marginTop: '16px', border: '1px solid var(--border)', borderRadius: '8px', padding: '14px', background: '#fff' },

  kpiGrid: { display: 'grid', gridTemplateColumns: 'repeat(4, 1fr)', gap: '14px', marginBottom: '10px' },
  kpiCard: { background: '#FBFAF8', border: '1px solid var(--border)', borderLeft: '4px solid var(--copper)', padding: '16px', borderRadius: '8px' },
  kpiLabel: { fontSize: '10.5px', fontWeight: '700', color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.5px', marginBottom: '6px' },
  kpiValue: { fontSize: '28px', fontWeight: '800', color: 'var(--text)', fontFamily: "'IBM Plex Mono', monospace" },

  // CHEQUES
  notifyBox: { background: 'var(--amber-light)', border: '1px solid #F0DBA8', borderRadius: '8px', padding: '12px 16px', marginBottom: '16px' },
  notifyRow: { fontSize: '12px', color: 'var(--copper-dim)', marginTop: '6px', paddingLeft: '12px' },
  tabBtn: { padding: '9px 20px', border: 'none', cursor: 'pointer', fontWeight: '600', fontSize: '13px', background: '#fff', color: 'var(--text)' },
  tabBtnActive: { padding: '9px 20px', border: 'none', cursor: 'pointer', fontWeight: '700', fontSize: '13px', background: 'var(--ink)', color: '#fff' },
  chequeLayout: { display: 'grid', gridTemplateColumns: '340px 1fr', gap: '20px', alignItems: 'start' },
  chequeForm: { background: '#FBFAF8', padding: '18px', borderRadius: '8px', border: '1px solid var(--border)' },
  formTitle: { margin: '0 0 14px', color: 'var(--text)', fontSize: '14px', fontWeight: '700' },
  field: { marginBottom: '13px' },
  fieldLabel: { display: 'block', fontSize: '11px', fontWeight: '700', marginBottom: '5px', color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.4px' },
  fieldInput: { width: '100%', padding: '10px 12px', border: '1px solid var(--border)', borderRadius: '7px', boxSizing: 'border-box', fontSize: '13.5px', fontFamily: 'inherit', background: '#fff', color: 'var(--text)' },
  autoSheetBox: { width: '100%', padding: '10px 12px', border: '1px dashed var(--border)', borderRadius: '7px', boxSizing: 'border-box', fontSize: '12.5px', fontFamily: "'IBM Plex Mono', monospace", background: '#FBFAF8', color: 'var(--text-muted)', cursor: 'help' },
  totalsRow: { display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '10px', fontSize: '13px' },
  totalsWords: { fontSize: '11.5px', color: 'var(--text-soft)', fontStyle: 'italic' },
  inlineCode: { background: '#FBFAF8', border: '1px solid var(--border)', borderRadius: '4px', padding: '1px 6px', fontFamily: "'IBM Plex Mono', monospace", fontSize: '12px', color: 'var(--copper)' },

  // USERS
  usersLayout: { display: 'grid', gridTemplateColumns: '320px 1fr', gap: '20px', alignItems: 'start' },
  roleBadge: { background: 'var(--paper)', border: '1px solid var(--border)', padding: '3px 8px', borderRadius: '4px', fontSize: '10.5px', fontWeight: '700', color: 'var(--text-muted)' },
};

export default Dashboard;
