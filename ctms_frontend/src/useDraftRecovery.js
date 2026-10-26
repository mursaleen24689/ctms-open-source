import { useCallback, useEffect, useRef, useState } from 'react';

const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;

/** Local, user-scoped recovery for unfinished CTMS forms; never stores auth tokens. */
export default function useDraftRecovery(storageKey, value, setValue, shouldPersist = () => true) {
  const [loadedKey, setLoadedKey] = useState('');
  const hydrated = loadedKey === storageKey;
  const [savedAt, setSavedAt] = useState('');
  const [recovered, setRecovered] = useState(false);
  const [saveError, setSaveError] = useState('');
  const [revision, setRevision] = useState(0);
  const skipSave = useRef(false);

  useEffect(() => {
    let active = true;
    let recoveredDraft = null;
    try {
      const raw = localStorage.getItem(storageKey);
      if (raw) {
        const draft = JSON.parse(raw);
        const age = Date.now() - Number(draft.savedAt || 0);
        if (draft.version === 1 && draft.value && age >= 0 && age <= MAX_AGE_MS) {
          recoveredDraft = draft;
        } else {
          localStorage.removeItem(storageKey);
        }
      }
    } catch {
      try { localStorage.removeItem(storageKey); } catch { /* storage may be unavailable */ }
    }
    queueMicrotask(() => {
      if (!active) return;
      if (recoveredDraft) {
        setValue(recoveredDraft.value);
        setSavedAt(new Date(recoveredDraft.savedAt).toISOString());
        setRecovered(true);
      } else {
        setSavedAt('');
        setRecovered(false);
      }
      setLoadedKey(storageKey);
    });
    return () => { active = false; };
  // The key is intentionally the only dependency: changing forms recovers that form's draft.
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [storageKey]);

  useEffect(() => {
    if (!hydrated) return undefined;
    if (skipSave.current) {
      skipSave.current = false;
      return undefined;
    }
    if (!shouldPersist(value)) {
      try { localStorage.removeItem(storageKey); } catch { /* storage may be unavailable */ }
      return undefined;
    }
    const persist = () => {
      try {
        const now = Date.now();
        localStorage.setItem(storageKey, JSON.stringify({ version: 1, savedAt: now, value }));
        setSavedAt(new Date(now).toISOString());
        setSaveError('');
      } catch {
        setSaveError('This browser could not save a recovery copy. Keep this page open until the server confirms your work is saved.');
      }
    };
    const onVisibilityChange = () => { if (document.visibilityState === 'hidden') persist(); };
    const timer = window.setTimeout(persist, 700);
    window.addEventListener('pagehide', persist);
    document.addEventListener('visibilitychange', onVisibilityChange);
    return () => {
      window.clearTimeout(timer);
      window.removeEventListener('pagehide', persist);
      document.removeEventListener('visibilitychange', onVisibilityChange);
    };
  }, [hydrated, revision, shouldPersist, storageKey, value]);

  const clearDraft = useCallback(() => {
    try { localStorage.removeItem(storageKey); } catch { /* storage may be unavailable */ }
    skipSave.current = true;
    setSavedAt('');
    setRecovered(false);
    setSaveError('');
    setRevision(n => n + 1);
  }, [storageKey]);

  return { savedAt, recovered, saveError, clearDraft, hydrated };
}
